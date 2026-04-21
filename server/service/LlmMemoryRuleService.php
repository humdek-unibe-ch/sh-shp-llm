<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmPromptRegistryService.php';

/**
 * CRUD and prompt-registry integration for normalized memory rules.
 */
class LlmMemoryRuleService extends BaseLlmService
{
    /** @var LlmPromptRegistryService */
    private $registry;

    /** @var bool|null */
    private $has_memory_key_registry = null;

    /** @var bool|null */
    private $has_rule_key_bindings = null;

    /**
     * @param object $services SelfHelp services container.
     */
    public function __construct($services)
    {
        parent::__construct($services);
        $this->registry = new LlmPromptRegistryService($services);
    }

    /**
     * List all rules.
     *
     * @return array
     */
    public function listRules()
    {
        $rows = $this->db->query_db(
            "SELECT *
             FROM llm_memory_rules
             ORDER BY enabled DESC, label ASC, id ASC"
        );

        if (!is_array($rows)) {
            return array();
        }

        return array_map(array($this, 'normalizeRuleRow'), $rows);
    }

    /**
     * Get one rule by id.
     *
     * @param int $rule_id
     * @return array|null
     */
    public function getRuleById($rule_id)
    {
        if ((int)$rule_id <= 0) {
            return null;
        }

        $row = $this->db->query_db_first(
            "SELECT * FROM llm_memory_rules WHERE id = :id LIMIT 1",
            array(':id' => (int)$rule_id)
        );

        return $row ? $this->normalizeRuleRow($row) : null;
    }

    /**
     * Create a new rule row.
     *
     * @param array $payload
     * @param string $prompt_template
     * @param string|null $prompt_meta_json
     * @param string|null $prompt_change_note
     * @return array
     */
    public function createRule($payload, $prompt_template = '', $prompt_meta_json = null, $prompt_change_note = null)
    {
        $prepared = $this->prepareRulePayload($payload);
        $rule_id = $this->db->insert('llm_memory_rules', $prepared);
        if (!$rule_id) {
            throw new Exception('Failed to create memory rule');
        }

        $this->syncRuleMemoryKeys($rule_id, $payload['memory_keys'] ?? array(LLM_MEMORY_DEFAULT_KEY));

        $rule = $this->getRuleById($rule_id);
        if (!$rule) {
            throw new Exception('Created memory rule could not be loaded');
        }

        if (trim((string)$prompt_template) !== '' || trim((string)$prompt_meta_json) !== '') {
            $this->syncPromptForRule($rule, $prompt_template, $prompt_meta_json, $prompt_change_note);
        }

        return $rule;
    }

    /**
     * Update an existing rule row.
     *
     * @param int $rule_id
     * @param array $payload
     * @param string|null $prompt_template
     * @param string|null $prompt_meta_json
     * @param string|null $prompt_change_note
     * @return array
     */
    public function updateRule($rule_id, $payload, $prompt_template = null, $prompt_meta_json = null, $prompt_change_note = null)
    {
        $existing = $this->getRuleById($rule_id);
        if (!$existing) {
            throw new Exception('Memory rule not found');
        }

        $prepared = $this->prepareRulePayload(array_merge($existing, (array)$payload), true);
        $this->db->update_by_ids('llm_memory_rules', $prepared, array('id' => (int)$rule_id));
        $this->syncRuleMemoryKeys($rule_id, $payload['memory_keys'] ?? $existing['memory_keys'] ?? array(LLM_MEMORY_DEFAULT_KEY));

        $rule = $this->getRuleById($rule_id);
        if (!$rule) {
            throw new Exception('Updated memory rule could not be loaded');
        }

        if ($prompt_template !== null || $prompt_meta_json !== null) {
            $this->syncPromptForRule(
                $rule,
                (string)$prompt_template,
                $prompt_meta_json,
                $prompt_change_note
            );
        }

        return $rule;
    }

    /**
     * Duplicate an existing rule.
     *
     * @param int $rule_id
     * @return array
     */
    public function duplicateRule($rule_id)
    {
        $existing = $this->getRuleById($rule_id);
        if (!$existing) {
            throw new Exception('Memory rule not found');
        }

        $copy = $existing;
        unset($copy['id'], $copy['prompt_binding'], $copy['sources_count']);
        $copy['label'] = trim((string)($existing['label'] ?: 'Memory Rule')) . ' Copy';

        $template = $this->getActivePromptTemplate($existing);
        $meta_json = $this->getActivePromptMetaJson($existing);

        return $this->createRule($copy, $template, $meta_json, 'Duplicated from rule #' . (int)$existing['id']);
    }

