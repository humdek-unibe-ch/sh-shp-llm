<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/BaseProvider.php';

/**
 * BFH Provider
 * 
 * Handles communication with BFH Inference API (https://inference.mlmp.ti.bfh.ch/api)
 * 
 * Response format includes additional metadata and reasoning content:
 * {
 *   "id": "chatcmpl-761",
 *   "created": 1766394796,
 *   "model": "gpt-oss:120b",
 *   "object": "chat.completion",
 *   "system_fingerprint": "fp_ollama",
 *   "choices": [{
 *     "finish_reason": "stop",
 *     "index": 0,
 *     "message": {
 *       "content": "...",
 *       "role": "assistant",
 *       "reasoning_content": "...",
 *       "provider_specific_fields": {...}
 *     }
 *   }],
 *   "usage": {
 *     "completion_tokens": 53,
 *     "prompt_tokens": 215,
 *     "total_tokens": 268
 *   }
 * }
 * 
 * @author SelfHelp Team
 */
class BfhProvider extends BaseProvider
{
    /**
     * {@inheritdoc}
     */
    public function getProviderId()
    {
        return 'bfh';
    }

    /**
     * {@inheritdoc}
     */
    public function getProviderName()
    {
        return 'BFH Inference API';
    }

    /**
     * {@inheritdoc}
     */
    public function canHandle($baseUrl)
    {
        return strpos($baseUrl, 'inference.mlmp.ti.bfh.ch') !== false;
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

        // Extract reasoning content if available
        $reasoning = null;
        if (isset($message['reasoning_content']) && !empty($message['reasoning_content'])) {
            $reasoning = $message['reasoning_content'];
        } elseif (isset($message['provider_specific_fields']['reasoning'])) {
            $reasoning = $message['provider_specific_fields']['reasoning'];
        }

        return [
            'content' => $message['content'],
            'role' => $message['role'],
            'finish_reason' => $rawResponse['choices'][0]['finish_reason'] ?? 'stop',
            'usage' => [
                'total_tokens' => $usage['total_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0
            ],
            'reasoning' => $reasoning,
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

        // BFH API uses 'delta' field similar to OpenAI
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

