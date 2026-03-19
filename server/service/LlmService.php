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

        $config = $this->getLlmConfig();
        $this->provider = LlmProviderRegistry::getProviderForUrl($config['llm_base_url']);
    }

    /**
     * Get provider for a specific server config
     *
     * @param array $server Server config with base_url
     * @return LlmProviderInterface
     */
    private function getProviderForServer($server)
    {
        return LlmProviderRegistry::getProviderForUrl($server['base_url']);
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
        $modelValue = $model ?: $config['llm_default_model'];
        $modelValue = $this->normalizeModelIdentifierForStorage($modelValue, $config);

        $data = [
            'id_users' => $user_id,
            'id_sections' => $section_id,
            'title' => $title ?: 'New Conversation',
            'model' => $modelValue,
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
     * Resolve conversation for a request: get existing or create new
     *
     * Handles:
     * - Creating a new conversation if none exists
     * - Checking concurrent conversation limits before creation
     * - Validating existing conversation ownership
     * - Creating new conversation if model changed
     *
     * @param int $user_id User ID
     * @param int|null $conversation_id Existing conversation ID (optional)
     * @param array $rate_data Rate limit data from checkRateLimit
     * @param string $model Model name
     * @param float|null $temperature Temperature setting
     * @param int|null $max_tokens Max tokens setting
     * @param int|null $section_id Section ID
     * @return array ['conversation_id' => int, 'is_new' => bool]
     * @throws Exception If conversation not found or limit exceeded
     */
    public function resolveConversation($user_id, $conversation_id, $rate_data, $model, $temperature = null, $max_tokens = null, $section_id = null)
    {
        $is_new = false;
        $config = $this->getLlmConfig();
        $normalizedModel = $this->normalizeModelIdentifierForStorage($model, $config);

        if (!$conversation_id) {
            $this->validateConcurrentConversationLimit($rate_data);
            $conversation_id = $this->getOrCreateConversationForModel(
                $user_id,
                $normalizedModel,
                $temperature,
                $max_tokens,
                $section_id
            );
            $is_new = true;
        } else {
            $existing = $this->getConversation($conversation_id, $user_id, $section_id);
            if (!$existing) {
                throw new Exception('Conversation not found');
            }

            $existingModel = $existing['model'];
            $parsed = $this->parseScopedModelId($normalizedModel);
            $rawModel = $parsed['model'] ?? $normalizedModel;
            $modelMatches = ($existingModel === $normalizedModel || $existingModel === $rawModel);

            if (!$modelMatches) {
                $this->validateConcurrentConversationLimit($rate_data);
                $conversation_id = $this->getOrCreateConversationForModel(
                    $user_id,
                    $normalizedModel,
                    $temperature,
                    $max_tokens,
                    $section_id
                );
                $is_new = true;
            }
        }

        return [
            'conversation_id' => $conversation_id,
            'is_new' => $is_new
        ];
    }

    /**
     * Validate concurrent conversation limit before creating a new one
     *
     * @param array $rate_data Rate limit data
     * @throws Exception If limit exceeded
     */
    private function validateConcurrentConversationLimit($rate_data)
    {
        $conversations = $rate_data['conversations'] ?? [];
        if (count($conversations) >= LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS) {
            throw new Exception(
                'Concurrent conversation limit exceeded: ' .
                LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS . ' conversations'
            );
        }
    }

    /**
     * Update conversation title for new conversations
     *
     * @param int $conversation_id Conversation ID
     * @param int $user_id User ID
     * @param string $first_message First message content (used to generate title)
     */
    public function updateNewConversationTitle($conversation_id, $user_id, $first_message)
    {
        $title = $this->generateConversationTitle($first_message);
        $this->updateConversation($conversation_id, $user_id, ['title' => $title]);
    }

    /**
     * Generate a conversation title based on the first message
     *
     * @param string $message Message content
     * @return string Generated title
     */
    private function generateConversationTitle($message)
    {
        $clean_message = trim($message);
        $clean_message = preg_replace('/[?!.,;:]+$/', '', $clean_message);

        $words = explode(' ', $clean_message);
        $title_words = array_slice($words, 0, 8);
        $title = implode(' ', $title_words);
        $title = ucfirst($title);

        if (strlen($title) > 50) {
            $shortened = substr($title, 0, 47);
            $last_space = strrpos($shortened, ' ');
            if ($last_space !== false) {
                $title = substr($shortened, 0, $last_space) . '...';
            } else {
                $title = substr($title, 0, 47) . '...';
            }
        }

        if (strlen($title) < 3) {
            $title = 'New Conversation';
        }

        return $title;
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
        $config = $this->getLlmConfig();
        $model = $this->normalizeModelIdentifierForStorage($model, $config);

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
        // Normalize the model so lookups match what createConversation stored
        if ($model) {
            $model = $this->normalizeModelIdentifierForStorage($model);
        }

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
            $parsed = $this->parseScopedModelId($model);
            $rawModel = $parsed['model'] ?? $model;
            if ($rawModel !== $model) {
                $sql .= " AND (model = :model OR model = :raw_model)";
                $params[':model'] = $model;
                $params[':raw_model'] = $rawModel;
            } else {
                $sql .= " AND model = :model";
                $params[':model'] = $model;
            }
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
        $sql = "SELECT * FROM llmConversations WHERE id = ? AND id_users = ? AND deleted = 0";
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
     * @param bool $is_validated Whether the message passed schema validation (default: true)
     * @param array|null $request_payload The request payload sent to LLM API (for debugging)
     * @return int Message ID
     * @throws LlmValidationException If content is invalid
     * @throws LlmException If conversation not found
     */
    public function addMessage($conversation_id, $role, $content, $attachments = null, $model = null, $tokens_used = null, $raw_response = null, $sent_context = null, $reasoning = null, $is_validated = true, $request_payload = null)
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

        // Process request payload (for assistant messages, stores the API request for debugging)
        // Sanitize to remove large base64 image data before storing
        $sanitizedPayload = $this->sanitizePayloadForStorage($request_payload);
        $requestPayloadData = $this->jsonEncode($sanitizedPayload);

        $data = [
            'id_llmConversations' => $conversation_id,
            'role' => $role,
            'content' => $content,
            'attachments' => $attachmentsData,
            'model' => $model,
            'tokens_used' => $tokens_used,
            'raw_response' => $rawResponseData,
            'sent_context' => $sentContextData,
            'reasoning' => $reasoning,
            'is_validated' => $is_validated ? 1 : 0,
            'request_payload' => $requestPayloadData
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
     * Log an assistant message from a normalized API response.
     *
     * This helper centralizes storage of:
     * - sent_context
     * - request_payload
     * - raw_response
     * - reasoning
     * - token usage
     *
     * @param int $conversation_id
     * @param string $content Assistant message content
     * @param string $model Model name
     * @param array|null $sent_context Context messages sent to the LLM
     * @param array|null $api_response Normalized response (content/usage/raw_response/reasoning/request_payload)
     * @param bool $is_validated Whether the message passed schema/format validation
     * @return int Message ID
     */
    public function addAssistantMessageFromApiResponse($conversation_id, $content, $model, $sent_context = null, $api_response = null, $is_validated = true)
    {
        $tokens_used = null;
        $raw_response = null;
        $reasoning = null;
        $request_payload = null;

        if (is_array($api_response)) {
            $tokens_used = $api_response['usage']['total_tokens'] ?? null;
            $raw_response = $api_response['raw_response'] ?? $api_response;
            $reasoning = $api_response['reasoning'] ?? null;
            $request_payload = $api_response['request_payload'] ?? null;
        }

        return $this->addMessage(
            $conversation_id,
            'assistant',
            $content,
            null,
            $model,
            $tokens_used,
            $raw_response,
            $sent_context,
            $reasoning,
            $is_validated,
            $request_payload
        );
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
     * Get conversation messages (only validated messages for user-facing chat)
     * 
     * @param int $conversation_id Conversation ID
     * @param int $limit Maximum messages to return
     * @return array Array of validated messages
     */
    public function getConversationMessages($conversation_id, $limit = LLM_DEFAULT_MESSAGE_LIMIT)
    {
        $cached = $this->cacheManager->get(LLM_CACHE_CONVERSATION_MESSAGES, $conversation_id, ['limit' => $limit]);

        if ($cached !== false) {
            return $cached;
        }

        // Only return validated messages (is_validated = 1) for user-facing chat
        $messages = $this->db->query_db(
            "SELECT m.id, m.role, m.content, m.attachments, m.model, m.tokens_used, m.timestamp, m.sent_context
             FROM llmMessages m
             INNER JOIN (
                 SELECT id FROM llmMessages
                 WHERE id_llmConversations = :conversation_id AND deleted = 0 AND is_validated = 1
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
     * Get available models from all configured LLM servers.
     *
     * Model IDs are returned in canonical scoped format:
     *   "ServerName :: model-id"
     * so server routing remains explicit at selection and execution time.
     *
     * @param array|null $config Optional configuration override
     * @return array Array of model data
     */
    public function getAvailableModels($config = null, $modelType = 'llm')
    {
        if (!$config) {
            $config = $this->getLlmConfig();
        }

        $servers = $config['llm_servers'] ?? [];

        // Fallback: single-server legacy mode
        if (empty($servers)) {
            return $this->fetchModelsFromServer([
                'name' => 'Default',
                'base_url' => $config['llm_base_url'],
                'api_key' => $config['llm_api_key']
            ], $config['llm_timeout'], false, $modelType);
        }

        $useServerScope = true;
        $allModels = [];

        foreach ($servers as $server) {
            $models = $this->fetchModelsFromServer($server, $config['llm_timeout'], $useServerScope, $modelType);
            $allModels = array_merge($allModels, $models);
        }

        if (empty($allModels)) {
            return $this->getDefaultModelListByType($modelType);
        }

        return $allModels;
    }

    /**
     * Public wrapper so hooks/components can normalize legacy raw model values
     * to canonical scoped IDs before rendering selects.
     *
     * @param string $model
     * @return string
     */
    public function normalizeModelIdentifier($model)
    {
        return $this->normalizeModelIdentifierForStorage($model);
    }

    /**
     * Fetch models from a single server endpoint.
     *
     * @param array $server Server config {name, base_url, api_key}
     * @param int $timeout Request timeout
     * @param bool $prefix Whether to prefix model ids with server name
     * @return array Normalized model list
     */
    private function fetchModelsFromServer($server, $timeout, $prefix = false, $modelType = 'llm')
    {
        $data = [
            'URL' => rtrim($server['base_url'], '/') . LLM_API_MODELS,
            'request_type' => 'GET',
            'header' => [
                'Authorization: Bearer ' . ($server['api_key'] ?? '')
            ],
            'timeout' => $timeout
        ];

        $response = BaseModel::execute_curl_call($data);

        if (!$response || !is_array($response) || empty($response['data'])) {
            return [];
        }

        $models = $this->normalizeModels($response['data']);
        $models = $this->filterModelsByType($models, $modelType);

        if ($prefix && !empty($server['name'])) {
            foreach ($models as &$model) {
                $model['id'] = $this->buildScopedModelId(
                    $server['name'] ?? 'Default',
                    $model['id'] ?? '',
                    true
                );
            }
            unset($model);
        }

        return $models;
    }

    /**
     * Filter model list by usage type.
     *
     * For 'llm': excludes audio, embedding, and reranker models so users
     * only see chat-capable models.
     * For 'audio': includes only whisper/speech models.
     *
     * @param array $models
     * @param string $modelType Supported: llm, audio
     * @return array
     */
    private function filterModelsByType($models, $modelType)
    {
        if ($modelType === 'audio') {
            return array_values(array_filter($models, function ($model) {
                $id = strtolower($model['id'] ?? '');
                return $this->isAudioModelId($id);
            }));
        }

        // For 'llm' type: exclude non-chat models (audio, embedding, reranker)
        return array_values(array_filter($models, function ($model) {
            $id = $model['id'] ?? '';
            return !llm_is_non_chat_model($id) && !$this->isAudioModelId(strtolower($id));
        }));
    }

    /**
     * Determine whether a model id is audio/speech-to-text capable.
     *
     * @param string $id
     * @return bool
     */
    private function isAudioModelId($id)
    {
        return strpos($id, 'whisper') !== false
            || strpos($id, 'speech') !== false
            || strpos($id, 'audio') !== false;
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
     * Default fallback list by model type.
     *
     * @param string $modelType
     * @return array
     */
    private function getDefaultModelListByType($modelType)
    {
        if ($modelType === 'audio') {
            return [
                ['id' => 'faster-whisper-large-v3', 'name' => 'Faster Whisper Large V3'],
                ['id' => 'whisper-large-v3', 'name' => 'Whisper Large V3'],
                ['id' => 'whisper-medium', 'name' => 'Whisper Medium'],
                ['id' => 'whisper-small', 'name' => 'Whisper Small']
            ];
        }

        return $this->getDefaultModelList()['data'];
    }

    /**
     * Execute a curl call to the LLM API with detailed error handling
     * 
     * Unlike BaseModel::execute_curl_call, this method provides:
     * - Actual curl error messages
     * - HTTP status codes
     * - Raw response even on failure (for debugging)
     * - Proper timeout handling
     * 
     * @param string $url API endpoint URL
     * @param array $headers HTTP headers
     * @param array $payload Request payload
     * @param int $timeout Request timeout in seconds
     * @return array ['error' => bool, 'response' => mixed, 'http_code' => int, 'error_message' => string, 'raw_response' => string]
     */
    private function executeLlmCurlCall($url, $headers, $payload, $timeout = 120)
    {
        $curl = curl_init();
        
        $curl_options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers
        ];
        
        // Skip SSL verification in debug mode (for local testing)
        if (defined('DEBUG') && DEBUG) {
            $curl_options[CURLOPT_SSL_VERIFYHOST] = false;
            $curl_options[CURLOPT_SSL_VERIFYPEER] = false;
        }
        
        curl_setopt_array($curl, $curl_options);
        
        $raw_response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        $curl_errno = curl_errno($curl);
        
        curl_close($curl);
        
        // Check for curl errors
        if ($curl_errno !== 0) {
            return [
                'error' => true,
                'response' => null,
                'http_code' => $http_code,
                'error_message' => "Curl error {$curl_errno}: {$curl_error}",
                'raw_response' => $raw_response
            ];
        }
        
        // Check for HTTP errors
        if ($http_code >= 400) {
            return [
                'error' => true,
                'response' => null,
                'http_code' => $http_code,
                'error_message' => "HTTP error {$http_code}",
                'raw_response' => $raw_response
            ];
        }
        
        // Try to decode JSON
        $decoded = json_decode($raw_response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'error' => true,
                'response' => null,
                'http_code' => $http_code,
                'error_message' => "Invalid JSON response: " . json_last_error_msg(),
                'raw_response' => $raw_response
            ];
        }
        
        // Success
        return [
            'error' => false,
            'response' => $decoded,
            'http_code' => $http_code,
            'error_message' => null,
            'raw_response' => $raw_response
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
     * @param array $log_options Required logging options (strict mode):
     *   - conversation_id (int, required)
     *   - sent_context (array|null, context to persist with assistant message)
     *   - is_validated (bool, default true)
     *   - assistant_content (string|null, optional override for logged content)
     * @return array Normalized response with 'request_payload' containing the full API request
     * @throws LlmApiException If API call fails
     * @throws InvalidArgumentException If strict logging options are missing
     */
    public function callLlmApi($messages, $model, $temperature = null, $max_tokens = null, $log_options = null)
    {
        if (!is_array($log_options) || empty($log_options['conversation_id'])) {
            throw new InvalidArgumentException(
                'callLlmApi strict mode requires log_options with conversation_id'
            );
        }

        $config = $this->getLlmConfig();

        // Resolve server from model identifier (handles "ServerName :: model" prefix)
        $resolved = $this->resolveModelServer($model);
        $server = $resolved['server'];
        $rawModel = $resolved['model'];

        // Use the provider for the resolved server
        $provider = $this->getProviderForServer($server);

        // Get API URL using provider
        $url = $provider->getApiUrl($server['base_url'], LLM_API_CHAT_COMPLETIONS);

        // Validate parameters using validator
        $temp_value = LlmValidator::temperature($temperature, $config['llm_temperature']);
        $max_tokens_value = LlmValidator::maxTokens($max_tokens, $config['llm_max_tokens']);

        // Convert messages for model compatibility (handles system role support)
        require_once __DIR__ . '/LlmModelCapabilities.php';
        $converted_messages = LlmModelCapabilities::convertMessagesForModel($messages, $rawModel);

        // Build standard payload
        $payload = [
            'model' => $rawModel,
            'messages' => $converted_messages,
            'temperature' => $temp_value,
            'max_tokens' => $max_tokens_value,
            'stream' => false
        ];

        // Merge provider-specific parameters
        $providerParams = $provider->getAdditionalRequestParams($payload);
        $payload = array_merge($payload, $providerParams);

        // Get authentication headers from provider
        $headers = $provider->getAuthHeaders($server['api_key']);

        // Make API request with detailed error handling
        $curl_result = $this->executeLlmCurlCall($url, $headers, $payload, $config['llm_timeout']);
        
        if ($curl_result['error']) {
            $error_msg = $curl_result['error_message'] ?? 'Unknown curl error';
            $http_code = $curl_result['http_code'] ?? 0;
            $raw_response = $curl_result['raw_response'] ?? null;
            
            // Log detailed error for debugging
            $this->logWarning('LLM API curl failed', [
                'error' => $error_msg,
                'http_code' => $http_code,
                'url' => $url,
                'raw_response_preview' => $raw_response ? substr($raw_response, 0, 500) : null
            ]);

            try {
                $this->addMessage(
                    (int)$log_options['conversation_id'],
                    'assistant',
                    '[API ERROR] ' . $error_msg,
                    null,
                    $model,
                    null,
                    ['error' => $error_msg, 'http_code' => $http_code, 'raw_response' => $raw_response],
                    $log_options['sent_context'] ?? null,
                    null,
                    false,
                    $payload
                );
            } catch (Exception $logException) {
                $this->logWarning('LLM API error auto-log failed', ['error' => $logException->getMessage()]);
            }
            
            if ($http_code >= 400) {
                throw LlmApiException::httpError($http_code, $raw_response, $payload);
            }
            throw LlmApiException::connectionFailed($url, $error_msg, $payload);
        }
        
        $response = $curl_result['response'];

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

        // Normalize response using resolved provider
        try {
            $normalized = $provider->normalizeResponse($response);
            // Include the full request payload in the response for debugging
            $normalized['request_payload'] = $payload;

            // Optional centralized assistant-message logging for consistency.
            $assistant_content = $log_options['assistant_content'] ?? ($normalized['content'] ?? null);
            if (!is_string($assistant_content) || trim($assistant_content) === '') {
                $assistant_content = '(empty response)';
            }
            $sent_context = $log_options['sent_context'] ?? null;
            $is_validated = array_key_exists('is_validated', $log_options)
                ? (bool)$log_options['is_validated']
                : true;

            try {
                $logged_message_id = $this->addAssistantMessageFromApiResponse(
                    (int)$log_options['conversation_id'],
                    trim((string)$assistant_content),
                    $model,
                    $sent_context,
                    $normalized,
                    $is_validated
                );
                $normalized['logged_message_id'] = $logged_message_id;
            } catch (Exception $logException) {
                $this->logWarning('LLM API auto-log failed', [
                    'error' => $logException->getMessage(),
                    'conversation_id' => $log_options['conversation_id']
                ]);
            }

            return $normalized;
        } catch (Exception $e) {
            try {
                $this->addMessage(
                    (int)$log_options['conversation_id'],
                    'assistant',
                    '[API ERROR] Provider normalization failed: ' . $e->getMessage(),
                    null,
                    $model,
                    null,
                    ['error' => $e->getMessage(), 'raw_response' => $response],
                    $log_options['sent_context'] ?? null,
                    null,
                    false,
                    $payload
                );
            } catch (Exception $logException) {
                $this->logWarning('LLM API normalization auto-log failed', ['error' => $logException->getMessage()]);
            }
            $this->logWarning('Provider normalization error', ['error' => $e->getMessage()]);
            throw LlmApiException::normalizationFailed($provider->getProviderName(), $e->getMessage(), $response, $payload);
        }
    }
}
?>
