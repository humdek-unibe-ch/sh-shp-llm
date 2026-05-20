<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmLoggingTrait.php';
require_once __DIR__ . '/../cache/LlmCacheManager.php';

/**
 * Base Service Class for LLM Plugin
 * 
 * Provides database access, cache management, transaction logging (LlmLoggingTrait),
 * and common utilities. All services that need database or cache access extend this.
 * 
 * Service Architecture (3 tiers):
 * 
 * 1. **Extend BaseLlmService** – for services that read/write the database or cache.
 *    Examples: LlmService, LlmAdminService, LlmDataSavingService,
 *    LlmDangerDetectionService, LlmProgressTrackingService, LlmScriptService,
 *    LlmSpeechToTextService, LlmApiFormatterService.
 * 
 * 2. **Composition (no extends)** – for pure-logic services that operate on
 *    data passed in via constructor/method args. They receive collaborators
 *    (like LlmService) via constructor injection instead of accessing the DB directly.
 *    Examples: LlmContextService, LlmResponseService, LlmFormModeService,
 *    LlmFloatingModeService, LlmStrictConversationService, LlmFileUploadService.
 * 
 * 3. **Static utilities** – for stateless helpers with no dependencies.
 *    Examples: LlmFileNamingService, LlmFileUtility, LlmLanguageUtility,
 *    LlmModelCapabilities, LlmValidator.
 * 
 * @abstract
 * @package LLM Plugin
 * @version 1.1.0
 */
