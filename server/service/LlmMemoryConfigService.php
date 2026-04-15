<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmMemoryRuleService.php';

/**
 * Loads global memory defaults from sh_module_llm and normalized memory rules
 * from the llm_memory_rules table.
 */
class LlmMemoryConfigService extends BaseLlmService
{
    /** @var array|null */
    private $config = null;

    /** @var array|null */
    private $rules = null;

    /** @var LlmMemoryRuleService */
    private $rule_service;

    public function __construct($services, ?LlmMemoryRuleService $rule_service = null)
    {
        parent::__construct($services);
        $this->rule_service = $rule_service ?: new LlmMemoryRuleService($services);
    }

    /**
     * Check whether the global memory system is enabled.
     *
     * @return bool
     */
    public function isMemoryEnabled()
    {
        $cfg = $this->getMemoryConfig();
        return !empty($cfg['enabled']);
    }

    /**
     * Get the module-level memory defaults.
     *
     * @return array
     */
    public function getMemoryConfig()
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $llm_config = $this->getLlmConfig();

        $this->config = [
            'enabled' => !empty($llm_config['llm_memory_enabled']) && $llm_config['llm_memory_enabled'] !== '0',
            'memory_key' => LLM_MEMORY_DEFAULT_KEY,
            'storage_mode' => !empty($llm_config['llm_memory_storage_mode']) ? $llm_config['llm_memory_storage_mode'] : LLM_MEMORY_DEFAULT_STORAGE_MODE,
            'table_name' => LLM_MEMORY_DEFAULT_TABLE,
            'history_table_name' => LLM_MEMORY_DEFAULT_HISTORY_TABLE,
        ];

