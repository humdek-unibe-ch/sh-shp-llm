<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmResponseService.php';

/**
 * LLM Prompt Response Render Service
 *
 * Normalizes raw LLM responses into a structured format suitable for
 * the Prompt Lab playground UI. Detects JSON content, extracts reasoning
 * tokens, and maps model metadata into a consistent response envelope.
 *
 * @package LLM Plugin
 */
class LlmPromptResponseRenderService
{
    /**
     * Normalize a runtime response for the playground UI.
     *
     * @param string $raw_content
     * @param string|null $model_name
     * @return array
     */
    public function render($raw_content, $model_name = null)
    {
        $response_service = new LlmResponseService(new LlmPromptRenderModelStub($model_name));
        $parsed = $response_service->parseResponse($raw_content);

        if (!$parsed['valid']) {
            $parsed = $response_service->createFallbackResponse($raw_content, $parsed);
        }

        $structured = $parsed['data'] ?? null;
        $display_content = is_array($structured)
            ? $response_service->toMarkdown($structured)
            : (string)$raw_content;

        return array(
            'raw_content' => $raw_content,
            'display_content' => $display_content,
            'parsed_response' => $structured,
            'safety' => is_array($structured) ? $response_service->assessSafety($structured) : null,
            'parse_errors' => $parsed['errors'] ?? array(),
            'is_fallback' => !empty($parsed['is_fallback'])
        );
    }
}

/**
 * Minimal stub implementing the model-name interface expected by
 * LlmResponseService. Used by the render service when no real
 * component model is available (e.g., during playground execution).
 *
 * @package LLM Plugin
 */
class LlmPromptRenderModelStub
{
    private $model_name;

    /** @param string|null $model_name LLM model name for capability detection. */
    public function __construct($model_name = null)
    {
        $this->model_name = $model_name ?: 'unknown';
    }

    /** @return string The configured LLM model name. */
    public function getConfiguredModel()
    {
        return $this->model_name;
    }
}
?>
