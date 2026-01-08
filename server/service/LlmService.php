<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/provider/LlmProviderRegistry.php';
require_once __DIR__ . '/validation/LlmValidator.php';
require_once __DIR__ . '/exception/LlmException.php';
require_once __DIR__ . '/exception/LlmValidationException.php';
require_once __DIR__ . '/exception/LlmRateLimitException.php';
require_once __DIR__ . '/exception/LlmApiException.php';

/**
 * Main LLM Service
 * 
 * Core service for LLM chat functionality. Handles:
 * - Conversation management (CRUD operations)
 * - Message management
 * - LLM API integration
 * - Rate limiting
 * 
 * Extends BaseLlmService for common functionality.
 * 
 * @package LLM Plugin
 * @version 1.1.0
 */
class LlmService extends BaseLlmService
{
    /** @var LlmProviderInterface Current LLM provider */
    protected $provider;

    /* =========================================================================
     * CONSTRUCTOR
     * ========================================================================= */

    /**
     * Constructor
     * 
     * @param object $services SelfHelp services container
     */
    public function __construct($services)
    {
        parent::__construct($services);
        
        // Initialize provider based on configuration
        $config = $this->getLlmConfig();
        $this->provider = LlmProviderRegistry::getProviderForUrl($config['llm_base_url']);
    }
    
    /**
     * Get the current provider instance
     * 
     * @return LlmProviderInterface Current provider
     */
    public function getProvider()
    {
        return $this->provider;
    }

    /* =========================================================================
     * CONFIGURATION
     * ========================================================================= */

