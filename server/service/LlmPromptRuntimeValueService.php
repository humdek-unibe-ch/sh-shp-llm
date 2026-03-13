<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmPromptExecutionProfileService.php';
require_once __DIR__ . '/LlmScriptService.php';

class LlmPromptRuntimeValueService extends BaseLlmService
{
    /** @var LlmPromptExecutionProfileService */
    private $profile_service;

    /** @var LlmScriptService */
    private $script_service;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->profile_service = new LlmPromptExecutionProfileService($services);
        $this->script_service = new LlmScriptService($services);
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
}
?>
