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