    /**
     * Get LLM configuration
     * 
     * Retrieves configuration from database with caching.
     * Falls back to defaults if not configured.
     * 
     * @return array Configuration array
     */
    public function getLlmConfig()
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
                    $this->logWarning('LLM config retrieval failed', ['error' => $e->getMessage()]);
                }
            }

            // Set defaults if not configured
            $config = array_merge([
                'llm_base_url' => 'http://localhost:8080',
                'llm_api_key' => '',
                'llm_default_model' => LLM_DEFAULT_MODEL,
                'llm_timeout' => LLM_DEFAULT_TIMEOUT,
                'llm_max_tokens' => LLM_DEFAULT_MAX_TOKENS,
                'llm_temperature' => LLM_DEFAULT_TEMPERATURE
            ], $config);
        }

        return $config;
    }

    /* =========================================================================
     * RATE LIMITING
     * ========================================================================= */

    /**
     * Check rate limiting for a user
     * 
     * @param int $user_id User ID
     * @return array Rate limit data
     * @throws LlmRateLimitException If rate limit exceeded
     */
    public function checkRateLimit($user_id)
    {
        $rate_data = $this->cacheManager->getRateLimitData($user_id);
        
        if (!$rate_data || $this->cacheManager->shouldResetRateLimit($rate_data)) {
            $rate_data = $this->cacheManager->initRateLimitData();
        }

        // Check requests per minute limit
        if ($rate_data['requests'] >= LLM_RATE_LIMIT_REQUESTS_PER_MINUTE) {
            throw LlmRateLimitException::requestsPerMinute(LLM_RATE_LIMIT_REQUESTS_PER_MINUTE);
        }

        // Check concurrent conversations limit
        if (count($rate_data['conversations']) >= LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS) {
            throw LlmRateLimitException::concurrentConversations(LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS);
        }

        return $rate_data;
    }

    /**
     * Update rate limiting data
     * 
     * @param int $user_id User ID
     * @param array|null $rate_data Existing rate data (optional)
     * @param int|null $conversation_id Conversation ID to track (optional)
     * @return void
     */
    public function updateRateLimit($user_id, $rate_data = null, $conversation_id = null)
    {
        // If rate_data is not provided, get it from cache
        if ($rate_data === null) {
            $rate_data = $this->cacheManager->getRateLimitData($user_id);

            // If no cached data exists, initialize it
            if (!$rate_data || $this->cacheManager->shouldResetRateLimit($rate_data)) {
                $rate_data = $this->cacheManager->initRateLimitData();
            }
        }

        // Increment request count
        if (!isset($rate_data['requests'])) {
            $rate_data['requests'] = 0;
        }
        $rate_data['requests']++;

        // Initialize conversations array if it doesn't exist
        if (!isset($rate_data['conversations'])) {
            $rate_data['conversations'] = [];
        }

        // Add conversation if provided and not already in the list
        if ($conversation_id && !in_array($conversation_id, $rate_data['conversations'])) {
            $rate_data['conversations'][] = $conversation_id;
        }

        $this->cacheManager->setRateLimitData($user_id, $rate_data);
    }

    /* =========================================================================
     * CONVERSATION MANAGEMENT
     * ========================================================================= */

    /**
     * Create a new conversation
     * 
     * @param int $user_id User ID
     * @param string|null $title Conversation title
     * @param string|null $model Model name
     * @param float|null $temperature Temperature setting
     * @param int|null $max_tokens Max tokens setting
     * @param int|null $section_id Section ID for multi-section pages
     * @return int New conversation ID
     */
    public function createConversation($user_id, $title = null, $model = null, $temperature = null, $max_tokens = null, $section_id = null)
    {
        $config = $this->getLlmConfig();

        $data = [
            'id_users' => $user_id,
            'id_sections' => $section_id,
            'title' => $title ?: 'New Conversation',
            'model' => $model ?: $config['llm_default_model'],
            'temperature' => LlmValidator::temperature($temperature, $config['llm_temperature']),
            'max_tokens' => LlmValidator::maxTokens($max_tokens, $config['llm_max_tokens'])
        ];

        $conversation_id = $this->db->insert('llmConversations', $data);

        // Clear user cache using cache manager
        $this->cacheManager->clearUserCache($user_id);

        // Log transaction using trait
        $this->logTransaction(transactionTypes_insert, 'llmConversations', $conversation_id, $user_id, 'New conversation created');

        return $conversation_id;
    }

    /**
     * Get or create a conversation for a specific model
     * 
     * Returns the most recent conversation for the model, or creates a new one if none exists.
     * 
     * @param int $user_id User ID
     * @param string $model Model name
     * @param float|null $temperature Temperature setting
     * @param int|null $max_tokens Max tokens setting
     * @param int|null $section_id Section ID
     * @return int Conversation ID
     */
    public function getOrCreateConversationForModel($user_id, $model, $temperature = null, $max_tokens = null, $section_id = null)
    {
        // Try to find an existing conversation for this model within the same section
        $existing_conversations = $this->getUserConversations($user_id, 1, $model, $section_id);

        if (!empty($existing_conversations)) {
            return $existing_conversations[0]['id'];
        }

        // No existing conversation found, create a new one
        return $this->createConversation($user_id, null, $model, $temperature, $max_tokens, $section_id);
    }

    /**
     * Get user conversations
     * 
     * @param int $user_id User ID
     * @param int $limit Maximum number of conversations
     * @param string|null $model Filter by model name
     * @param int|null $section_id Filter by section ID
     * @return array Array of conversation records
     */
    public function getUserConversations($user_id, $limit = LLM_DEFAULT_CONVERSATION_LIMIT, $model = null, $section_id = null)
    {
        $cache_params = ['limit' => $limit];
        if ($model) {
            $cache_params['model'] = $model;
        }
        if ($section_id) {
            $cache_params['section_id'] = $section_id;
        }
        
        $cached = $this->cacheManager->get(LLM_CACHE_USER_CONVERSATIONS, $user_id, $cache_params);

        if ($cached !== false) {
            return $cached;
        }

        $sql = "SELECT id, id_sections, title, model, created_at, updated_at, blocked, blocked_reason, blocked_at
                FROM llmConversations
                WHERE id_users = :id_user AND deleted = 0";
        $params = [':id_user' => $user_id];

        if ($model) {
            $sql .= " AND model = :model";
            $params[':model'] = $model;
        }

        if ($section_id) {
            $sql .= " AND id_sections = :section_id";
            $params[':section_id'] = $section_id;
        }

        $sql .= " ORDER BY updated_at DESC LIMIT " . (int)$limit . ";";

        $conversations = $this->db->query_db($sql, $params);

        $this->cacheManager->set(LLM_CACHE_USER_CONVERSATIONS, $user_id, $conversations, $cache_params);
        return $conversations;
    }

    /**
     * Get a specific conversation
     * 
     * @param int $conversation_id Conversation ID
     * @param int $user_id User ID
     * @param int|null $section_id Optional section ID to verify ownership
     * @return array|null Conversation data or null if not found
     */
    public function getConversation($conversation_id, $user_id, $section_id = null)
    {
        $sql = "SELECT * FROM llmConversations WHERE id = ? AND id_users = ?";
        $params = [$conversation_id, $user_id];

        if ($section_id !== null) {
            $sql .= " AND id_sections = ?";
            $params[] = $section_id;
        }

        $conversation = $this->db->query_db_first($sql, $params);

        return $conversation ?: null;
    }

    /**
     * Update conversation
     * 
     * @param int $conversation_id Conversation ID
     * @param int $user_id User ID
     * @param array $data Data to update
     * @return bool Success
     * @throws LlmException If conversation not found
     */
    public function updateConversation($conversation_id, $user_id, $data)
    {
        // Verify ownership
        $conversation = $this->getConversation($conversation_id, $user_id);
        if (!$conversation) {
            throw new LlmException('Conversation not found or access denied', 404);
        }

        $allowed_fields = ['title', 'model', 'temperature', 'max_tokens'];
        $update_data = [];

        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                // Apply validation for specific fields
                if ($field === 'temperature') {
                    $update_data[$field] = LlmValidator::temperature($data[$field]);
                } elseif ($field === 'max_tokens') {
                    $update_data[$field] = LlmValidator::maxTokens($data[$field]);
                } else {
                    $update_data[$field] = $data[$field];
                }
            }
        }

        if (!empty($update_data)) {
            $this->db->update_by_ids('llmConversations', $update_data, ['id' => $conversation_id]);

            // Clear cache
            $this->cacheManager->clearConversationMessageCache($conversation_id);

            // Log transaction
            $this->logTransaction(transactionTypes_update, 'llmConversations', $conversation_id, $user_id, 'Conversation updated: ' . json_encode($update_data));
        }

        return true;
    }

    /**
     * Delete conversation (soft delete)
     * 
     * @param int $conversation_id Conversation ID
     * @param int $user_id User ID
     * @return bool Success
     * @throws LlmException If conversation not found
     */
    public function deleteConversation($conversation_id, $user_id)
    {
        // Verify ownership
        $conversation = $this->getConversation($conversation_id, $user_id);
        if (!$conversation) {
            throw new LlmException('Conversation not found or access denied', 404);
        }

        // Soft delete conversation
        $this->db->update_by_ids('llmConversations', ['deleted' => 1], ['id' => $conversation_id]);

        // Soft delete all messages
        $this->db->update_by_ids('llmMessages', ['deleted' => 1], ['id_llmConversations' => $conversation_id]);

        // Clear cache
        $this->cacheManager->clearUserCache($user_id);

        // Log transaction
        $this->logTransaction(transactionTypes_delete, 'llmConversations', $conversation_id, $user_id, 'Conversation deleted');

        return true;
    }

    /* =========================================================================
     * MESSAGE MANAGEMENT
     * ========================================================================= */

    /**
     * Add a message to a conversation
     *
     * @param int $conversation_id Conversation ID
     * @param string $role Message role (user/assistant/system)
     * @param string $content Message content (must be clean text only)
     * @param array|string|null $attachments File attachments metadata
     * @param string|null $model AI model used
     * @param int|null $tokens_used Token count
     * @param array|null $raw_response Raw API response data
     * @param array|null $sent_context Context messages sent with this message
     * @param string|null $reasoning Optional reasoning from LLM
     * @return int Message ID
     * @throws LlmValidationException If content is invalid
     * @throws LlmException If conversation not found
     */
    public function addMessage($conversation_id, $role, $content, $attachments = null, $model = null, $tokens_used = null, $raw_response = null, $sent_context = null, $reasoning = null)
    {
        // Validate content
        $content = LlmValidator::messageContent($content);
        $role = LlmValidator::role($role);

        // Verify conversation exists
        $conversation = $this->db->query_db_first(
            "SELECT id_users FROM llmConversations WHERE id = ?",
            [$conversation_id]
        );

        if (!$conversation) {
            throw new LlmException('Conversation not found', 404);
        }

        // Process attachments
        $attachmentsData = $this->processAttachments($attachments);

        // Process raw response
        $rawResponseData = $this->jsonEncode($raw_response);

        // Process sent context
        $sentContextData = $this->jsonEncode($sent_context);

        $data = [
            'id_llmConversations' => $conversation_id,
            'role' => $role,
            'content' => $content,
            'attachments' => $attachmentsData,
            'model' => $model,
            'tokens_used' => $tokens_used,
            'raw_response' => $rawResponseData,
            'sent_context' => $sentContextData,
            'reasoning' => $reasoning
        ];

        // Final validation to prevent JSON in content field
        if (strpos($data['content'], '{"id":') !== false) {
            $this->logError('Content field contains JSON data - preventing corruption');
            $data['content'] = substr($data['content'], 0, strpos($data['content'], '{"id":'));
        }

        $message_id = $this->db->insert('llmMessages', $data);

        // Update conversation timestamp
        $this->db->update_by_ids('llmConversations',
            ['updated_at' => date('Y-m-d H:i:s')],
            ['id' => $conversation_id]
        );

        // Clear cache
        $this->cacheManager->clearConversationMessageCache($conversation_id);

        // Log transaction
        $this->logTransaction(transactionTypes_insert, 'llmMessages', $message_id, $conversation['id_users'], "Message added to conversation $conversation_id");

        return $message_id;
    }

    /**
     * Process attachments for storage
     * 
     * @param array|string|null $attachments Raw attachments data
     * @return string|null JSON encoded attachments
     */
    private function processAttachments($attachments)
    {
        if (!$attachments) {
            return null;
        }

        if (is_array($attachments)) {
            return $this->jsonEncode($attachments);
        }

        if (is_string($attachments)) {
            // Check if it's already valid JSON
            $decoded = json_decode($attachments, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $attachments;
            }
            
            // Backward compatibility - single path string
            return $this->jsonEncode([[
                'path' => $attachments,
                'original_name' => basename($attachments)
            ]]);
        }

        return null;
    }

    /**
     * Update a message
     *
     * @param int $message_id Message ID
     * @param array $data Data to update
     * @return bool Success
     */
    public function updateMessage($message_id, $data)
    {
        return $this->db->update_by_ids('llmMessages', $data, ['id' => $message_id]);
    }

    /**
     * Get conversation messages
     * 
     * @param int $conversation_id Conversation ID
     * @param int $limit Maximum messages to return
     * @return array Array of messages
     */
    public function getConversationMessages($conversation_id, $limit = LLM_DEFAULT_MESSAGE_LIMIT)
    {
        $cached = $this->cacheManager->get(LLM_CACHE_CONVERSATION_MESSAGES, $conversation_id, ['limit' => $limit]);

        if ($cached !== false) {
            return $cached;
        }

        $messages = $this->db->query_db(
            "SELECT m.id, m.role, m.content, m.attachments, m.model, m.tokens_used, m.timestamp, m.sent_context
             FROM llmMessages m
             INNER JOIN (
                 SELECT id FROM llmMessages
                 WHERE id_llmConversations = :conversation_id AND deleted = 0
                 ORDER BY timestamp DESC
                 LIMIT " . (int)$limit . "
             ) recent ON m.id = recent.id
             ORDER BY m.timestamp ASC;",
            [':conversation_id' => $conversation_id]
        );

        $this->cacheManager->set(LLM_CACHE_CONVERSATION_MESSAGES, $conversation_id, $messages, ['limit' => $limit]);
        return $messages;
    }

    /* =========================================================================
     * LLM API INTEGRATION
     * ========================================================================= */

    /**
     * Get available models from LLM API
     * 
     * @param array|null $config Optional configuration override
     * @return array Array of model data
     */
    public function getAvailableModels($config = null)
    {
        if (!$config) {
            $config = $this->getLlmConfig();
        }

        $data = [
            'URL' => rtrim($config['llm_base_url'], '/') . LLM_API_MODELS,
            'request_type' => 'GET',
            'header' => [
                'Authorization: Bearer ' . $config['llm_api_key']
            ],
            'timeout' => $config['llm_timeout']
        ];

        $response = BaseModel::execute_curl_call($data);

        // If API call fails, return default model list
        if (!$response || !is_array($response) || empty($response['data'])) {
            return $this->getDefaultModelList()['data'];
        }

        return $this->normalizeModels($response['data']);
    }

    /**
     * Normalize models from different providers
     * 
     * @param array $models Raw model data
     * @return array Normalized models
     */
    private function normalizeModels($models)
    {
        return array_map(function($model) {
            if (isset($model['info'])) {
                return [
                    'id' => $model['id'],
                    'created' => $model['created'] ?? time(),
                    'object' => $model['object'] ?? 'model',
                    'owned_by' => $model['owned_by'] ?? 'unknown',
                    'meta' => $model['info']['meta'] ?? null
                ];
            }
            return $model;
        }, $models);
    }

    /**
     * Get default model list when API is unavailable
     * 
     * @return array Default model list
     */
    private function getDefaultModelList()
    {
        return [
            "data" => [
                ["id" => "qwen3-coder-30b-a3b-instruct", "created" => 1764016765, "object" => "model", "owned_by" => "gpustack", "meta" => null],
                ["id" => "gpt-oss-120b", "created" => 1763993286, "object" => "model", "owned_by" => "gpustack", "meta" => null],
                ["id" => "apertus-8b-instruct-2509", "created" => 1764237775, "object" => "model", "owned_by" => "gpustack", "meta" => null],
                ["id" => "deepseek-r1-0528-qwen3-8b", "created" => 1764223774, "object" => "model", "owned_by" => "gpustack", "meta" => null],
                ["id" => "minimax-m2", "created" => 1764020415, "object" => "model", "owned_by" => "gpustack", "meta" => null],
                ["id" => "internvl3-8b-instruct", "created" => 1764016711, "object" => "model", "owned_by" => "gpustack", "meta" => null],
                ["id" => "qwen3-vl-8b-instruct", "created" => 1764572225, "object" => "model", "owned_by" => "gpustack", "meta" => null]
            ],
            "object" => "list"
        ];
    }

    /**
     * Call LLM API for chat completion
     * 
     * Uses provider abstraction to handle different API formats.
     * 
     * @param array $messages Messages to send
     * @param string $model Model name
     * @param float|null $temperature Temperature setting
     * @param int|null $max_tokens Max tokens setting
     * @return array Normalized response
     * @throws LlmApiException If API call fails
     */
    public function callLlmApi($messages, $model, $temperature = null, $max_tokens = null)
    {
        $config = $this->getLlmConfig();

        // Get API URL using provider
        $url = $this->provider->getApiUrl($config['llm_base_url'], LLM_API_CHAT_COMPLETIONS);

        // Validate parameters using validator
        $temp_value = LlmValidator::temperature($temperature, $config['llm_temperature']);
        $max_tokens_value = LlmValidator::maxTokens($max_tokens, $config['llm_max_tokens']);

        // Build standard payload
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temp_value,
            'max_tokens' => $max_tokens_value,
            'stream' => false
        ];

        // Merge provider-specific parameters
        $providerParams = $this->provider->getAdditionalRequestParams($payload);
        $payload = array_merge($payload, $providerParams);

        // Get authentication headers from provider
        $headers = $this->provider->getAuthHeaders($config['llm_api_key']);

        $data = [
            'URL' => $url,
            'request_type' => 'POST',
            'header' => $headers,
            'post_params' => json_encode($payload),
            'timeout' => $config['llm_timeout']
        ];

        $response = BaseModel::execute_curl_call($data);

        if (!$response) {
            throw LlmApiException::noResponse();
        }

        // If response is a string, try to decode it as JSON
        if (is_string($response)) {
            $decoded = json_decode($response, true);
            if ($decoded !== null) {
                $response = $decoded;
            } else {
            $this->logWarning('LLM API returned raw string', ['response' => substr($response, 0, 500)]);
            throw LlmApiException::invalidResponse('Unexpected string response', $response);
            }
        }

        // Normalize response using provider
        try {
            return $this->provider->normalizeResponse($response);
        } catch (Exception $e) {
            $this->logWarning('Provider normalization error', ['error' => $e->getMessage()]);
            throw LlmApiException::normalizationFailed($this->provider->getProviderName(), $e->getMessage(), $response);
        }
    }
}
?>