    /**
     * Delete a rule and its prompt-registry entry stream.
     *
     * @param int $rule_id
     * @return bool
     */
    public function deleteRule($rule_id)
    {
        $rule = $this->getRuleById($rule_id);
        if (!$rule) {
            return false;
        }

        $linked_actions = $this->findFormActionLinksForRule($rule);
        if (!empty($linked_actions)) {
            $action_names = array_map(function ($action) {
                return trim((string)($action['action_name'] ?? '')) ?: ('Action #' . (int)($action['id'] ?? 0));
            }, $linked_actions);

            throw new Exception(
                'Cannot delete memory rule "' . ($rule['label'] ?: ('#' . $rule['id']))
                . '" because it is linked in form actions: '
                . implode(', ', $action_names)
                . '. Remove the rule from those actions first.'
            );
        }

        $owner_type_id = $this->db->get_lookup_id_by_code('llm_prompt_owner_types', LLM_PROMPT_OWNER_MEMORY_RULE);
        if ($owner_type_id) {
            $entry = $this->db->query_db_first(
                "SELECT id
                 FROM llm_prompt_entries
                 WHERE id_llm_prompt_owner_types = :owner_type
                   AND owner_id = :owner_id
                   AND prompt_slot = :prompt_slot
                 LIMIT 1",
                array(
                    ':owner_type' => $owner_type_id,
                    ':owner_id' => (int)$rule_id,
                    ':prompt_slot' => 'memory_rule'
                )
            );

            if (!empty($entry['id'])) {
                $this->db->query_db(
                    "DELETE FROM llm_prompt_entries WHERE id = :id",
                    array(':id' => (int)$entry['id'])
                );
            }
        }

        if ($this->hasRuleKeyBindings()) {
            $this->db->execute_update_db(
                "DELETE FROM llm_memory_rule_keys WHERE id_llm_memory_rules = :rule_id",
                array(':rule_id' => (int)$rule_id)
            );
        }

        $this->db->remove_by_fk('llm_memory_rules', 'id', (int)$rule_id);
        return true;
    }

    /**
     * Find form actions that reference a given rule by ID in their config JSON.
     *
     * @param array $rule Normalized rule row with 'id'.
     * @return array List of form-action stubs [{id, action_name}] that reference the rule.
     */
    private function findFormActionLinksForRule($rule)
    {
        $rule_id = (int)($rule['id'] ?? 0);
        if ($rule_id <= 0) {
            return array();
        }

        try {
            $rows = $this->db->query_db(
                "SELECT id, action_name, config
                 FROM view_formActions
                 ORDER BY action_name ASC"
            );
        } catch (Exception $e) {
            return array();
        }

        $linked = array();
        foreach ((array)$rows as $row) {
            $config = json_decode((string)($row['config'] ?? ''), true);
            if (!is_array($config)) {
                continue;
            }

            $jobs = $this->extractMemoryJobsFromConfig($config);
            foreach ($jobs as $job) {
                if ($this->jobReferencesRule($job, $rule_id)) {
                    $linked[] = array(
                        'id' => (int)($row['id'] ?? 0),
                        'action_name' => (string)($row['action_name'] ?? ''),
                    );
                    break;
                }
            }
        }

        return $linked;
    }

    /**
     * Recursively walk a form-action config tree and extract all memory-update job nodes.
     *
     * @param array $config Form action configuration tree.
     * @return array Flat list of memory-update job nodes found at any depth.
     */
    private function extractMemoryJobsFromConfig($config)
    {
        $jobs = array();
        $walk = function ($node) use (&$walk, &$jobs) {
            if (!is_array($node)) {
                return;
            }

            if (($node['job_type'] ?? '') === ACTION_JOB_TYPE_LLM_MEMORY_UPDATE || ($node['type'] ?? '') === ACTION_JOB_TYPE_LLM_MEMORY_UPDATE) {
                $jobs[] = $node;
            }

            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };

        $walk($config);
        return $jobs;
    }

    /**
     * Check whether a memory-update job node references a specific rule by ID.
     *
     * @param array  $job      Memory-update job config node.
     * @param int    $rule_id  Rule primary key.
     * @return bool True if the job references this rule.
     */
    private function jobReferencesRule($job, $rule_id)
    {
        $job_rule_ids = $this->normalizeRuleIds($job['memory_rule_id'] ?? ($job['memory_rule_ids'] ?? ''));
        return !empty($job_rule_ids) && in_array((int)$rule_id, $job_rule_ids, true);
    }

