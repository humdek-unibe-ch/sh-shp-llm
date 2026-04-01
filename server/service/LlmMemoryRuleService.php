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

        $base_key = (string)$existing['key'];
        $copy_key = $base_key . '_copy';
        $suffix = 2;
        while ($this->getRuleByKey($copy_key)) {
            $copy_key = $base_key . '_copy_' . $suffix;
            $suffix++;
        }

        $copy = $existing;
        unset($copy['id'], $copy['prompt_binding'], $copy['sources_count']);
        $copy['key'] = $copy_key;
        $copy['label'] = trim((string)($existing['label'] ?: $existing['key'])) . ' Copy';

        $template = $this->getActivePromptTemplate($existing);
        $meta_json = $this->getActivePromptMetaJson($existing);

        return $this->createRule($copy, $template, $meta_json, 'Duplicated from rule ' . $existing['key']);
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
        return array(
            'id' => (int)($row['id'] ?? 0),
            'key' => (string)($row['rule_key'] ?? ''),
            'label' => (string)($row['label'] ?? ''),
            'enabled' => !empty($row['enabled']) && $row['enabled'] !== '0',
            'memory_key' => (string)($row['memory_key'] ?? LLM_MEMORY_DEFAULT_KEY),
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
        $rule_key = trim((string)($payload['key'] ?? $payload['rule_key'] ?? ''));
        if ($rule_key === '') {
            throw new Exception('Rule key is required');
        }

        $existing = $this->getRuleByKey($rule_key);
        $existing_id = (int)($payload['id'] ?? 0);
        if ($existing && (!$for_update || (int)$existing['id'] !== $existing_id)) {
            throw new Exception('A memory rule with this key already exists');
        }

        return array(
            'rule_key' => $rule_key,
            'label' => trim((string)($payload['label'] ?? '')),
            'enabled' => !empty($payload['enabled']) ? 1 : 0,
            'memory_key' => trim((string)($payload['memory_key'] ?? LLM_MEMORY_DEFAULT_KEY)) ?: LLM_MEMORY_DEFAULT_KEY,
            'source_type' => trim((string)($payload['source_type'] ?? '')),
            'source_match_json' => $this->encodeJson($payload['source_match'] ?? array()),
            'trigger_types_json' => $this->encodeJson($payload['trigger_types'] ?? array('finished')),
            'storage_mode_override' => trim((string)($payload['storage_mode_override'] ?? '')),
            'execution_mode' => trim((string)($payload['execution_mode'] ?? LLM_MEMORY_EXECUTION_LLM_SUMMARIZE)) ?: LLM_MEMORY_EXECUTION_LLM_SUMMARIZE,
            'field_mapping_json' => $this->encodeJson($payload['field_mapping'] ?? array()),
            'data_config_json' => $this->encodeJson($payload['data_config'] ?? array()),
            'llm_model' => trim((string)($payload['llm_model'] ?? '')),
            'llm_temperature' => (string)($payload['llm_temperature'] ?? '0.2'),
            'llm_max_tokens' => (int)($payload['llm_max_tokens'] ?? 1200),
            'refresh_sections_json' => $this->encodeJson($payload['refresh_sections'] ?? array()),
            'usage_tags_json' => $this->encodeJson($payload['usage_tags'] ?? array()),
            'id_users_updated' => $_SESSION['id_user'] ?? null,
            'id_users_created' => $for_update ? ($payload['id_users_created'] ?? null) : ($_SESSION['id_user'] ?? null),
        );
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
}
