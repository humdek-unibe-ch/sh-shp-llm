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
             ORDER BY enabled DESC, label ASC, rule_key ASC"
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
     * Get one rule by key.
     *
     * @param string $rule_key
     * @return array|null
     */
    public function getRuleByKey($rule_key)
    {
        $rule_key = trim((string)$rule_key);
        if ($rule_key === '') {
            return null;
        }

        $row = $this->db->query_db_first(
            "SELECT * FROM llm_memory_rules WHERE rule_key = :rule_key LIMIT 1",
            array(':rule_key' => $rule_key)
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

        $this->syncRuleMemoryKeys($rule_id, $payload['memory_keys'] ?? array($prepared['memory_key']));

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
        $this->syncRuleMemoryKeys($rule_id, $payload['memory_keys'] ?? $existing['memory_keys'] ?? array($prepared['memory_key']));

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
        $copy['key'] = '';
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
     * Resolve active prompt template for UI duplication/bootstrap.
     *
     * @param array $rule
     * @return string
     */
    public function getActivePromptTemplate($rule)
    {
        $bootstrap = $this->registry->bootstrapOwner($this->buildPromptDescriptor($rule));
        return (string)($bootstrap['active_version']['template_raw'] ?? '');
    }

    /**
     * Resolve active prompt metadata JSON for UI duplication/bootstrap.
     *
     * @param array $rule
     * @return string
     */
    public function getActivePromptMetaJson($rule)
    {
        $bootstrap = $this->registry->bootstrapOwner($this->buildPromptDescriptor($rule));
        $meta = $bootstrap['active_version']['metadata_json'] ?? null;
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
            array(
                'llm_model' => $rule['llm_model'] ?? '',
                'llm_temperature' => $rule['llm_temperature'] ?? '0.2',
                'llm_max_tokens' => $rule['llm_max_tokens'] ?? '1200'
            )
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
            array(
                'llm_model' => $rule['llm_model'] ?? '',
                'llm_temperature' => $rule['llm_temperature'] ?? '0.2',
                'llm_max_tokens' => $rule['llm_max_tokens'] ?? '1200'
            )
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
            'title' => $rule['label'] ?: $rule['key'],
            'rule_config' => $rule
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
        $key_codes = $this->getRuleMemoryKeyCodes($rule_id, (string)($row['memory_key'] ?? LLM_MEMORY_DEFAULT_KEY));

        return array(
            'id' => $rule_id,
            'key' => (string)($row['rule_key'] ?? ''),
            'label' => (string)($row['label'] ?? ''),
            'enabled' => !empty($row['enabled']) && $row['enabled'] !== '0',
            'memory_key' => !empty($key_codes[0]) ? $key_codes[0] : (string)($row['memory_key'] ?? LLM_MEMORY_DEFAULT_KEY),
            'memory_keys' => $key_codes,
            'source_type' => (string)($row['source_type'] ?? ''),
            'source_match' => $this->decodeJsonObject($row['source_match_json'] ?? '{}'),
            'trigger_types' => $this->decodeJsonArray($row['trigger_types_json'] ?? '["finished"]', array('finished')),
            'storage_mode_override' => (string)($row['storage_mode_override'] ?? ''),
            'execution_mode' => (string)($row['execution_mode'] ?? LLM_MEMORY_EXECUTION_LLM_SUMMARIZE),
            'field_mapping' => $this->decodeJsonObject($row['field_mapping_json'] ?? '{}'),
            'data_config' => $this->decodeJsonArray($row['data_config_json'] ?? '[]'),
            'prompt_binding' => array(
                'owner_type' => LLM_PROMPT_OWNER_MEMORY_RULE,
                'owner_id' => (int)($row['id'] ?? 0),
                'prompt_slot' => 'memory_rule'
            ),
            'llm_model' => (string)($row['llm_model'] ?? ''),
            'llm_temperature' => (string)($row['llm_temperature'] ?? '0.2'),
            'llm_max_tokens' => (string)($row['llm_max_tokens'] ?? '1200'),
            'refresh_sections' => $this->decodeJsonArray($row['refresh_sections_json'] ?? '[]'),
            'usage_tags' => $this->decodeJsonArray($row['usage_tags_json'] ?? '[]'),
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
        $existing_id = (int)($payload['id'] ?? 0);
        $label = trim((string)($payload['label'] ?? ''));
        if ($label === '') {
            $label = $for_update ? 'Memory Rule' : ('Memory Rule ' . date('YmdHis'));
        }
        $rule_key = $this->buildUniqueRuleKey(
            (string)($payload['key'] ?? $payload['rule_key'] ?? ''),
            $label,
            $existing_id
        );

        $memory_keys = $this->normalizeMemoryKeyCodes($payload['memory_keys'] ?? array($payload['memory_key'] ?? LLM_MEMORY_DEFAULT_KEY));
        if (empty($memory_keys)) {
            $memory_keys = array(LLM_MEMORY_DEFAULT_KEY);
        }

        return array(
            'rule_key' => $rule_key,
            'label' => $label,
            'enabled' => !empty($payload['enabled']) ? 1 : 0,
            'memory_key' => $memory_keys[0],
            'source_type' => trim((string)($payload['source_type'] ?? '')),
            'source_match_json' => $this->encodeJson($payload['source_match'] ?? array()),
            'trigger_types_json' => $this->encodeJson($payload['trigger_types'] ?? array('finished')),
            'storage_mode_override' => trim((string)($payload['storage_mode_override'] ?? '')),
            'execution_mode' => trim((string)($payload['execution_mode'] ?? LLM_MEMORY_EXECUTION_LLM_SUMMARIZE)) ?: LLM_MEMORY_EXECUTION_LLM_SUMMARIZE,
            'field_mapping_json' => $this->encodeJson($payload['field_mapping'] ?? array()),
            'data_config_json' => $this->encodeJson($payload['data_config'] ?? array()),
            'llm_model' => trim((string)($payload['llm_model'] ?? '')),
            'llm_temperature' => $this->normalizeNullableString($payload['llm_temperature'] ?? null),
            'llm_max_tokens' => $this->normalizeNullableInt($payload['llm_max_tokens'] ?? null),
            'refresh_sections_json' => $this->encodeJson($payload['refresh_sections'] ?? array()),
            'usage_tags_json' => $this->encodeJson($payload['usage_tags'] ?? array()),
            'id_users_updated' => $_SESSION['id_user'] ?? null,
            'id_users_created' => $for_update ? ($payload['id_users_created'] ?? null) : ($_SESSION['id_user'] ?? null),
        );
    }

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

    public function getEditorBootstrap($settings_model = null)
    {
        $llm_config = $this->getLlmConfig();
        $source_type_options = array(
            array('value' => LLM_MEMORY_SOURCE_FORM_ACTION, 'label' => 'Form action submit'),
            array('value' => LLM_MEMORY_SOURCE_LOGIN, 'label' => 'Login'),
            array('value' => LLM_MEMORY_SOURCE_PROFILE_NAME, 'label' => 'Profile name change'),
        );
        // TODO: Re-enable llmChat form submit as an editor option once the
        // attachment UX for chat-driven sources is finalized.
        $execution_mode_options = array(
            array('value' => LLM_MEMORY_EXECUTION_LLM_SUMMARIZE, 'label' => 'LLM summarize'),
            array('value' => LLM_MEMORY_EXECUTION_DIRECT_MAPPING, 'label' => 'Direct mapping'),
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
            'execution_modes' => $execution_mode_options,
            'storage_modes' => $storage_mode_options,
            'sections' => $this->getAvailableSectionsForEditor(),
        );
    }

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
            $this->db->update_by_ids('llm_memory_rules', array('memory_key' => $key_codes[0]), array('id' => $rule_id));
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

        $this->db->update_by_ids('llm_memory_rules', array('memory_key' => $key_codes[0]), array('id' => $rule_id));
    }

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

    private function decodeJsonObject($value)
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : array();
    }

    private function decodeJsonArray($value, $fallback = array())
    {
        if (is_array($value)) {
            return array_values($value);
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? array_values($decoded) : $fallback;
    }

    private function encodeJson($value)
    {
        return json_encode($value ?? array(), JSON_UNESCAPED_SLASHES);
    }

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

    private function normalizeMemoryKeyCode($memory_key)
    {
        $memory_key = strtolower(trim((string)$memory_key));
        $memory_key = preg_replace('/[^a-z0-9_\-]/', '_', $memory_key);
        $memory_key = preg_replace('/_+/', '_', $memory_key);
        return trim((string)$memory_key, '_');
    }

    private function normalizeNullableString($value)
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function normalizeNullableInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int)$value;
    }

    private function buildUniqueRuleKey($preferred_key, $label, $existing_id = 0)
    {
        $base_key = $this->normalizeMemoryKeyCode($preferred_key);
        if ($base_key === '') {
            $base_key = $this->normalizeMemoryKeyCode($label);
        }
        if ($base_key === '') {
            $base_key = 'memory_rule';
        }

        $candidate = $base_key;
        $suffix = 2;
        while (true) {
            $existing = $this->getRuleByKey($candidate);
            if (!$existing || (int)$existing['id'] === (int)$existing_id) {
                return $candidate;
            }
            $candidate = $base_key . '_' . $suffix;
            $suffix++;
        }
    }

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

    private function getRuleMemoryKeyCodes($rule_id, $fallback_key)
    {
        $fallback_code = $this->normalizeMemoryKeyCode($fallback_key) ?: LLM_MEMORY_DEFAULT_KEY;
        if ($rule_id <= 0 || !$this->hasMemoryKeyRegistry() || !$this->hasRuleKeyBindings()) {
            return array($fallback_code);
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
            return array($fallback_code);
        }

        $codes = array();
        foreach ((array)$rows as $row) {
            $code = $this->normalizeMemoryKeyCode($row['key_code'] ?? '');
            if ($code !== '' && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        if (empty($codes)) {
            $codes[] = $fallback_code;
        }

        return $codes;
    }

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

    private function humanizeMemoryKeyLabel($code)
    {
        $label = str_replace(array('_', '-'), ' ', (string)$code);
        $label = ucwords(trim($label));
        return $label !== '' ? $label : 'Global';
    }

    private function getAvailableModelsForEditor()
    {
        require_once __DIR__ . '/LlmService.php';
        $service = new LlmService($this->services);
        return $service->getAvailableModels();
    }

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