    /**
     * Normalize a raw comma-separated or array value into a unique list of integer rule IDs.
     *
     * @param string|array $raw CSV string or array of rule IDs.
     * @return int[] Unique, positive integer rule IDs.
     */
    public function normalizeRuleIds($raw)
    {
        if (is_array($raw)) {
            $ids = $raw;
        } else {
            $ids = explode(',', (string)$raw);
        }

        $ids = array_map('intval', $ids);
        return array_values(array_filter(array_unique($ids)));
    }

    /**
     * Resolve active prompt template for UI duplication/bootstrap.
     *
     * @param array $rule
     * @return string
     */
    public function getActivePromptTemplate($rule)
    {
        $active_version = $this->registry->resolveActiveVersionForOwner($this->buildPromptDescriptor($rule));
        return (string)($active_version['template_raw'] ?? '');
    }

    /**
     * Resolve active prompt metadata JSON for UI duplication/bootstrap.
     *
     * @param array $rule
     * @return string
     */
    public function getActivePromptMetaJson($rule)
    {
        $active_version = $this->registry->resolveActiveVersionForOwner($this->buildPromptDescriptor($rule));
        $meta = $active_version['metadata_json'] ?? null;
        return is_string($meta) && trim($meta) !== '' ? $meta : '{}';
    }

    /**
     * Return the latest prompt bootstrap for a rule after save/update.
     *
     * @param array|int $rule
     * @param string|null $active_content
     * @param string|null $meta_json
     * @return array
     */
    public function getPromptBootstrap($rule, $active_content = null, $meta_json = null)
    {
        if (!is_array($rule)) {
            $rule = $this->getRuleById((int)$rule);
        }
        if (!$rule) {
            throw new Exception('Memory rule not found for prompt bootstrap');
        }

        return $this->registry->bootstrapOwner(
            $this->buildPromptDescriptor($rule),
            $active_content !== null ? (string)$active_content : $this->getActivePromptTemplate($rule),
            $meta_json !== null ? $meta_json : $this->getActivePromptMetaJson($rule),
            false,
            $this->buildPromptRuntimeOverrides($rule)
        );
    }

    /**
     * Sync the rule prompt into prompt lab.
     *
     * @param array $rule
     * @param string $prompt_template
     * @param string|null $prompt_meta_json
     * @param string|null $prompt_change_note
     * @return array
     */
    public function syncPromptForRule($rule, $prompt_template, $prompt_meta_json = null, $prompt_change_note = null)
    {
        $descriptor = $this->buildPromptDescriptor($rule);
        $meta_json = $this->applyPromptChangeNote($prompt_meta_json, $prompt_change_note);

        return $this->registry->syncPromptSave(
            $descriptor,
            (string)$prompt_template,
            $meta_json,
            $this->buildPromptRuntimeOverrides($rule)
        );
    }

    /**
     * Build the canonical prompt-lab descriptor for a rule.
     *
     * @param array|int $rule
     * @return array
     */
    public function buildPromptDescriptor($rule)
    {
        if (!is_array($rule)) {
            $rule = $this->getRuleById((int)$rule);
        }
        if (!$rule) {
            throw new Exception('Memory rule not found for prompt descriptor');
        }

        return array(
            'owner_type' => LLM_PROMPT_OWNER_MEMORY_RULE,
            'owner_id' => (int)$rule['id'],
            'prompt_slot' => 'memory_rule',
            'id_languages' => $this->registry->getCurrentCmsLanguageId(),
            'title' => $rule['label'] ?: ('Rule #' . $rule['id']),
            'rule_config' => $rule
        );
    }

    /**
     * Build prompt runtime overrides with rule values inheriting from module defaults.
     *
     * @param array $rule
     * @return array
     */
    private function buildPromptRuntimeOverrides($rule)
    {
        $llm_config = $this->getLlmConfig();
        $rule_temp = $rule['llm_temperature'] ?? '';
        $rule_tokens = $rule['llm_max_tokens'] ?? '';

        return array(
            'llm_model' => !empty($rule['llm_model'])
                ? (string)$rule['llm_model']
                : (string)($llm_config['llm_default_model'] ?? LLM_DEFAULT_MODEL),
            'llm_temperature' => ($rule_temp !== '' && $rule_temp !== null)
                ? (string)$rule_temp
                : (string)($llm_config['llm_temperature'] ?? LLM_DEFAULT_TEMPERATURE),
            'llm_max_tokens' => ($rule_tokens !== '' && $rule_tokens !== null && (int)$rule_tokens > 0)
                ? (string)$rule_tokens
                : (string)($llm_config['llm_max_tokens'] ?? LLM_DEFAULT_MAX_TOKENS),
        );
    }

