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

                // Handle file uploads if any
                $uploadedFiles = $this->handleFileUploads($conversation_id);

                // Save user message - THIS IS THE CRITICAL PART THAT WAS MISSING
                $messageId = $this->llm_service->addMessage($conversation_id, 'user', $message, $uploadedFiles, $model);

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

            // Handle file uploads if any
            $uploadedFiles = $this->handleFileUploads($conversation_id);

            // Save user message with file attachments
            $messageId = $this->llm_service->addMessage($conversation_id, 'user', $message, $uploadedFiles, $model);

            // If files were uploaded, rename them to include the message ID
            if ($uploadedFiles && $messageId) {
                $this->updateFileNamesWithMessageId($conversation_id, $messageId, $uploadedFiles);
            }

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
     * Handles multimodal content for vision models (images, documents)
     *
     * @param array $messages Array of message objects from database
     * @return array Messages formatted for OpenAI-compatible API
     */
    private function convertToApiFormat($messages)
    {
        $api_messages = [];
        $configuredModel = $this->model->getConfiguredModel();
        $isVisionModel = llm_is_vision_model($configuredModel);

        foreach ($messages as $message) {
            $api_message = [
                'role' => $message['role'],
                'content' => $message['content']
            ];

            // Handle attachments for multimodal content
            $attachments = null;
            if (!empty($message['attachments'])) {
                // Attachments stored as JSON
                $decoded = json_decode($message['attachments'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $attachments = $decoded;
                }
            }

            if (!empty($attachments)) {
                $contentParts = [];

                // Add text content first
                if (!empty($message['content'])) {
                    $contentParts[] = [
                        'type' => 'text',
                        'text' => $message['content']
                    ];
                }

                // Add each attachment based on type
                foreach ($attachments as $attachment) {
                    $attachmentContent = $this->formatAttachmentForApi($attachment, $isVisionModel);
                    if ($attachmentContent) {
                        $contentParts[] = $attachmentContent;
                    }
                }

                // Only use multimodal format if we have attachments
                if (count($contentParts) > 1 || (count($contentParts) === 1 && $contentParts[0]['type'] !== 'text')) {
                    $api_message['content'] = $contentParts;
                }
            }

            $api_messages[] = $api_message;
        }

        return $api_messages;
    }


    /**
     * Check if a path is an image based on extension
     *
     * @param string $path File path
     * @return bool True if path is an image
     */
    private function isImagePath($path)
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, LLM_ALLOWED_IMAGE_EXTENSIONS);
    }

    /**
     * Format an attachment for API request
     * Encodes images as base64 data URLs for vision models
     * Includes text content for documents
     *
     * @param array $attachment Attachment info array
     * @param bool $isVisionModel Whether the model supports vision
     * @return array|null Formatted content part for API
     */
    private function formatAttachmentForApi($attachment, $isVisionModel)
    {
        $path = $attachment['path'] ?? '';
        $fullPath = __DIR__ . "/../../../../{$path}";

        if (!file_exists($fullPath)) {
            error_log("Attachment file not found: {$fullPath}");
            return null;
        }

        $isImage = $attachment['is_image'] ?? $this->isImagePath($path);

        if ($isImage && $isVisionModel) {
            // Encode image as base64 data URL for vision models
            return $this->encodeImageForApi($fullPath, $attachment);
        } elseif (!$isImage) {
            // For documents, include file content as text
            return $this->encodeDocumentForApi($fullPath, $attachment);
        }

        return null;
    }

    /**
     * Encode image file as base64 data URL for vision API
     *
     * @param string $fullPath Full path to image file
     * @param array $attachment Attachment info
     * @return array|null Image content part for API
     */
    private function encodeImageForApi($fullPath, $attachment)
    {
        $imageData = file_get_contents($fullPath);
        if ($imageData === false) {
            error_log("Failed to read image file: {$fullPath}");
            return null;
        }

        $mimeType = $attachment['type'] ?? $this->detectMimeType($fullPath) ?? 'image/jpeg';
        $base64Data = base64_encode($imageData);

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => "data:{$mimeType};base64,{$base64Data}"
            ]
        ];
    }

    /**
     * Encode document file content for API
     * Reads text-based documents and includes their content in the message
     *
     * @param string $fullPath Full path to document file
     * @param array $attachment Attachment info
     * @return array|null Text content part for API
     */
    private function encodeDocumentForApi($fullPath, $attachment)
    {
        $originalName = $attachment['original_name'] ?? basename($fullPath);
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

        // For text-based files, include the content
        $textExtensions = ['txt', 'md', 'csv', 'json', 'xml', 'py', 'js', 'php', 'html', 'css', 'sql', 'sh', 'yaml', 'yml'];

        if (in_array($extension, $textExtensions)) {
            $content = file_get_contents($fullPath);
            if ($content !== false) {
                // Limit content size to prevent API issues (max 50KB of text per file)
                $maxTextSize = 50 * 1024;
                if (strlen($content) > $maxTextSize) {
                    $content = substr($content, 0, $maxTextSize) . "\n\n[Content truncated due to size limit...]";
                }

                return [
                    'type' => 'text',
                    'text' => "\n\n--- File: {$originalName} ---\n```{$extension}\n{$content}\n```\n--- End of file ---\n"
                ];
            }
        }

        // For binary files like PDF, just note the attachment
        return [
            'type' => 'text',
            'text' => "\n[Attached file: {$originalName}]\n"
        ];
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

    /**
     * Handle file uploads for messages
     * Saves files to the upload directory with conversation/message structure
     * Includes comprehensive validation for file type, size, MIME type, and duplicates
     *
     * @param int $conversationId The conversation ID
     * @return array|null Array of uploaded file information or null if no files
     * @throws Exception When validation fails
     */
    private function handleFileUploads($conversationId)
    {
        // Check for files uploaded via FormData (uploaded_files[])
        if (!empty($_FILES['uploaded_files'])) {
            $files = $_FILES['uploaded_files'];
        } elseif (!empty($_FILES)) {
            // Fallback for direct file uploads
            $files = $_FILES;
        } else {
            return null;
        }

        $uploadedFiles = [];
        $processedHashes = []; // Track file hashes for duplicate detection
        $fileCount = 0;

        // Handle both single file and multiple files array
        if (isset($files['name']) && is_array($files['name'])) {
            // Multiple files
            $totalFiles = count($files['name']);

            // Check maximum files limit
            if ($totalFiles > LLM_MAX_FILES_PER_MESSAGE) {
                throw new Exception('Maximum ' . LLM_MAX_FILES_PER_MESSAGE . ' files allowed per message');
            }

            for ($i = 0; $i < $totalFiles; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK && !empty($files['name'][$i])) {
                    $file = [
                        'name' => $files['name'][$i],
                        'type' => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error' => $files['error'][$i],
                        'size' => $files['size'][$i]
                    ];
                    $processedFile = $this->processUploadedFile($file, $conversationId, $processedHashes);
                    if ($processedFile) {
                        $uploadedFiles[] = $processedFile;
                        $processedHashes[] = $processedFile['hash'];
                        $fileCount++;
                    }
                } elseif ($files['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                    // Log but don't fail for individual file errors
                    error_log('File upload error for ' . ($files['name'][$i] ?? 'unknown') . ': ' . $this->getUploadErrorMessage($files['error'][$i]));
                }
            }
        } elseif (isset($files['name']) && !empty($files['name'])) {
            // Single file
            if ($files['error'] === UPLOAD_ERR_OK) {
                $processedFile = $this->processUploadedFile($files, $conversationId, $processedHashes);
                if ($processedFile) {
                    $uploadedFiles[] = $processedFile;
                }
            } elseif ($files['error'] !== UPLOAD_ERR_NO_FILE) {
                throw new Exception('File upload error: ' . $this->getUploadErrorMessage($files['error']));
            }
        }

        return empty($uploadedFiles) ? null : $uploadedFiles;
    }

    /**
     * Process and validate a single uploaded file
     * Performs comprehensive validation including MIME type checking and duplicate detection
     *
     * @param array $file The file array from $_FILES
     * @param int $conversationId The conversation ID
     * @param array $processedHashes Array of already processed file hashes for duplicate detection
     * @return array|null File information array or null if file should be skipped
     * @throws Exception When validation fails critically
     */
    private function processUploadedFile($file, $conversationId, $processedHashes = [])
    {
        // Sanitize filename to prevent path traversal and invalid characters
        $originalName = $this->sanitizeFileName($file['name']);

        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error for "' . $originalName . '": ' . $this->getUploadErrorMessage($file['error']));
        }

        // Validate file size
        if ($file['size'] > LLM_MAX_FILE_SIZE) {
            throw new Exception('File "' . $originalName . '" exceeds maximum limit of ' . $this->formatFileSize(LLM_MAX_FILE_SIZE));
        }

        // Validate file size is not empty
        if ($file['size'] === 0) {
            throw new Exception('File "' . $originalName . '" is empty');
        }

        // Validate file extension
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (empty($extension)) {
            throw new Exception('File "' . $originalName . '" has no extension');
        }

        if (!in_array($extension, LLM_ALLOWED_EXTENSIONS)) {
            throw new Exception('File type ".' . $extension . '" not allowed. Allowed types: ' . implode(', ', LLM_ALLOWED_EXTENSIONS));
        }

        // Validate MIME type using finfo (more reliable than browser-provided type)
        $detectedMimeType = $this->detectMimeType($file['tmp_name']);
        if (!llm_validate_mime_type($extension, $detectedMimeType)) {
            // Allow if browser-reported type matches (some systems have different finfo databases)
            if (!llm_validate_mime_type($extension, $file['type'])) {
                throw new Exception('File "' . $originalName . '" has invalid content type. Expected type for .' . $extension . ' but got ' . $detectedMimeType);
            }
        }

        // Check for duplicate files using content hash
        $fileHash = md5_file($file['tmp_name']);
        if (in_array($fileHash, $processedHashes)) {
            // Skip duplicate files silently
            return null;
        }

        // Determine file type category
        $fileCategory = llm_get_file_type_category($extension);

        // Generate secure filename with conversation context
        $timestamp = time();
        $random = bin2hex(random_bytes(8));
        $secureFileName = "temp_{$conversationId}_{$timestamp}_{$random}.{$extension}";
        $relativePath = LLM_UPLOAD_FOLDER . "/{$conversationId}/{$secureFileName}";
        $fullPath = __DIR__ . "/../../../../{$relativePath}";

        // Create directory with proper permissions if it doesn't exist
        $directory = dirname($fullPath);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                throw new Exception('Failed to create upload directory');
            }
        }

        // Move uploaded file securely
        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            throw new Exception('Failed to save file "' . $originalName . '"');
        }

        // Set proper file permissions
        chmod($fullPath, 0644);

        // Return comprehensive file info
        return [
            'original_name' => $originalName,
            'filename' => $secureFileName,
            'path' => $relativePath,
            'size' => $file['size'],
            'type' => $detectedMimeType ?: $file['type'],
            'extension' => $extension,
            'category' => $fileCategory,
            'hash' => $fileHash,
            'url' => "?file_path={$relativePath}",
            'is_image' => $fileCategory === LLM_FILE_TYPE_IMAGE
        ];
    }

    /**
     * Sanitize filename to prevent security issues
     * Removes path traversal attempts and invalid characters
     *
     * @param string $filename Original filename
     * @return string Sanitized filename
     */
    private function sanitizeFileName($filename)
    {
        // Remove path components (directory traversal prevention)
        $filename = basename($filename);

        // Remove null bytes
        $filename = str_replace("\0", '', $filename);

        // Replace potentially dangerous characters
        $filename = preg_replace('/[^\w\-\.\s]/', '_', $filename);

        // Collapse multiple underscores/spaces
        $filename = preg_replace('/[\s_]+/', '_', $filename);

        // Trim and limit length
        $filename = trim($filename, '._');
        if (strlen($filename) > 255) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $filename = substr($name, 0, 250 - strlen($ext)) . '.' . $ext;
        }

        return $filename ?: 'unnamed_file';
    }

    /**
     * Detect MIME type using finfo
     * Falls back to file extension-based detection if finfo fails
     *
     * @param string $filePath Path to the file
     * @return string|null Detected MIME type
     */
    private function detectMimeType($filePath)
    {
        if (!file_exists($filePath)) {
            return null;
        }

        // Try finfo first (most reliable)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $filePath);
            finfo_close($finfo);

            if ($mimeType && $mimeType !== 'application/octet-stream') {
                return $mimeType;
            }
        }

        // Try mime_content_type as fallback
        if (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($filePath);
            if ($mimeType && $mimeType !== 'application/octet-stream') {
                return $mimeType;
            }
        }

        return null;
    }

    /**
     * Format file size for human-readable display
     *
     * @param int $bytes File size in bytes
     * @return string Formatted file size
     */
    private function formatFileSize($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 1) . ' ' . $units[$unitIndex];
    }

    /**
     * Update file names to include message ID after message is saved
     *
     * @param int $conversationId The conversation ID
     * @param int $messageId The message ID
     * @param array $uploadedFiles Array of uploaded file information
     */
    private function updateFileNamesWithMessageId($conversationId, $messageId, $uploadedFiles)
    {
        foreach ($uploadedFiles as $file) {
            // Extract current filename parts
            $currentPath = __DIR__ . "/../../../../{$file['path']}";
            $extension = pathinfo($file['filename'], PATHINFO_EXTENSION);

            // Create new filename with message ID
            $newFileName = "conv_{$conversationId}_msg_{$messageId}_" . bin2hex(random_bytes(8)) . ".{$extension}";
            $newRelativePath = LLM_UPLOAD_FOLDER . "/{$conversationId}/{$newFileName}";
            $newFullPath = __DIR__ . "/../../../../{$newRelativePath}";

            // Rename the file
            if (file_exists($currentPath) && rename($currentPath, $newFullPath)) {
                // Update the file info with new path
                $file['filename'] = $newFileName;
                $file['path'] = $newRelativePath;
                $file['url'] = "?file_path={$newRelativePath}";
            }
        }

        // Update the message in database with corrected file attachments
        $attachmentsJson = json_encode($uploadedFiles);
        $this->llm_service->updateMessage($messageId, ['attachments' => $attachmentsJson]);
    }

    /**
     * Get human-readable upload error message
     *
     * @param int $errorCode The upload error code
     * @return string Human-readable error message
     */
    private function getUploadErrorMessage($errorCode)
    {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension stopped the file upload';
            default:
                return 'Unknown upload error';
        }
    }
}
?>
