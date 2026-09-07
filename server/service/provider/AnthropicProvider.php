<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/BaseProvider.php';

/**
 * Anthropic (Claude) Provider
 *
 * Handles api.anthropic.com. Chat uses Messages API (`/messages`), not
 * OpenAI `/chat/completions`. Model listing uses GET `/models` with
 * `x-api-key` + `anthropic-version` (Bearer alone is rejected).
 *
 * Expected admin base URL: https://api.anthropic.com/v1
 */
class AnthropicProvider extends BaseProvider
{
    const API_VERSION = '2023-06-01';
    const MESSAGES_ENDPOINT = '/messages';

    /**
     * {@inheritdoc}
     */
    public function getProviderId()
    {
        return 'anthropic';
    }

    /**
     * {@inheritdoc}
     */
    public function getProviderName()
    {
        return 'Anthropic';
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

        return strpos($lower, 'api.anthropic.com') !== false
            || (bool)preg_match('/(^|[.\\/])anthropic\\.com([\\/:]|$)/', $lower);
    }

    /**
     * {@inheritdoc}
     *
     * Map OpenAI-style chat path to Anthropic Messages; paginate models list.
     */
    public function getApiUrl($baseUrl, $endpoint)
    {
        $base = rtrim((string)$baseUrl, '/');
        $endpoint = (string)$endpoint;

        if ($endpoint === LLM_API_CHAT_COMPLETIONS || $endpoint === '/chat/completions') {
            return $base . self::MESSAGES_ENDPOINT;
        }

        if ($endpoint === LLM_API_MODELS || $endpoint === '/models') {
            // Default page size is 20; admin dropdowns need the full catalogue.
            return $base . '/models?limit=1000';
        }

        return $base . $endpoint;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthHeaders($apiKey)
    {
        return [
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . self::API_VERSION,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    /**
     * {@inheritdoc}
     *
     * Convert OpenAI chat-completions payload → Anthropic Messages body.
     */
    public function adaptChatCompletionPayload(array $payload)
    {
        $effort = $this->consumeReasoningEffort($payload);

        $messages = isset($payload['messages']) && is_array($payload['messages'])
            ? $payload['messages']
            : [];

        $systemParts = [];
        $convertedMessages = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = isset($message['role']) ? (string)$message['role'] : '';
            $content = $message['content'] ?? '';

            if ($role === 'system') {
                $systemText = $this->flattenTextContent($content);
                if ($systemText !== '') {
                    $systemParts[] = $systemText;
                }
                continue;
            }

            if ($role !== 'user' && $role !== 'assistant') {
                // Anthropic only accepts user/assistant in messages[]; fold others into user.
                $role = 'user';
            }

            $convertedMessages[] = [
                'role' => $role,
                'content' => $this->convertContentForAnthropic($content),
            ];
        }

        $convertedMessages = $this->ensureAlternatingRoles($convertedMessages);

        $adapted = [
            'model' => $payload['model'] ?? '',
            'messages' => $convertedMessages,
            'max_tokens' => isset($payload['max_tokens'])
                ? (int)$payload['max_tokens']
                : (isset($payload['max_completion_tokens']) ? (int)$payload['max_completion_tokens'] : (int)LLM_DEFAULT_MAX_TOKENS),
        ];

        if (!empty($systemParts)) {
            $adapted['system'] = implode("\n\n", $systemParts);
        }

        if (array_key_exists('temperature', $payload) && $payload['temperature'] !== null) {
            $adapted['temperature'] = (float)$payload['temperature'];
        }

        // Anthropic rejects unknown OpenAI fields (stream is ok as bool; omit noise).
        if (!empty($payload['stream'])) {
            $adapted['stream'] = true;
        }

        if ($effort === 'none') {
            $adapted['thinking'] = ['type' => 'disabled'];
        } elseif ($effort === 'minimal') {
            // Anthropic effort enum has no "minimal"; nearest is low + adaptive.
            $adapted = $this->applyAnthropicReasoningEffort($adapted, 'low');
        } elseif ($effort !== null) {
            $adapted = $this->applyAnthropicReasoningEffort($adapted, $effort);
        }

        return $adapted;
    }

    /**
     * {@inheritdoc}
     */
    public function normalizeResponse($rawResponse)
    {
        if (!is_array($rawResponse)) {
            throw new Exception('Anthropic response is not an array');
        }

        $contentBlocks = isset($rawResponse['content']) && is_array($rawResponse['content'])
            ? $rawResponse['content']
            : null;
        if ($contentBlocks === null) {
            throw new Exception('Anthropic response missing required field: content');
        }

        $textParts = [];
        $reasoningParts = [];
        foreach ($contentBlocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? '';
            if ($type === 'text' && isset($block['text'])) {
                $textParts[] = (string)$block['text'];
            } elseif ($type === 'thinking' && isset($block['thinking'])) {
                $reasoningParts[] = (string)$block['thinking'];
            }
        }

        $usage = isset($rawResponse['usage']) && is_array($rawResponse['usage'])
            ? $rawResponse['usage']
            : [];
        $inputTokens = (int)($usage['input_tokens'] ?? 0);
        $outputTokens = (int)($usage['output_tokens'] ?? 0);

        $stopReason = $rawResponse['stop_reason'] ?? 'end_turn';
        $finishReason = $stopReason === 'end_turn' ? 'stop'
            : ($stopReason === 'max_tokens' ? 'length' : (string)$stopReason);

        return [
            'content' => implode('', $textParts),
            'role' => $rawResponse['role'] ?? 'assistant',
            'finish_reason' => $finishReason,
            'usage' => [
                'total_tokens' => $inputTokens + $outputTokens,
                'completion_tokens' => $outputTokens,
                'prompt_tokens' => $inputTokens,
            ],
            'reasoning' => !empty($reasoningParts) ? implode("\n\n", $reasoningParts) : null,
            'raw_response' => $rawResponse,
        ];
    }

    /**
     * {@inheritdoc}
     *
     * Anthropic Messages API uses a top-level `system` field — keep role=system
     * so adaptChatCompletionPayload() can lift it (covers all Anthropic models,
     * including fable/mythos/sonnet/opus/haiku, without name heuristics).
     */
    public function usesTopLevelSystemPrompt()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function modelSupportsVision($modelId)
    {
        require_once __DIR__ . '/../LlmModelCapabilities.php';

        $raw = LlmModelCapabilities::getRawModelId($modelId);
        if ($raw === '' || LlmModelCapabilities::isNonChatModel($raw)) {
            return false;
        }

        // Anthropic chat models accept image content blocks; do not key off "claude".
        return true;
    }

    /**
     * Convert OpenAI-style content (string or parts) to Anthropic content.
     *
     * @param mixed $content
     * @return string|array
     */
    private function convertContentForAnthropic($content)
    {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return (string)$content;
        }

        // Associative single part? treat as list of parts only when list-like.
        $parts = [];
        $isList = array_keys($content) === range(0, count($content) - 1);
        if (!$isList) {
            // Unexpected shape — stringify.
            return $this->flattenTextContent($content);
        }

        foreach ($content as $part) {
            if (!is_array($part)) {
                if (is_string($part) && $part !== '') {
                    $parts[] = ['type' => 'text', 'text' => $part];
                }
                continue;
            }

            $type = $part['type'] ?? '';
            if ($type === 'text') {
                $text = isset($part['text']) ? (string)$part['text'] : '';
                if ($text !== '') {
                    $parts[] = ['type' => 'text', 'text' => $text];
                }
                continue;
            }

            if ($type === 'image_url') {
                $imagePart = $this->convertImageUrlPart($part);
                if ($imagePart !== null) {
                    $parts[] = $imagePart;
                }
                continue;
            }

            if ($type === 'image') {
                // Already Anthropic-shaped
                $parts[] = $part;
            }
        }

        if (count($parts) === 1 && ($parts[0]['type'] ?? '') === 'text') {
            return $parts[0]['text'];
        }

        return !empty($parts) ? $parts : '';
    }

    /**
     * Map OpenAI image_url part → Anthropic image block.
     *
     * @param array $part
     * @return array|null
     */
    private function convertImageUrlPart(array $part)
    {
        $url = '';
        if (isset($part['image_url']) && is_array($part['image_url'])) {
            $url = (string)($part['image_url']['url'] ?? '');
        } elseif (isset($part['image_url']) && is_string($part['image_url'])) {
            $url = $part['image_url'];
        }

        if ($url === '') {
            return null;
        }

        // data:image/jpeg;base64,....
        if (preg_match('#^data:(image/(?:jpeg|png|gif|webp));base64,(.+)$#s', $url, $m)) {
            return [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $m[1],
                    'data' => $m[2],
                ],
            ];
        }

        // Remote URL
        if (preg_match('#^https?://#i', $url)) {
            return [
                'type' => 'image',
                'source' => [
                    'type' => 'url',
                    'url' => $url,
                ],
            ];
        }

        return null;
    }