    /**
     * Normalize DB row into runtime/admin shape.
     *
     * @param array $row
     * @return array
     */
    public function normalizeRuleRow($row)
    {
        $rule_id = (int)($row['id'] ?? 0);
        $key_codes = $this->getRuleMemoryKeyCodes($rule_id);

        return array(
            'id' => $rule_id,
            'label' => (string)($row['label'] ?? ''),
            'enabled' => !empty($row['enabled']) && $row['enabled'] !== '0',
            'memory_keys' => $key_codes,
            'source_type' => (string)($row['source_type'] ?? ''),
            'source_match' => $this->decodeJsonObject($row['source_match_json'] ?? '{}'),
            'trigger_types' => $this->decodeJsonArray($row['trigger_types_json'] ?? '["finished"]', array('finished')),
            'storage_mode_override' => (string)($row['storage_mode_override'] ?? ''),
            'data_config' => $this->decodeJsonArray($row['data_config_json'] ?? '[]'),
            'prompt_binding' => array(
                'owner_type' => LLM_PROMPT_OWNER_MEMORY_RULE,
                'owner_id' => (int)($row['id'] ?? 0),
                'prompt_slot' => 'memory_rule'
            ),
            'llm_model' => (string)($row['llm_model'] ?? ''),
            'llm_temperature' => $row['llm_temperature'] !== null && $row['llm_temperature'] !== '' ? (string)$row['llm_temperature'] : '',
            'llm_max_tokens' => $row['llm_max_tokens'] !== null && $row['llm_max_tokens'] !== '' ? (string)$row['llm_max_tokens'] : '',
            'refresh_sections' => $this->decodeJsonArray($row['refresh_sections_json'] ?? '[]'),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'id_users_created' => $row['id_users_created'] ?? null,
            'id_users_updated' => $row['id_users_updated'] ?? null,
        );
    }

    /**
     * Prepare inbound payload for insert/update.
     *
     * @param array $payload
     * @param bool $for_update
     * @return array
     */
    private function prepareRulePayload($payload, $for_update = false)
    {
        $label = trim((string)($payload['label'] ?? ''));
        if ($label === '') {
            $label = $for_update ? 'Memory Rule' : ('Memory Rule ' . date('YmdHis'));
        }

        return array(
            'label' => $label,
            'enabled' => !empty($payload['enabled']) ? 1 : 0,
            'source_type' => trim((string)($payload['source_type'] ?? '')),
            'source_match_json' => $this->encodeJson($payload['source_match'] ?? array()),
            'trigger_types_json' => $this->encodeJson($payload['trigger_types'] ?? array('finished')),
            'storage_mode_override' => trim((string)($payload['storage_mode_override'] ?? '')),
            'data_config_json' => $this->encodeJson($payload['data_config'] ?? array()),
            'llm_model' => trim((string)($payload['llm_model'] ?? '')),
            'llm_temperature' => $this->normalizeNullableString($payload['llm_temperature'] ?? null),
            'llm_max_tokens' => $this->normalizeNullableInt($payload['llm_max_tokens'] ?? null),
            'refresh_sections_json' => $this->encodeJson($payload['refresh_sections'] ?? array()),
            'id_users_updated' => $_SESSION['id_user'] ?? null,
            'id_users_created' => $for_update ? ($payload['id_users_created'] ?? null) : ($_SESSION['id_user'] ?? null),
        );
    }

    /**
     * List all registered memory key definitions (or the default global key if the registry table doesn't exist).
     *
     * @return array<int, array{code: string, label: string, description: string, enabled: bool}>
     */
    public function listMemoryKeys()
    {
        if (!$this->hasMemoryKeyRegistry()) {
            return array(array(
                'code' => LLM_MEMORY_DEFAULT_KEY,
                'label' => 'Global',
                'description' => 'Default shared memory space.',
                'enabled' => true,
            ));
        }

        $rows = $this->db->query_db(
            "SELECT key_code, label, description, enabled
             FROM llm_memory_keys
             ORDER BY sort_order ASC, label ASC, key_code ASC"
        );

        $keys = array();
        foreach ((array)$rows as $row) {
            $code = $this->normalizeMemoryKeyCode($row['key_code'] ?? '');
            if ($code === '') {
                continue;
            }

            $keys[] = array(
                'code' => $code,
                'label' => trim((string)($row['label'] ?? '')) ?: $this->humanizeMemoryKeyLabel($code),
                'description' => (string)($row['description'] ?? ''),
                'enabled' => !isset($row['enabled']) || (string)$row['enabled'] !== '0',
            );
        }

        if (empty($keys)) {
            $keys[] = array(
                'code' => LLM_MEMORY_DEFAULT_KEY,
                'label' => 'Global',
                'description' => 'Default shared memory space.',
                'enabled' => true,
            );
        }

        return $keys;
    }

