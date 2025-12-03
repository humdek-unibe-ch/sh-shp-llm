<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/BaseController.php";

/**
 * The controller class for the LLM chat component.
 * Handles form submissions and user interactions.
 */
class LlmchatController extends BaseController
{
    private $llm_service;

    /* Constructors ***********************************************************/

    /**
     * The constructor.
     *
     * @param object $model
     *  The model instance of the component.
     */
    public function __construct($model)
    {
        parent::__construct($model);
        $this->llm_service = new LlmService($this->model->get_services());

        $router = $model->get_services()->get_router();
        if(is_array($router->route['params']) && isset($router->route['params']['data'])){
            // Handle data requests immediately without normal initialization
            $model->return_data($router->route['params']['data']);
            return; // Exit constructor cleanly
        }

        // Handle different request types based on parameters
        $this->handleRequest();
    }

    /**
     * Handle incoming requests based on POST/GET parameters
     */
    private function handleRequest()
    {
        // Check for streaming request first
        if (isset($_GET['streaming']) && $_GET['streaming'] === '1') {
            $this->handleStreamingRequest();
            return;
        }

        // Check for AJAX-like parameters that were previously handled by AjaxLlmChat
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';

            switch ($action) {
                case 'send_message':
                    $this->handleMessageSubmission();
                    break;
                case 'new_conversation':
                    $this->handleNewConversation();
                    break;
                case 'delete_conversation':
                    $this->handleDeleteConversation();
                    break;
                default:
                    // Regular form submission for message sending
                    if (isset($_POST['message'])) {
                        $this->handleMessageSubmission();
                    }
                    break;
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
            // Handle GET requests for conversation data
            $action = $_GET['action'] ?? '';

            switch ($action) {
                case 'get_conversation':
                    $this->getConversationData();
                    break;
                case 'get_conversations':
                    $this->getConversationsData();
                    break;
                default:
                    // Regular page load - continue with normal rendering
                    break;
            }
        }
    }

    /**
     * Handle streaming request
     * Optimized for smooth, fluid streaming delivery
     */
    private function handleStreamingRequest()
    {
        // Set SSE headers for optimal streaming
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable nginx buffering

        // Clear any output buffers for immediate delivery
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Disable output buffering at PHP level
        @ini_set('output_buffering', 'Off');
        @ini_set('zlib.output_compression', 'Off');
        ob_implicit_flush(true);

        // Get conversation ID from URL parameter
        $conversation_id = $_GET['conversation'] ?? null;

        if (!$conversation_id) {
            $this->sendSSE(['type' => 'error', 'message' => 'Conversation ID required']);
            exit;
        }

        $user_id = $this->model->getUserId();
        if (!$user_id) {
            echo "data: " . json_encode(['type' => 'error', 'message' => 'Authentication required']) . "\n\n";
            flush();
            exit;
        }

        // Verify conversation exists and belongs to user
        $conversation = $this->llm_service->getConversation($conversation_id, $user_id);
        if (!$conversation) {
            echo "data: " . json_encode(['type' => 'error', 'message' => 'Conversation not found']) . "\n\n";
            flush();
            exit;
        }


        // Get conversation messages for LLM
        $messages = $this->llm_service->getConversationMessages($conversation_id, 50);
        $api_messages = $this->convertToApiFormat($messages);

        // Send connected event immediately
        $this->sendSSE(['type' => 'connected', 'conversation_id' => $conversation_id]);

        $full_response = '';
        $tokens_used = 0;

        try {
            // Start real streaming with callback
            $this->llm_service->streamLlmResponse(
                $api_messages,
                $conversation['model'],
                $conversation['temperature'],
                $conversation['max_tokens'],
                function($chunk) use (&$full_response, &$tokens_used, $conversation_id, $conversation) {
                    if ($chunk === '[DONE]') {
                        // Streaming completed
                        $this->sendSSE(['type' => 'done', 'tokens_used' => $tokens_used]);

                        // Save the complete assistant message
                        try {
                            $this->llm_service->addMessage(
                                $conversation_id,
                                'assistant',
                                $full_response,
                                null,
                                $conversation['model'],
                                $tokens_used
                            );
                        } catch (Exception $e) {
                            $this->sendSSE(['type' => 'error', 'message' => 'Failed to save message']);
                        }

                        return;
                    }

                    // Check for usage data
                    if (strpos($chunk, '[USAGE:') === 0) {
                        $usage_str = substr($chunk, 7, -1); // Remove '[USAGE:' and ']'
                        $tokens_used = intval($usage_str);
                        return;
                    }

                    // Accumulate the response
                    $full_response .= $chunk;

                    // Send chunk to client
                    $this->sendSSE(['type' => 'chunk', 'content' => $chunk]);
                }
            );
        } catch (Exception $e) {
            error_log('Streaming failed: ' . $e->getMessage());
            $this->sendSSE(['type' => 'error', 'message' => $e->getMessage()]);
        }

        exit;
    }

