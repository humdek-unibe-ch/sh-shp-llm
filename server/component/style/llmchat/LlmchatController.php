<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/BaseController.php";
require_once __DIR__ . "/../../../service/LlmFileUploadService.php";
require_once __DIR__ . "/../../../service/LlmApiFormatterService.php";
require_once __DIR__ . "/../../../service/LlmStreamingService.php";

/**
 * The controller class for the LLM chat component.
 * Handles form submissions and user interactions.
 */
class LlmchatController extends BaseController
{
    private $llm_service;
    private $file_upload_service;
    private $api_formatter_service;
    private $streaming_service;

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
        $this->file_upload_service = new LlmFileUploadService($this->llm_service);
        $this->api_formatter_service = new LlmApiFormatterService();
        $this->streaming_service = new LlmStreamingService($this->llm_service);

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

        // Verify conversation exists and belongs to user
        $conversation = $this->llm_service->getConversation($conversation_id, $user_id);
        if (!$conversation) {
            echo "data: " . json_encode(['type' => 'error', 'message' => 'Conversation not found']) . "\n\n";
            flush();
            exit;
        }

        // Get model and parameters from conversation or defaults
        $model = $conversation['model'] ?? $this->model->getConfiguredModel();
        
        // Get and validate temperature (must be between 0.0 and 2.0)
        $temperature = $conversation['temperature'] ?? $this->model->getLlmTemperature();
        $temperature = (float)$temperature;
        if ($temperature < 0.0 || $temperature > 2.0) {
            $temperature = 0.7;
        }
        
        // Get and validate max_tokens
        $max_tokens = $conversation['max_tokens'] ?? $this->model->getLlmMaxTokens();
        $max_tokens = (int)$max_tokens;
        if ($max_tokens < 1 || $max_tokens > 16384) {
            $max_tokens = 2048;
        }

        // Get conversation messages for LLM
        $messages = $this->llm_service->getConversationMessages($conversation_id, 50);
        
        error_log("Streaming: Retrieved " . count($messages) . " messages for conversation {$conversation_id}");
        foreach ($messages as $idx => $msg) {
            $hasAttach = !empty($msg['attachments']) ? 'yes' : 'no';
            $attachPreview = !empty($msg['attachments']) ? substr($msg['attachments'], 0, 200) : 'none';
            error_log("Streaming: Message {$idx} - role={$msg['role']}, has_attachments={$hasAttach}, attachments_preview={$attachPreview}");
        }
        
        if (empty($messages)) {
            $this->sendSSE(['type' => 'error', 'message' => 'No messages found in conversation']);
            exit;
        }
        
        $api_messages = $this->api_formatter_service->convertToApiFormat($messages, $model);
        
        // Validate we have at least one user message
        if (empty($api_messages)) {
            $this->sendSSE(['type' => 'error', 'message' => 'No valid messages to send']);
            exit;
        }

        // Send connected event immediately
        $this->sendSSE(['type' => 'connected', 'conversation_id' => $conversation_id]);

        $full_response = '';
        $tokens_used = 0;
        $chunk_count = 0;
        $streaming_message_id = null;
        $last_save_length = 0;
        
        // Constants for partial saves
        $save_interval = 10; // Save every 10 chunks
        $min_chars_before_save = 50; // Minimum chars before first save

