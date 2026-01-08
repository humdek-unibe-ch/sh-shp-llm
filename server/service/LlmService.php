<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . '/provider/LlmProviderRegistry.php';

class LlmService
{
    protected $services;
    protected $db;
    protected $cache;
    protected $provider;

    /* Constructors ***********************************************************/

    /**
     * Constructor
     */
    public function __construct($services)
    {
        $this->services = $services;
        $this->db = $services->get_db();
        $this->cache = $this->db->get_cache();
        
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

    /* Private Methods *********************************************************/

    /**
     * Get LLM configuration
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
                            if (strpos($key, 'llm_') === 0) { // Only LLM-related fields
                                $config[$key] = $value;
                            }
                        }
                    }
                } catch (Exception $e) {
                    // If stored procedure fails, continue with defaults
                    error_log('LLM config retrieval failed: ' . $e->getMessage());
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

    /**
     * Check rate limiting
     */
    public function checkRateLimit($user_id)
    {
        $cache_key = LLM_CACHE_RATE_LIMIT . '_' . $user_id;
        $current_minute = date('Y-m-d H:i');

        $rate_data = $this->cache->get($cache_key);
        if (!$rate_data) {
            $rate_data = [
                'minute' => $current_minute,
                'requests' => 0,
                'conversations' => []
            ];
        }

        // Reset if new minute
        if ($rate_data['minute'] !== $current_minute) {
            $rate_data = [
                'minute' => $current_minute,
                'requests' => 0,
                'conversations' => []
            ];
        }

        // Check limits
        if ($rate_data['requests'] >= LLM_RATE_LIMIT_REQUESTS_PER_MINUTE) {
            throw new Exception('Rate limit exceeded: ' . LLM_RATE_LIMIT_REQUESTS_PER_MINUTE . ' requests per minute');
        }

        if (count($rate_data['conversations']) >= LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS) {
            throw new Exception('Concurrent conversation limit exceeded: ' . LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS . ' conversations');
        }

        return $rate_data;
    }

    /**
     * Update rate limiting data
     */
    public function updateRateLimit($user_id, $rate_data = null, $conversation_id = null)
    {
        // If rate_data is not provided, get it from cache
        if ($rate_data === null) {
            $cache_key = LLM_CACHE_RATE_LIMIT . '_' . $user_id;
            $rate_data = $this->cache->get($cache_key);

            // If no cached data exists, initialize it
            if (!$rate_data) {
                $current_minute = date('Y-m-d H:i');
                $rate_data = [
                    'minute' => $current_minute,
                    'requests' => 0,
                    'conversations' => []
                ];
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

        $cache_key = LLM_CACHE_RATE_LIMIT . '_' . $user_id;
        $this->cache->set($cache_key, $rate_data, 60); // Cache for 1 minute
    }

    /**
     * Clear user cache
     */
    private function clearUserCache($user_id)
    {
        $this->cache->clear_cache(LLM_CACHE_USER_CONVERSATIONS, $user_id);
        // Clear all conversation message caches for this user
        $this->clearConversationMessagesCache($user_id);
    }

    /**
     * Clear conversation messages cache for a specific user
     * Since conversation message caches use conversation_id as key, we need to find all user's conversations
     */
    private function clearConversationMessagesCache($user_id)
    {
        // Get all conversation IDs for this user (including blocked ones for cache clearing)
        $conversations = $this->db->query_db(
            "SELECT id FROM llmConversations WHERE id_users = ? AND deleted = 0",
            [$user_id]
        );

        // Clear message cache for each conversation
        foreach ($conversations as $conversation) {
            $this->cache->clear_cache(LLM_CACHE_CONVERSATION_MESSAGES, $conversation['id']);
        }
    }

    /**
     * Log transaction using the proper Transaction service
     */
    protected function logTransaction($operation, $table, $record_id, $user_id, $details = '')
    {
        $this->services->get_transaction()->add_transaction(
            $operation,                    // tran_type
            TRANSACTION_BY_LLM_PLUGIN,     // tran_by
            $user_id,                      // user_id
            $table,                        // table_name
            $record_id,                    // entry_id
            false,                         // log_row (don't log full row data)
            $details                       // verbal_log
        );
    }

    /* Public Methods *********************************************************/

    /* Conversation Management */

    /**
     * Create a new conversation
     */
    public function createConversation($user_id, $title = null, $model = null, $temperature = null, $max_tokens = null, $section_id = null)
    {
        $config = $this->getLlmConfig();

        $data = [
            'id_users' => $user_id,
            'id_sections' => $section_id,
            'title' => $title ?: 'New Conversation',
            'model' => $model ?: $config['llm_default_model'],
            'temperature' => $temperature ?: $config['llm_temperature'],
            'max_tokens' => $max_tokens ?: $config['llm_max_tokens']
        ];

        $conversation_id = $this->db->insert('llmConversations', $data);

        // Clear user cache
        $this->clearUserCache($user_id);

        // Log transaction
        $this->logTransaction(transactionTypes_insert, 'llmConversations', $conversation_id, $user_id, 'New conversation created');

        return $conversation_id;
    }

    /**
     * Get or create a conversation for a specific model (legacy behavior)
     * Returns the most recent conversation for the model, or creates a new one if none exists.
     */
    public function getOrCreateConversationForModel($user_id, $model, $temperature = null, $max_tokens = null, $section_id = null)
    {
        // First, try to find an existing conversation for this model within the same section
        // CRITICAL: Must pass section_id to ensure conversations are isolated per section
        $existing_conversations = $this->getUserConversations($user_id, 1, $model, $section_id);

        if (!empty($existing_conversations)) {
            // Return the most recent conversation for this model in this section
            return $existing_conversations[0]['id'];
        }

        // No existing conversation found for this section, create a new one
        return $this->createConversation($user_id, null, $model, $temperature, $max_tokens, $section_id);
    }

    /**
     * Get user conversations
     * 
     * @param int $user_id The user ID
     * @param int $limit Maximum number of conversations to return
     * @param string|null $model Filter by model name
     * @param int|null $section_id Filter by section ID (for multi-section pages)
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
        $cache_key = $this->cache->generate_key(LLM_CACHE_USER_CONVERSATIONS, $user_id, $cache_params);
        $cached = $this->cache->get($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $sql = "SELECT id, id_sections, title, model, created_at, updated_at, blocked, blocked_reason, blocked_at
                FROM llmConversations
                WHERE id_users = :id_user AND deleted = 0";
        $params = array(':id_user' => $user_id);

        if ($model) {
            $sql .= " AND model = :model";
            $params[':model'] = $model;
        }

        // Filter by section ID when provided - ensures each llmChat section shows only its own conversations
        if ($section_id) {
            $sql .= " AND id_sections = :section_id";
            $params[':section_id'] = $section_id;
        }

        $sql .= " ORDER BY updated_at DESC LIMIT " . $limit . ";";

        $conversations = $this->db->query_db($sql, $params);

        $this->cache->set($cache_key, $conversations, 300); // Cache for 5 minutes
        return $conversations;
    }

    /**
     * Get a specific conversation
     * 
     * @param int $conversation_id The conversation ID
     * @param int $user_id The user ID
     * @param int|null $section_id Optional section ID to verify conversation belongs to this section
     * @return array|null Conversation data or null if not found/not authorized
     */
    public function getConversation($conversation_id, $user_id, $section_id = null)
    {
        $sql = "SELECT * FROM llmConversations WHERE id = ? AND id_users = ?";
        $params = [$conversation_id, $user_id];

        // If section_id is provided, verify the conversation belongs to this section
        // This prevents accessing conversations from other llmChat instances on the same page
        if ($section_id !== null) {
            $sql .= " AND id_sections = ?";
            $params[] = $section_id;
        }

        $conversation = $this->db->query_db_first($sql, $params);

        return $conversation ?: null;
    }

    /**
     * Update conversation
     */
    public function updateConversation($conversation_id, $user_id, $data)
    {
        // Verify ownership
        $conversation = $this->getConversation($conversation_id, $user_id);
        if (!$conversation) {
            throw new Exception('Conversation not found or access denied');
        }

        $allowed_fields = ['title', 'model', 'temperature', 'max_tokens'];
        $update_data = [];

        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
            }
        }

        if (!empty($update_data)) {
            $this->db->update_by_ids('llmConversations', $update_data, ['id' => $conversation_id]);

            // Clear cache
            $this->cache->clear_cache(LLM_CACHE_CONVERSATION_MESSAGES, $conversation_id);

        // Log transaction
        $this->logTransaction(transactionTypes_update, 'llmConversations', $conversation_id, $user_id, 'Conversation updated: ' . json_encode($update_data));
        }

        return true;
    }

    /**
     * Delete conversation
     */
    public function deleteConversation($conversation_id, $user_id)
    {
        // Verify ownership
        $conversation = $this->getConversation($conversation_id, $user_id);
        if (!$conversation) {
            throw new Exception('Conversation not found or access denied');
        }

        // Soft delete conversation (mark as deleted)
        $this->db->update_by_ids('llmConversations', ['deleted' => 1], ['id' => $conversation_id]);

        // Also soft delete all messages in this conversation
        $this->db->update_by_ids('llmMessages', ['deleted' => 1], ['id_llmConversations' => $conversation_id]);

        // Clear cache
        $this->clearUserCache($user_id);

        // Log transaction
        $this->logTransaction(transactionTypes_delete, 'llmConversations', $conversation_id, $user_id, 'Conversation deleted');

        return true;
    }

    /* Message Management */

    /**
     * Add a message to conversation
     */
    /**
     * Add a message to a conversation - Industry Standard Implementation
     *
     * @param int $conversation_id The conversation ID
     * @param string $role The message role (user/assistant/system)
     * @param string $content The message content (must be clean text only)
     * @param array|string|null $attachments File attachments metadata
     * @param string|null $model The AI model used
     * @param int|null $tokens_used Token count for the message
     * @param array|null $raw_response Raw API response data (will be JSON encoded)
     * @param array|null $sent_context Context messages that were sent with this message (for debugging/audit)
     * @param string|null $reasoning Optional reasoning/thinking process from LLM (provider-specific)
     * @return int The message ID
     */
    public function addMessage($conversation_id, $role, $content, $attachments = null, $model = null, $tokens_used = null, $raw_response = null, $sent_context = null, $reasoning = null)
    {
        // Validate inputs to prevent corruption
        if (!is_string($content) || empty($content)) {
            throw new Exception('Message content must be a non-empty string');
        }

        // Verify conversation exists and get user_id
        $conversation = $this->db->query_db_first(
            "SELECT id_users FROM llmConversations WHERE id = ?",
            [$conversation_id]
        );

        if (!$conversation) {
            throw new Exception('Conversation not found');
        }

        // Handle attachments - store full metadata in attachments field
        $attachmentsData = null;
        if ($attachments) {
            if (is_array($attachments)) {
                $attachmentsData = json_encode($attachments);
            } elseif (is_string($attachments)) {
                // Check if it's already JSON (e.g., form submission metadata)
                $decoded = json_decode($attachments, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    // It's valid JSON - check if it's form submission or needs wrapping
                    if (isset($decoded['type']) && $decoded['type'] === 'form_submission') {
                        // Form submission - store as-is
                        $attachmentsData = $attachments;
                    } else {
                        // Other JSON data - store as-is
                        $attachmentsData = $attachments;
                    }
                } else {
                    // Backward compatibility - single path string (file path)
                    $attachmentsData = json_encode([[
                        'path' => $attachments,
                        'original_name' => basename($attachments)
                    ]]);
                }
            }
        }

        // Handle raw response - ensure it's properly JSON encoded
        $rawResponseData = null;
        if ($raw_response !== null) {
            if (is_array($raw_response)) {
                $rawResponseData = json_encode($raw_response);
            } elseif (is_string($raw_response)) {
                // Assume it's already JSON or needs to be stored as-is
                $rawResponseData = $raw_response;
            }
        }

        // Handle sent_context - store context snapshot for debugging/audit
        $sentContextData = null;
        if ($sent_context !== null) {
            if (is_array($sent_context)) {
                $sentContextData = json_encode($sent_context);
            } elseif (is_string($sent_context)) {
                $sentContextData = $sent_context;
            }
        }

        $data = [
            'id_llmConversations' => $conversation_id,
            'role' => $role,
            'content' => $content, // Guaranteed to be clean text only
            'attachments' => $attachmentsData,
            'model' => $model,
            'tokens_used' => $tokens_used,
            'raw_response' => $rawResponseData,
            'sent_context' => $sentContextData,
            'reasoning' => $reasoning
        ];


        // Final validation before insert
        if (strpos($data['content'], '{"id":') !== false) {
            error_log('CRITICAL: Content field contains JSON data - preventing corruption');
            $data['content'] = substr($data['content'], 0, strpos($data['content'], '{"id":'));
        }

        $message_id = $this->db->insert('llmMessages', $data);

        // Update conversation timestamp
        $this->db->update_by_ids('llmConversations',
            ['updated_at' => date('Y-m-d H:i:s')],
            ['id' => $conversation_id]
        );

        // Clear cache
        $this->cache->clear_cache(LLM_CACHE_CONVERSATION_MESSAGES, $conversation_id);

        // Log transaction
        $this->logTransaction(transactionTypes_insert, 'llmMessages', $message_id, $conversation['id_users'], "Message added to conversation $conversation_id");

        return $message_id;
    }

    /**
     * Update a message
     *
     * @param int $message_id The message ID to update
     * @param array $data The data to update
     * @return bool True if update was successful
     */
    public function updateMessage($message_id, $data)
    {
        return $this->db->update_by_ids('llmMessages', $data, ['id' => $message_id]);
    }


    /**
     * Get conversation messages
     */
    public function getConversationMessages($conversation_id, $limit = LLM_DEFAULT_MESSAGE_LIMIT)
    {
        $cache_key = $this->cache->generate_key(LLM_CACHE_CONVERSATION_MESSAGES, $conversation_id, ['limit' => $limit]);
        $cached = $this->cache->get($cache_key);

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
                 LIMIT " . $limit . "
             ) recent ON m.id = recent.id
             ORDER BY m.timestamp ASC;",
            [':conversation_id' => $conversation_id]
        );

