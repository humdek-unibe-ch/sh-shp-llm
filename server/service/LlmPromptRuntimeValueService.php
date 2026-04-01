<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmPromptExecutionProfileService.php';
require_once __DIR__ . '/LlmScriptService.php';
require_once __DIR__ . '/LlmMemoryRuleService.php';

class LlmPromptRuntimeValueService extends BaseLlmService
{
    /** @var LlmPromptExecutionProfileService */
    private $profile_service;

    /** @var LlmScriptService */
    private $script_service;

    /** @var LlmMemoryRuleService */
    private $memory_rule_service;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->profile_service = new LlmPromptExecutionProfileService($services);
        $this->script_service = new LlmScriptService($services);
        $this->memory_rule_service = new LlmMemoryRuleService($services);
    }

    /**
     * Resolve current runtime values for a prompt owner and merge overrides.
     *
     * @param array $descriptor
     * @param array $overrides
     * @return array
     */
    public function resolveRuntimeValues($descriptor, $overrides = array())
    {
        $profile = $this->profile_service->resolveExecutionProfile($descriptor);
        $overrides = is_array($overrides) ? $overrides : array();

        if (($descriptor['owner_type'] ?? '') === LLM_PROMPT_OWNER_SCRIPT) {
            $runtime_values = $this->script_service->fetch_script((int)($descriptor['owner_id'] ?? 0));
            $runtime_values = is_array($runtime_values) ? $runtime_values : array();
        } elseif (($descriptor['owner_type'] ?? '') === LLM_PROMPT_OWNER_MEMORY_RULE) {
            $runtime_values = $this->resolveMemoryRuleRuntimeValues($descriptor);
        } else {
            $runtime_values = $this->profile_service->getStyleFieldValues(
                (int)($descriptor['owner_id'] ?? 0),
                $descriptor['id_languages'] ?? null,
                $this->profile_service->getCompanionFieldNames($profile)
            );
        }

        foreach ($overrides as $key => $value) {
            $runtime_values[$key] = $value;
        }

        return $runtime_values;
    }

    /**
     * Resolve runtime values for a memory rule prompt owner.
     * The memory rule stores its own model/temperature/max_tokens in the rule JSON.
     *
     * @param array $descriptor
     * @return array
     */
    private function resolveMemoryRuleRuntimeValues($descriptor)
    {
        $rule_config = $descriptor['rule_config'] ?? array();
        if (empty($rule_config) && !empty($descriptor['owner_id'])) {
            $loaded = $this->memory_rule_service->getRuleById((int)$descriptor['owner_id']);
            if (is_array($loaded)) {
                $rule_config = $loaded;
            }
        }
        return array(
            'llm_model' => $rule_config['llm_model'] ?? '',
            'llm_temperature' => $rule_config['llm_temperature'] ?? '0.2',
            'llm_max_tokens' => $rule_config['llm_max_tokens'] ?? '1200'
        );
    }
}
?>