    /**
     * List all memory keys enriched with usage statistics (rule count, current/history row counts, can_delete flag).
     *
     * @return array Each entry extends listMemoryKeys() output with is_default, rules_count, current_rows, history_rows, can_delete.
     */
    public function listMemoryKeysWithUsage()
    {
        $keys = $this->listMemoryKeys();
        $usage = $this->getMemoryKeyUsageMap();

        return array_map(function ($key) use ($usage) {
            $code = (string)($key['code'] ?? '');
            $key_usage = $usage[$code] ?? array(
                'rules_count' => 0,
                'current_rows' => 0,
                'history_rows' => 0,
            );
            $is_default = $code === LLM_MEMORY_DEFAULT_KEY;

            return array_merge($key, array(
                'is_default' => $is_default,
                'rules_count' => (int)($key_usage['rules_count'] ?? 0),
                'current_rows' => (int)($key_usage['current_rows'] ?? 0),
                'history_rows' => (int)($key_usage['history_rows'] ?? 0),
                'can_delete' => !$is_default
                    && (int)($key_usage['rules_count'] ?? 0) === 0
                    && (int)($key_usage['current_rows'] ?? 0) === 0
                    && (int)($key_usage['history_rows'] ?? 0) === 0,
            ));
        }, $keys);
    }

    /**
     * Delete a memory key from the registry. Refuses to delete the default key or keys with active usage.
     *
     * @param string $key_code Memory key code to delete.
     * @return bool True on success.
     * @throws Exception If key is invalid, default, has rules attached, or has stored data.
     */
    public function deleteMemoryKey($key_code)
    {
        $key_code = $this->normalizeMemoryKeyCode($key_code);
        if ($key_code === '') {
            throw new Exception('Invalid memory key');
        }
        if ($key_code === LLM_MEMORY_DEFAULT_KEY) {
            throw new Exception('The default global key cannot be deleted');
        }
        if (!$this->hasMemoryKeyRegistry()) {
            throw new Exception('Memory key registry is not available');
        }

        $usage = $this->getMemoryKeyUsageMap();
        $key_usage = $usage[$key_code] ?? array(
            'rules_count' => 0,
            'current_rows' => 0,
            'history_rows' => 0,
        );
        if ((int)$key_usage['rules_count'] > 0) {
            throw new Exception('Cannot delete a memory key that is still attached to rules');
        }
        if ((int)$key_usage['current_rows'] > 0 || (int)$key_usage['history_rows'] > 0) {
            throw new Exception('Cannot delete a memory key that still has saved memory data');
        }

        $this->db->execute_update_db(
            "DELETE FROM llm_memory_keys WHERE key_code = :code",
            array(':code' => $key_code)
        );

        return true;
    }

    /**
     * Assemble the full bootstrap payload for the memory-rule editor UI.
     *
     * @param object|null $settings_model Optional settings model (unused, reserved for future overrides).
     * @return array Bootstrap config with available_keys, defaults, models, source_types, storage_modes, sections.
     */
    public function getEditorBootstrap($settings_model = null)
    {
        $llm_config = $this->getLlmConfig();
        $source_type_options = array(
            array('value' => LLM_MEMORY_SOURCE_FORM_ACTION, 'label' => 'Form action submit'),
            array('value' => LLM_MEMORY_SOURCE_LOGIN, 'label' => 'Login'),
            array('value' => LLM_MEMORY_SOURCE_PROFILE_NAME, 'label' => 'Profile name change'),
        );
        $storage_mode_options = array(
            array('value' => '', 'label' => 'Use module default'),
            array('value' => 'record', 'label' => 'Record only'),
            array('value' => 'log', 'label' => 'History only'),
            array('value' => 'both', 'label' => 'Current + history'),
        );

        return array(
            'available_keys' => $this->listMemoryKeys(),
            'defaults' => array(
                'llm_model' => (string)($llm_config['llm_default_model'] ?? ''),
                'llm_temperature' => (string)($llm_config['llm_temperature'] ?? ''),
                'llm_max_tokens' => (string)($llm_config['llm_max_tokens'] ?? ''),
                'storage_mode' => $this->normalizeStorageMode($llm_config['llm_memory_storage_mode'] ?? LLM_MEMORY_DEFAULT_STORAGE_MODE),
            ),
            'models' => $this->getAvailableModelsForEditor(),
            'source_types' => $source_type_options,
            'storage_modes' => $storage_mode_options,
            'sections' => $this->getAvailableSectionsForEditor(),
        );
    }

