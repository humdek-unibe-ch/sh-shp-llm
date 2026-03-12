<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmResponseService.php';

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

class LlmPromptRenderModelStub
{
    private $model_name;

    public function __construct($model_name = null)
    {
        $this->model_name = $model_name ?: 'unknown';
    }

    public function getConfiguredModel()
    {
        return $this->model_name;
    }
}
?>
