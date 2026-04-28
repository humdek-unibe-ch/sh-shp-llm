<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmPromptExecutionProfileService.php';
require_once __DIR__ . '/prompt/LlmPromptAssetLoader.php';

/**
 * LLM Prompt Standard Service
 *
 * Provides standard scaffold templates, default expected-label
 * definitions, and profile-aware prompt assembly for the Prompt
 * Builder and evaluation systems. Acts as the source of truth for
 * the canonical prompt structure and safety/quality labels.
 *
 * @package LLM Plugin
 */
class LlmPromptStandardService
{
    /** @var LlmPromptExecutionProfileService Profile resolution */
    private $profile_service;

    /** @var LlmPromptAssetLoader Loads scaffold templates from disk */
    private $prompt_assets;

    public function __construct($services)
    {
        $this->profile_service = new LlmPromptExecutionProfileService($services);
        $this->prompt_assets = new LlmPromptAssetLoader();
    }

    /** @return array Default expected label schema with safety and quality sections. */
    public function getDefaultExpectedLabels()
    {
        return array(
            'safety' => array(
                'danger_level' => null
            )
        );
    }

    /**
     * Merge provided expected labels with defaults, ensuring safety section exists.
     *
     * @param array|null $expected_labels Existing labels to normalize.
     * @return array Normalized labels with defaults applied.
     */
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

    /**
     * Build the prompt contract payload describing required sections and style rules for the descriptor.
     *
     * @param array $descriptor Owner descriptor with owner_type, prompt_slot.
     * @return array Contract payload with section_order and rules.
     */
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

    /**
     * Generate a human-readable scaffold guidance text for prompt writing based on contract sections.
     *
     * @param array       $descriptor        Owner descriptor.
     * @param array|null  $section_order     Explicit section order, or null to derive from contract.
     * @param string      $owner_label       Label for the prompt owner (e.g. 'llmChat').
     * @param string      $execution_profile Execution profile code.
     * @return string Formatted guidance text.
     */
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