    /**
     * Synchronize the many-to-many memory-key bindings for a rule.
     *
     * @param int   $rule_id     Memory rule primary key.
     * @param array $memory_keys Array of memory key codes to bind.
     * @return void
     */
    public function syncRuleMemoryKeys($rule_id, $memory_keys)
    {
        $rule_id = (int)$rule_id;
        if ($rule_id <= 0) {
            return;
        }

        $key_codes = $this->normalizeMemoryKeyCodes($memory_keys);
        if (empty($key_codes)) {
            $key_codes = array(LLM_MEMORY_DEFAULT_KEY);
        }

        if (!$this->hasMemoryKeyRegistry() || !$this->hasRuleKeyBindings()) {
            return;
        }

        $this->upsertMemoryKeys($key_codes);
        $this->db->execute_update_db(
            "DELETE FROM llm_memory_rule_keys WHERE id_llm_memory_rules = :rule_id",
            array(':rule_id' => $rule_id)
        );

        foreach ($key_codes as $code) {
            $key_row = $this->db->query_db_first(
                "SELECT id FROM llm_memory_keys WHERE key_code = :code LIMIT 1",
                array(':code' => $code)
            );
            if (empty($key_row['id'])) {
                continue;
            }

            $this->db->execute_update_db(
                "INSERT IGNORE INTO llm_memory_rule_keys (id_llm_memory_rules, id_llm_memory_keys)
                 VALUES (:rule_id, :key_id)",
                array(
                    ':rule_id' => $rule_id,
                    ':key_id' => (int)$key_row['id'],
                )
            );
        }
    }

