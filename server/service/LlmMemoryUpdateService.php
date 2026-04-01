<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmMemoryConfigService.php';
require_once __DIR__ . '/LlmMemoryStorageService.php';
require_once __DIR__ . '/LlmMemoryTriggerService.php';
require_once __DIR__ . '/LlmMemoryRuleService.php';
require_once __DIR__ . '/LlmService.php';

/**
 * Orchestrates a single memory update: loads current memory,
 * builds prompt, calls LLM, validates result, and persists data.
 * Handles both direct-mapping and LLM-summarization execution modes.
 */
class LlmMemoryUpdateService extends BaseLlmService
{
    /** @var LlmMemoryConfigService */
    private $config_service;

    /** @var LlmMemoryStorageService */
    private $storage_service;

    /** @var LlmService */
    private $llm_service;

    /** @var LlmMemoryRuleService */
    private $rule_service;

    public function __construct($services, ?LlmMemoryConfigService $config_service = null)
    {
        parent::__construct($services);
        $this->config_service = $config_service ?: new LlmMemoryConfigService($services);
        $this->storage_service = new LlmMemoryStorageService($services, $this->config_service);
        $this->llm_service = new LlmService($services);
        $this->rule_service = new LlmMemoryRuleService($services);
    }

    /**
     * Execute a direct-mapping memory update (no LLM call needed).
     *
     * @param array $rule              Resolved rule config
     * @param array $normalized_payload Normalized event payload
     * @return bool
     */
    public function executeDirectMapping($rule, $normalized_payload)
    {
        $user_id = $normalized_payload['user_id'];
        $memory_key = !empty($normalized_payload['memory_key_override'])
            ? $normalized_payload['memory_key_override']
            : $this->config_service->resolveMemoryKey($rule);
        $storage_mode = !empty($normalized_payload['force_storage_mode'])
            ? LlmMemoryConfigService::normalizeStorageMode($normalized_payload['force_storage_mode'])
            : $this->config_service->resolveStorageMode($rule);

        $trigger_service = new LlmMemoryTriggerService($this->services, $this->config_service);
        $dedupe_key = $trigger_service->buildDedupeKey(
            $user_id, $memory_key, $rule['key'],
            $normalized_payload['source_type'],
            $normalized_payload['source_ref'] ?? '',
            $normalized_payload['trigger_type'] ?? '',
            $normalized_payload['fields'] ?? []
        );

        if ($this->storage_service->dedupeKeyExists($dedupe_key)) {
            $this->persistIgnored($user_id, $memory_key, $rule, $normalized_payload, $dedupe_key, LLM_MEMORY_STATUS_DUPLICATE);
            return true;
        }

        $event_at = $normalized_payload['event_at'] ?? date('Y-m-d H:i:s');
        if (!$this->storage_service->isNewerEvent($user_id, $memory_key, $event_at)) {
            $this->persistIgnored($user_id, $memory_key, $rule, $normalized_payload, $dedupe_key, LLM_MEMORY_STATUS_STALE);
            return true;
        }

        $field_mapping = $rule['field_mapping'] ?? [];
        $flat_fields = [];
        foreach ($field_mapping as $target_key => $source_template) {
            $flat_fields[$target_key] = $this->interpolateTemplate($source_template, $normalized_payload['fields'] ?? []);
        }

        $memory_data = [
            'memory_text'    => implode('; ', array_map(function ($k, $v) { return "$k: $v"; }, array_keys($flat_fields), $flat_fields)),
            'memory_object'  => $flat_fields,
            'flat_fields'    => $flat_fields,
            'change_summary' => 'Direct mapping from ' . ($rule['source_type'] ?? 'unknown') . ' event.',
        ];

        $metadata = [
            'rule_key'      => $rule['key'],
            'source_type'   => $normalized_payload['source_type'],
            'source_ref'    => $normalized_payload['source_ref'] ?? '',
            'trigger_type'  => $normalized_payload['trigger_type'] ?? '',
            'payload_json'  => $normalized_payload['fields'] ?? [],
            'event_at'      => $event_at,
            'dedupe_key'    => $dedupe_key,
            'update_status' => LLM_MEMORY_STATUS_APPLIED,
        ];

        $this->storage_service->initializeMemoryTables();
        return $this->storage_service->saveMemoryUpdate($user_id, $memory_key, $memory_data, $metadata, $storage_mode);
    }

