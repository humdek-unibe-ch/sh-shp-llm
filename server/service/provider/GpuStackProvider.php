<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/BaseProvider.php';

/**
 * GPUStack Provider
 * 
 * Handles communication with GPUStack API (https://gpustack.unibe.ch/v1)
 * This is the original provider implementation.
 * 
 * Response format follows OpenAI-compatible structure:
 * {
 *   "choices": [{
 *     "message": {"content": "...", "role": "assistant"},
 *     "finish_reason": "stop"
 *   }],
 *   "usage": {"total_tokens": 123}
 * }
 * 
 * @author SelfHelp Team
 */
class GpuStackProvider extends BaseProvider
{
    /**
     * {@inheritdoc}
     */
    public function getProviderId()
    {
        return 'gpustack';
    }

    /**
     * {@inheritdoc}
     */
    public function getProviderName()
    {
        return 'GPUStack (UniBE)';
    }

    /**
     * {@inheritdoc}
     */
    public function canHandle($baseUrl)
    {
        return strpos($baseUrl, 'gpustack.unibe.ch') !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function normalizeResponse($rawResponse)
    {
        // Validate response structure
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
            'reasoning' => null, // GPUStack doesn't provide reasoning content
            'raw_response' => $rawResponse
        ];
    }

}