abstract class BaseLlmService
{
    use LlmLoggingTrait;
    /**
     * Canonical separator for server-scoped model IDs persisted in DB/UI.
     * Example: "GPUStack Production :: qwen3-vl-8b-instruct"
     */
    const MODEL_SERVER_SEPARATOR = ' :: ';

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
     * Populates `llm_base_url` and `llm_api_key` convenience keys
     * from the first entry in `llm_servers` for internal use.
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
                        foreach ($page_data as $key => $value) {
                            if (strpos($key, 'llm_') === 0) {
                                $config[$key] = $value;
                            }
                        }
                    }
                } catch (Exception $e) {
                    $this->logError('LLM config retrieval failed', ['error' => $e->getMessage()]);
                }
            }

            // Parse multi-server API keys from JSON field
            $servers = $this->parseApiKeysConfig($config);
            $config['llm_servers'] = $servers;

            // Populate convenience keys from first server entry
            if (!empty($servers)) {
                $config['llm_base_url'] = $servers[0]['base_url'];
                $config['llm_api_key'] = $servers[0]['api_key'];
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

    /**
     * Parse the llm_api_keys JSON field into an array of server configs.
     *
     * Each entry: {name: string, base_url: string, api_key: string}
     *
     * Falls back to legacy llm_base_url / llm_api_key when no entries exist.
     *
     * @param array $config Raw config from database
     * @return array Server configuration entries
     */
    private function parseApiKeysConfig($config)
    {
        $servers = [];

        if (!empty($config['llm_api_keys'])) {
            $decoded = json_decode($config['llm_api_keys'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    if (!empty($entry['base_url'])) {
                        $servers[] = [
                            'name' => $entry['name'] ?? 'Default',
                            'base_url' => rtrim($entry['base_url'], '/'),
                            'api_key' => $entry['api_key'] ?? ''
                        ];
                    }
                }
            }
        }

        // Fallback: if no servers from JSON, use legacy fields
        if (empty($servers) && !empty($config['llm_base_url'])) {
            $servers[] = [
                'name' => 'Default',
                'base_url' => rtrim($config['llm_base_url'], '/'),
                'api_key' => $config['llm_api_key'] ?? ''
            ];
        }

        return $servers;
    }

    /**
     * Get all configured server entries.
     *
     * @return array Array of server configs [{name, base_url, api_key}, ...]
     */
    protected function getServerConfigs()
    {
        $config = $this->getLlmConfig();
        return $config['llm_servers'] ?? [];
    }

    /**
     * Resolve a model identifier that may be prefixed with a server name.
     *
     * Model format: "ServerName :: actual-model-id" or just "model-id" (legacy).
     * Returns the matching server config and the raw model ID.
     *
     * @param string $model Model identifier (possibly prefixed)
     * @return array ['server' => [...], 'model' => string]
     */
    protected function resolveModelServer($model)
    {
        $config = $this->getLlmConfig();
        $servers = $config['llm_servers'] ?? [];
        $parsed = $this->parseScopedModelId($model);
        $serverName = $parsed['server_name'];
        $rawModel = $parsed['model'];

        if (!empty($serverName)) {
            foreach ($servers as $server) {
                if (($server['name'] ?? '') === $serverName) {
                    return ['server' => $server, 'model' => $rawModel];
                }
            }
        }

        // Fallback: use first server or default config
        if (!empty($servers)) {
            return ['server' => $servers[0], 'model' => $rawModel];
        }
        return [
            'server' => [
                'name' => 'Default',
                'base_url' => $config['llm_base_url'] ?? '',
                'api_key' => $config['llm_api_key'] ?? ''
            ],
            'model' => $rawModel
        ];
    }

    /**
     * Build a model identifier for select/storage.
     * With multiple servers configured this returns:
     *   "Server Name :: model-id"
     * With single server this returns the raw model-id (backward compatible).
     *
     * @param string $serverName
     * @param string $modelId
     * @param bool $useServerScope
     * @return string
     */
    protected function buildScopedModelId($serverName, $modelId, $useServerScope)
    {
        $modelId = trim((string)$modelId);
        if ($modelId === '') {
            return '';
        }
        if (!$useServerScope) {
            return $modelId;
        }
        $serverName = trim((string)$serverName);
        if ($serverName === '') {
            return $modelId;
        }
        return $serverName . self::MODEL_SERVER_SEPARATOR . $modelId;
    }

    /**
     * Parse a scoped model identifier.
     *
     * Supports:
     * - New canonical format: "Server Name :: model-id"
     * - Legacy format: "Server Name - model-id"
     * - Raw format: "model-id"
     *
     * @param string $value
     * @return array ['server_name' => string|null, 'model' => string, 'has_prefix' => bool]
     */
    protected function parseScopedModelId($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return ['server_name' => null, 'model' => '', 'has_prefix' => false];
        }

        $separatorPos = strpos($value, self::MODEL_SERVER_SEPARATOR);
        if ($separatorPos !== false) {
            return [
                'server_name' => trim(substr($value, 0, $separatorPos)),
                'model' => trim(substr($value, $separatorPos + strlen(self::MODEL_SERVER_SEPARATOR))),
                'has_prefix' => true
            ];
        }

        // Backward compatibility with old "Server - model" format
        $legacyPos = strpos($value, ' - ');
        if ($legacyPos !== false) {
            return [
                'server_name' => trim(substr($value, 0, $legacyPos)),
                'model' => trim(substr($value, $legacyPos + 3)),
                'has_prefix' => true
            ];
        }

        return ['server_name' => null, 'model' => $value, 'has_prefix' => false];
    }

    /**
     * Normalize model identifier for DB storage / select values.
     *
     * When at least one server is configured, model IDs are persisted in
     * canonical scoped format:
     *   "Server Name :: model-id"
     *
     * With no configured servers, keeps raw model ID.
     *
     * @param string $model
     * @param array|null $config Optional preloaded config
     * @return string
     */
    protected function normalizeModelIdentifierForStorage($model, $config = null)
    {
        $model = trim((string)$model);
        if ($model === '') {
            return $model;
        }

        if ($config === null) {
            $config = $this->getLlmConfig();
        }

        $servers = $config['llm_servers'] ?? array();
        if (empty($servers)) {
            $parsed = $this->parseScopedModelId($model);
            return $parsed['model'] ?? $model;
        }

        $resolved = $this->resolveModelServer($model);
        $serverName = $resolved['server']['name'] ?? '';
        $rawModel = $resolved['model'] ?? $model;

        return $this->buildScopedModelId($serverName, $rawModel, true);
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

    /**
     * Record a plugin-scoped transaction log entry.
     *
     * @param string $action insert|update|delete
     * @param string $table
     * @param int $ref_id
     * @param string $message
     * @return void
     */
    protected function addPluginTransaction($action, $table, $ref_id, $message)
    {
        $type = transactionTypes_update;
        if ($action === 'insert') {
            $type = transactionTypes_insert;
        } elseif ($action === 'delete') {
            $type = transactionTypes_delete;
        }

        $this->services->get_transaction()->add_transaction(
            $type,
            TRANSACTION_BY_LLM_PLUGIN,
            $this->getCurrentUserId(),
            $table,
            $ref_id,
            false,
            $message
        );
    }

    /**
     * Locate the PHP CLI binary using multiple strategies.
     * Shared across all services that spawn background PHP processes.
     *
     * @return string Path to php binary
     */
    public static function resolvePhpCliBinary()
    {
        $is_win = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $bin_name = $is_win ? 'php.exe' : 'php';

        foreach (array(
            defined('LLM_PHP_CLI_BINARY') ? LLM_PHP_CLI_BINARY : null,
            getenv('SELFHELP_PHP_CLI_BINARY'),
            getenv('PHP_CLI_BINARY'),
            defined('SELFHELP_PHP_CLI_BINARY') ? SELFHELP_PHP_CLI_BINARY : null,
            defined('PHP_CLI_BINARY') ? PHP_CLI_BINARY : null,
        ) as $configured_bin) {
            if (!is_string($configured_bin)) {
                continue;
            }

            $configured_bin = trim($configured_bin, " \t\n\r\0\x0B\"'");
            if ($configured_bin !== '' && file_exists($configured_bin)) {
                return $configured_bin;
            }
        }

        if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
            return PHP_BINARY;
        }

        if (!$is_win) {
            foreach (['command -v php', 'which php'] as $lookup_cmd) {
                $which = @shell_exec($lookup_cmd . ' 2>/dev/null');
                if ($which) {
                    $which = trim($which);
                    if ($which !== '' && file_exists($which)) {
                        return $which;
                    }
                }
            }
        } else {
            $where = @shell_exec('where php 2>NUL');
            if ($where) {
                $first_line = trim(strtok($where, "\n"));
                if ($first_line !== '' && file_exists($first_line)) {
                    return $first_line;
                }
            }
        }

        $ext_dir = ini_get('extension_dir');
        if ($ext_dir) {
            $php_dir = dirname(rtrim($ext_dir, '/\\'));
            $candidate = $php_dir . DIRECTORY_SEPARATOR . $bin_name;
            if (file_exists($candidate)) {
                return $candidate;
            }
            $candidate2 = dirname($php_dir) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $bin_name;
            if (file_exists($candidate2)) {
                return $candidate2;
            }
        }

        return $bin_name;
    }
}
