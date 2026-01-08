<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmException.php';

/**
 * LLM API Exception
 * 
 * Thrown when LLM API calls fail.
 * Captures the raw API response for debugging.
 * 
 * Usage:
 * ```php
 * // No response from API
 * throw LlmApiException::noResponse();
 * 
 * // Invalid response format
 * throw LlmApiException::invalidResponse('Missing choices array', $rawResponse);
 * 
 * // Provider normalization failed
 * throw LlmApiException::normalizationFailed('bfh', 'Missing content field', $rawResponse);
 * 
 * // Timeout
 * throw LlmApiException::timeout(30);
 * ```
 * 
 * @package LLM Plugin
 * @version 1.0.0
 */
class LlmApiException extends LlmException
{
    /** @var array|string|null Raw API response for debugging */
    protected $rawResponse;

    /** @var string|null Provider identifier */
    protected $provider;

    /** @var string|null API endpoint that failed */
    protected $endpoint;

    /**
     * Constructor
     * 
     * @param string $message Error message
     * @param array|string|null $rawResponse Raw API response
     * @param array $context Additional context data
     */
    public function __construct($message = 'LLM API error', $rawResponse = null, array $context = [])
    {
        parent::__construct($message, 502, $context);
        $this->rawResponse = $rawResponse;
        $this->provider = $context['provider'] ?? null;
        $this->endpoint = $context['endpoint'] ?? null;
    }

    /**
     * Get raw API response
     * 
     * @return array|string|null Raw response data
     */
    public function getRawResponse()
    {
        return $this->rawResponse;
    }

    /**
     * Get provider identifier
     * 
     * @return string|null Provider name
     */
    public function getProvider()
    {
        return $this->provider;
    }

    /**
     * Get the endpoint that failed
     * 
     * @return string|null API endpoint
     */
    public function getEndpoint()
    {
        return $this->endpoint;
    }

    /**
     * Check if we have a raw response
     * 
     * @return bool True if raw response exists
     */
    public function hasRawResponse()
    {
        return $this->rawResponse !== null;
    }

    /**
     * Get raw response as string (for logging)
     * 
     * @param int $maxLength Maximum string length
     * @return string Response string (truncated if necessary)
     */
    public function getRawResponseString($maxLength = 1000)
    {
        if ($this->rawResponse === null) {
            return 'null';
        }

        $str = is_string($this->rawResponse) 
            ? $this->rawResponse 
            : json_encode($this->rawResponse, JSON_UNESCAPED_SLASHES);

        if (strlen($str) > $maxLength) {
            return substr($str, 0, $maxLength) . '... [truncated]';
        }

        return $str;
    }

    /**
     * {@inheritdoc}
     */
    public function toArray()
    {
        $data = parent::toArray();
        $data['provider'] = $this->provider;
        $data['endpoint'] = $this->endpoint;
        // Don't include raw response in array to avoid exposing sensitive data
        $data['has_raw_response'] = $this->hasRawResponse();
        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function log()
    {
        parent::log();
        if ($this->hasRawResponse()) {
            error_log("[LLM API Response] " . $this->getRawResponseString(500));
        }
    }

    /* =========================================================================
     * STATIC FACTORY METHODS
     * ========================================================================= */

    /**
     * Create exception for no response received
     * 
     * @return self
     */
    public static function noResponse()
    {
        return new self('LLM API request failed - no response received', null, [
            'error_type' => 'no_response'
        ]);
    }

    /**
     * Create exception for invalid response format
     * 
     * @param string $details Error details
     * @param array|string|null $rawResponse Raw response
     * @return self
     */
    public static function invalidResponse($details, $rawResponse = null)
    {
        return new self(
            "LLM API returned invalid response: {$details}",
            $rawResponse,
            ['error_type' => 'invalid_response']
        );
    }

    /**
     * Create exception for normalization failure
     * 
     * @param string $provider Provider name
     * @param string $error Error message
     * @param array|string|null $rawResponse Raw response
     * @return self
     */
    public static function normalizationFailed($provider, $error, $rawResponse = null)
    {
        return new self(
            "Failed to normalize {$provider} response: {$error}",
            $rawResponse,
            [
                'error_type' => 'normalization_failed',
                'provider' => $provider
            ]
        );
    }

    /**
     * Create exception for request timeout
     * 
     * @param int $timeout Timeout value in seconds
     * @param string|null $endpoint API endpoint
     * @return self
     */
    public static function timeout($timeout, $endpoint = null)
    {
        return new self(
            "LLM API request timed out after {$timeout} seconds",
            null,
            [
                'error_type' => 'timeout',
                'timeout' => $timeout,
                'endpoint' => $endpoint
            ]
        );
    }

    /**
     * Create exception for connection failure
     * 
     * @param string $url API URL
     * @param string|null $curlError cURL error message
     * @return self
     */
    public static function connectionFailed($url, $curlError = null)
    {
        $message = "Failed to connect to LLM API: {$url}";
        if ($curlError) {
            $message .= " ({$curlError})";
        }

        return new self($message, null, [
            'error_type' => 'connection_failed',
            'url' => $url,
            'curl_error' => $curlError
        ]);
    }

    /**
     * Create exception for HTTP error status
     * 
     * @param int $statusCode HTTP status code
     * @param array|string|null $rawResponse Raw response
     * @return self
     */
    public static function httpError($statusCode, $rawResponse = null)
    {
        $statusMessages = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable'
        ];

        $statusMessage = $statusMessages[$statusCode] ?? 'Unknown Error';

        return new self(
            "LLM API returned HTTP {$statusCode}: {$statusMessage}",
            $rawResponse,
            [
                'error_type' => 'http_error',
                'http_status' => $statusCode
            ]
        );
    }

    /**
     * Create exception for model not found
     * 
     * @param string $model Model identifier
     * @return self
     */
    public static function modelNotFound($model)
    {
        return new self(
            "Model not found: {$model}",
            null,
            [
                'error_type' => 'model_not_found',
                'model' => $model
            ]
        );
    }

    /**
     * Create exception for content filter triggered
     * 
     * @param string|null $reason Reason for filtering
     * @return self
     */
    public static function contentFiltered($reason = null)
    {
        $message = 'Content was filtered by the API';
        if ($reason) {
            $message .= ": {$reason}";
        }

        return new self($message, null, [
            'error_type' => 'content_filtered',
            'reason' => $reason
        ]);
    }
}
