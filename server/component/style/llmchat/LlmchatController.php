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
                case 'get_config':
                    $this->getChatConfig();
                    break;
                case 'get_conversation':
                    $this->getConversationData();
                    break;
                case 'get_conversations':
                    $this->getConversationsData();
                    break;
                case 'admin_filters':
                    $this->handleAdminFilters();
                    break;
                case 'admin_conversations':
                    $this->handleAdminConversations();
                    break;
                case 'admin_messages':
                    $this->handleAdminMessages();
                    break;
                case 'get_auto_started':
                    $this->getAutoStartedConversation();
                    break;
                default:
                    // Regular page load - continue with normal rendering
                    break;
            }
        }

        // Check for auto-start conversation after handling all requests
        // This ensures auto-start only happens during normal page loads, not API calls
        if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
            !isset($_GET['action']) &&
            !isset($_GET['streaming'])) {
            $this->checkAndAutoStartConversation();
        }
    }

    /**
     * Get auto-started conversation data for the frontend
     * This is called by the frontend to check if a conversation was auto-started
     */
    private function getAutoStartedConversation()
    {
        $user_id = $this->model->getUserId();
        if (!$user_id) {
            $this->sendJsonResponse(['auto_started' => false]);
            return;
        }

        // Check if there was an auto-started conversation in this session
        $session_key = 'llm_auto_started_' . $this->model->getSectionId();
        if (!isset($_SESSION[$session_key])) {
            $this->sendJsonResponse(['auto_started' => false]);
            return;
        }

        // Get the current conversation (should be the auto-started one)
        $conversation = $this->model->getCurrentConversation();
        if (!$conversation) {
            $this->sendJsonResponse(['auto_started' => false]);
            return;
        }

        $messages = $this->model->getConversationMessages();
        if (empty($messages)) {
            $this->sendJsonResponse(['auto_started' => false]);
            return;
        }

        $this->sendJsonResponse([
            'auto_started' => true,
            'conversation' => $conversation,
            'messages' => $messages
        ]);
    }

    /**
     * Handle streaming request
     * Optimized for smooth, fluid streaming delivery
     */
    /**
     * Handle streaming request - Industry Standard Implementation
     * Delegates all streaming logic to dedicated streaming service
     */
    private function handleStreamingRequest()
    {
        $conversation_id = $_GET['conversation'] ?? null;

        if (!$conversation_id) {
            $this->sendSSE(['type' => 'error', 'message' => 'Conversation ID required']);
            exit;
        }

        $user_id = $this->model->getUserId();

        if (!$user_id) {
            $this->sendSSE(['type' => 'error', 'message' => 'User not authenticated']);
            exit;
        }

        // Verify conversation exists and belongs to user
        $conversation = $this->llm_service->getConversation($conversation_id, $user_id);
        if (!$conversation) {
            $this->sendSSE(['type' => 'error', 'message' => 'Conversation not found']);
            exit;
        }

        // Get conversation messages for LLM
        $messages = $this->llm_service->getConversationMessages($conversation_id, 50);
        if (empty($messages)) {
            $this->sendSSE(['type' => 'error', 'message' => 'No messages found in conversation']);
            exit;
        }

        // Get conversation context if configured
        $context_messages = $this->model->getParsedConversationContext();

        // Convert messages to API format with context prepended
        $api_messages = $this->api_formatter_service->convertToApiFormat($messages, $conversation['model'], $context_messages);
        if (empty($api_messages)) {
            $this->sendSSE(['type' => 'error', 'message' => 'No valid messages to send']);
            exit;
        }

        // Delegate to streaming service - industry standard approach
        // Pass context for tracking/audit purposes
        $this->streaming_service->startStreamingResponse(
            $conversation_id,
            $api_messages,
            $conversation['model'],
            false, // is_new_conversation not relevant for existing streaming
            $context_messages // sent_context for tracking
        );
    }

    /**
     * Check if auto-start conversation should be triggered
     * Called during component initialization when no conversation exists
     */
    private function checkAndAutoStartConversation()
    {
        // Only auto-start if enabled in configuration
        if (!$this->model->isAutoStartConversationEnabled()) {
            return;
        }

        $user_id = $this->model->getUserId();
        if (!$user_id) {
            return; // User not authenticated
        }

        // Check if there's already an active conversation
        $current_conversation = $this->model->getCurrentConversation();
        if ($current_conversation) {
            return; // Conversation already exists
        }

        // Check if conversations list is enabled
        $conversations_list_enabled = $this->model->isConversationsListEnabled();

        if ($conversations_list_enabled) {
            // When conversations list is enabled, only auto-start if no conversations exist at all
            $user_conversations = $this->llm_service->getUserConversations(
                $user_id,
                1, // Just check if any exist
                $this->model->getConfiguredModel()
            );
            if (!empty($user_conversations)) {
                return; // User already has conversations
            }
        }

        // Perform auto-start
        $this->performAutoStartConversation();
    }

    /**
     * Perform the auto-start conversation by creating a conversation and sending the initial message
     */
    private function performAutoStartConversation()
    {
        $user_id = $this->model->getUserId();
        if (!$user_id) {
            return;
        }

        try {
            // Check rate limiting before creating conversation
            $rate_data = $this->llm_service->checkRateLimit($user_id);

            // Create new conversation for auto-start
            $conversation_id = $this->llm_service->getOrCreateConversationForModel(
                $user_id,
                $this->model->getConfiguredModel(),
                $this->model->getLlmTemperature(),
                $this->model->getLlmMaxTokens(),
                $this->model->getSectionId()
            );

            // Generate title for auto-start conversation
            $auto_start_title = $this->generateAutoStartTitle();
            $this->llm_service->updateConversation($conversation_id, $user_id, ['title' => $auto_start_title]);

            // Get context-aware auto-start message and context
            $auto_start_message = $this->model->generateContextAwareAutoStartMessage();
            $context_messages = $this->model->getParsedConversationContext();

            // Create auto-start message with context
            $message_id = $this->llm_service->addMessage(
                $conversation_id,
                'assistant', // AI sends the auto-start message
                $auto_start_message,
                null, // No file attachments for auto-start
                $this->model->getConfiguredModel(),
                null, // Tokens will be calculated when actually sent
                null, // No raw response for auto-start
                $context_messages // Include context for tracking
            );

            // Update rate limiting
            $this->llm_service->updateRateLimit($user_id, $rate_data, $conversation_id);

            // Mark as auto-started in session to prevent duplicates
            $session_key = 'llm_auto_started_' . $this->model->getSectionId();
            $_SESSION[$session_key] = true;

            // Auto-start setup complete - normal page rendering will continue
            // Frontend will check for auto-started conversation via API

        } catch (Exception $e) {
            // Log error but don't fail the page load - auto-start is optional
            error_log('LLM Auto-start failed: ' . $e->getMessage());
            // Don't send error response as this is called during normal page load
        }
    }

    /**
     * Generate a title for auto-started conversations
     */
    private function generateAutoStartTitle()
    {
        return 'AI Assistant - ' . date('M j, H:i');
    }

    /**
     * Handle message submission
     */
    private function handleMessageSubmission()
    {
        $user_id = $this->model->getUserId();

        // Check if user is authenticated
        if (!$user_id) {
            $this->sendJsonResponse(['error' => 'User not authenticated'], 401);
            return;
        }

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
                    $conversation_id = $this->llm_service->getOrCreateConversationForModel($user_id, $model, $temperature, $max_tokens, $this->model->getSectionId());
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
                        $conversation_id = $this->llm_service->getOrCreateConversationForModel($user_id, $model, $temperature, $max_tokens, $this->model->getSectionId());
                        $is_new_conversation = true;
                    }
                }

                // Generate title for new conversations based on the first message
                if ($is_new_conversation) {
                    $generated_title = $this->generateConversationTitle($message);
                    $this->llm_service->updateConversation($conversation_id, $user_id, ['title' => $generated_title]);
                }

                // Handle file uploads if any
                $uploadedFiles = $this->file_upload_service->handleFileUploads($conversation_id);

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
                $conversation_id = $this->llm_service->getOrCreateConversationForModel($user_id, $model, $temperature, $max_tokens, $this->model->getSectionId());
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
                    $conversation_id = $this->llm_service->getOrCreateConversationForModel($user_id, $model, $temperature, $max_tokens, $this->model->getSectionId());
                    $is_new_conversation = true;
                }
            }

            // Generate title for new conversations based on the first message
            if ($is_new_conversation) {
                $generated_title = $this->generateConversationTitle($message);
                $this->llm_service->updateConversation($conversation_id, $user_id, ['title' => $generated_title]);
            }

            // Handle file uploads if any
            $uploadedFiles = $this->file_upload_service->handleFileUploads($conversation_id);

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
            
            // Get conversation context if configured
            $context_messages = $this->model->getParsedConversationContext();
            
            // Convert messages to API format with context prepended
            $api_messages = $this->api_formatter_service->convertToApiFormat($messages, $model, $context_messages);

            // Call LLM API - check if streaming is enabled in the style configuration (DB field)
            $streaming_enabled = $this->model->isStreamingEnabled();

            if ($streaming_enabled) {
                // Start streaming response with context tracking
                $this->streaming_service->startStreamingResponse($conversation_id, $api_messages, $model, $is_new_conversation, $context_messages);
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
                    
                    throw new Exception($error_message);
                }

                // Check for valid success response
                if (is_array($response) && isset($response['choices'][0]['message']['content'])) {
                    $assistant_message = $response['choices'][0]['message']['content'];
                    $tokens_used = $response['usage']['total_tokens'] ?? null;

                    // Ensure content is properly extracted and clean
                    $clean_content = trim($assistant_message);

                    // Save assistant message with full response and context for debugging
                    $this->llm_service->addMessage($conversation_id, 'assistant', $clean_content, null, $model, $tokens_used, $response, $context_messages);

                    $this->sendJsonResponse([
                        'conversation_id' => $conversation_id,
                        'message' => $assistant_message,
                        'streaming' => false,
                        'is_new_conversation' => $is_new_conversation
                    ]);
                } else {
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

        // Check if user is authenticated
        if (!$user_id) {
            $this->sendJsonResponse(['error' => 'User not authenticated'], 401);
            return;
        }

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

            $conversation_id = $this->llm_service->createConversation($user_id, $title, $model, null, null, $this->model->getSectionId());

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

        // Check if user is authenticated
        if (!$user_id) {
            $this->sendJsonResponse(['error' => 'User not authenticated'], 401);
            return;
        }

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
     * Get chat configuration (API endpoint)
     * Returns all configuration needed for React component initialization
     */
    private function getChatConfig()
    {
        $user_id = $this->model->getUserId();

        // Check if user is authenticated
        if (!$user_id) {
            $this->sendJsonResponse(['error' => 'User not authenticated'], 401);
            return;
        }

        try {
            // Build complete configuration for React component
            $config = [
                'userId' => $user_id,
                'currentConversationId' => $this->model->getConversationId(),
                'configuredModel' => $this->model->getConfiguredModel(),
                'maxFilesPerMessage' => LLM_MAX_FILES_PER_MESSAGE,
                'maxFileSize' => LLM_MAX_FILE_SIZE,
                'streamingEnabled' => $this->model->isStreamingEnabled(),
                'enableConversationsList' => $this->model->isConversationsListEnabled(),
                'enableFileUploads' => $this->model->isFileUploadsEnabled(),
                'enableFullPageReload' => $this->model->isFullPageReloadEnabled(),
                'acceptedFileTypes' => implode(',', array_map(fn($ext) => ".{$ext}", $this->model->getAcceptedFileTypes())),
                'isVisionModel' => $this->model->isVisionModel(),
                'hasConversationContext' => $this->model->hasConversationContext(),
                'autoStartConversation' => $this->model->isAutoStartConversationEnabled(),
                'autoStartMessage' => $this->model->getAutoStartMessage(),
                // UI Labels
                'messagePlaceholder' => $this->model->getMessagePlaceholder(),
                'noConversationsMessage' => $this->model->getNoConversationsMessage(),
                'newConversationTitleLabel' => $this->model->getNewConversationTitleLabel(),
                'conversationTitleLabel' => $this->model->getConversationTitleLabel(),
                'cancelButtonLabel' => $this->model->getCancelButtonLabel(),
                'createButtonLabel' => $this->model->getCreateButtonLabel(),
                'deleteConfirmationTitle' => $this->model->getDeleteConfirmationTitle(),
                'deleteConfirmationMessage' => $this->model->getDeleteConfirmationMessage(),
                'confirmDeleteButtonLabel' => $this->model->getConfirmDeleteButtonLabel(),
                'cancelDeleteButtonLabel' => $this->model->getCancelDeleteButtonLabel(),
                'tokensSuffix' => $this->model->getTokensUsedSuffix(),
                'aiThinkingText' => $this->model->getAiThinkingText(),
                'conversationsHeading' => $this->model->getConversationsHeading(),
                'newChatButtonLabel' => $this->model->getNewChatButtonLabel(),
                'selectConversationHeading' => $this->model->getSelectConversationHeading(),
                'selectConversationDescription' => $this->model->getSelectConversationDescription(),
                'modelLabelPrefix' => $this->model->getModelLabelPrefix(),
                'noMessagesMessage' => $this->model->getNoMessagesMessage(),
                'loadingText' => $this->model->getLoadingText(),
                'uploadImageLabel' => $this->model->getUploadImageLabel(),
                'uploadHelpText' => $this->model->getUploadHelpText(),
                'clearButtonLabel' => $this->model->getClearButtonLabel(),
                'submitButtonLabel' => $this->model->getSubmitButtonLabel(),
                'emptyMessageError' => $this->model->getEmptyMessageError(),
                'streamingActiveError' => $this->model->getStreamingActiveError(),
                'defaultChatTitle' => $this->model->getDefaultChatTitle(),
                'deleteButtonTitle' => $this->model->getDeleteButtonTitle(),
                'conversationTitlePlaceholder' => $this->model->getConversationTitlePlaceholder(),
                'singleFileAttachedText' => $this->model->getSingleFileAttachedText(),
                'multipleFilesAttachedText' => $this->model->getMultipleFilesAttachedText(),
                'emptyStateTitle' => $this->model->getEmptyStateTitle(),
                'emptyStateDescription' => $this->model->getEmptyStateDescription(),
                'loadingMessagesText' => $this->model->getLoadingMessagesText(),
                'streamingInProgressPlaceholder' => $this->model->getStreamingInProgressPlaceholder(),
                'attachFilesTitle' => $this->model->getAttachFilesTitle(),
                'noVisionSupportTitle' => $this->model->getNoVisionSupportTitle(),
                'noVisionSupportText' => $this->model->getNoVisionSupportText(),
                'sendMessageTitle' => $this->model->getSendMessageTitle(),
                'removeFileTitle' => $this->model->getRemoveFileTitle(),
                // File config
                'fileConfig' => [
                    'maxFileSize' => LLM_MAX_FILE_SIZE,
                    'maxFilesPerMessage' => LLM_MAX_FILES_PER_MESSAGE,
                    'allowedImageExtensions' => LLM_ALLOWED_IMAGE_EXTENSIONS,
                    'allowedDocumentExtensions' => LLM_ALLOWED_DOCUMENT_EXTENSIONS,
                    'allowedCodeExtensions' => LLM_ALLOWED_CODE_EXTENSIONS,
                    'allowedExtensions' => LLM_ALLOWED_EXTENSIONS,
                    'visionModels' => LLM_VISION_MODELS
                ]
            ];

            $this->sendJsonResponse(['config' => $config]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get conversation data (AJAX)
     */
    public function getConversationData()
    {
        $user_id = $this->model->getUserId();

        // Check if user is authenticated
        if (!$user_id) {
            $this->sendJsonResponse(['error' => 'User not authenticated'], 401);
            return;
        }

        $conversation_id = $_GET['conversation_id'] ?? null;

        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID required'], 400);
            return;
        }

        try {
            $conversation = $this->llm_service->getConversation($conversation_id, $user_id);

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
        $user_id = $this->model->getUserId();

        // Check if user is authenticated
        if (!$user_id) {
            $this->sendJsonResponse(['error' => 'User not authenticated'], 401);
            return;
        }

        try {
            $conversations = $this->llm_service->getUserConversations($user_id, 50, $this->model->get_db_field('llm_model'));
            $this->sendJsonResponse(['conversations' => $conversations]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle admin filters request (users and sections)
     */
    private function handleAdminFilters()
    {
        try {
            // Import admin model for admin functionality
            require_once __DIR__ . "/../../moduleLlmAdminConsole/ModuleLlmAdminConsoleModel.php";
            $admin_model = new ModuleLlmAdminConsoleModel($this->model->get_services(), [], $this->model->getPageId());
            $filters = $admin_model->getAdminFilters();

            $this->sendJsonResponse([
                'filters' => $filters
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle admin conversations request
     */
    private function handleAdminConversations()
    {
        $page = (int)($_GET['page'] ?? 1);
        $per_page = min((int)($_GET['per_page'] ?? 50), 100); // Max 100 per page

        $filters = [];
        if (!empty($_GET['user_id'])) $filters['user_id'] = $_GET['user_id'];
        if (!empty($_GET['section_id'])) $filters['section_id'] = $_GET['section_id'];
        if (!empty($_GET['q'])) $filters['query'] = $_GET['q'];

        try {
            // Import admin model for admin functionality
            require_once __DIR__ . "/../../moduleLlmAdminConsole/ModuleLlmAdminConsoleModel.php";
            $admin_model = new ModuleLlmAdminConsoleModel($this->model->get_services(), [], $this->model->getPageId());
            $result = $admin_model->getAdminConversations($filters, $page, $per_page);

            $this->sendJsonResponse($result);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle admin conversation messages request
     */
    private function handleAdminMessages()
    {
        $conversation_id = $_GET['conversation_id'] ?? null;

        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID required'], 400);
            return;
        }

        try {
            // Import admin model for admin functionality
            require_once __DIR__ . "/../../moduleLlmAdminConsole/ModuleLlmAdminConsoleModel.php";
            $admin_model = new ModuleLlmAdminConsoleModel($this->model->get_services(), [], $this->model->getPageId());
            $result = $admin_model->getAdminConversationMessages($conversation_id);

            if ($result === null) {
                $this->sendJsonResponse(['error' => 'Conversation not found'], 404);
                return;
            }

            $this->sendJsonResponse($result);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
?>
