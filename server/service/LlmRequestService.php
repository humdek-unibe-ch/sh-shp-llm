<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * LLM Request Service
 * 
 * Handles common request processing logic for the LLM chat component.
 * This service centralizes:
 * - User authentication validation
 * - Rate limiting
 * - Conversation management (create, get, validate)
 * - Message handling
 * 
 * The goal is to reduce code repetition in the controller by providing
 * a unified interface for common operations.
 */
class LlmRequestService
{
    private $llm_service;
    private $model;

    /**
     * Constructor
     * 
     * @param LlmService $llm_service The LLM service instance
     * @param LlmChatModel $model The model instance for configuration
     */
    public function __construct($llm_service, $model)
    {
        $this->llm_service = $llm_service;
        $this->model = $model;
    }

    /**
     * Validate that the user is authenticated
     * 
     * @return int|null User ID if authenticated, null otherwise
     */
    public function validateUser()
    {
        return $this->model->getUserId();
    }

    /**
     * Check rate limiting and return rate data
     * 
     * @param int $user_id The user ID
     * @return array Rate limit data
     * @throws Exception If rate limit exceeded
     */
    public function checkRateLimit($user_id)
    {
        return $this->llm_service->checkRateLimit($user_id);
    }

    /**
     * Update rate limiting after a request
     * 
     * @param int $user_id The user ID
     * @param array $rate_data The rate data from checkRateLimit
     * @param int $conversation_id The conversation ID
     */
    public function updateRateLimit($user_id, $rate_data, $conversation_id)
    {
        $this->llm_service->updateRateLimit($user_id, $rate_data, $conversation_id);
    }

