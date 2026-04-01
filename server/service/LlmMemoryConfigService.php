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
            'memory_key' => !empty($llm_config['llm_memory_key']) ? $llm_config['llm_memory_key'] : LLM_MEMORY_DEFAULT_KEY,
            'storage_mode' => !empty($llm_config['llm_memory_storage_mode']) ? $llm_config['llm_memory_storage_mode'] : LLM_MEMORY_DEFAULT_STORAGE_MODE,
            'table_name' => !empty($llm_config['llm_memory_table_name']) ? $llm_config['llm_memory_table_name'] : LLM_MEMORY_DEFAULT_TABLE,
            'history_table_name' => !empty($llm_config['llm_memory_history_table_name']) ? $llm_config['llm_memory_history_table_name'] : LLM_MEMORY_DEFAULT_HISTORY_TABLE,
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
     * Load and return all normalized memory rules keyed by rule key.
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
            if (empty($rule['key'])) {
                continue;
            }
            $this->rules[$rule['key']] = $this->applyRuleDefaults($rule);
        }

        return $this->rules;
    }

    /**
     * @param string $key
     * @return array|null
     */
    public function getRuleByKey($key)
    {
        $key = trim((string)$key);
        if ($key === '') {
            return null;
        }

        $rules = $this->getRules();
        if (isset($rules[$key])) {
            return $rules[$key];
        }

        $rule = $this->rule_service->getRuleByKey($key);
        return $rule ? $this->applyRuleDefaults($rule) : null;
    }

    /**
     * @param int $id
     * @return array|null
     */
    public function getRuleById($id)
    {
        $rule = $this->rule_service->getRuleById((int)$id);
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
     * @param array $rule
     * @return string
     */
    public function resolveMemoryKey($rule)
    {
        if (!empty($rule['memory_key'])) {
            return $rule['memory_key'];
        }
        return $this->getDefaultMemoryKey();
    }

    /**
     * @param array $rule
     * @return array
     */
    private function applyRuleDefaults($rule)
    {
        return array_merge([
            'id' => 0,
            'key' => '',
            'label' => '',
            'enabled' => true,
            'memory_key' => LLM_MEMORY_DEFAULT_KEY,
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
            'prompt_version_override' => 0,
            'llm_model' => '',
            'llm_temperature' => 0.2,
            'llm_max_tokens' => 1200,
            'refresh_sections' => [],
            'usage_tags' => [],
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