        $this->cache->set($cache_key, $messages, 300); // Cache for 5 minutes
        return $messages;
    }

    /* LLM API Integration */

    /**
     * Get available models from LLM API
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

        // If API call fails or returns no data, return default model list
        if (!$response || !is_array($response) || empty($response['data'])) {
            return $this->getDefaultModelList()['data'];
        }

        // Normalize models to handle different provider structures
        return $this->normalizeModels($response['data']);
    }

    /**
     * Normalize models from different providers to a consistent structure
     */
    private function normalizeModels($models)
    {
        return array_map(function($model) {
            // Check if this is a new provider model with 'info' structure
            if (isset($model['info'])) {
                // For new provider, keep the original model id but normalize structure
                return [
                    'id' => $model['id'],
                    'created' => $model['created'] ?? time(),
                    'object' => $model['object'] ?? 'model',
                    'owned_by' => $model['owned_by'] ?? 'unknown',
                    'meta' => $model['info']['meta'] ?? null
                ];
            }

            // For GPUStack and other providers, return as-is
            return $model;
        }, $models);
    }


    /**
     * Get default model list when API is unavailable
     */
    private function getDefaultModelList()
    {
        // Return available models for fallback when API is unavailable
        return [
            "data" => [
                [
                    "id" => "qwen3-coder-30b-a3b-instruct",
                    "created" => 1764016765,
                    "object" => "model",
                    "owned_by" => "gpustack",
                    "meta" => null
                ],
                [
                    "id" => "gpt-oss-120b",
                    "created" => 1763993286,
                    "object" => "model",
                    "owned_by" => "gpustack",
                    "meta" => null
                ],
                [
                    "id" => "apertus-8b-instruct-2509",
                    "created" => 1764237775,
                    "object" => "model",
                    "owned_by" => "gpustack",
                    "meta" => null
                ],
                [
                    "id" => "deepseek-r1-0528-qwen3-8b",
                    "created" => 1764223774,
                    "object" => "model",
                    "owned_by" => "gpustack",
                    "meta" => null
                ],
                [
                    "id" => "minimax-m2",
                    "created" => 1764020415,
                    "object" => "model",
                    "owned_by" => "gpustack",
                    "meta" => null
                ],
                [
                    "id" => "internvl3-8b-instruct",
                    "created" => 1764016711,
                    "object" => "model",
                    "owned_by" => "gpustack",
                    "meta" => null
                ],
                [
                    "id" => "qwen3-vl-8b-instruct",
                    "created" => 1764572225,
                    "object" => "model",
                    "owned_by" => "gpustack",
                    "meta" => null
                ]
            ],
            "object" => "list"
        ];
    }

    /**
     * Call LLM API for chat completion
     * 
     * Uses provider abstraction to handle different API formats.
     * Returns normalized response structure.
     * All calls are synchronous HTTP POST requests.
     */
    public function callLlmApi($messages, $model, $temperature = null, $max_tokens = null)
    {
        $config = $this->getLlmConfig();

        // Get API URL using provider
        $url = $this->provider->getApiUrl($config['llm_base_url'], LLM_API_CHAT_COMPLETIONS);

        // Validate and clamp temperature to valid range (0.0 - 2.0)
        $temp_value = (float)($temperature ?: $config['llm_temperature']);
        if ($temp_value < 0.0) $temp_value = 0.0;
        if ($temp_value > 2.0) $temp_value = 2.0;
        
        // Validate max_tokens
        $max_tokens_value = (int)($max_tokens ?: $config['llm_max_tokens']);
        if ($max_tokens_value < 1) $max_tokens_value = 2048;
        if ($max_tokens_value > 16384) $max_tokens_value = 16384;

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
            throw new Exception('LLM API request failed - no response received');
        }

        // If response is a string, try to decode it as JSON
        if (is_string($response)) {
            $decoded = json_decode($response, true);
            if ($decoded !== null) {
                $response = $decoded;
            } else {
                // Raw string response - might be an error message
                error_log('LLM API returned raw string: ' . substr($response, 0, 500));
                throw new Exception('LLM API returned unexpected response: ' . substr($response, 0, 200));
            }
        }

        // Normalize response using provider
        try {
            return $this->provider->normalizeResponse($response);
        } catch (Exception $e) {
            error_log('LLM Provider normalization error: ' . $e->getMessage());
            error_log('Raw response: ' . json_encode($response));
            throw new Exception('Failed to normalize LLM response: ' . $e->getMessage());
        }
    }

    /* File Upload Handling */

}
?>