    /**
     * Get or create a conversation for the user
     * 
     * Handles the logic of:
     * - Creating a new conversation if none exists
     * - Checking concurrent conversation limits
     * - Validating existing conversation ownership
     * - Creating new conversation if model changed
     * 
     * @param int $user_id The user ID
     * @param string|null $conversation_id Existing conversation ID (optional)
     * @param array $rate_data Rate limit data
     * @param int $section_id The section ID
     * @return array ['conversation_id' => string, 'is_new' => bool]
     * @throws Exception If conversation not found or limit exceeded
     */
    public function getOrCreateConversation($user_id, $conversation_id, $rate_data, $section_id)
    {
        $model = $this->model->getConfiguredModel();
        $temperature = $this->model->getLlmTemperature();
        $max_tokens = $this->model->getLlmMaxTokens();
        $is_new = false;

        if (!$conversation_id) {
            // Check concurrent conversation limit
            $this->validateConcurrentConversationLimit($rate_data);
            
            // Create new conversation
            $conversation_id = $this->llm_service->getOrCreateConversationForModel(
                $user_id,
                $model,
                $temperature,
                $max_tokens,
                $section_id
            );
            $is_new = true;
        } else {
            // Validate existing conversation
            $existing = $this->llm_service->getConversation($conversation_id, $user_id, $section_id);
            if (!$existing) {
                throw new Exception('Conversation not found');
            }

            // Check if model changed - create new conversation
            if ($existing['model'] !== $model) {
                $this->validateConcurrentConversationLimit($rate_data);
                
                $conversation_id = $this->llm_service->getOrCreateConversationForModel(
                    $user_id,
                    $model,
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
     * Validate concurrent conversation limit
     * 
     * @param array $rate_data Rate limit data
     * @throws Exception If limit exceeded
     */
    private function validateConcurrentConversationLimit($rate_data)
    {
        if (count($rate_data['conversations']) >= LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS) {
            throw new Exception(
                'Concurrent conversation limit exceeded: ' . 
                LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS . ' conversations'
            );
        }
    }

    /**
     * Update conversation title for new conversations
     * 
     * @param int $conversation_id The conversation ID
     * @param int $user_id The user ID
     * @param string $first_message The first message content (used to generate title)
     */
    public function updateNewConversationTitle($conversation_id, $user_id, $first_message)
    {
        $title = $this->generateConversationTitle($first_message);
        $this->llm_service->updateConversation($conversation_id, $user_id, ['title' => $title]);
    }

    /**
     * Generate a conversation title based on the first message
     * 
     * @param string $message The message content
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
     * Add a user message to the conversation
     * 
     * @param int $conversation_id The conversation ID
     * @param string $content Message content
     * @param array|string|null $attachments File attachments (array, JSON string, or null)
     * @return int Message ID
     */
    public function addUserMessage($conversation_id, $content, $attachments = null)
    {
        $model = $this->model->getConfiguredModel();
        return $this->llm_service->addMessage(
            $conversation_id,
            'user',
            $content,
            $attachments,
            $model
        );
    }

    /**
     * Add an assistant message to the conversation
     * 
     * @param int $conversation_id The conversation ID
     * @param string $content Message content
     * @param int|null $tokens_used Tokens used
     * @param array|null $raw_response Raw API response
     * @param array|null $context_messages Context messages sent
     * @param string|null $reasoning Optional reasoning content from LLM
     * @param bool $is_validated Whether the response passed schema validation (default: true)
     * @param array|null $request_payload The request payload sent to LLM API (for debugging)
     * @return int Message ID
     */
    public function addAssistantMessage($conversation_id, $content, $tokens_used = null, $raw_response = null, $context_messages = null, $reasoning = null, $is_validated = true, $request_payload = null)
    {
        $model = $this->model->getConfiguredModel();
        return $this->llm_service->addMessage(
            $conversation_id,
            'assistant',
            trim($content),
            null,
            $model,
            $tokens_used,
            $raw_response,
            $context_messages,
            $reasoning,
            $is_validated,
            $request_payload
        );
    }

    /**
     * Get conversation messages for API call
     * 
     * @param int $conversation_id The conversation ID
     * @param int $limit Message limit
     * @return array Messages
     */
    public function getConversationMessages($conversation_id, $limit = 50)
    {
        return $this->llm_service->getConversationMessages($conversation_id, $limit);
    }

    /**
     * Get a conversation by ID
     * 
     * @param int $conversation_id The conversation ID
     * @param int $user_id The user ID
     * @param int|null $section_id The section ID (optional)
     * @return array|null Conversation data
     */
    public function getConversation($conversation_id, $user_id, $section_id = null)
    {
        return $this->llm_service->getConversation($conversation_id, $user_id, $section_id);
    }

    /**
     * Get user conversations filtered by section
     * 
     * @param int $user_id The user ID
     * @param int $limit Conversation limit
     * @param int|null $section_id The section ID
     * @return array Conversations
     */
    public function getUserConversations($user_id, $limit, $section_id = null)
    {
        $model = $this->model->getConfiguredModel();
        return $this->llm_service->getUserConversations($user_id, $limit, $model, $section_id);
    }

    /**
     * Create a new conversation
     * 
     * @param int $user_id The user ID
     * @param string $title Conversation title
     * @param int $section_id The section ID
     * @return int Conversation ID
     */
    public function createConversation($user_id, $title, $section_id)
    {
        $model = $this->model->getConfiguredModel();
        return $this->llm_service->createConversation(
            $user_id,
            $title,
            $model,
            null,
            null,
            $section_id
        );
    }

    /**
     * Delete a conversation
     * 
     * @param int $conversation_id The conversation ID
     * @param int $user_id The user ID
     */
    public function deleteConversation($conversation_id, $user_id)
    {
        $this->llm_service->deleteConversation($conversation_id, $user_id);
    }

    /**
     * Update a message
     * 
     * @param int $message_id The message ID
     * @param array $data Data to update
     */
    public function updateMessage($message_id, $data)
    {
        $this->llm_service->updateMessage($message_id, $data);
    }

    /**
     * Call LLM API
     * 
     * @param array $api_messages Messages in API format
     * @return array API response
     */
    public function callLlmApi($api_messages)
    {
        $model = $this->model->getConfiguredModel();
        $temperature = $this->model->getLlmTemperature();
        $max_tokens = $this->model->getLlmMaxTokens();
        
        return $this->llm_service->callLlmApi($api_messages, $model, $temperature, $max_tokens);
    }

    /**
     * Get the underlying LLM service
     * 
     * @return LlmService
     */
    public function getLlmService()
    {
        return $this->llm_service;
    }
}
?>