    /**
     * Execute an LLM-summarization memory update.
     *
     * @param array $rule              Resolved rule config
     * @param array $normalized_payload Normalized event payload
     * @return bool
     */
    public function executeLlmSummarization($rule, $normalized_payload)
    {
        $user_id = $normalized_payload['user_id'];
        $memory_key = !empty($normalized_payload['memory_key_override'])
            ? $normalized_payload['memory_key_override']
            : $this->config_service->resolveMemoryKey($rule);
        $storage_mode = !empty($normalized_payload['force_storage_mode'])
            ? LlmMemoryConfigService::normalizeStorageMode($normalized_payload['force_storage_mode'])
            : $this->config_service->resolveStorageMode($rule);

        $trigger_service = new LlmMemoryTriggerService($this->services, $this->config_service);
        $dedupe_key = $trigger_service->buildDedupeKey(
            $user_id, $memory_key, $rule['key'],
            $normalized_payload['source_type'],
            $normalized_payload['source_ref'] ?? '',
            $normalized_payload['trigger_type'] ?? '',
            $normalized_payload['fields'] ?? []
        );

        if ($this->storage_service->dedupeKeyExists($dedupe_key)) {
            $this->persistIgnored($user_id, $memory_key, $rule, $normalized_payload, $dedupe_key, LLM_MEMORY_STATUS_DUPLICATE);
            return true;
        }

        $event_at = $normalized_payload['event_at'] ?? date('Y-m-d H:i:s');
        if (!$this->storage_service->isNewerEvent($user_id, $memory_key, $event_at)) {
            $this->persistIgnored($user_id, $memory_key, $rule, $normalized_payload, $dedupe_key, LLM_MEMORY_STATUS_STALE);
            return true;
        }

        $this->storage_service->initializeMemoryTables();
        $current_memory = $this->storage_service->getEffectiveMemory($user_id, $memory_key);

        $prompt = $this->buildPrompt($rule, $normalized_payload, $current_memory);
        $model = !empty($rule['llm_model']) ? $rule['llm_model'] : null;
        $temperature = isset($rule['llm_temperature']) ? (float)$rule['llm_temperature'] : 0.2;
        $max_tokens = isset($rule['llm_max_tokens']) ? (int)$rule['llm_max_tokens'] : 1200;

        try {
            $conversation_id = $this->resolveMemoryConversationId($user_id, $memory_key);

            $response = $this->llm_service->callLlmApi(
                $prompt,
                $model,
                $temperature,
                $max_tokens,
                [
                    'conversation_id' => $conversation_id,
                    'sent_context'    => $prompt,
                    'is_validated'    => true,
                ]
            );

            if (!$response || !isset($response['content'])) {
                $this->handleFailedUpdate($user_id, $rule['key'], $dedupe_key, 'No content in LLM response');
                return false;
            }

            $memory_data = $this->parseMemoryOutput($response['content']);
            if (!$memory_data) {
                $this->handleFailedUpdate($user_id, $rule['key'], $dedupe_key, 'Failed to parse LLM output as valid memory structure');
                return false;
            }

            $metadata = [
                'rule_key'      => $rule['key'],
                'source_type'   => $normalized_payload['source_type'],
                'source_ref'    => $normalized_payload['source_ref'] ?? '',
                'trigger_type'  => $normalized_payload['trigger_type'] ?? '',
                'payload_json'  => $normalized_payload['fields'] ?? [],
                'event_at'      => $event_at,
                'dedupe_key'    => $dedupe_key,
                'update_status' => LLM_MEMORY_STATUS_APPLIED,
            ];

            $success = $this->storage_service->saveMemoryUpdate($user_id, $memory_key, $memory_data, $metadata, $storage_mode);

            if ($success && !empty($rule['refresh_sections'])) {
                $section_ids = is_array($rule['refresh_sections']) ? $rule['refresh_sections'] : json_decode($rule['refresh_sections'], true);
                if (is_array($section_ids) && !empty($section_ids)) {
                    require_once __DIR__ . '/LlmScriptService.php';
                    $script_service = new LlmScriptService($this->services);
                    $script_service->insert_refresh_event(
                        $user_id,
                        $section_ids,
                        'llm_memory_updated',
                        json_encode(['rule_key' => $rule['key'], 'memory_key' => $memory_key])
                    );
                }
            }

            return $success;

        } catch (Exception $e) {
            $this->handleFailedUpdate($user_id, $rule['key'], $dedupe_key, $e->getMessage());
            return false;
        }
    }

