<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmMemoryConfigService.php';
require_once __DIR__ . '/LlmMemoryStorageService.php';
require_once __DIR__ . '/LlmMemoryRuleService.php';
require_once __DIR__ . '/LlmService.php';
require_once __DIR__ . '/prompt/LlmPromptAssetLoader.php';

/**
 * Orchestrates a single memory update: loads current memory,
 * builds prompt, calls LLM, validates result, and persists data.
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
     * Execute an LLM memory update.
     *
     * @param array $rule              Resolved rule config
     * @param array $normalized_payload Normalized event payload
     * @return bool
     */
    public function executeLlmSummarization($rule, $normalized_payload)
    {
        $user_id = $normalized_payload['user_id'];
        $rule_id = (int)($rule['id'] ?? 0);
        $memory_key = $this->resolveEffectiveMemoryKey($rule, $normalized_payload);
        $storage_mode = !empty($normalized_payload['force_storage_mode'])
            ? LlmMemoryConfigService::normalizeStorageMode($normalized_payload['force_storage_mode'])
            : $this->config_service->resolveStorageMode($rule);

        $dedupe_key = LlmMemoryConfigService::buildDedupeKey(
            $user_id, $memory_key, $rule_id,
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

        $prompt = $this->buildPrompt($rule, $normalized_payload, $current_memory, $memory_key);
        $model = $this->config_service->resolveRuleLlmModel($rule);
        $temperature = $this->config_service->resolveRuleLlmTemperature($rule);
        $max_tokens = $this->config_service->resolveRuleLlmMaxTokens($rule);

        if (defined('DEBUG') && DEBUG) {
            error_log('LLM Memory: resolved model for rule #'
                . $rule_id
                . ' => ' . ($model !== '' ? $model : '[empty]'));
        }

        try {
            $conversation_id = $this->resolveMemoryConversationId($user_id, $memory_key, $model);

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
                $this->handleFailedUpdate($user_id, $rule_id, $dedupe_key, 'No content in LLM response');
                return false;
            }

            $parse_error = '';
            $memory_data = $this->parseMemoryOutput($response['content'], $parse_error);
            if (!$memory_data) {
                error_log("LLM Memory: parse failed (attempt 1), error=$parse_error, raw=" . mb_substr($response['content'], 0, 500));

                $retry_tokens = min($max_tokens * 2, 8192);
                $retry_response = $this->llm_service->callLlmApi(
                    $prompt,
                    $model,
                    $temperature,
                    $retry_tokens,
                    [
                        'conversation_id' => $conversation_id,
                        'sent_context'    => $prompt,
                        'is_validated'    => true,
                    ]
                );
                if ($retry_response && isset($retry_response['content'])) {
                    $memory_data = $this->parseMemoryOutput($retry_response['content'], $parse_error);
                }

                if (!$memory_data) {
                    $this->handleFailedUpdate($user_id, $rule_id, $dedupe_key,
                        "Failed to parse LLM output after retry: $parse_error");
                    return false;
                }
            }

            $metadata = [
                'rule_id'       => $rule_id,
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
                        json_encode(['rule_id' => $rule_id, 'memory_key' => $memory_key])
                    );
                }
            }

            return $success;

        } catch (Exception $e) {
            $this->handleFailedUpdate($user_id, $rule_id, $dedupe_key, $e->getMessage());
            return false;
        }
    }

    /* Private Methods *********************************************************/

    /**
     * Build the LLM prompt messages array.
     *
     * Architecture:
     *   system = smart built-in prompt (merge/append/replace rules, JSON schema, language)
     *   user   = auto-assembled sections (current memory, submitted data, data_config, admin instructions)
     *
     * @param array      $rule
     * @param array      $normalized_payload
     * @param array|null $current_memory
     * @param string     $memory_key
     * @return array API messages
     */
    private function buildPrompt($rule, $normalized_payload, $current_memory, $memory_key)
    {
        $variables = $this->buildInterpolationVariables($rule, $normalized_payload, $current_memory, $memory_key);
        $user_language = $normalized_payload['user_language'] ?? '';

        return [
            ['role' => 'system', 'content' => $this->buildSystemMessage($user_language, $memory_key)],
            ['role' => 'user',   'content' => $this->buildUserMessage($rule, $normalized_payload, $current_memory, $variables, $memory_key)],
        ];
    }

    /**
     * Build the system message from the asset template with dynamic schema, language, and key scope.
     *
     * @param string $user_language  e.g. "Deutsch (Schweiz)" or ""
     * @param string $memory_key     The memory key being updated
     * @return string
     */
    private function buildSystemMessage($user_language, $memory_key = '')
    {
        $loader = new LlmPromptAssetLoader();
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

        $system = str_replace(
            ['{{output_schema}}', '{{memory_key}}'],
            [$output_schema, $memory_key ?: LLM_MEMORY_DEFAULT_KEY],
            $loader->load('core.memory.system')
        );

        if (!empty($user_language)) {
            $suffix = $loader->load('core.memory.language_suffix');
            $system .= "\n\n" . str_replace('{{user_language}}', $user_language, $suffix);
        }

        return $system;
    }

    /**
     * Auto-assemble the user message from structured context sections.
     *
     * Sections are always injected so admins never need to reference
     * {{memory_text}} or {{event_payload_json}} manually.
     *
     * @param array      $rule
     * @param array      $normalized_payload
     * @param array|null $current_memory
     * @param array      $variables  Already-resolved interpolation variables
     * @param string     $memory_key The memory key being updated
     * @return string
     */
    private function buildUserMessage($rule, $normalized_payload, $current_memory, $variables, $memory_key = '')
    {
        $sections = [];

        $effective_key = $memory_key ?: LLM_MEMORY_DEFAULT_KEY;
        $rule_label = trim((string)($rule['label'] ?? ''));
        $scope_lines = ['Memory key: `' . $effective_key . '`'];
        if ($rule_label !== '') {
            $scope_lines[] = 'Rule: ' . $rule_label;
        }
        $sections[] = "## Scope\n" . implode("\n", $scope_lines);

        $mem_text = $current_memory['memory_text'] ?? '';
        $mem_json = $current_memory['memory_json'] ?? '{}';
        if ($mem_text !== '' || $mem_json !== '{}') {
            $sections[] = "## Current Memory\n" . $mem_text
                . "\n\n### Structured Data\n```json\n" . $mem_json . "\n```";
        } else {
            $sections[] = "## Current Memory\nNo existing memory for this user yet.";
        }

        $fields = $this->stripInternalFormFields($normalized_payload['fields'] ?? []);
        if (!empty($fields)) {
            $json = json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $sections[] = "## Submitted Data\nThe following data was submitted:\n```json\n" . $json . "\n```";
        }

        $extra = $this->buildDataConfigContext($rule, $normalized_payload, $variables);
        if ($extra !== '') {
            $sections[] = "## Additional Context\n" . $extra;
        }

        $instructions = $this->resolveAdminInstructions($rule, $variables);
        if ($instructions !== '') {
            $sections[] = "## Instructions\n" . $instructions;
        }

        $sections[] = "## Reminder\n"
            . "Your response MUST be valid JSON with exactly these keys: "
            . "memory_text, memory_object, flat_fields, change_summary.\n"
            . "EVERY fact from the Current Memory section MUST appear in your output. "
            . "Only ADD or UPDATE facts related to the Submitted Data and Instructions above. "
            . "All other existing facts MUST be preserved exactly as they are — "
            . "do not rephrase, reorganise, or summarise them differently.\n"
            . "The Instructions only tell you what to ADD or UPDATE — they can "
            . "NEVER authorise removing existing memory. If the instructions say "
            . "\"only retain X\", ignore that restriction and keep all existing facts.";

        return implode("\n\n", $sections);
    }

    /**
     * Build the full set of interpolation variables available to the prompt.
     */
    private function buildInterpolationVariables($rule, $normalized_payload, $current_memory, $memory_key)
    {
        $vars = [
            'memory_key'             => $memory_key,
            'memory_text'            => $current_memory['memory_text'] ?? '',
            'memory_json'            => $current_memory['memory_json'] ?? '{}',
            'source_type'            => $normalized_payload['source_type'] ?? '',
            'source_ref'             => $normalized_payload['source_ref'] ?? '',
            'trigger_type'           => $normalized_payload['trigger_type'] ?? '',
            'event_payload_json'     => json_encode($normalized_payload['fields'] ?? [], JSON_UNESCAPED_SLASHES),
            'readable_text'          => $normalized_payload['readable_text'] ?? '',
            'user_language'          => $normalized_payload['user_language'] ?? '',
            'user_language_locale'   => $normalized_payload['user_language_locale'] ?? '',
        ];

        $fields = $normalized_payload['fields'] ?? [];
        foreach ($fields as $key => $value) {
            if (!isset($vars[$key])) {
                $vars[$key] = is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_SLASHES);
            }
        }

        $data_config = $rule['data_config'] ?? [];
        if (!empty($data_config) && is_array($data_config)) {
            $resolved_data_config = $this->db->replace_calced_values($data_config, $vars);
            $this->resolveDataConfig($resolved_data_config, $normalized_payload, $vars);
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

            $scope = trim((string)($entry['scope'] ?? ''));
            $display_name = (string)$user_input->get_dataTable_displayName($table_id);
            $display_name = $display_name !== '' ? $display_name : $table_name;

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

                    if ($scope !== '') {
                        $vars[$scope . '.' . $target] = $vars[$target];
                    }
                }

                continue;
            }

            if ($retrieve === 'JSON' || $retrieve === 'all_as_array') {
                $encoded = json_encode($selected, JSON_UNESCAPED_SLASHES);
                $vars[$table_name] = $encoded;
                $vars[$display_name] = $encoded;
                $vars[$table_name . '_count'] = (string)count($rows);
                $vars[$display_name . '_count'] = (string)count($rows);

                if ($scope !== '') {
                    $vars[$scope] = $encoded;
                    $vars[$scope . '.' . $table_name] = $encoded;
                    $vars[$scope . '.' . $display_name] = $encoded;
                    $vars[$scope . '.' . $table_name . '_count'] = (string)count($rows);
                    $vars[$scope . '.' . $display_name . '_count'] = (string)count($rows);
                }

                if (!empty($entry['all_fields'])) {
                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        foreach ($row as $col_key => $col_val) {
                            $value = is_scalar($col_val) ? (string)$col_val : json_encode($col_val, JSON_UNESCAPED_SLASHES);
                            $vars[$col_key] = $value;
                            if ($scope !== '') {
                                $vars[$scope . '.' . $col_key] = $value;
                            }
                        }
                    }
                }
            } elseif (is_array($selected)) {
                foreach ($selected as $col_key => $col_val) {
                    $value = is_scalar($col_val) ? (string)$col_val : json_encode($col_val, JSON_UNESCAPED_SLASHES);
                    if (!isset($vars[$col_key])) {
                        $vars[$col_key] = $value;
                    }
                    if ($scope !== '') {
                        $vars[$scope . '.' . $col_key] = $value;
                    }
                }
            }
        }
    }

    /**
     * Build readable context from resolved data_config entries for the
     * auto-injected "Additional Context" section. Entries that contributed
     * data to the variables are formatted as labeled JSON blocks.
     *
     * @param array $rule
     * @param array $normalized_payload
     * @param array $variables
     * @return string Human-readable context or empty string
     */
    private function buildDataConfigContext($rule, $normalized_payload, $variables)
    {
        $data_config = $rule['data_config'] ?? [];
        if (empty($data_config) || !is_array($data_config)) {
            return '';
        }

        $user_id = $normalized_payload['user_id'] ?? null;
        if (!$user_id) {
            return '';
        }

        $user_input = $this->services->get_user_input();
        $blocks = [];

        foreach ($data_config as $entry) {
            $table_name = $entry['table'] ?? '';
            if (empty($table_name)) {
                continue;
            }

            $table_id = $user_input->get_dataTable_id($table_name);
            if (!$table_id) {
                continue;
            }

            $display_name = (string)$user_input->get_dataTable_displayName($table_id);
            $label = $display_name !== '' ? $display_name : $table_name;
            $scope = trim((string)($entry['scope'] ?? ''));
            if ($scope !== '') {
                $label .= ' (' . $scope . ')';
            }

            $retrieve = $entry['retrieve'] ?? 'last';
            $filter = ($retrieve === 'last') ? "ORDER BY record_id DESC" : "ORDER BY record_id ASC";
            if (!empty($entry['filter'])) {
                $filter = $entry['filter'];
            }

            $current_user = array_key_exists('current_user', $entry) ? !empty($entry['current_user']) : true;
            $rows = $user_input->get_data($table_id, $filter, $current_user, $user_id);
            if (!$rows || !is_array($rows)) {
                continue;
            }

            $rows = array_values(array_filter($rows, function ($v) {
                return (!isset($v['deleted']) || $v['deleted'] != 1);
            }));
            if (empty($rows)) {
                continue;
            }

            if ($retrieve === 'JSON' || $retrieve === 'all_as_array') {
                $selected = $rows;
            } elseif ($retrieve === 'first') {
                $selected = reset($rows) ?: [];
            } else {
                $selected = end($rows) ?: [];
            }

            $json = json_encode($selected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $blocks[] = "### " . $label . "\n```json\n" . $json . "\n```";
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Resolve the admin instructions: from Prompt Lab or the default asset template.
     * The result is interpolated with the full variable set.
     *
     * @param array $rule
     * @param array $variables
     * @return string
     */
    private function resolveAdminInstructions($rule, $variables)
    {
        $template = $this->resolvePromptTemplate($rule);
        $interpolated = trim($this->db->replace_calced_values($template, $variables));
        return $interpolated;
    }

    /**
     * Resolve the prompt template: from prompt-lab binding or fallback to default asset.
     */
    private function resolvePromptTemplate($rule)
    {
        $binding = $rule['prompt_binding'] ?? [];
        if (!empty($binding['owner_type']) && !empty($binding['prompt_slot']) && !empty($rule['id'])) {
            try {
                $registry = new LlmPromptRegistryService($this->services);
                $descriptor = $this->rule_service->buildPromptDescriptor($rule);
                $active_version = $registry->resolveActiveVersionForOwner($descriptor);
                if ($active_version && !empty($active_version['template_raw'])) {
                    return $active_version['template_raw'];
                }
            } catch (Exception $e) {
                error_log('LLM Memory: prompt-lab resolution failed: ' . $e->getMessage());
            }
        }

        return $this->getDefaultPromptTemplate();
    }

    /**
     * Load the default instructions from the asset file.
     *
     * @return string
     */
    private function getDefaultPromptTemplate()
    {
        $loader = new LlmPromptAssetLoader();
        return $loader->load('core.memory.default_instructions');
    }

    private static $INTERNAL_FORM_FIELDS = [
        'ajax', 'is_log', 'redirect_at_end', 'trigger_type', 'record_id',
        '__form_name', '__session_id', '__csrf_token', '__post_id',
        '#section_id', '#form_id',
    ];

    /**
     * Remove SelfHelp internal form metadata that has no semantic meaning for user memory.
     *
     * @param array $fields Raw form field values.
     * @return array Cleaned fields with internal keys removed.
     */
    private function stripInternalFormFields(array $fields)
    {
        return array_diff_key($fields, array_flip(self::$INTERNAL_FORM_FIELDS));
    }

    /**
     * Parse the LLM response content into the required memory output structure.
     *
     * @param string $content Raw LLM response content
     * @return array|null Parsed memory data or null on failure
     */
    private function parseMemoryOutput($content, &$error = '')
    {
        $content = trim($content);

        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $content, $matches)) {
            $content = $matches[1];
        }

        $parsed = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed)) {
            $error = 'Invalid JSON: ' . json_last_error_msg()
                . ' (first 200 chars: ' . mb_substr($content, 0, 200) . ')';
            return null;
        }

        $required = ['memory_text', 'memory_object', 'flat_fields', 'change_summary'];
        $missing = array_diff($required, array_keys($parsed));
        if (!empty($missing)) {
            $error = 'Missing required keys: ' . implode(', ', $missing)
                . '; got keys: ' . implode(', ', array_keys($parsed));
            return null;
        }

        if (!is_array($parsed['memory_object'])) {
            $error = 'memory_object is not an array/object';
            return null;
        }
        if (!is_array($parsed['flat_fields'])) {
            $error = 'flat_fields is not an array/object';
            return null;
        }

        return [
            'memory_text'    => (string)($parsed['memory_text'] ?? ''),
            'memory_object'  => $parsed['memory_object'],
            'flat_fields'    => $parsed['flat_fields'],
            'change_summary' => (string)($parsed['change_summary'] ?? ''),
        ];
    }

    /**
     * Resolve or create a conversation ID dedicated to memory updates for this user/key.
     */
    private function resolveMemoryConversationId($user_id, $memory_key, $model = null)
    {
        $title = '__memory_update__' . $memory_key;
        $config = $this->getLlmConfig();
        $effective_model = $model ?: ($config['llm_default_model'] ?? LLM_DEFAULT_MODEL);
        $effective_model = $this->llm_service->normalizeModelIdentifier($effective_model);

        $existing = $this->db->query_db_first(
            "SELECT id, model FROM llmConversations WHERE id_users = :uid AND title = :title AND deleted = 0 ORDER BY id DESC LIMIT 1",
            [':uid' => $user_id, ':title' => $title]
        );

        if ($existing && !empty($existing['id'])) {
            if (!empty($effective_model) && (string)($existing['model'] ?? '') !== $effective_model) {
                $this->db->update_by_ids('llmConversations', [
                    'model' => $effective_model,
                ], [
                    'id' => (int)$existing['id'],
                ]);
            }
            return (int)$existing['id'];
        }

        return $this->llm_service->createConversation(
            $user_id, $title, $effective_model, null, null, null
        );
    }

    /**
     * Persist an ignored outcome (duplicate or stale) to history and log.
     */
    private function persistIgnored($user_id, $memory_key, $rule, $normalized_payload, $dedupe_key, $status)
    {
        $rule_id = (int)($rule['id'] ?? 0);
        if (defined('DEBUG') && DEBUG) {
            error_log("LLM Memory: $status for user=$user_id, rule_id=$rule_id, dedupe=$dedupe_key");
        }

        $this->storage_service->initializeMemoryTables();
        $this->storage_service->persistIgnoredHistory($user_id, $memory_key, [
            'rule_id'       => $rule_id,
            'source_type'   => $normalized_payload['source_type'] ?? '',
            'source_ref'    => $normalized_payload['source_ref'] ?? '',
            'trigger_type'  => $normalized_payload['trigger_type'] ?? '',
            'payload_json'  => $normalized_payload['fields'] ?? [],
            'event_at'      => $normalized_payload['event_at'] ?? date('Y-m-d H:i:s'),
            'dedupe_key'    => $dedupe_key,
            'update_status' => $status,
        ]);
    }

    /**
     * Log a memory update failure to error_log and the transaction audit trail.
     *
     * @param int    $user_id    User ID.
     * @param int    $rule_id    Rule ID that failed.
     * @param string $dedupe_key Deduplication key.
     * @param string $error      Error message.
     */
    private function logFailure($user_id, $rule_id, $dedupe_key, $error)
    {
        error_log("LLM Memory: update failed for user=$user_id, rule_id=$rule_id: $error");
        $this->services->get_transaction()->add_transaction(
            transactionTypes_insert,
            TRANSACTION_BY_LLM_MEMORY,
            $user_id, null, null, false,
            "Memory update failed: rule_id=$rule_id, error=$error"
        );
    }

    /**
     * Handle a failed memory update.
     */
    private function handleFailedUpdate($user_id, $rule_id, $dedupe_key, $error_detail)
    {
        $this->logFailure($user_id, $rule_id, $dedupe_key, $error_detail);
    }

    /**
     * Determine the effective memory key, using payload override if present, otherwise the rule config.
     *
     * @param array $rule               Rule row.
     * @param array $normalized_payload Payload with optional 'memory_key_override'.
     * @return string Effective memory key.
     */
    private function resolveEffectiveMemoryKey($rule, $normalized_payload)
    {
        return !empty($normalized_payload['memory_key_override'])
            ? (string)$normalized_payload['memory_key_override']
            : $this->config_service->resolveMemoryKey($rule);
    }
}
?>
