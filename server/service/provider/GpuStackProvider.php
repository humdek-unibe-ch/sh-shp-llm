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

    /**
     * {@inheritdoc}
     */
    public function normalizeStreamingChunk($chunk)
    {
        $line = trim($chunk);
        
        if (empty($line)) {
            return null;
        }

        // Handle SSE format: "data: {...}"
        if (strpos($line, 'data: ') === 0) {
            $jsonData = substr($line, 6);
        } else {
            $jsonData = $line;
        }

        // Check for stream end marker
        if ($jsonData === '[DONE]') {
            return '[DONE]';
        }

        // Parse JSON chunk
        $parsed = json_decode($jsonData, true);
        if (!$parsed) {
            return null;
        }

        // Check for API error
        if (isset($parsed['error'])) {
            return '[DONE]'; // End stream on error
        }

        // Extract content chunk
        if (isset($parsed['choices'][0]['delta']['content'])) {
            $content = $parsed['choices'][0]['delta']['content'];
            if ($content !== '') {
                return $content;
            }
        }

        // Check for usage data
        if (isset($parsed['usage']['total_tokens'])) {
            return '[USAGE:' . $parsed['usage']['total_tokens'] . ']';
        }

        // Check for finish reason
        if (isset($parsed['choices'][0]['finish_reason']) && $parsed['choices'][0]['finish_reason']) {
            return '[DONE]';
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsStreaming()
    {
        return true;
    }
}
?>