    /* Private Methods *********************************************************/

    /**
     * Build the LLM prompt messages array from rule config, payload, and current memory.
     *
     * @param array      $rule
     * @param array      $normalized_payload
     * @param array|null $current_memory
     * @return array API messages
     */
    private function buildPrompt($rule, $normalized_payload, $current_memory)
    {
        $variables = $this->buildInterpolationVariables($rule, $normalized_payload, $current_memory);
        $prompt_text = $this->resolvePromptTemplate($rule);
        $interpolated = $this->interpolatePrompt($prompt_text, $variables);

        $output_schema = json_encode([
            'type' => 'object',
            'required' => ['memory_text', 'memory_object', 'flat_fields', 'change_summary'],
            'properties' => [
                'memory_text'    => ['type' => 'string', 'description' => 'Human-readable summary of the user memory'],
                'memory_object'  => ['type' => 'object', 'description' => 'Structured key-value memory data'],
                'flat_fields'    => ['type' => 'object', 'description' => 'Flattened fields for dataTable storage'],
                'change_summary' => ['type' => 'string', 'description' => 'Brief description of what changed'],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $system = "You are a memory extraction assistant. Your task is to maintain a user's evolving memory profile.\n"
            . "ALWAYS respond with valid JSON matching this schema:\n" . $output_schema . "\n"
            . "Keep memory concise. Only store stable, useful user facts. Remove outdated information.";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $interpolated],
        ];
    }

    /**
     * Build the full set of interpolation variables available to the prompt.
     */
    private function buildInterpolationVariables($rule, $normalized_payload, $current_memory)
    {
        $vars = [
            'memory_key'          => $this->config_service->resolveMemoryKey($rule),
            'memory_text'         => $current_memory['memory_text'] ?? '',
            'memory_json'         => $current_memory['memory_json'] ?? '{}',
            'source_type'         => $normalized_payload['source_type'] ?? '',
            'source_ref'          => $normalized_payload['source_ref'] ?? '',
            'trigger_type'        => $normalized_payload['trigger_type'] ?? '',
            'event_payload_json'  => json_encode($normalized_payload['fields'] ?? [], JSON_UNESCAPED_SLASHES),
            'readable_text'       => $normalized_payload['readable_text'] ?? '',
        ];

        $fields = $normalized_payload['fields'] ?? [];
        foreach ($fields as $key => $value) {
            if (!isset($vars[$key])) {
                $vars[$key] = is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_SLASHES);
            }
        }

        $data_config = $rule['data_config'] ?? [];
        if (!empty($data_config) && is_array($data_config)) {
            $this->resolveDataConfig($data_config, $normalized_payload, $vars);
        }

        return $vars;
    }

