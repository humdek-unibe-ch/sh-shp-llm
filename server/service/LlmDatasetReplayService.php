<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmPromptPlaygroundService.php';
require_once __DIR__ . '/LlmPromptRuntimeValueService.php';

/**
 * LLM Dataset Replay Service
 *
 * Replays prompt templates through the LLM for each test case in a dataset.
 * Substitutes test-case variables into the prompt, executes it via the
 * playground service, and returns raw LLM output for scoring.
 *
 * Used by the evaluation runner to generate actual outputs that are then
 * compared against expected results.
 *
 * @package LLM Plugin
 * @see LlmEvaluationRunnerService For orchestration context
 */
class LlmDatasetReplayService extends BaseLlmService
{
    /** @var LlmPromptPlaygroundService Handles prompt execution */
    private $playground_service;

    /** @var LlmPromptRuntimeValueService Resolves runtime variable bindings */
    private $runtime_value_service;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->playground_service = new LlmPromptPlaygroundService($services);
        $this->runtime_value_service = new LlmPromptRuntimeValueService($services);
    }

    /**
     * Replay a dataset case through the playground to get a fresh LLM response.
     *
     * @param array $dataset_case Case row with input_payload_json, expected output, etc.
     * @param array $target       Target prompt info (target_type, draft_prompt, target_version_id).
     * @param array $options      Additional options (selected_models, runtime_overrides).
     * @return array Playground run result with rendered content and metadata.
     */
    public function replayCase($dataset_case, $target, $options = array())
    {
        $input_payload = $this->decodePayload($dataset_case['input_payload_json'] ?? '{}');
        $descriptor = is_array($input_payload['owner_descriptor'] ?? null) ? $input_payload['owner_descriptor'] : array();
        $fallback_descriptor = is_array($options['fallback_descriptor'] ?? null) ? $options['fallback_descriptor'] : array();
        if (empty($descriptor['owner_type']) && !empty($fallback_descriptor['owner_type'])) {
            $descriptor = $fallback_descriptor;
        }

        $case_overrides = is_array($input_payload['runtime_overrides'] ?? null) ? $input_payload['runtime_overrides'] : array();
        foreach (is_array($options['runtime_overrides'] ?? null) ? $options['runtime_overrides'] : array() as $key => $value) {
            $case_overrides[$key] = $value;
        }

        $runtime_values = $this->runtime_value_service->resolveRuntimeValues($descriptor, $case_overrides);
        $variables = is_array($input_payload['variables'] ?? null) ? $input_payload['variables'] : array();
        if (empty($variables) && is_array($input_payload['form_data'] ?? null)) {
            $variables = $input_payload['form_data'];
        }

        return $this->playground_service->run(
            $descriptor,
            (string)($target['draft_prompt'] ?? ''),
            $runtime_values,
            $variables,
            is_array($input_payload['message_history'] ?? null) ? $input_payload['message_history'] : array(),
            is_array($options['selected_models'] ?? null) ? $options['selected_models'] : array(),
            array(
                'run_mode' => LLM_PROMPT_RUN_MODE_DATASET_EVAL,
                'target_version_id' => !empty($target['target_ref']['prompt_version_id']) ? (int)$target['target_ref']['prompt_version_id'] : null
            )
        );
    }

    /** Safely decode a JSON string to array, returning empty array on failure. */
    private function decodePayload($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return array();
        }
        $decoded = $this->jsonDecode($value);
        return is_array($decoded) ? $decoded : array();
    }
}
?>
