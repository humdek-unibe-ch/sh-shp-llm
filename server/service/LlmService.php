<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

class LlmService
{
    private $services;
    private $db;
    private $cache;

    /* Constructors ***********************************************************/

    /**
     * Constructor
     */
    public function __construct($services)
    {
        $this->services = $services;
        $this->db = $services->get_db();
        $this->cache = $this->db->get_cache();
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
                'llm_temperature' => LLM_DEFAULT_TEMPERATURE,
                'llm_streaming_enabled' => '1'
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
    public function updateRateLimit($user_id, $rate_data, $conversation_id = null)
    {
        $rate_data['requests']++;

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
        $this->cache->clear_cache(LLM_CACHE_CONVERSATION_MESSAGES, $user_id);
    }

    /**
     * Log transaction
     */
    private function logTransaction($operation, $table, $record_id, $user_id, $details = '')
    {
        $this->services->get_transaction_service()->log_operation(
            $operation,
            $table,
            $record_id,
            $user_id,
            $details
        );
    }

    /* Public Methods *********************************************************/

    /* Conversation Management */

    /**
     * Create a new conversation
     */
    public function createConversation($user_id, $title = null, $model = null, $temperature = null, $max_tokens = null)
    {
        $config = $this->getLlmConfig();

        $data = [
            'id_users' => $user_id,
            'title' => $title ?: 'New Conversation',
            'model' => $model ?: $config['llm_default_model'],
            'temperature' => $temperature ?: $config['llm_temperature'],
            'max_tokens' => $max_tokens ?: $config['llm_max_tokens']
        ];

        $conversation_id = $this->db->insert('llmConversations', $data);

        // Clear user cache
        $this->clearUserCache($user_id);

        // Log transaction
        $this->logTransaction('CREATE', 'llmConversations', $conversation_id, $user_id, 'New conversation created');

        return $conversation_id;
    }

    /**
     * Get user conversations
     */
    public function getUserConversations($user_id, $limit = LLM_DEFAULT_CONVERSATION_LIMIT)
    {
        $cache_key = $this->cache->generate_key(LLM_CACHE_USER_CONVERSATIONS, $user_id, ['limit' => $limit]);
        $cached = $this->cache->get($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $conversations = $this->db->query_db(
            "SELECT id, title, model, created_at, updated_at
             FROM llmConversations
             WHERE id_users = :id_user
             ORDER BY updated_at DESC
             LIMIT " . $limit . ";",
            array(':id_user' => $user_id)
        );

        $this->cache->set($cache_key, $conversations, 300); // Cache for 5 minutes
        return $conversations;
    }

    /**
     * Get a specific conversation
     */
    public function getConversation($conversation_id, $user_id)
    {
        $conversation = $this->db->query_db_first(
            "SELECT * FROM llmConversations
             WHERE id = ? AND id_users = ?",
            [$conversation_id, $user_id]
        );

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
            $this->clearUserCache($user_id);

        // Log transaction
        $this->logTransaction('UPDATE', 'llmConversations', $conversation_id, $user_id, 'Conversation updated: ' . json_encode($update_data));
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

        // Delete conversation (messages will be deleted via CASCADE)
        $this->db->delete_by_ids('llmConversations', ['id' => $conversation_id]);

        // Clear cache
        $this->clearUserCache($user_id);

        // Log transaction
        $this->logTransaction('DELETE', 'llmConversations', $conversation_id, $user_id, 'Conversation deleted');

        return true;
    }

    /* Message Management */

    /**
     * Add a message to conversation
     */
    public function addMessage($conversation_id, $role, $content, $image_path = null, $model = null, $tokens_used = null)
    {
        // Verify conversation exists and get user_id
        $conversation = $this->db->query_db_first(
            "SELECT id_users FROM llmConversations WHERE id = ?",
            [$conversation_id]
        );

        if (!$conversation) {
            throw new Exception('Conversation not found');
        }

        $data = [
            'id_conversation' => $conversation_id,
            'role' => $role,
            'content' => $content,
            'image_path' => $image_path,
            'model' => $model,
            'tokens_used' => $tokens_used
        ];

        $message_id = $this->db->insert('llmMessages', $data);

        // Update conversation timestamp
        $this->db->update_by_ids('llmConversations',
            ['updated_at' => date('Y-m-d H:i:s')],
            ['id' => $conversation_id]
        );

        // Clear cache
        $this->clearUserCache($conversation['id_users']);

        // Log transaction
        $this->logTransaction('CREATE', 'llmMessages', $message_id, $conversation['id_users'], "Message added to conversation $conversation_id");

        return $message_id;
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
            "SELECT id, role, content, image_path, model, tokens_used, timestamp
             FROM llmMessages
             WHERE id_llmConversations = ?
             ORDER BY timestamp ASC
             LIMIT ?",
            [$conversation_id, $limit]
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

        if (!$response) {
            throw new Exception('Failed to fetch models from LLM API');
        }

        return $response['data'] ?? [];
    }

    /**
     * Call LLM API for chat completion
     */
    public function callLlmApi($messages, $model, $temperature = null, $max_tokens = null, $stream = false)
    {
        $config = $this->getLlmConfig();

        $url = rtrim($config['llm_base_url'], '/') . LLM_API_CHAT_COMPLETIONS;

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature ?: $config['llm_temperature'],
            'max_tokens' => $max_tokens ?: $config['llm_max_tokens'],
            'stream' => $stream
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $config['llm_timeout'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $config['llm_api_key'],
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            throw new Exception('LLM API request failed with code: ' . $http_code);
        }

        return json_decode($response, true);
    }

    /**
     * Stream LLM response
     */
    public function streamLlmResponse($messages, $model, $temperature = null, $max_tokens = null, $callback = null)
    {
        $config = $this->getLlmConfig();

        $url = rtrim($config['llm_base_url'], '/') . LLM_API_CHAT_COMPLETIONS;

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature ?: $config['llm_temperature'],
            'max_tokens' => $max_tokens ?: $config['llm_max_tokens'],
            'stream' => true
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $config['llm_timeout'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $config['llm_api_key'],
                'Content-Type: application/json'
            ],
            CURLOPT_WRITEFUNCTION => function($ch, $data) use ($callback) {
                // Parse SSE data
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    if (strpos($line, 'data: ') === 0) {
                        $json_data = substr($line, 6);
                        if ($json_data === '[DONE]') {
                            if ($callback) $callback('[DONE]');
                            return strlen($data);
                        }

                        $parsed = json_decode($json_data, true);
                        if ($parsed && isset($parsed['choices'][0]['delta']['content'])) {
                            $content = $parsed['choices'][0]['delta']['content'];
                            if ($callback && !empty($content)) {
                                $callback($content);
                            }
                        }
                    }
                }
                return strlen($data);
            }
        ]);

