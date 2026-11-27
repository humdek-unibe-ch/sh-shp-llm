<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmMemoryConfigService.php';
require_once __DIR__ . '/LlmMemoryStorageService.php';
require_once __DIR__ . '/LlmMemoryRuleService.php';
require_once __DIR__ . '/LlmMemoryItems.php';
require_once __DIR__ . '/LlmService.php';
require_once __DIR__ . '/prompt/LlmPromptAssetLoader.php';

/**
 * Orchestrates a single memory update: loads current memory,
 * builds prompt, calls LLM, validates result, and persists data.
 *
 * A memory is a list of items (LlmMemoryItems). The model does not rewrite it:
 * it answers with operations (add / update / delete) on single items, which are
 * applied here, in code, to what is stored at that moment. A memory that is
 * still free text (written before items existed) is converted by the model
 * with add operations on the first update.
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
     * Log the outcome of one memory update on the scheduled job that ran it
     * (shown with the job in Scheduled Jobs). Core marks the job done when
     * the task returns - for a background update that is when the worker
     * started, so this entry is where the real result is visible.
     *
     * @param int $job_id scheduledJobs id, 0 when the update did not come from a job
     * @param array $rule
     * @param array $normalized_payload
     * @param bool $success
     * @param string $error Short reason, never user data
     */
    public function recordJobResult($job_id, $rule, $normalized_payload, $success, $error = '')
    {
        if ((int)$job_id <= 0) {
            return;
        }
        $transaction = $this->services->get_transaction();
        $transaction->add_transaction(
            $success ? transactionTypes_execute_task_ok : transactionTypes_execute_task_fail,
            TRANSACTION_BY_LLM_MEMORY,
            (int)($normalized_payload['user_id'] ?? 0) ?: null,
            $transaction::TABLE_SCHEDULED_JOBS,
            (int)$job_id,
            false,
            'LLM memory update ' . ($success ? 'finished' : 'failed')
                . ': rule #' . (int)($rule['id'] ?? 0)
                . ', memory key "' . $this->resolveEffectiveMemoryKey($rule, $normalized_payload) . '"'
                . ($error !== '' ? ' (' . $error . ')' : '')
        );
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
        $memory = LlmMemoryItems::fromRow($current_memory);
        $key_sections = $this->config_service->getKeySections($memory_key);
        // free text written before items existed: the model converts it with add operations
        $converting = $memory['kind'] === 'legacy';

        $prompt = $this->buildPrompt($rule, $normalized_payload, $current_memory, $memory_key, $memory, $key_sections);
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
            $llm_ops = $this->parseMemoryOutput($response['content'], $parse_error, $converting);
            if (!$llm_ops) {
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
                    $llm_ops = $this->parseMemoryOutput($retry_response['content'], $parse_error, $converting);
                }

                if (!$llm_ops) {
                    $this->handleFailedUpdate($user_id, $rule_id, $dedupe_key,
                        "Failed to parse LLM output after retry: $parse_error");
                    return false;
                }
            }

            // The memory can change while the model works (the user edits it in the editor, another
            // update runs): the operations are applied to what is stored now, not to what the model was
            // shown. Items an operation names that are gone meanwhile are skipped, all others stay as they are.
            $this->cache->clear_cache($this->cache::CACHE_TYPE_USER_INPUT);
            $latest = LlmMemoryItems::fromRow($this->storage_service->getEffectiveMemory($user_id, $memory_key));
            if ($this->legacyMemoryChanged($memory, $latest)) {
                $this->handleFailedUpdate($user_id, $rule_id, $dedupe_key,
                    'The free-text memory changed while it was being converted');
                return false;
            }

            $ops = $llm_ops['ops'];
            if (empty($key_sections)) {
                // a key without sections: the model was asked for no section; one it names anyway is
                // dropped, so the fact lands in the flat list instead of being skipped
                foreach ($ops as $index => $op) {
                    unset($ops[$index]['section']);
                }
            }
            $applied = LlmMemoryItems::applyOps($latest['items'], $ops, $key_sections, LlmMemoryItems::SOURCE_LLM);
            if ($converting && $applied['applied'] === 0) {
                $this->handleFailedUpdate($user_id, $rule_id, $dedupe_key,
                    'The free-text memory was not converted: no operation could be applied');
                return false;
            }
            if (!empty($applied['skipped'])) {
                $this->logWarning('Memory operations skipped', [
                    'user_id'    => (int)$user_id,
                    'memory_key' => $memory_key,
                    'rule_id'    => $rule_id,
                    'skipped'    => $applied['skipped'],
                ]);
            }

            $memory_data = [
                'memory_text'    => LlmMemoryItems::renderText($applied['items'], $key_sections),
                'memory_object'  => LlmMemoryItems::toMemoryObject($applied['items']),
                'flat_fields'    => [],
                'change_summary' => $llm_ops['change_summary'],
            ];

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
            LlmErrorReporter::log('Memory update for user ' . (int)$user_id . ', rule ' . (int)$rule_id, $e);
            $this->recordFailure($user_id, $rule_id, LlmErrorReporter::describe($e));
            return false;
        }
    }

    /* Private Methods *********************************************************/

    /**
     * Build the LLM prompt messages array.
     *
     * Architecture:
     *   system = smart built-in prompt (operations, preservation rules, JSON schema, language)
     *   user   = auto-assembled sections (sections of the key, current memory items, submitted data,
     *            data_config, admin instructions)
     *
     * @param array      $rule
     * @param array      $normalized_payload
     * @param array|null $current_memory  The stored memory row
     * @param string     $memory_key
     * @param array      $memory          LlmMemoryItems::fromRow() of $current_memory
     * @param array      $key_sections    Sections of $memory_key
     * @return array API messages
     */
    private function buildPrompt($rule, $normalized_payload, $current_memory, $memory_key, $memory, $key_sections)
    {
        $variables = $this->buildInterpolationVariables($rule, $normalized_payload, $current_memory, $memory_key);
        $user_language = $normalized_payload['user_language'] ?? '';

        return [
            ['role' => 'system', 'content' => $this->buildSystemMessage($user_language, $memory_key, $key_sections)],
            ['role' => 'user',   'content' => $this->buildUserMessage($rule, $normalized_payload, $variables, $memory_key, $memory, $key_sections)],
        ];
    }

    /**
     * Build the system message from the asset template with dynamic schema, language, and key scope.
     *
     * @param string $user_language  e.g. "Deutsch (Schweiz)" or ""
     * @param string $memory_key     The memory key being updated
     * @param array  $key_sections   Sections of the key (their codes are the allowed `section` values)
     * @return string
     */
    private function buildSystemMessage($user_language, $memory_key = '', $key_sections = [])
    {
        $loader = new LlmPromptAssetLoader();
        $text_schema = ['type' => 'string', 'maxLength' => LlmMemoryItems::MAX_TEXT_LENGTH, 'description' => 'One short fact'];
        // a key without sections is one flat list: its operations carry no section
        $codes = array_column($key_sections, 'code');
        $add_op = ['op' => ['const' => 'add'], 'section' => ['type' => 'string', 'enum' => $codes], 'text' => $text_schema];
        $update_op = [
            'op'      => ['const' => 'update'],
            'id'      => ['type' => 'string', 'description' => 'Id of an item in Current Memory'],
            'text'    => $text_schema,
            'section' => ['type' => 'string', 'enum' => $codes, 'description' => 'Only to move the item to another section'],
        ];
        if (empty($codes)) {
            unset($add_op['section'], $update_op['section']);
        }
        $output_schema = json_encode([
            'type' => 'object',
            'required' => ['ops', 'change_summary'],
            'properties' => [
                'ops' => [
                    'type' => 'array',
                    'description' => 'Operations on memory items, applied in order. Empty when nothing changes.',
                    'items' => ['oneOf' => [
                        [
                            'type' => 'object',
                            'required' => empty($codes) ? ['op', 'text'] : ['op', 'section', 'text'],
                            'properties' => $add_op,
                        ],
                        [
                            'type' => 'object',
                            'required' => ['op', 'id', 'text'],
                            'properties' => $update_op,
                        ],
                        [
                            'type' => 'object',
                            'required' => ['op', 'id'],
                            'properties' => [
                                'op' => ['const' => 'delete'],
                                'id' => ['type' => 'string', 'description' => 'Id of an item in Current Memory'],
                            ],
                        ],
                    ]],
                ],
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
     * @param array      $variables    Already-resolved interpolation variables
     * @param string     $memory_key   The memory key being updated
     * @param array      $memory       LlmMemoryItems::fromRow() of the stored memory
     * @param array      $key_sections Sections of $memory_key
     * @return string
     */
    private function buildUserMessage($rule, $normalized_payload, $variables, $memory_key, $memory, $key_sections)
    {
        $sections = [];

        $effective_key = $memory_key ?: LLM_MEMORY_DEFAULT_KEY;
        $rule_label = trim((string)($rule['label'] ?? ''));
        $scope_lines = ['Memory key: `' . $effective_key . '`'];
        if ($rule_label !== '') {
            $scope_lines[] = 'Rule: ' . $rule_label;
        }
        $sections[] = "## Scope\n" . implode("\n", $scope_lines);

        if (!empty($key_sections)) {
            $section_lines = [];
            foreach ($key_sections as $section) {
                $section_lines[] = '- `' . $section['code'] . '` (' . $section['label'] . ')'
                    . ($section['hint'] !== '' ? ': ' . $section['hint'] : '');
            }
            $sections[] = "## Sections\nEvery item belongs to exactly one of these sections. Use the code:\n" . implode("\n", $section_lines);
        } else {
            $sections[] = "## Sections\nThis memory has no sections: it is one list of items. Do not send a \"section\" in any operation.";
        }

        if ($memory['kind'] === 'items' && !empty($memory['items'])) {
            $sections[] = "## Current Memory\n### Structured Data\n```json\n" . $this->formatItemsForPrompt($memory['items']) . "\n```";
        } elseif ($memory['kind'] === 'items') {
            $sections[] = "## Current Memory\nThe memory has no items yet.";
        } elseif ($memory['kind'] === 'legacy') {
            // not converted yet: the old text goes to the model as data to turn into items
            $legacy = "## Legacy Memory (to convert)\n" . $memory['legacy_text'];
            if ($memory['legacy_json'] !== '') {
                $legacy .= "\n\n### Structured Data\n```json\n" . $memory['legacy_json'] . "\n```";
            }
            $sections[] = "## Current Memory\nThe memory has no items yet: it is still free text, see Legacy Memory (to convert).";
            $sections[] = $legacy;
        } else {
            $sections[] = "## Current Memory\nNo existing memory for this user yet.";
        }

        // A deleted form entry: the rule's Instructions decide whether the
        // memory forgets it or keeps it as history; the plugin only says so.
        $deleted = ($normalized_payload['trigger_type'] ?? '') === actionTriggerTypes_deleted;
        if ($deleted) {
            $sections[] = "## Event\n"
                . "The participant deleted this entry (its status is now \"deleted\"). "
                . "Submitted Data shows the entry's last values before it was deleted. "
                . "Handle it as the Instructions say. Without an instruction about deleted entries, "
                . "do not add it as new information.";
        }

        $fields = $this->stripInternalFormFields($normalized_payload['fields'] ?? []);
        if (!empty($fields)) {
            $json = json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $sections[] = "## Submitted Data\n"
                . ($deleted ? "The deleted entry contained this data:" : "The following data was submitted:")
                . "\n```json\n" . $json . "\n```";
        }

        // form / SurveyJS submissions carry a labeled snapshot (LlmMemorySubmissionContextService)
        $readable = trim((string)($normalized_payload['readable_text'] ?? ''));
        if (($normalized_payload['source_type'] ?? '') === LLM_MEMORY_SOURCE_FORM_ACTION && $readable !== '') {
            $sections[] = "## Submission Context\nThe same submission as in Submitted Data, with the full question labels and readable answer texts:\n```json\n" . $readable . "\n```";
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
            . "Your response MUST be valid JSON with exactly these keys: ops, change_summary.\n"
            . "Every item you do not name in an operation stays exactly as it is. "
            . "Use only ids from Current Memory" . (empty($key_sections) ? " and no section. " : " and only the section codes from Sections. ")
            . "Only add, update or delete what the Submitted Data and Instructions above give a clear reason for.\n"
            . ($memory['kind'] === 'legacy'
                ? "The memory is still free text: convert EVERY fact of the Legacy Memory with add operations "
                    . "(one fact per item" . (empty($key_sections) ? "" : ", in the right section") . ") and add what the Submitted Data brings. "
                    . "ops MUST NOT be empty.\n"
                : '')
            . ($deleted
                ? "Only exception: items that came only from the deleted entry may be "
                    . "deleted if, and only if, the Instructions say so. "
                    . "Every other item MUST be kept."
                : "The Instructions tell you what to ADD or UPDATE — they can "
                    . "NEVER authorise deleting items. Only an explicit request in the Submitted Data to remove "
                    . "or forget something deletes the items it names, and no others. If the instructions say "
                    . "\"only retain X\", ignore that restriction and keep all existing items.");

        return implode("\n\n", $sections);
    }

    /**
     * The items as the model sees them: one JSON object per line with id, section and text.
     *
     * @param array $items
     * @return string JSON array
     */
    private function formatItemsForPrompt(array $items)
    {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = '  ' . json_encode(
                ['id' => $item['id'], 'section' => $item['section'], 'text' => $item['text']],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }
        return "[\n" . implode(",\n", $lines) . "\n]";
    }

    /**
     * Did a free-text memory change between the moment the model was asked and the moment
     * its operations are applied? Operations on items can be applied to a memory that changed
     * meanwhile (ids that are gone are skipped); a conversion cannot: the model converted a text
     * that is no longer the one stored, or added what another update already converted.
     *
     * @param array $prompted LlmMemoryItems::fromRow() of the memory the model was shown
     * @param array $latest   LlmMemoryItems::fromRow() of the memory stored now
     * @return bool
     */
    private function legacyMemoryChanged(array $prompted, array $latest)
    {
        if ($prompted['kind'] !== 'legacy' && $latest['kind'] !== 'legacy') {
            return false;
        }
        return $prompted['kind'] !== $latest['kind']
            || $prompted['legacy_text'] !== $latest['legacy_text']
            || $prompted['legacy_json'] !== $latest['legacy_json'];
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
            // Form values are entered by users: a typed {{...}} stays text and can
            // never remove a filter (PageDb::mark_interpolation_values(), SelfHelp v7.13.0)
            $resolved_data_config = $this->db->unmark_interpolation_values(
                $this->db->replace_calced_values($data_config, $this->db->mark_interpolation_values($vars)),
                array('@')
            );
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
        // One value must not resolve a {{placeholder}} typed into another
        $interpolated = trim($this->db->unmark_interpolation_values(
            $this->db->replace_calced_values($template, $this->db->mark_interpolation_values($variables))
        ));
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
                LlmErrorReporter::log('Memory prompt-lab prompt resolution', $e);
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
     * Parse the LLM response content into the operations to apply.
     *
     * The shape of every operation is checked here (a model that answers with
     * something else gets one more try); whether an operation fits the memory (an id that
     * exists, a section of the key) is decided when it is applied, by LlmMemoryItems.
     * Empty `ops` is a valid answer: nothing changes.
     *
     * @param string $content    Raw LLM response content
     * @param string $error      Set to the reason when the content is not valid
     * @param bool   $converting The memory is free text and must be converted: needs add operations
     * @return array|null ['ops' => array, 'change_summary' => string], or null on failure
     */
    private function parseMemoryOutput($content, &$error = '', $converting = false)
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

        $required = ['ops', 'change_summary'];
        $missing = array_diff($required, array_keys($parsed));
        if (!empty($missing)) {
            $error = 'Missing required keys: ' . implode(', ', $missing)
                . '; got keys: ' . implode(', ', array_keys($parsed));
            return null;
        }

        if (!is_array($parsed['ops']) || ($parsed['ops'] !== [] && !array_is_list($parsed['ops']))) {
            $error = 'ops is not a list';
            return null;
        }
        if (!is_scalar($parsed['change_summary']) && $parsed['change_summary'] !== null) {
            $error = 'change_summary is not text';
            return null;
        }

        $adds = 0;
        foreach ($parsed['ops'] as $position => $op) {
            $name = is_array($op) && isset($op['op']) && is_string($op['op']) ? $op['op'] : '';
            if ($name === 'add') {
                // section: required by the key's sections, checked when the op is applied (a key without sections has none)
                $valid = isset($op['text']) && is_string($op['text']) && (!isset($op['section']) || is_string($op['section']));
                $adds++;
            } elseif ($name === 'update') {
                $valid = isset($op['id']) && is_string($op['id'])
                    && ((isset($op['text']) && is_string($op['text'])) || (isset($op['section']) && is_string($op['section'])));
            } elseif ($name === 'delete') {
                $valid = isset($op['id']) && is_string($op['id']);
            } else {
                $error = 'ops[' . $position . '] is not an add, update or delete operation';
                return null;
            }
            if (!$valid) {
                $error = 'ops[' . $position . '] (' . $name . ') lacks its string fields';
                return null;
            }
        }
        if ($converting && $adds === 0) {
            $error = 'The free-text memory must be converted with add operations, ops has none';
            return null;
        }

        return [
            'ops'            => $parsed['ops'],
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
            $user_id, $title, $effective_model, null, null, null, LLM_CONV_SOURCE_MEMORY
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
        $this->recordFailure($user_id, $rule_id, $error);
    }

    /**
     * Write a memory update failure to the transaction audit trail.
     *
     * @param int    $user_id User ID.
     * @param int    $rule_id Rule ID that failed.
     * @param string $error   What may be written down (see LlmErrorReporter::describe()).
     */
    private function recordFailure($user_id, $rule_id, $error)
    {
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