        try {
            // Start streaming with callback
            $this->llm_service->streamLlmResponse(
                $api_messages,
                $model,
                $temperature,
                $max_tokens,
                function($chunk) use (&$full_response, &$tokens_used, &$chunk_count, &$streaming_message_id, &$last_save_length, $conversation_id, $conversation, $save_interval, $min_chars_before_save) {
                    if ($chunk === '[DONE]') {
                        // Streaming completed - finalize the message
                        $this->sendSSE(['type' => 'done', 'tokens_used' => $tokens_used]);

                        try {
                            if ($streaming_message_id) {
                                // Update existing streaming message to mark as complete
                                $this->llm_service->updateStreamingMessage(
                                    $streaming_message_id,
                                    $full_response,
                                    $tokens_used,
                                    false // is_streaming = false (complete)
                                );
                            } else {
                                // Create new complete message (fallback if no partial saves occurred)
                                $this->llm_service->addMessage(
                                    $conversation_id,
                                    'assistant',
                                    $full_response,
                                    null,
                                    $conversation['model'],
                                    $tokens_used
                                );
                            }
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
                    $chunk_count++;

                    // Send chunk to client
                    $this->sendSSE(['type' => 'chunk', 'content' => $chunk]);

                    // Periodic partial save to prevent data loss
                    $should_save = ($chunk_count % $save_interval === 0) 
                                   && (strlen($full_response) >= $min_chars_before_save)
                                   && (strlen($full_response) > $last_save_length + 20);
                    
                    if ($should_save) {
                        try {
                            if ($streaming_message_id) {
                                $this->llm_service->updateStreamingMessage(
                                    $streaming_message_id,
                                    $full_response,
                                    null,
                                    true
                                );
                            } else {
                                $streaming_message_id = $this->llm_service->addStreamingMessage(
                                    $conversation_id,
                                    $full_response,
                                    $conversation['model']
                                );
                            }
                            $last_save_length = strlen($full_response);
                        } catch (Exception $e) {
                            // Log but don't interrupt streaming
                            error_log('Partial save failed: ' . $e->getMessage());
                        }
                    }
                }
            );
        } catch (Exception $e) {
            // Try to save partial response on error
            if (!empty($full_response)) {
                try {
                    if ($streaming_message_id) {
                        $this->llm_service->updateStreamingMessage(
                            $streaming_message_id,
                            $full_response . "\n\n[Streaming interrupted: " . $e->getMessage() . "]",
                            $tokens_used,
                            false
                        );
                    } else {
                        $this->llm_service->addMessage(
                            $conversation_id,
                            'assistant',
                            $full_response . "\n\n[Streaming interrupted: " . $e->getMessage() . "]",
                            null,
                            $conversation['model'],
                            $tokens_used
                        );
                    }
                } catch (Exception $saveError) {
                    // Silently ignore save errors
                }
            } else {
                // No response received - create a placeholder message to prevent empty messages
                try {
                    $this->llm_service->addMessage(
                        $conversation_id,
                        'assistant',
                        "[Error: " . $e->getMessage() . "]",
                        null,
                        $conversation['model'],
                        0
                    );
                } catch (Exception $saveError) {
                    // Silently ignore save errors
                }
            }
            
            $this->sendSSE(['type' => 'error', 'message' => $e->getMessage()]);
        }

        exit;
    }

    /**
     * Handle message submission
     */
    private function handleMessageSubmission()
    {
        $user_id = $this->model->getUserId();
        $conversation_id = $_POST['conversation_id'] ?? null;
        $message = trim($_POST['message'] ?? '');
        $model = $_POST['model'] ?? $this->model->getConfiguredModel();
        $temperature = $_POST['temperature'] ?? $this->model->getLlmTemperature();
        $max_tokens = $_POST['max_tokens'] ?? $this->model->getLlmMaxTokens();

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
                    // Get or create conversation for the specific model
                    $conversation_id = $this->llm_service->getOrCreateConversationForModel($user_id, $model, $temperature, $max_tokens);
                    $is_new_conversation = true;
                } else {
                    // Check if existing conversation matches the requested model
                    $existing_conversation = $this->llm_service->getConversation($conversation_id, $user_id);
                    if (!$existing_conversation) {
                        throw new Exception('Conversation not found');
                    }

                    // If model changed, create new conversation for the new model
                    if ($existing_conversation['model'] !== $model) {
                        // For new conversations, check if we can add one more concurrent conversation
                        if (count($rate_data['conversations']) >= LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS) {
                            throw new Exception('Concurrent conversation limit exceeded: ' . LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS . ' conversations');
                        }
                        // Create new conversation for the new model
                        $conversation_id = $this->llm_service->getOrCreateConversationForModel($user_id, $model, $temperature, $max_tokens);
                        $is_new_conversation = true;
                    }
                }

                // Generate title for new conversations based on the first message
                if ($is_new_conversation) {
                    $generated_title = $this->generateConversationTitle($message);
                    $this->llm_service->updateConversation($conversation_id, $user_id, ['title' => $generated_title]);
                }

                // Handle file uploads if any
                error_log('About to handle file uploads for streaming conversation: ' . $conversation_id);
                $uploadedFiles = $this->file_upload_service->handleFileUploads($conversation_id);
                error_log('Streaming file upload result: ' . json_encode($uploadedFiles));

                // Save user message - THIS IS THE CRITICAL PART THAT WAS MISSING
                $messageId = $this->llm_service->addMessage($conversation_id, 'user', $message, $uploadedFiles, $model);

                // Update rate limiting with the current rate data
                $this->llm_service->updateRateLimit($user_id, $rate_data, $conversation_id);

                // Get the saved message data for response
                $savedMessage = [
                    'id' => $messageId,
                    'role' => 'user',
                    'content' => $message,
                    'attachments' => $uploadedFiles,
                    'model' => $model,
                    'created_at' => date('Y-m-d H:i:s')
                ];

                // Store message data in session for streaming (optional, but keep for compatibility)
                $_SESSION['streaming_conversation_id'] = $conversation_id;
                $_SESSION['streaming_message'] = $message;
                $_SESSION['streaming_model'] = $model;

                $this->sendJsonResponse([
                    'status' => 'prepared',
                    'conversation_id' => $conversation_id,
                    'is_new_conversation' => $is_new_conversation,
                    'user_message' => $savedMessage
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
                // Get or create conversation for the specific model
                $conversation_id = $this->llm_service->getOrCreateConversationForModel($user_id, $model, $temperature, $max_tokens);
                $is_new_conversation = true;
            } else {
                // Check if existing conversation matches the requested model
                $existing_conversation = $this->llm_service->getConversation($conversation_id, $user_id);
                if (!$existing_conversation) {
                    throw new Exception('Conversation not found');
                }

                // If model changed, create new conversation for the new model
                if ($existing_conversation['model'] !== $model) {
                    // For new conversations, check if we can add one more concurrent conversation
                    if (count($rate_data['conversations']) >= LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS) {
                        throw new Exception('Concurrent conversation limit exceeded: ' . LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS . ' conversations');
                    }
                    // Create new conversation for the new model
                    $conversation_id = $this->llm_service->getOrCreateConversationForModel($user_id, $model, $temperature, $max_tokens);
                    $is_new_conversation = true;
                }
            }

            // Generate title for new conversations based on the first message
            if ($is_new_conversation) {
                $generated_title = $this->generateConversationTitle($message);
                $this->llm_service->updateConversation($conversation_id, $user_id, ['title' => $generated_title]);
            }

            // Handle file uploads if any
            error_log('About to handle file uploads for conversation: ' . $conversation_id);
            $uploadedFiles = $this->file_upload_service->handleFileUploads($conversation_id);
            error_log('File upload result: ' . json_encode($uploadedFiles));

            // Save user message with file attachments
            $messageId = $this->llm_service->addMessage($conversation_id, 'user', $message, $uploadedFiles, $model);

            // If files were uploaded, rename them to include the message ID
            if ($uploadedFiles && $messageId) {
                $this->file_upload_service->updateFileNamesWithMessageId($conversation_id, $messageId, $uploadedFiles);
            }

            // Update rate limiting with the current rate data
            $this->llm_service->updateRateLimit($user_id, $rate_data, $conversation_id);

            // Get conversation messages for LLM
            $messages = $this->llm_service->getConversationMessages($conversation_id, 50);
            $api_messages = $this->api_formatter_service->convertToApiFormat($messages, $model);

            // Call LLM API - check if streaming is enabled in the style configuration (DB field)
            $streaming_enabled = $this->model->isStreamingEnabled();

            if ($streaming_enabled) {
                // Start streaming response
                $this->streaming_service->startStreamingResponse($conversation_id, $api_messages, $model, $is_new_conversation);
                // This should exit, so no more code should run
                return;
            } else {
                // Get complete response
                $response = $this->llm_service->callLlmApi($api_messages, $model, $temperature, $max_tokens);

                // Check for API error responses first
                if (is_array($response) && isset($response['error'])) {
                    // Extract error message from various possible structures
                    $error_message = 'LLM API error';
                    if (is_array($response['error'])) {
                        if (isset($response['error']['message'])) {
                            $error_message = $response['error']['message'];
                        } elseif (isset($response['error']['type'])) {
                            $error_message = 'LLM API error: ' . $response['error']['type'];
                        }
                    } elseif (is_string($response['error'])) {
                        $error_message = $response['error'];
                    }
                    
                    // Log the full error for debugging
                    error_log('LLM API error response: ' . json_encode($response));
                    throw new Exception($error_message);
                }

                // Check for valid success response
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
                    // Log the unexpected response for debugging
                    error_log('Unexpected LLM API response format: ' . json_encode($response));
                    
                    // Try to extract any message from the response
                    $error_detail = '';
                    if (is_array($response)) {
                        if (isset($response['message'])) {
                            $error_detail = ': ' . $response['message'];
                        } elseif (isset($response['detail'])) {
                            $error_detail = ': ' . (is_string($response['detail']) ? $response['detail'] : json_encode($response['detail']));
                        }
                    } elseif (is_string($response)) {
                        $error_detail = ': ' . substr($response, 0, 200); // Limit length
                    }
                    
                    throw new Exception('Invalid response from LLM API' . $error_detail);
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

        // Check if conversations list is enabled
        if (!$this->model->isConversationsListEnabled()) {
            $this->sendJsonResponse(['error' => 'Creating new conversations is not allowed when conversations list is disabled'], 403);
            return;
        }

        $title = trim($_POST['title'] ?? 'New Conversation');
        $model = $_POST['model'] ?? $this->model->getConfiguredModel();

        try {
            // Check rate limiting before creating new conversation
            $rate_data = $this->llm_service->checkRateLimit($user_id);

            $conversation_id = $this->llm_service->createConversation($user_id, $title, $model, $this->model->getSectionId());

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
            $conversations = $this->llm_service->getUserConversations($this->model->getUserId(), 50, $this->model->get_db_field('llm_model'));
            $this->sendJsonResponse(['conversations' => $conversations]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
?>
