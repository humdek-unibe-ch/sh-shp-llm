<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmLoggingTrait.php';
require_once __DIR__ . '/../cache/LlmCacheManager.php';

/**
 * Base Service Class for LLM Plugin
 * 
 * Abstract base class that provides common functionality for all LLM services:
 * - Database and cache access
 * - Transaction logging via LlmLoggingTrait
 * - Centralized cache management via LlmCacheManager
 * - Common utility methods
 * 
 * All services that need database/cache access should extend this class.
 * 
 * Usage:
 * ```php
 * class MyLlmService extends BaseLlmService
 * {
 *     public function myMethod()
 *     {
 *         // Access database
 *         $result = $this->db->query_db("SELECT * FROM table");
 *         
 *         // Use cache manager
 *         $this->cacheManager->clearUserCache($userId);
 *         
 *         // Log transactions
 *         $this->logTransaction(transactionTypes_insert, 'table', $id, $userId, 'Description');
 *         
 *         // Debug logging
 *         $this->logDebug('Operation completed', ['result' => $result]);
 *     }
 * }
 * ```
 * 
 * @abstract
 * @package LLM Plugin
 * @version 1.0.0
 */
abstract class BaseLlmService
{
    use LlmLoggingTrait;

    /** @var object SelfHelp services container */
    protected $services;

    /** @var object SelfHelp database instance */
    protected $db;

    /** @var object SelfHelp cache instance */
    protected $cache;

    /** @var LlmCacheManager Centralized cache manager */
    protected $cacheManager;

    /**
     * Constructor - initializes common service dependencies
     * 
     * @param object $services SelfHelp services container
     */
    public function __construct($services)
    {
        $this->services = $services;
        $this->db = $services->get_db();
        $this->cache = $this->db->get_cache();
        $this->cacheManager = new LlmCacheManager($this->cache, $this->db);
    }

    /* =========================================================================
     * PROTECTED ACCESSORS
     * ========================================================================= */

    /**
     * Get the services container
     * 
     * @return object SelfHelp services container
     */
    protected function getServices()
    {
        return $this->services;
    }

    /**
     * Get the database instance
     * 
     * @return object SelfHelp database instance
     */
    protected function getDb()
    {
        return $this->db;
    }

    /**
     * Get the cache instance
     * 
     * @return object SelfHelp cache instance
     */
    protected function getCache()
    {
        return $this->cache;
    }

    /**
     * Get the cache manager
     *
     * @return LlmCacheManager Cache manager instance
     */
    protected function getCacheManager()
    {
        return $this->cacheManager;
    }

    /* =========================================================================
     * LLM CONFIGURATION
     * ========================================================================= */