    /**
     * Flatten content to plain text (for system prompts).
     *
     * @param mixed $content
     * @return string
     */
    private function flattenTextContent($content)
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (!is_array($content)) {
            return trim((string)$content);
        }

        $parts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $part;
            } elseif (is_array($part) && isset($part['text'])) {
                $parts[] = (string)$part['text'];
            }
        }
        return trim(implode("\n", $parts));
    }

    /**
     * Anthropic requires alternating user/assistant turns; merge consecutive same roles.
     *
     * @param array $messages
     * @return array
     */
    private function ensureAlternatingRoles(array $messages)
    {
        $merged = [];
        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';

            if (empty($merged)) {
                // First turn must be user
                if ($role !== 'user') {
                    $merged[] = ['role' => 'user', 'content' => $content];
                } else {
                    $merged[] = ['role' => $role, 'content' => $content];
                }
                continue;
            }

            $lastIndex = count($merged) - 1;
            $lastRole = $merged[$lastIndex]['role'];
            if ($lastRole === $role) {
                $merged[$lastIndex]['content'] = $this->mergeContents(
                    $merged[$lastIndex]['content'],
                    $content
                );
            } else {
                $merged[] = ['role' => $role, 'content' => $content];
            }
        }

        // Anthropic rejects empty messages arrays
        if (empty($merged)) {
            $merged[] = ['role' => 'user', 'content' => ''];
        }

        return $merged;
    }

    /**
     * @param mixed $a
     * @param mixed $b
     * @return string|array
     */
    private function mergeContents($a, $b)
    {
        $aParts = $this->contentToParts($a);
        $bParts = $this->contentToParts($b);
        $parts = array_merge($aParts, $bParts);

        if (count($parts) === 1 && ($parts[0]['type'] ?? '') === 'text') {
            return $parts[0]['text'];
        }
        return $parts;
    }

    /**
     * @param mixed $content
     * @return array
     */
    private function contentToParts($content)
    {
        if (is_string($content)) {
            return $content === '' ? [] : [['type' => 'text', 'text' => $content]];
        }
        if (!is_array($content)) {
            $str = (string)$content;
            return $str === '' ? [] : [['type' => 'text', 'text' => $str]];
        }
        if (isset($content['type'])) {
            return [$content];
        }
        return $content;
    }
}
?>
