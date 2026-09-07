<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/BaseProvider.php';

/**
 * OpenAI Provider
 *
 * Handles api.openai.com (and Azure OpenAI hostnames). Response shape matches
 * the standard OpenAI chat completions schema.
 *
 * OpenAI chat completions for current models require `max_completion_tokens`
 * and reject legacy `max_tokens` (HTTP 400). That remapping is done in
 * adaptChatCompletionPayload() so every OpenAI call is covered — including
 * future ids like gpt-6-astra — without brittle model-name lists.
 */
class OpenAIProvider extends BaseProvider
{
    /**
     * {@inheritdoc}
     */
    public function getProviderId()
    {
        return 'openai';
    }

    /**
     * {@inheritdoc}
     */
    public function getProviderName()
    {
        return 'OpenAI';
    }

    /**
     * {@inheritdoc}
     */
    public function canHandle($baseUrl)
    {
        $lower = strtolower((string)$baseUrl);
        if ($lower === '') {
            return false;
        }

        return strpos($lower, 'api.openai.com') !== false
            || strpos($lower, 'openai.azure.com') !== false
            || (bool)preg_match('/(^|[.\\/])openai\\.com([\\/:]|$)/', $lower);
    }

    /**
     * {@inheritdoc}
     *
     * Always prefer max_completion_tokens for OpenAI hosts.
     */
    public function adaptChatCompletionPayload(array $payload)
    {
        if (array_key_exists('max_tokens', $payload)) {
            $payload['max_completion_tokens'] = $payload['max_tokens'];
            unset($payload['max_tokens']);
        }

        return $payload;
    }

    /**
     * {@inheritdoc}
     *
     * OpenAI does not return vision modality on GET /v1/models; decide from id.
     */
    public function modelSupportsVision($modelId)
    {
        require_once __DIR__ . '/../LlmModelCapabilities.php';

        $raw = LlmModelCapabilities::getRawModelId($modelId);
        if ($raw === '' || LlmModelCapabilities::isNonChatModel($raw)) {
            return false;
        }

        if (LlmModelCapabilities::matchesOpenAiVisionHeuristic($raw)) {
            return true;
        }

        // Looks like an OpenAI id but not a known vision chat model → no vision
        $lower = strtolower($raw);
        if (preg_match('/^(gpt-|chatgpt-|o[1-4]([.-]|$))/', $lower)) {
            return false;
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function normalizeResponse($rawResponse)
    {
        $this->validateResponse($rawResponse, [
            'choices.0.message.content',
            'choices.0.message.role'
        ]);

        $message = $rawResponse['choices'][0]['message'];
        $usage = $rawResponse['usage'] ?? [];

        return [
            'content' => $message['content'],
            'role' => $message['role'],
            'finish_reason' => $rawResponse['choices'][0]['finish_reason'] ?? 'stop',
            'usage' => [
                'total_tokens' => $usage['total_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0
            ],
            'reasoning' => null,
            'raw_response' => $rawResponse
        ];
    }
}
?>