    /**
     * Resolve data_config entries: fetch data from referenced dataTables
     * and merge into the interpolation variables.
     */
    private function resolveDataConfig($data_config, $normalized_payload, &$vars)
    {
        $user_id = $normalized_payload['user_id'] ?? null;
        if (!$user_id) {
            return;
        }

        $user_input = $this->services->get_user_input();

        foreach ($data_config as $entry) {
            $table_name = $entry['table'] ?? '';
            if (empty($table_name)) {
                continue;
            }

            $table_id = $user_input->get_dataTable_id($table_name);
            if (!$table_id) {
                continue;
            }

            $retrieve = $entry['retrieve'] ?? 'last';
            $filter = "ORDER BY record_id ASC";
            if ($retrieve === 'last') {
                $filter = "ORDER BY record_id DESC";
            }
            if (!empty($entry['filter'])) {
                $filter = $entry['filter'];
            }

            $current_user = array_key_exists('current_user', $entry) ? !empty($entry['current_user']) : true;
            $rows = $user_input->get_data(
                $table_id,
                $filter,
                $current_user,
                $user_id
            );

            if (!$rows || !is_array($rows)) {
                continue;
            }

            $rows = array_values(array_filter($rows, function ($value) {
                return (!isset($value['deleted']) || $value['deleted'] != 1);
            }));

            if (empty($rows)) {
                continue;
            }

            $selected = [];
            if ($retrieve === 'JSON') {
                $selected = $rows;
            } elseif ($retrieve === 'first') {
                $selected = reset($rows) ?: [];
            } elseif ($retrieve === 'all_as_array') {
                $selected = array_values($rows);
            } else {
                $selected = end($rows) ?: [];
            }

            $map_fields = $entry['map_fields'] ?? [];
            if (is_array($map_fields) && !empty($map_fields)) {
                foreach ($map_fields as $map) {
                    $source = $map['field_name'] ?? '';
                    $target = $map['value'] ?? $source;
                    if ($source === '' || $target === '') {
                        continue;
                    }

                    if ($retrieve === 'JSON' || $retrieve === 'all_as_array') {
                        $value = [];
                        foreach ($selected as $row) {
                            if (is_array($row) && array_key_exists($source, $row)) {
                                $value[] = $row[$source];
                            }
                        }
                    } else {
                        $value = is_array($selected) && array_key_exists($source, $selected) ? $selected[$source] : null;
                    }

                    $vars[$target] = is_scalar($value) || $value === null
                        ? (string)$value
                        : json_encode($value, JSON_UNESCAPED_SLASHES);
                }

                continue;
            }

            if ($retrieve === 'JSON' || $retrieve === 'all_as_array') {
                $vars[$table_name] = json_encode($selected, JSON_UNESCAPED_SLASHES);
                $vars[$table_name . '_count'] = (string)count($rows);
            } elseif (is_array($selected)) {
                foreach ($selected as $col_key => $col_val) {
                    if (!isset($vars[$col_key])) {
                        $vars[$col_key] = is_scalar($col_val) ? (string)$col_val : json_encode($col_val, JSON_UNESCAPED_SLASHES);
                    }
                }
            }
        }
    }

    /**
     * Resolve the prompt template: from prompt-lab binding or fallback to inline template.
     */
    private function resolvePromptTemplate($rule)
    {
        $binding = $rule['prompt_binding'] ?? [];
        if (!empty($binding['owner_type']) && !empty($binding['prompt_slot']) && !empty($rule['id'])) {
            try {
                $registry = new LlmPromptRegistryService($this->services);
                if (!empty($rule['prompt_version_override'])) {
                    $version = $registry->getVersion((int)$rule['prompt_version_override']);
                    if ($version && !empty($version['template_raw'])) {
                        return $version['template_raw'];
                    }
                }
                $descriptor = $this->rule_service->buildPromptDescriptor($rule);
                $bootstrap = $registry->bootstrapOwner($descriptor);
                $active_version = $bootstrap['active_version'] ?? null;
                if ($active_version && !empty($active_version['template_raw'])) {
                    return $active_version['template_raw'];
                }
            } catch (Exception $e) {
                error_log('LLM Memory: prompt-lab resolution failed: ' . $e->getMessage());
            }
        }

        return $this->getDefaultPromptTemplate($rule);
    }

    /**
     * Provide a sensible default prompt when no prompt is configured.
     */
    private function getDefaultPromptTemplate($rule)
    {
        return "## Memory Update Task\n\n"
            . "### Source Event\n"
            . "Type: {{source_type}}\n"
            . "Trigger: {{trigger_type}}\n\n"
            . "### Current User Memory\n"
            . "{{memory_text}}\n\n"
            . "### Current Memory (JSON)\n"
            . "{{memory_json}}\n\n"
            . "### New Event Data\n"
            . "{{event_payload_json}}\n\n"
            . "### Instructions\n"
            . "Merge the new event data into the existing user memory.\n"
            . "Keep only stable, useful facts about the user.\n"
            . "If information conflicts, prefer the newer data.\n"
            . "Do not bloat the memory with transient details.";
    }