        curl_exec($ch);
        curl_close($ch);
    }

    /* File Upload Handling */

    /**
     * Save uploaded files for LLM
     */
    public function saveUploadedFiles($conversation_id, $user_id)
    {
        $upload_dir = LLM_UPLOAD_FOLDER . '/' . $user_id . '/' . $conversation_id;
        $full_upload_dir = __DIR__ . '/../../../../' . $upload_dir;

        // Create directory if it doesn't exist
        if (!is_dir($full_upload_dir)) {
            mkdir($full_upload_dir, 0755, true);
        }

        $uploaded_files = [];

        foreach ($_FILES as $index => $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                continue;
            }

            // Validate file
            if ($file['size'] > LLM_MAX_FILE_SIZE) {
                throw new Exception('File size exceeds limit');
            }

            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, LLM_ALLOWED_EXTENSIONS)) {
                throw new Exception('File type not allowed');
            }

            // Generate unique filename
            $filename = date('Ymd_His') . '_' . uniqid() . '.' . $extension;
            $filepath = $full_upload_dir . '/' . $filename;

            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $uploaded_files[] = $upload_dir . '/' . $filename;
            }
        }

        return $uploaded_files;
    }

    /* Admin Methods */

    /**
     * Get all conversations (admin only)
     */
    public function getAllConversations($limit = 100, $offset = 0)
    {
        return $this->db->query_db(
            "SELECT c.*, u.name as user_name
             FROM llmConversations c
             INNER JOIN users u ON c.id_users = u.id
             ORDER BY c.updated_at DESC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    /**
     * Get conversation by ID (admin only)
     */
    public function getConversationById($conversation_id)
    {
        return $this->db->query_db_first(
            "SELECT c.*, u.name as user_name
             FROM llmConversations c
             INNER JOIN users u ON c.id_users = u.id
             WHERE c.id = ?",
            [$conversation_id]
        );
    }
}
?>
