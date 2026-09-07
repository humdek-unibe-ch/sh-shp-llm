<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmProviderInterface.php';

/**
 * Base Provider Abstract Class
 * 
 * Provides common functionality for all LLM providers.
 * Concrete providers should extend this class and implement
 * the abstract methods specific to their API format.
 * 
 * @author SelfHelp Team
 */
abstract class BaseProvider implements LlmProviderInterface
{
    /**
     * Default implementation of getApiUrl
     * Most providers use baseUrl + endpoint format
     * 
     * @param string $baseUrl Base URL from configuration
     * @param string $endpoint Endpoint path
     * @return string Complete URL
     */
    public function getApiUrl($baseUrl, $endpoint)
    {
        return rtrim($baseUrl, '/') . $endpoint;
    }

    /**
     * Default implementation of getAuthHeaders
     * Most providers use Bearer token authentication
     * 
     * @param string $apiKey API key from configuration
     * @return array Array of header strings
     */
    public function getAuthHeaders($apiKey)
    {
        return [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ];
    }

    /**
     * Default implementation - no additional parameters
     * 
     * @param array $standardParams Standard request parameters
     * @return array Empty array
     */
    public function getAdditionalRequestParams($standardParams)
    {
        return [];
    }

    /**
     * Default: leave the payload unchanged.
     *
     * GPUStack / BFH keep `max_tokens` (OpenAI-compatible local stacks still
     * expect it for most hosted models). OpenAIProvider overrides this.
     *
     * @param array $payload
     * @return array
     */
    public function adaptChatCompletionPayload(array $payload)
    {
        // Drop internal effort key so OpenAI-compatible hosts that do not
        // support it (typical GPUStack models) are not sent an unknown field.
        unset($payload['reasoning_effort']);
        return $payload;
    }

    /**
     * Read and remove the internal reasoning_effort key from a payload.
     *
     * @param array $payload
     * @return string|null Normalized effort or null to omit
     */
    protected function consumeReasoningEffort(array &$payload)
    {
        require_once __DIR__ . '/../LlmModelCapabilities.php';

        $raw = null;
        if (array_key_exists('reasoning_effort', $payload)) {
            $raw = $payload['reasoning_effort'];
            unset($payload['reasoning_effort']);
        }

        return LlmModelCapabilities::normalizeReasoningEffort($raw);
    }

    /**
     * Map internal effort into OpenAI Chat Completions shape.
     *
     * Chat Completions uses top-level `reasoning_effort` (string).
     * The nested `reasoning.effort` object is for the Responses API only —
     * sending it to /chat/completions returns HTTP 400 Unknown parameter.
     *
     * @param array $payload
     * @param string $effort
     * @return array
     */
    protected function applyOpenAiReasoningEffort(array $payload, $effort)
    {
        unset($payload['reasoning']);
        $payload['reasoning_effort'] = $effort;
        return $payload;
    }

    /**
     * Map internal effort into Anthropic Messages API shape (for AnthropicProvider).
     *
     * Modern Claude: output_config.effort + thinking.type=adaptive when unset.
     *
     * @param array $payload
     * @param string $effort
     * @return array
     */
    protected function applyAnthropicReasoningEffort(array $payload, $effort)
    {
        $outputConfig = isset($payload['output_config']) && is_array($payload['output_config'])
            ? $payload['output_config']
            : [];
        $outputConfig['effort'] = $effort;
        $payload['output_config'] = $outputConfig;

        if (!isset($payload['thinking']) || !is_array($payload['thinking'])) {
            $payload['thinking'] = ['type' => 'adaptive'];
        }

        return $payload;
    }

    /**
     * Default: unknown — LlmModelCapabilities falls back to allowlist/patterns/heuristics.
     *
     * @param string $modelId
     * @return bool|null
     */
    public function modelSupportsVision($modelId)
    {
        return null;
    }

    /**
     * Extract content from normalized response structure
     * Helper method for providers
     * 
     * @param array $response Response array
     * @param string $path Dot-notation path (e.g., 'choices.0.message.content')
     * @param mixed $default Default value if path not found
     * @return mixed Value at path or default
     */
    protected function getFromPath($response, $path, $default = null)
    {
        $keys = explode('.', $path);
        $value = $response;

        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } else {
                return $default;
            }
        }

        return $value;
    }

    /**
     * Validate that response has required structure
     * 
     * @param array $response Response to validate
     * @param array $requiredPaths Array of required dot-notation paths
     * @throws Exception If required path is missing
     */
    protected function validateResponse($response, $requiredPaths)
    {
        foreach ($requiredPaths as $path) {
            if ($this->getFromPath($response, $path) === null) {
                throw new Exception(
                    $this->getProviderName() . " response missing required field: $path"
                );
            }
        }
    }
}
?>

