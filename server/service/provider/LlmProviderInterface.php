<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * LLM Provider Interface
 * 
 * Defines the contract that all LLM providers must implement.
 * This abstraction allows the system to work with different LLM APIs
 * (GPUStack, BFH, OpenAI, etc.) while maintaining a consistent interface.
 * 
 * @author SelfHelp Team
 */
interface LlmProviderInterface
{
    /**
     * Get the provider's unique identifier
     * 
     * @return string Provider identifier (e.g., 'gpustack', 'bfh', 'openai')
     */
    public function getProviderId();

    /**
     * Get the provider's display name
     * 
     * @return string Human-readable provider name
     */
    public function getProviderName();

    /**
     * Check if this provider can handle the given base URL
     * 
     * @param string $baseUrl The base URL from configuration
     * @return bool True if this provider handles the URL
     */
    public function canHandle($baseUrl);

    /**
     * Normalize an API response to standard format
     * 
     * Converts provider-specific response format to a normalized structure:
     * [
     *   'content' => string,           // Assistant message content
     *   'role' => string,              // 'assistant'
     *   'finish_reason' => string,     // 'stop', 'length', etc.
     *   'usage' => [
     *     'total_tokens' => int,
     *     'completion_tokens' => int,
     *     'prompt_tokens' => int
     *   ],
     *   'reasoning' => string|null,    // Optional reasoning content
     *   'raw_response' => array        // Full original response
     * ]
     * 
     * @param array $rawResponse Raw response from API
     * @return array Normalized response
     * @throws Exception If response cannot be normalized
     */
    public function normalizeResponse($rawResponse);

    /**
     * Get the complete API endpoint URL for a specific endpoint
     * 
     * @param string $baseUrl Base URL from configuration
     * @param string $endpoint Endpoint path (e.g., '/chat/completions')
     * @return string Complete URL
     */
    public function getApiUrl($baseUrl, $endpoint);

    /**
     * Get authentication headers for API requests
     * 
     * @param string $apiKey API key from configuration
     * @return array Array of header strings
     */
    public function getAuthHeaders($apiKey);

    /**
     * Get additional request parameters specific to this provider
     * 
     * Allows providers to add custom parameters to the request payload.
     * Returns an array that will be merged with the standard payload.
     * 
     * @param array $standardParams Standard request parameters
     * @return array Additional provider-specific parameters
     */
    public function getAdditionalRequestParams($standardParams);
}
?>