    /**
     * Interpolate {{variable}} placeholders in the prompt text.
     */
    private function interpolatePrompt($template, $variables)
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function ($matches) use ($variables) {
            $key = $matches[1];
            return $variables[$key] ?? $matches[0];
        }, $template);
    }

    /**
     * Interpolate a field mapping template value.
     */
    private function interpolateTemplate($template, $fields)
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function ($matches) use ($fields) {
            $key = $matches[1];
            return isset($fields[$key]) ? (is_scalar($fields[$key]) ? $fields[$key] : json_encode($fields[$key])) : $matches[0];
        }, $template);
    }

    /**
     * Parse the LLM response content into the required memory output structure.
     *
     * @param string $content Raw LLM response content
     * @return array|null Parsed memory data or null on failure
     */
    private function parseMemoryOutput($content)
    {
        $content = trim($content);

        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $content, $matches)) {
            $content = $matches[1];
        }

        $parsed = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed)) {
            return null;
        }

        if (
            !array_key_exists('memory_text', $parsed)
            || !array_key_exists('memory_object', $parsed)
            || !array_key_exists('flat_fields', $parsed)
            || !array_key_exists('change_summary', $parsed)
        ) {
            return null;
        }

        if (!is_array($parsed['memory_object']) || !is_array($parsed['flat_fields'])) {
            return null;
        }

        return [
            'memory_text'    => (string)($parsed['memory_text'] ?? ''),
            'memory_object'  => $parsed['memory_object'] ?? [],
            'flat_fields'    => $parsed['flat_fields'] ?? [],
            'change_summary' => (string)($parsed['change_summary'] ?? ''),
        ];
    }

    /**
     * Resolve or create a conversation ID dedicated to memory updates for this user/key.
     * Uses a stable title pattern so repeated calls reuse the same conversation.
     */
    private function resolveMemoryConversationId($user_id, $memory_key)
    {
        $title = '__memory_update__' . $memory_key;

        $existing = $this->db->query_db_first(
            "SELECT id FROM llmConversations WHERE id_users = :uid AND title = :title AND deleted = 0 ORDER BY id DESC LIMIT 1",
            [':uid' => $user_id, ':title' => $title]
        );

        if ($existing && !empty($existing['id'])) {
            return (int)$existing['id'];
        }

        $config = $this->getLlmConfig();
        $model = $config['llm_default_model'] ?? LLM_DEFAULT_MODEL;

        return $this->llm_service->createConversation(
            $user_id, $title, $model, null, null, null
        );
    }

    /**
     * Persist an ignored outcome (duplicate or stale) to history and log.
     */
    private function persistIgnored($user_id, $memory_key, $rule, $normalized_payload, $dedupe_key, $status)
    {
        if (defined('DEBUG') && DEBUG) {
            error_log("LLM Memory: $status for user=$user_id, rule=" . $rule['key'] . ", dedupe=$dedupe_key");
        }

        $this->storage_service->initializeMemoryTables();
        $this->storage_service->persistIgnoredHistory($user_id, $memory_key, [
            'rule_key'      => $rule['key'],
            'source_type'   => $normalized_payload['source_type'] ?? '',
            'source_ref'    => $normalized_payload['source_ref'] ?? '',
            'trigger_type'  => $normalized_payload['trigger_type'] ?? '',
            'payload_json'  => $normalized_payload['fields'] ?? [],
            'event_at'      => $normalized_payload['event_at'] ?? date('Y-m-d H:i:s'),
            'dedupe_key'    => $dedupe_key,
            'update_status' => $status,
        ]);
    }

    private function logFailure($user_id, $rule_key, $dedupe_key, $error)
    {
        error_log("LLM Memory: update failed for user=$user_id, rule=$rule_key: $error");
        $this->services->get_transaction()->add_transaction(
            transactionTypes_insert,
            TRANSACTION_BY_LLM_MEMORY,
            $user_id, null, null, false,
            "Memory update failed: rule=$rule_key, error=$error"
        );
    }

    /**
     * Handle a failed memory update.
     * Per design: failures are only logged via the transaction system,
     * NOT persisted as history rows. This prevents polluting the history
     * table with incomplete data.
     */
    private function handleFailedUpdate($user_id, $rule_key, $dedupe_key, $error_detail)
    {
        $this->logFailure($user_id, $rule_key, $dedupe_key, $error_detail);
    }
}
?>
