<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmPromptExecutionProfileService.php';
require_once __DIR__ . '/prompt/LlmPromptAssetLoader.php';

class LlmPromptStandardService
{
    /** @var LlmPromptExecutionProfileService */
    private $profile_service;
    /** @var LlmPromptAssetLoader */
    private $prompt_assets;

    public function __construct($services)
    {
        $this->profile_service = new LlmPromptExecutionProfileService($services);
        $this->prompt_assets = new LlmPromptAssetLoader();
    }

    public function getDefaultExpectedLabels()
    {
        return array(
            'safety' => array(
                'danger_level' => null
            )
        );
    }

    public function normalizeExpectedLabels($expected_labels = null)
    {
        $normalized = is_array($expected_labels) ? $expected_labels : array();
        if (empty($normalized['safety']) || !is_array($normalized['safety'])) {
            $normalized['safety'] = array();
        }
        if (!array_key_exists('danger_level', $normalized['safety'])) {
            $normalized['safety']['danger_level'] = null;
        }

        return $normalized;
    }

    public function buildPromptContractPayload($descriptor = array())
    {
        $execution_profile = '';
        if (!empty($descriptor['owner_type']) || !empty($descriptor['prompt_slot'])) {
            $execution_profile = (string)$this->profile_service->resolveExecutionProfile($descriptor);
        }

        $owner_type = (string)($descriptor['owner_type'] ?? '');
        if ($owner_type === LLM_PROMPT_OWNER_SCRIPT) {
            $owner_label = 'llm_script';
        } elseif ($owner_type === LLM_PROMPT_OWNER_STYLE_FIELD) {
            $owner_label = 'style_field';
        } else {
            $owner_label = 'prompt_owner';
        }

        $section_order = array(
            'task_role',
            'style_requirements',
            'domain_safety_or_business_rules',
            'examples',
            'output_behavior'
        );

        return array(
            'owner_type' => $owner_label,
            'execution_profile' => $execution_profile !== '' ? $execution_profile : 'text_only',
            'section_order' => $section_order,
            'guidance' => $this->buildPromptScaffoldGuidance($descriptor, $section_order, $owner_label, $execution_profile)
        );
    }

    public function buildPromptScaffoldGuidance($descriptor = array(), $section_order = null, $owner_label = '', $execution_profile = '')
    {
        $contract = $section_order ?: $this->buildPromptContractPayload($descriptor)['section_order'];
        $owner_label = $owner_label !== '' ? $owner_label : (string)($descriptor['owner_type'] ?? 'prompt_owner');
        $execution_profile = $execution_profile !== '' ? $execution_profile : (string)$this->profile_service->resolveExecutionProfile($descriptor);

        $template = $this->prompt_assets->load('core.prompt_scaffold.standard');

        return strtr($template, array(
            '{{owner_type}}' => $owner_label !== '' ? $owner_label : 'prompt_owner',
            '{{execution_profile}}' => $execution_profile !== '' ? $execution_profile : 'text_only',
            '{{section_order}}' => implode(' -> ', $contract),
        ));
    }
}
?>