    /* Private Methods *********************************************************/

    /**
     * Handle message submission
     */
    private function handleMessageSubmission()
    {
        $user_id = $this->model->getUserId();
        $conversation_id = $_POST['conversation_id'] ?? null;
        $message = trim($_POST['message'] ?? '');
        $model = $_POST['model'] ?? $this->model->getConfiguredModel();

        if (empty($message)) {
            $this->sendJsonResponse(['error' => 'Message cannot be empty'], 400);
            return;
        }

        try {
            // Check if this is a streaming preparation request
            $is_streaming_prep = isset($_POST['prepare_streaming']) && $_POST['prepare_streaming'] === '1';

            if ($is_streaming_prep) {
                // For streaming preparation, we still need to save the user message
                // and perform all the same setup as regular message submission

                // Check rate limiting and get current rate data
                $rate_data = $this->llm_service->checkRateLimit($user_id);

                // Create conversation if needed
                $is_new_conversation = false;
                if (!$conversation_id) {
                    // For new conversations, check if we can add one more concurrent conversation
                    if (count($rate_data['conversations']) >= LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS) {
                        throw new Exception('Concurrent conversation limit exceeded: ' . LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS . ' conversations');
                    }
                    $conversation_id = $this->llm_service->createConversation($user_id, null, $model);
                    $is_new_conversation = true;
                }

                // Generate title for new conversations based on the first message
                if ($is_new_conversation) {
                    $generated_title = $this->generateConversationTitle($message);
                    $this->llm_service->updateConversation($conversation_id, $user_id, ['title' => $generated_title]);
                }

                // Save user message - THIS IS THE CRITICAL PART THAT WAS MISSING
                $this->llm_service->addMessage($conversation_id, 'user', $message, null, $model);

                // Update rate limiting with the current rate data
                $this->llm_service->updateRateLimit($user_id, $rate_data, $conversation_id);

                // Store message data in session for streaming (optional, but keep for compatibility)
                $_SESSION['streaming_conversation_id'] = $conversation_id;
                $_SESSION['streaming_message'] = $message;
                $_SESSION['streaming_model'] = $model;

                $this->sendJsonResponse([
                    'status' => 'prepared',
                    'conversation_id' => $conversation_id,
                    'is_new_conversation' => $is_new_conversation
                ]);
                return;
            }

            // Check rate limiting and get current rate data
            $rate_data = $this->llm_service->checkRateLimit($user_id);

            // Create conversation if needed
            $is_new_conversation = false;
            if (!$conversation_id) {
                // For new conversations, check if we can add one more concurrent conversation
                if (count($rate_data['conversations']) >= LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS) {
                    throw new Exception('Concurrent conversation limit exceeded: ' . LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS . ' conversations');
                }
                $conversation_id = $this->llm_service->createConversation($user_id, null, $model);
                $is_new_conversation = true;
            }

            // Generate title for new conversations based on the first message
            if ($is_new_conversation) {
                $generated_title = $this->generateConversationTitle($message);
                $this->llm_service->updateConversation($conversation_id, $user_id, ['title' => $generated_title]);
            }

            // Save user message
            $this->llm_service->addMessage($conversation_id, 'user', $message, null, $model);

            // Update rate limiting with the current rate data
            $this->llm_service->updateRateLimit($user_id, $rate_data, $conversation_id);

            // Get conversation messages for LLM
            $messages = $this->llm_service->getConversationMessages($conversation_id, 50);
            $api_messages = $this->convertToApiFormat($messages);

            // Call LLM API - check if streaming is requested (via GET param or config)
            $streaming_requested = isset($_GET['streaming']) && $_GET['streaming'] === '1';
            $streaming_enabled = $this->model->isStreamingEnabled();

            if ($streaming_requested || $streaming_enabled) {
                // Start streaming response
                $this->startStreamingResponse($conversation_id, $api_messages, $model, $is_new_conversation);
                // This should exit, so no more code should run
                return;
            } else {
                // Get complete response
                $response = $this->llm_service->callLlmApi($api_messages, $model);

                if (is_array($response) && isset($response['choices'][0]['message']['content'])) {
                    $assistant_message = $response['choices'][0]['message']['content'];
                    $tokens_used = $response['usage']['total_tokens'] ?? null;

                    // Save assistant message with full response for debugging
                    $this->llm_service->addMessage($conversation_id, 'assistant', $assistant_message, null, $model, $tokens_used, json_encode($response));

                    $this->sendJsonResponse([
                        'conversation_id' => $conversation_id,
                        'message' => $assistant_message,
                        'streaming' => false,
                        'is_new_conversation' => $is_new_conversation
                    ]);
                } else {
                    throw new Exception('Invalid response from LLM API');
                }
            }

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle conversation creation
     */
    private function handleNewConversation()
    {
        $user_id = $this->model->getUserId();
        $title = trim($_POST['title'] ?? 'New Conversation');
        $model = $_POST['model'] ?? $this->model->getConfiguredModel();

        try {
            // Check rate limiting before creating new conversation
            $rate_data = $this->llm_service->checkRateLimit($user_id);

            $conversation_id = $this->llm_service->createConversation($user_id, $title, $model);

            // Update rate limiting to include the new conversation
            $this->llm_service->updateRateLimit($user_id, $rate_data, $conversation_id);

            $this->sendJsonResponse(['conversation_id' => $conversation_id]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle conversation deletion
     */
    private function handleDeleteConversation()
    {
        $user_id = $this->model->getUserId();
        $conversation_id = $_POST['conversation_id'] ?? null;

        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID required'], 400);
            return;
        }

        try {
            $this->llm_service->deleteConversation($conversation_id, $user_id);
            $this->sendJsonResponse(['success' => true]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Convert messages to OpenAI API format
     */
    private function convertToApiFormat($messages)
    {
        $api_messages = [];

        foreach ($messages as $message) {
            $api_message = [
                'role' => $message['role'],
                'content' => $message['content']
            ];

            // Handle images for vision models
            if (!empty($message['image_path'])) {
                $api_message['content'] = [
                    [
                        'type' => 'text',
                        'text' => $message['content']
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => '?file_path=' . $message['image_path']
                        ]
                    ]
                ];
            }

            $api_messages[] = $api_message;
        }

        return $api_messages;
    }

    /**
     * Generate a conversation title based on the first message
     */
    private function generateConversationTitle($message)
    {
        // Clean the message and extract the first meaningful part
        $clean_message = trim($message);

        // Remove trailing punctuation
        $clean_message = preg_replace('/[?!.,;:]+$/', '', $clean_message);

        // Get first 8 words
        $words = explode(' ', $clean_message);
        $title_words = array_slice($words, 0, 8);
        $title = implode(' ', $title_words);

        // Capitalize first letter
        $title = ucfirst($title);

        // If title is too long, try to find a natural break point
        if (strlen($title) > 50) {
            // Try to cut at word boundaries, preferring to keep complete thoughts
            $shortened = substr($title, 0, 47);
            $last_space = strrpos($shortened, ' ');
            if ($last_space !== false) {
                $title = substr($shortened, 0, $last_space) . '...';
            } else {
                $title = substr($title, 0, 47) . '...';
            }
        }

        // Fallback if title is too short or empty
        if (strlen($title) < 3) {
            $title = 'New Conversation';
        }

        return $title;
    }

    /**
     * Start streaming response using Server-Sent Events
     * Optimized for smooth, fluid streaming delivery
     */
    private function startStreamingResponse($conversation_id, $messages, $model, $is_new_conversation)
    {
        // Check if any content has already been sent
        if (headers_sent()) {
            error_log('Headers already sent, cannot start streaming');
            return;
        }

        // Set SSE headers for optimal streaming
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable nginx buffering
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Cache-Control');

        // Disable all output buffering for immediate delivery
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Disable output buffering and compression at PHP level
        @ini_set('output_buffering', 'Off');
        @ini_set('zlib.output_compression', 'Off');
        @ini_set('implicit_flush', 1);
        ob_implicit_flush(true);

        // Send initial connection event
        $this->sendSSE(['type' => 'connected', 'conversation_id' => $conversation_id]);

        $full_response = '';
        $tokens_used = 0;

        try {
            // Start streaming with callback
            $this->llm_service->streamLlmResponse(
                $messages,
                $model,
                $this->model->getLlmTemperature(),
                $this->model->getLlmMaxTokens(),
                function($chunk) use (&$full_response, &$tokens_used, $conversation_id, $model) {
                    if ($chunk === '[DONE]') {
                        // Streaming completed
                        $this->sendSSE(['type' => 'done', 'tokens_used' => $tokens_used]);

                        // Save the complete assistant message
                        try {
                            $message_id = $this->llm_service->addMessage(
                                $conversation_id,
                                'assistant',
                                $full_response,
                                null,
                                $model,
                                $tokens_used
                            );
                            error_log("Streaming completed. Saved message ID: $message_id for conversation: $conversation_id");
                        } catch (Exception $e) {
                            error_log('Failed to save streamed message: ' . $e->getMessage());
                            $this->sendSSE(['type' => 'error', 'message' => 'Failed to save message: ' . $e->getMessage()]);
                        }

                        // Close the connection
                        $this->sendSSE(['type' => 'close']);
                        return;
                    }

                    // Check for usage data
                    if (strpos($chunk, '[USAGE:') === 0) {
                        $usage_str = substr($chunk, 7, -1); // Remove '[USAGE:' and ']'
                        $tokens_used = intval($usage_str);
                        return;
                    }

                    // Accumulate the response
                    $full_response .= $chunk;

                    // Send chunk to client
                    $this->sendSSE([
                        'type' => 'chunk',
                        'content' => $chunk
                    ]);
                }
            );
        } catch (Exception $e) {
            error_log('Streaming failed: ' . $e->getMessage());
            $this->sendSSE(['type' => 'error', 'message' => $e->getMessage()]);
        }

        // Ensure connection is closed
        if (function_exists('uopz_allow_exit')) {
            uopz_allow_exit(true);
        }
        exit;
    }

    /**
     * Send Server-Sent Event
     * Optimized for smooth, low-latency delivery
     */
    private function sendSSE($data)
    {
        // Use JSON encoding with minimal whitespace for faster transmission
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

        // Flush immediately for real-time delivery
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();

        // Small delay to prevent overwhelming the client on rapid chunks
        // This helps with smooth rendering on the frontend
        if (isset($data['type']) && $data['type'] === 'chunk') {
            usleep(5000); // 5ms delay between chunks for smoother rendering
        }
    }

    /**
     * Send JSON response
     */
    private function sendJsonResponse($data, $status_code = 200)
    {
        // If headers have already been sent (e.g., for streaming), just send JSON
        if (!headers_sent()) {
            http_response_code($status_code);
            header('Content-Type: application/json');
        }
        echo json_encode($data);
        if (function_exists('uopz_allow_exit')) {
            uopz_allow_exit(true);
        }
        exit;
    }

    /* Public Methods *********************************************************/

    /**
     * Handle form submission
     */
    public function handleSubmission()
    {
        // Check if user is logged in
        if (!$this->model->getUserId()) {
            $this->sendJsonResponse(['error' => 'Authentication required'], 401);
            return;
        }

        $action = $_POST['action'] ?? 'send_message';

        switch ($action) {
            case 'send_message':
                $this->handleMessageSubmission();
                break;
            case 'new_conversation':
                $this->handleNewConversation();
                break;
            case 'delete_conversation':
                $this->handleDeleteConversation();
                break;
            default:
                $this->sendJsonResponse(['error' => 'Unknown action'], 400);
        }
    }

    /**
     * Get conversation data (AJAX)
     */
    public function getConversationData()
    {
        $conversation_id = $_GET['conversation_id'] ?? null;

        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID required'], 400);
            return;
        }

        try {
            $conversation = $this->llm_service->getConversation($conversation_id, $this->model->getUserId());

            if (!$conversation) {
                $this->sendJsonResponse(['error' => 'Conversation not found'], 404);
                return;
            }

            $messages = $this->llm_service->getConversationMessages($conversation_id) ?: [];

            // Format message content with markdown parsing
            foreach ($messages as &$message) {
                $message['formatted_content'] = $this->model->formatMessageContent($message['content']);
            }

            $this->sendJsonResponse([
                'conversation' => $conversation,
                'messages' => $messages
            ]);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get conversations list data
     */
    public function getConversationsData()
    {
        try {
            $conversations = $this->llm_service->getUserConversations($this->model->getUserId(), 50, $this->model->get_db_field('llm_default_model'));
            $this->sendJsonResponse(['conversations' => $conversations]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
?>