    /**
     * Inject a pending change note into the prompt metadata JSON before saving a version.
     *
     * @param string|null $prompt_meta_json  Existing metadata JSON string.
     * @param string|null $prompt_change_note Human note describing the change.
     * @return string Updated metadata JSON with pendingChangeNote injected.
     */
    private function applyPromptChangeNote($prompt_meta_json, $prompt_change_note)
    {
        $meta = array();
        if (is_string($prompt_meta_json) && trim($prompt_meta_json) !== '') {
            $decoded = json_decode($prompt_meta_json, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        if (!isset($meta[LLM_PROMPT_META_KEY]) || !is_array($meta[LLM_PROMPT_META_KEY])) {
            $meta[LLM_PROMPT_META_KEY] = array();
        }

        if (is_string($prompt_change_note) && trim($prompt_change_note) !== '') {
            $meta[LLM_PROMPT_META_KEY]['pendingChangeNote'] = trim($prompt_change_note);
        }

        return !empty($meta) ? json_encode($meta) : '{}';
    }

    /**
     * Decode a JSON string into an associative array, returning empty array on failure.
     *
     * @param string|array $value JSON string or already-decoded array.
     * @return array Decoded associative array.
     */
    private function decodeJsonObject($value)
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Decode a JSON string into an indexed array, returning a fallback on failure.
     *
     * @param string|array $value    JSON string or already-decoded array.
     * @param array        $fallback Default value if decoding fails.
     * @return array Indexed array.
     */
    private function decodeJsonArray($value, $fallback = array())
    {
        if (is_array($value)) {
            return array_values($value);
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? array_values($decoded) : $fallback;
    }

    /**
     * JSON-encode a value with unescaped slashes, defaulting null to empty array.
     *
     * @param mixed $value Value to encode.
     * @return string JSON string.
     */
    private function encodeJson($value)
    {
        return json_encode($value ?? array(), JSON_UNESCAPED_SLASHES);
    }

    /**
     * Normalize an array (or single value) of memory key codes into unique, sanitized codes.
     *
     * @param array|string $memory_keys Raw key codes.
     * @return string[] Unique normalized memory key codes.
     */
    private function normalizeMemoryKeyCodes($memory_keys)
    {
        if (!is_array($memory_keys)) {
            $memory_keys = array($memory_keys);
        }

        $normalized = array();
        foreach ($memory_keys as $memory_key) {
            $code = $this->normalizeMemoryKeyCode($memory_key);
            if ($code !== '' && !in_array($code, $normalized, true)) {
                $normalized[] = $code;
            }
        }

        return array_values($normalized);
    }

    /**
     * Sanitize a single memory key code to lowercase alphanumeric with underscores/hyphens.
     *
     * @param string $memory_key Raw key string.
     * @return string Normalized key code, or empty string if invalid.
     */
    private function normalizeMemoryKeyCode($memory_key)
    {
        $memory_key = strtolower(trim((string)$memory_key));
        $memory_key = preg_replace('/[^a-z0-9_\-]/', '_', $memory_key);
        $memory_key = preg_replace('/_+/', '_', $memory_key);
        return trim((string)$memory_key, '_');
    }

    /**
     * Convert a value to trimmed string, returning null for empty/blank values.
     *
     * @param string|null $value Raw value.
     * @return string|null Trimmed string or null.
     */
    private function normalizeNullableString($value)
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    /**
     * Convert a value to integer, returning null for empty/null values.
     *
     * @param int|string|null $value Raw value.
     * @return int|null Integer or null.
     */
    private function normalizeNullableInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int)$value;
    }

    /**
     * Check if the llm_memory_keys table exists (cached after first check).
     *
     * @return bool True if the memory-key registry table is present.
     */
    private function hasMemoryKeyRegistry()
    {
        if ($this->has_memory_key_registry !== null) {
            return $this->has_memory_key_registry;
        }

        try {
            $row = $this->db->query_db_first("SHOW TABLES LIKE 'llm_memory_keys'");
            $this->has_memory_key_registry = !empty($row);
        } catch (Exception $e) {
            $this->has_memory_key_registry = false;
        }

        return $this->has_memory_key_registry;
    }

    /**
     * Check if the llm_memory_rule_keys junction table exists (cached after first check).
     *
     * @return bool True if the rule-key binding table is present.
     */
    private function hasRuleKeyBindings()
    {
        if ($this->has_rule_key_bindings !== null) {
            return $this->has_rule_key_bindings;
        }

        try {
            $row = $this->db->query_db_first("SHOW TABLES LIKE 'llm_memory_rule_keys'");
            $this->has_rule_key_bindings = !empty($row);
        } catch (Exception $e) {
            $this->has_rule_key_bindings = false;
        }

        return $this->has_rule_key_bindings;
    }

    /**
     * Resolve all memory key codes bound to a rule via the junction table.
     *
     * @param int $rule_id Rule primary key.
     * @return string[] Ordered list of memory key codes.
     */
    private function getRuleMemoryKeyCodes($rule_id)
    {
        if ($rule_id <= 0 || !$this->hasMemoryKeyRegistry() || !$this->hasRuleKeyBindings()) {
            return array(LLM_MEMORY_DEFAULT_KEY);
        }

        try {
            $rows = $this->db->query_db(
                "SELECT mk.key_code
                 FROM llm_memory_rule_keys rk
                 INNER JOIN llm_memory_keys mk ON mk.id = rk.id_llm_memory_keys
                 WHERE rk.id_llm_memory_rules = :rule_id
                 ORDER BY mk.sort_order ASC, mk.label ASC, mk.key_code ASC",
                array(':rule_id' => $rule_id)
            );
        } catch (Exception $e) {
            return array(LLM_MEMORY_DEFAULT_KEY);
        }

        $codes = array();
        foreach ((array)$rows as $row) {
            $code = $this->normalizeMemoryKeyCode($row['key_code'] ?? '');
            if ($code !== '' && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        if (empty($codes)) {
            $codes[] = LLM_MEMORY_DEFAULT_KEY;
        }

        return $codes;
    }

    /**
     * Ensure all given memory key codes exist in the llm_memory_keys registry (INSERT IGNORE).
     *
     * @param array $key_codes Memory key codes to upsert.
     * @return void
     */
    private function upsertMemoryKeys($key_codes)
    {
        if (!$this->hasMemoryKeyRegistry()) {
            return;
        }

        foreach ($this->normalizeMemoryKeyCodes($key_codes) as $code) {
            $this->db->execute_update_db(
                "INSERT IGNORE INTO llm_memory_keys (key_code, label, description, enabled, sort_order)
                 VALUES (:code, :label, '', 1, 100)",
                array(
                    ':code' => $code,
                    ':label' => $this->humanizeMemoryKeyLabel($code),
                )
            );
        }
    }

    /**
     * Convert a snake_case/kebab-case memory key code into a human-readable title-case label.
     *
     * @param string $code Memory key code.
     * @return string Humanized label (e.g. 'user_preferences' -> 'User Preferences').
     */
    private function humanizeMemoryKeyLabel($code)
    {
        $label = str_replace(array('_', '-'), ' ', (string)$code);
        $label = ucwords(trim($label));
        return $label !== '' ? $label : 'Global';
    }

    /**
     * Build a map of memory key codes to their usage statistics (rule bindings, current/history row counts).
     *
     * @return array<string, array{rules_count?: int, current_rows?: int, history_rows?: int}>
     */
    private function getMemoryKeyUsageMap()
    {
        $usage = array();

        if ($this->hasMemoryKeyRegistry() && $this->hasRuleKeyBindings()) {
            try {
                $rule_rows = $this->db->query_db(
                    "SELECT mk.key_code, COUNT(*) AS rules_count
                     FROM llm_memory_rule_keys rk
                     INNER JOIN llm_memory_keys mk ON mk.id = rk.id_llm_memory_keys
                     GROUP BY mk.key_code"
                );
                foreach ((array)$rule_rows as $row) {
                    $code = $this->normalizeMemoryKeyCode($row['key_code'] ?? '');
                    if ($code === '') {
                        continue;
                    }
                    if (!isset($usage[$code])) {
                        $usage[$code] = array();
                    }
                    $usage[$code]['rules_count'] = (int)($row['rules_count'] ?? 0);
                }
            } catch (Exception $e) {
                // keep zero usage fallback
            }
        }

        $tables = array(
            'current_rows' => LLM_MEMORY_DEFAULT_TABLE,
            'history_rows' => LLM_MEMORY_DEFAULT_HISTORY_TABLE,
        );
        foreach ($tables as $field => $table_name) {
            if (!$this->tableExists($table_name)) {
                continue;
            }
            try {
                $rows = $this->db->query_db(
                    "SELECT memory_key, COUNT(*) AS row_count
                     FROM {$table_name}
                     GROUP BY memory_key"
                );
                foreach ((array)$rows as $row) {
                    $code = $this->normalizeMemoryKeyCode($row['memory_key'] ?? '');
                    if ($code === '') {
                        continue;
                    }
                    if (!isset($usage[$code])) {
                        $usage[$code] = array();
                    }
                    $usage[$code][$field] = (int)($row['row_count'] ?? 0);
                }
            } catch (Exception $e) {
                // table might not exist yet on fresh installs
            }
        }

        return $usage;
    }

    /**
     * Check whether a database table exists by name.
     *
     * @param string $table_name Table name to check.
     * @return bool True if the table exists.
     */
    private function tableExists($table_name)
    {
        try {
            $row = $this->db->query_db_first(
                "SHOW TABLES LIKE :table_name",
                array(':table_name' => $table_name)
            );
            return !empty($row);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Fetch the list of available LLM models for the rule editor dropdown.
     *
     * @return array Model list from LlmService::getAvailableModels().
     */
    private function getAvailableModelsForEditor()
    {
        require_once __DIR__ . '/LlmService.php';
        $service = new LlmService($this->services);
        return $service->getAvailableModels();
    }

    /**
     * Fetch all CMS sections as [{id, name}] for the rule editor's refresh-sections picker.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function getAvailableSectionsForEditor()
    {
        try {
            $rows = $this->db->query_db(
                "SELECT s.id, s.name
                 FROM sections s
                 ORDER BY s.name ASC"
            );
        } catch (Exception $e) {
            return array();
        }

        $sections = array();
        foreach ((array)$rows as $row) {
            $section_id = (int)($row['id'] ?? 0);
            if ($section_id <= 0) {
                continue;
            }

            $sections[] = array(
                'id' => $section_id,
                'name' => (string)($row['name'] ?? ('Section #' . $section_id)),
            );
        }

        return $sections;
    }

    /**
     * Map raw storage mode values (including legacy lookup codes) to normalized short forms.
     *
     * @param string $raw Raw storage mode value or legacy lookup code.
     * @return string Normalized mode: 'record', 'log', or 'both'.
     */
    private function normalizeStorageMode($raw)
    {
        $map = array(
            'memory_storage_record' => 'record',
            'memory_storage_log' => 'log',
            'memory_storage_both' => 'both',
            'record' => 'record',
            'log' => 'log',
            'both' => 'both',
        );
        return $map[(string)$raw] ?? 'both';
    }
}