    /**
     * Get LLM configuration
     *
     * Retrieves configuration from database with caching.
     * Falls back to defaults if not configured.
     *
     * @return array Configuration array
     */
    protected function getLlmConfig()
    {
        static $config = null;

        if ($config === null) {
            $config = [];

            // Get the LLM configuration page
            $page = $this->db->query_db_first(
                "SELECT id FROM pages WHERE keyword = ?",
                [PAGE_LLM_CONFIG]
            );

            if ($page) {
                try {
                    // Use the proper stored procedure to get page fields
                    $page_data = $this->db->query_db_first(
                        'CALL get_page_fields(?, ?, ?, ?, ?)',
                        [$page['id'], 1, 1, '', '']
                    );

                    if ($page_data) {
                        // Extract LLM configuration fields from the page data
                        foreach ($page_data as $key => $value) {
                            if (strpos($key, 'llm_') === 0) {
                                $config[$key] = $value;
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Log but don't fail - use defaults
                    $this->logError('LLM config retrieval failed', ['error' => $e->getMessage()]);
                }
            }

            // Apply defaults for any missing config
            $defaults = [
                'llm_base_url' => 'https://gpustack.unibe.ch/v1',
                'llm_api_key' => '',
                'llm_default_model' => LLM_DEFAULT_MODEL,
                'llm_timeout' => LLM_DEFAULT_TIMEOUT,
                'llm_max_tokens' => LLM_DEFAULT_MAX_TOKENS,
                'llm_temperature' => LLM_DEFAULT_TEMPERATURE
            ];

            $config = array_merge($defaults, $config);
        }

        return $config;
    }

    /* =========================================================================
     * COMMON UTILITY METHODS
     * ========================================================================= */

    /**
     * Get the current user ID from session
     * 
     * @return int|null User ID or null if not authenticated
     */
    protected function getCurrentUserId()
    {
        return $_SESSION['id_user'] ?? null;
    }

    /**
     * Check if a user is authenticated
     * 
     * @return bool True if user is authenticated
     */
    protected function isAuthenticated()
    {
        return !empty($_SESSION['id_user']);
    }

    /**
     * Safely encode data as JSON
     * 
     * @param mixed $data Data to encode
     * @param int $flags JSON encoding flags
     * @return string|null JSON string or null on failure
     */
    protected function jsonEncode($data, $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    {
        $json = json_encode($data, $flags);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logError('JSON encode failed: ' . json_last_error_msg());
            return null;
        }
        return $json;
    }

    /**
     * Sanitize payload for database storage
     * 
     * Removes large base64 image data to prevent memory issues and database bloat.
     * Replaces base64 data with a placeholder showing the image was included.
     * 
     * @param array|null $payload The request payload to sanitize
     * @return array|null Sanitized payload
     */
    protected function sanitizePayloadForStorage($payload)
    {
        if (!$payload || !is_array($payload)) {
            return $payload;
        }

        // Deep clone to avoid modifying original
        $sanitized = $this->deepSanitizePayload($payload);
        return $sanitized;
    }

    /**
     * Recursively sanitize payload, removing base64 image data
     * 
     * @param mixed $data Data to sanitize
     * @return mixed Sanitized data
     */
    private function deepSanitizePayload($data)
    {
        if (!is_array($data)) {
            // Check if it's a base64 data URL string
            if (is_string($data) && $this->isBase64DataUrl($data)) {
                return $this->createBase64Placeholder($data);
            }
            return $data;
        }

        $sanitized = [];
        foreach ($data as $key => $value) {
            // Check for image_url structure with base64 data
            if ($key === 'image_url' && is_array($value) && isset($value['url'])) {
                if ($this->isBase64DataUrl($value['url'])) {
                    $sanitized[$key] = [
                        'url' => $this->createBase64Placeholder($value['url'])
                    ];
                    continue;
                }
            }

            // Check for base64 string in 'url' key
            if ($key === 'url' && is_string($value) && $this->isBase64DataUrl($value)) {
                $sanitized[$key] = $this->createBase64Placeholder($value);
                continue;
            }

            // Recursively process arrays
            if (is_array($value)) {
                $sanitized[$key] = $this->deepSanitizePayload($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Check if a string is a base64 data URL
     * 
     * @param string $str String to check
     * @return bool True if it's a base64 data URL
     */
    private function isBase64DataUrl($str)
    {
        if (!is_string($str)) {
            return false;
        }
        return preg_match('/^data:[^;]+;base64,/', $str) === 1;
    }

    /**
     * Create a placeholder for base64 data
     * 
     * @param string $base64Url The base64 data URL
     * @return string Placeholder with metadata
     */
    private function createBase64Placeholder($base64Url)
    {
        // Extract mime type from data URL
        $mimeType = 'unknown';
        if (preg_match('/^data:([^;]+);base64,/', $base64Url, $matches)) {
            $mimeType = $matches[1];
        }

        // Calculate approximate original size
        $base64Data = preg_replace('/^data:[^;]+;base64,/', '', $base64Url);
        $estimatedSize = strlen($base64Data) * 0.75; // Base64 is ~33% larger than binary
        $sizeKb = round($estimatedSize / 1024, 1);

        return "[BASE64_IMAGE_REMOVED: {$mimeType}, ~{$sizeKb}KB - stored in attachments field]";
    }

    /**
     * Safely decode JSON string
     * 
     * @param string $json JSON string to decode
     * @param bool $assoc Return associative array (default: true)
     * @return mixed|null Decoded data or null on failure
     */
    protected function jsonDecode($json, $assoc = true)
    {
        $data = json_decode($json, $assoc);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logError('JSON decode failed: ' . json_last_error_msg());
            return null;
        }
        return $data;
    }

    /**
     * Get a value from an array with a default
     * 
     * @param array $array Source array
     * @param string $key Key to look up
     * @param mixed $default Default value if key doesn't exist
     * @return mixed Value or default
     */
    protected function arrayGet(array $array, $key, $default = null)
    {
        return array_key_exists($key, $array) ? $array[$key] : $default;
    }

    /**
     * Check if a string is valid JSON
     * 
     * @param string $string String to check
     * @return bool True if valid JSON
     */
    protected function isJson($string)
    {
        if (!is_string($string)) {
            return false;
        }
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Sanitize a string for safe output
     * 
     * @param string $string String to sanitize
     * @return string Sanitized string
     */
    protected function sanitize($string)
    {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Format a timestamp for display
     * 
     * @param string|int $timestamp Timestamp (string or Unix timestamp)
     * @param string $format Date format (default: Y-m-d H:i:s)
     * @return string Formatted date string
     */
    protected function formatTimestamp($timestamp, $format = 'Y-m-d H:i:s')
    {
        if (is_numeric($timestamp)) {
            return date($format, $timestamp);
        }
        return date($format, strtotime($timestamp));
    }
}