        return $this->config;
    }

    /**
     * Get the default memory key.
     *
     * @return string
     */
    public function getDefaultMemoryKey()
    {
        return $this->getMemoryConfig()['memory_key'];
    }

    /**
     * Get the configured storage mode.
     *
     * @return string
     */
    public function getStorageMode()
    {
        return self::normalizeStorageMode($this->getMemoryConfig()['storage_mode']);
    }

    /**
     * Normalize storage mode from either lookup code or plain value.
     *
     * @param string $raw
     * @return string
     */
    public static function normalizeStorageMode($raw)
    {
        $map = [
            'memory_storage_record' => 'record',
            'memory_storage_log' => 'log',
            'memory_storage_both' => 'both',
            'record' => 'record',
            'log' => 'log',
            'both' => 'both',
        ];
        return $map[$raw] ?? 'both';
    }

    /**
     * Build a deterministic dedupe key for a memory update.
     *
     * @param int $user_id
     * @param string $memory_key
     * @param int $rule_id
     * @param string $source_type
     * @param string $source_ref
     * @param string $trigger_type
     * @param array $payload_fields
     * @return string
     */
    public static function buildDedupeKey($user_id, $memory_key, $rule_id, $source_type, $source_ref, $trigger_type, $payload_fields)
    {
        $fingerprint = json_encode([
            'u'  => $user_id,
            'mk' => $memory_key,
            'ri' => $rule_id,
            'st' => $source_type,
            'sr' => $source_ref,
            'tt' => $trigger_type,
            'pf' => md5(json_encode($payload_fields, JSON_UNESCAPED_SLASHES)),
        ], JSON_UNESCAPED_SLASHES);

        return hash('sha256', $fingerprint);
    }

    /**
     * @return string
     */
    public function getCurrentTableName()
    {
        return $this->getMemoryConfig()['table_name'];
    }

    /**
     * @return string
     */
    public function getHistoryTableName()
    {
        return $this->getMemoryConfig()['history_table_name'];
    }

    /**
     * Load and return all normalized memory rules keyed by rule ID.
     *
     * @return array
     */
    public function getRules()
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $this->rules = [];
        foreach ($this->rule_service->listRules() as $rule) {
            $rule_id = (int)($rule['id'] ?? 0);
            if ($rule_id <= 0) {
                continue;
            }
            $this->rules[$rule_id] = $this->applyRuleDefaults($rule);
        }

        return $this->rules;
    }

    /**
     * @param int $id
     * @return array|null
     */
    public function getRuleById($id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return null;
        }

        $rules = $this->getRules();
        if (isset($rules[$id])) {
            return $rules[$id];
        }

        $rule = $this->rule_service->getRuleById($id);
        return $rule ? $this->applyRuleDefaults($rule) : null;
    }

    /**
     * @param string $source_type
     * @param array $source_match_criteria
     * @return array
     */
    public function findMatchingRules($source_type, $source_match_criteria = [])
    {
        $matches = [];

        foreach ($this->getRules() as $rule) {
            if (!$rule['enabled']) {
                continue;
            }
            if ($rule['source_type'] !== $source_type) {
                continue;
            }
            if (!$this->matchesSourceCriteria($rule, $source_match_criteria)) {
                continue;
            }
            $matches[] = $rule;
        }

        return $matches;
    }

    /**
     * @param array $rule
     * @return string
     */
    public function resolveStorageMode($rule)
    {
        if (!empty($rule['storage_mode_override'])) {
            $normalized = self::normalizeStorageMode($rule['storage_mode_override']);
            if (in_array($normalized, ['record', 'log', 'both'], true)) {
                return $normalized;
            }
        }
        return $this->getStorageMode();
    }

    /**
     * Resolve the primary memory key for a rule (first from memory_keys array).
     *
     * @param array $rule
     * @return string
     */
    public function resolveMemoryKey($rule)
    {
        if (!empty($rule['memory_keys']) && is_array($rule['memory_keys'])) {
            $first = reset($rule['memory_keys']);
            if (!empty($first)) {
                return (string)$first;
            }
        }
        return $this->getDefaultMemoryKey();
    }

    /**
     * @param array $rule
     * @return array
     */
    public function getRuleTargetMemoryKeys($rule)
    {
        $keys = array();
        if (!empty($rule['memory_keys']) && is_array($rule['memory_keys'])) {
            foreach ($rule['memory_keys'] as $memory_key) {
                $memory_key = trim((string)$memory_key);
                if ($memory_key !== '' && !in_array($memory_key, $keys, true)) {
                    $keys[] = $memory_key;
                }
            }
        }

        if (empty($keys)) {
            $keys[] = $this->resolveMemoryKey($rule);
        }

        return $keys;
    }

    /**
     * @param array $rule
     * @return array
     */
    private function applyRuleDefaults($rule)
    {
        return array_merge([
            'id' => 0,
            'label' => '',
            'enabled' => true,
            'memory_keys' => [LLM_MEMORY_DEFAULT_KEY],
            'source_type' => '',
            'source_match' => [],
            'trigger_types' => ['finished'],
            'storage_mode_override' => '',
            'execution_mode' => LLM_MEMORY_EXECUTION_LLM_SUMMARIZE,
            'field_mapping' => [],
            'data_config' => [],
            'prompt_binding' => [
                'owner_type' => LLM_PROMPT_OWNER_MEMORY_RULE,
                'owner_id' => 0,
                'prompt_slot' => 'memory_rule',
            ],
            'llm_model' => '',
            'llm_temperature' => '',
            'llm_max_tokens' => '',
            'refresh_sections' => [],
        ], $rule);
    }

    /**
     * @param array $rule
     * @param array $criteria
     * @return bool
     */
    private function matchesSourceCriteria($rule, $criteria)
    {
        $match_config = $rule['source_match'] ?? [];
        if (empty($match_config)) {
            return true;
        }

        foreach ($match_config as $field => $expected) {
            if (!isset($criteria[$field])) {
                return false;
            }
            if ((string)$criteria[$field] !== (string)$expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reset cached state.
     */
    public function resetCache()
    {
        $this->config = null;
        $this->rules = null;
    }
}
?>
