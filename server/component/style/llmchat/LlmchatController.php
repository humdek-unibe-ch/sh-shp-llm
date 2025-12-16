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
require_once __DIR__ . "/../../../service/StrictConversationService.php";
require_once __DIR__ . "/../../../service/LlmFormModeService.php";
require_once __DIR__ . "/../../../service/LlmDataSavingService.php";

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
    private $strict_conversation_service;
    private $form_mode_service;
    private $data_saving_service;

    /** @var float Request start time for activity logging */
    private $request_start_time;

    /** @var string|null Current API action being processed */
    private $current_action;

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

        // Track request start time for activity logging
        $this->request_start_time = microtime(true);
        $this->current_action = null;

        $this->llm_service = new LlmService($this->model->get_services());
        $this->file_upload_service = new LlmFileUploadService($this->llm_service);
        $this->api_formatter_service = new LlmApiFormatterService();
        $this->streaming_service = new LlmStreamingService($this->llm_service);
        $this->strict_conversation_service = new StrictConversationService($this->llm_service);
        $this->form_mode_service = new LlmFormModeService();
        $this->data_saving_service = new LlmDataSavingService($this->model->get_services());

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
     * Get section_id from request parameters
     * 
     * For multi-section support, each chat instance passes its section_id with API calls.
     * This ensures that API calls are processed for the correct section, not just the
     * first rendered section on the page.
     * 
     * @return int|null The section ID from request or model fallback
     */
    private function getRequestSectionId()
    {
        return $_GET['section_id'] ?? $_POST['section_id'] ?? $this->model->getSectionId();
    }

    /**
     * Handle incoming requests based on POST/GET parameters
     */
    private function handleRequest()
    {
        // Check for streaming request first
        if (isset($_GET['streaming']) && $_GET['streaming'] === '1') {
            $this->current_action = 'streaming';
            $this->handleStreamingRequest();
            return;
        }

        // Check for AJAX-like parameters that were previously handled by AjaxLlmChat
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';
            $this->current_action = $action ?: 'post_message';

            switch ($action) {
                case 'send_message':
                    $this->handleMessageSubmission();
                    break;
                case 'submit_form':
                    $this->handleFormSubmission();
                    break;
                case 'continue_conversation':
                    $this->handleContinueConversation();
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
            $this->current_action = $action ?: null;

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
        // The session stores the conversation ID (not just a boolean flag)
        $session_key = 'llm_auto_started_' . $this->model->getSectionId();
        if (!isset($_SESSION[$session_key]) || empty($_SESSION[$session_key])) {
            $this->sendJsonResponse(['auto_started' => false]);
            return;
        }

        $auto_started_conversation_id = $_SESSION[$session_key];

        // Get the auto-started conversation directly by ID
        // This is more reliable than using model->getCurrentConversation() which depends on URL params
        $conversation = $this->llm_service->getConversation($auto_started_conversation_id, $user_id);
        if (!$conversation) {
            // Clear invalid session data
            unset($_SESSION[$session_key]);
            $this->sendJsonResponse(['auto_started' => false]);
            return;
        }

        // Get messages for this specific conversation
        $messages = $this->llm_service->getConversationMessages($auto_started_conversation_id, 50);
        if (empty($messages)) {
            $this->sendJsonResponse(['auto_started' => false]);
            return;
        }

        // Clear the session flag after successfully returning the data
        // This prevents the auto-start data from being returned on subsequent page loads
        unset($_SESSION[$session_key]);

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
        $conversation = $this->llm_service->getConversation($conversation_id, $user_id, $this->getRequestSectionId());
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

        // Apply form mode context if enabled (takes priority over strict mode)
        if ($this->model->isFormModeEnabled()) {
            $context_messages = $this->form_mode_service->buildFormModeContext($context_messages);
        }
        // Apply strict conversation mode if enabled (only if not in form mode)
        elseif ($this->model->shouldApplyStrictMode()) {
            $context_messages = $this->strict_conversation_service->buildStrictModeContext(
                $context_messages,
                $this->model->getConversationContext()
            );
        }

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
     * 
     * In Form Mode: Calls the LLM API to generate the initial form based on context
     * In Normal Mode: Uses a static context-aware greeting message
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

            // Get context messages
            $context_messages = $this->model->getParsedConversationContext();

            // Check if form mode is enabled - if so, we need to call the LLM to generate the initial form
            if ($this->model->isFormModeEnabled()) {
                $this->performFormModeAutoStart($conversation_id, $user_id, $context_messages, $rate_data);
            } else {
                // Normal mode: Use static context-aware greeting
                $auto_start_message = $this->model->generateContextAwareAutoStartMessage();

                // Create auto-start message with context
                $this->llm_service->addMessage(
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

                // Store auto-started conversation ID in session
                $session_key = 'llm_auto_started_' . $this->model->getSectionId();
                $_SESSION[$session_key] = $conversation_id;
            }

        } catch (Exception $e) {
            // Log error but don't fail the page load - auto-start is optional
            error_log('LLM Auto-start failed: ' . $e->getMessage());
            // Don't send error response as this is called during normal page load
        }
    }

    /**
     * Perform auto-start in Form Mode by calling the LLM to generate the initial form
     * 
     * This method sends a system message to the LLM asking it to generate the first form
     * based on the configured conversation context.
     */
    private function performFormModeAutoStart($conversation_id, $user_id, $context_messages, $rate_data)
    {
        try {
            // Build form mode context (includes JSON schema instructions)
            $form_context = $this->form_mode_service->buildFormModeContext($context_messages);

            // Create an initial "system" prompt to trigger form generation
            $initial_prompt = [
                [
                    'role' => 'user',
                    'content' => 'Please start the conversation by providing the first form for me to fill out.'
                ]
            ];

            // Combine context with initial prompt
            $api_messages = array_merge($form_context, $initial_prompt);

            // Call LLM API to generate the initial form
            $model = $this->model->getConfiguredModel();
            $temperature = $this->model->getLlmTemperature();
            $max_tokens = $this->model->getLlmMaxTokens();

            $response = $this->llm_service->callLlmApi($api_messages, $model, $temperature, $max_tokens);

            if (is_array($response) && isset($response['choices'][0]['message']['content'])) {
                $assistant_message = $response['choices'][0]['message']['content'];
                $tokens_used = $response['usage']['total_tokens'] ?? null;

                // Save the LLM-generated form as the assistant message
                $this->llm_service->addMessage(
                    $conversation_id,
                    'assistant',
                    $assistant_message,
                    null,
                    $model,
                    $tokens_used,
                    $response,
                    $form_context
                );

                // Update rate limiting
                $this->llm_service->updateRateLimit($user_id, $rate_data, $conversation_id);

                // Store auto-started conversation ID in session
                $session_key = 'llm_auto_started_' . $this->model->getSectionId();
                $_SESSION[$session_key] = $conversation_id;

            } else {
                // LLM didn't return a valid response - fall back to error message
                error_log('LLM Form Mode Auto-start: Invalid LLM response');
                $this->llm_service->addMessage(
                    $conversation_id,
                    'assistant',
                    'I apologize, but I was unable to generate the initial form. Please try refreshing the page.',
                    null,
                    $model,
                    null,
                    null,
                    $form_context
                );

                // Still store in session so the conversation is shown
                $session_key = 'llm_auto_started_' . $this->model->getSectionId();
                $_SESSION[$session_key] = $conversation_id;
            }

        } catch (Exception $e) {
            error_log('LLM Form Mode Auto-start failed: ' . $e->getMessage());
            throw $e; // Re-throw to be caught by parent
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
                    $conversation_id = $this->llm_service->getOrCreateConversationForModel($user_id, $model, $temperature, $max_tokens, $this->getRequestSectionId());
                    $is_new_conversation = true;
                } else {
                    // Check if existing conversation matches the requested model
                    $existing_conversation = $this->llm_service->getConversation($conversation_id, $user_id, $this->getRequestSectionId());
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
                        $conversation_id = $this->llm_service->getOrCreateConversationForModel($user_id, $model, $temperature, $max_tokens, $this->getRequestSectionId());
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
                $conversation_id = $this->llm_service->getOrCreateConversationForModel($user_id, $model, $temperature, $max_tokens, $this->getRequestSectionId());
                $is_new_conversation = true;
            } else {
                // Check if existing conversation matches the requested model
                $existing_conversation = $this->llm_service->getConversation($conversation_id, $user_id, $this->getRequestSectionId());
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
                    $conversation_id = $this->llm_service->getOrCreateConversationForModel($user_id, $model, $temperature, $max_tokens, $this->getRequestSectionId());
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

            // Apply strict conversation mode if enabled
            // This embeds enforcement instructions directly into the context,
            // allowing the LLM to naturally handle topic relevance without a separate API call
            if ($this->model->shouldApplyStrictMode()) {
                $context_messages = $this->strict_conversation_service->buildStrictModeContext(
                    $context_messages,
                    $this->model->getConversationContext()
                );
            }

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
     * Handle form submission in form mode
     * Processes form selections and sends them to the LLM
     */
    private function handleFormSubmission()
    {
        $user_id = $this->model->getUserId();

        // Check if user is authenticated
        if (!$user_id) {
            $this->sendJsonResponse(['error' => 'User not authenticated'], 401);
            return;
        }

        // Form submissions are now allowed even when form mode is disabled
        // This allows LLMs to return forms dynamically and users to submit them

        $conversation_id = $_POST['conversation_id'] ?? null;
        $form_values_json = $_POST['form_values'] ?? '{}';
        $readable_text = trim($_POST['readable_text'] ?? '');
        $model = $_POST['model'] ?? $this->model->getConfiguredModel();
        $temperature = $_POST['temperature'] ?? $this->model->getLlmTemperature();
        $max_tokens = $_POST['max_tokens'] ?? $this->model->getLlmMaxTokens();

        // Validate form values
        $form_values = $this->form_mode_service->parseFormValues($form_values_json);
        if ($form_values === null) {
            $this->sendJsonResponse(['error' => 'Invalid form values'], 400);
            return;
        }

        // Check if form has any actual selections
        if (!$this->form_mode_service->hasSelections($form_values)) {
            $this->sendJsonResponse(['error' => 'Please select at least one option before submitting'], 400);
            return;
        }

        // Generate readable text from form values if not provided
        if (empty($readable_text)) {
            $readable_text = $this->form_mode_service->generateReadableTextFromFormValues($form_values);
        }

        // Final check for readable text
        if (empty($readable_text)) {
            $this->sendJsonResponse(['error' => 'Could not generate form submission text'], 400);
            return;
        }

        try {
            // Check if this is a streaming preparation request
            $is_streaming_prep = isset($_POST['prepare_streaming']) && $_POST['prepare_streaming'] === '1';

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
                $conversation_id = $this->llm_service->getOrCreateConversationForModel($user_id, $model, $temperature, $max_tokens, $this->getRequestSectionId());
                $is_new_conversation = true;
            } else {
                // Check if existing conversation matches the requested model
                $existing_conversation = $this->llm_service->getConversation($conversation_id, $user_id, $this->getRequestSectionId());
                if (!$existing_conversation) {
                    throw new Exception('Conversation not found');
                }

                // If model changed, create new conversation for the new model
                if ($existing_conversation['model'] !== $model) {
                    if (count($rate_data['conversations']) >= LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS) {
                        throw new Exception('Concurrent conversation limit exceeded: ' . LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS . ' conversations');
                    }
                    $conversation_id = $this->llm_service->getOrCreateConversationForModel($user_id, $model, $temperature, $max_tokens, $this->getRequestSectionId());
                    $is_new_conversation = true;
                }
            }

            // Generate title for new conversations based on the form submission
            if ($is_new_conversation) {
                $generated_title = $this->generateConversationTitle($readable_text);
                $this->llm_service->updateConversation($conversation_id, $user_id, ['title' => $generated_title]);
            }

            // Save user message with readable text (this is what the user sees)
            // Store the structured form values in attachments for reference
            $form_metadata = $this->form_mode_service->createFormMetadata($form_values);
            $messageId = $this->llm_service->addMessage($conversation_id, 'user', $readable_text, $form_metadata, $model);

            // Save form data to SelfHelp UserInput if enabled
            if ($this->model->isDataSavingEnabled()) {
                $this->saveFormDataToUserInput(
                    $form_values,
                    $user_id,
                    $messageId,
                    $conversation_id
                );
            }

            // Update rate limiting with the current rate data
            $this->llm_service->updateRateLimit($user_id, $rate_data, $conversation_id);

            if ($is_streaming_prep) {
                // For streaming preparation, return the conversation ID
                $savedMessage = [
                    'id' => $messageId,
                    'role' => 'user',
                    'content' => $readable_text,
                    'attachments' => $form_metadata,
                    'model' => $model,
                    'created_at' => date('Y-m-d H:i:s')
                ];

                // Store message data in session for streaming
                $_SESSION['streaming_conversation_id'] = $conversation_id;
                $_SESSION['streaming_message'] = $readable_text;
                $_SESSION['streaming_model'] = $model;

                $this->sendJsonResponse([
                    'status' => 'prepared',
                    'conversation_id' => $conversation_id,
                    'is_new_conversation' => $is_new_conversation,
                    'user_message' => $savedMessage
                ]);
                return;
            }

            // Get conversation messages for LLM
            $messages = $this->llm_service->getConversationMessages($conversation_id, 50);
            
            // Get conversation context if configured
            $context_messages = $this->model->getParsedConversationContext();

            // Apply form mode system prompt to enforce JSON form responses
            $context_messages = $this->form_mode_service->buildFormModeContext($context_messages);

            // Convert messages to API format with context prepended
            $api_messages = $this->api_formatter_service->convertToApiFormat($messages, $model, $context_messages);

            // Call LLM API - check if streaming is enabled in the style configuration
            $streaming_enabled = $this->model->isStreamingEnabled();

            if ($streaming_enabled) {
                // Start streaming response with context tracking
                $this->streaming_service->startStreamingResponse($conversation_id, $api_messages, $model, $is_new_conversation, $context_messages);
                return;
            } else {
                // Get complete response
                $response = $this->llm_service->callLlmApi($api_messages, $model, $temperature, $max_tokens);

                // Check for API error responses first
                if (is_array($response) && isset($response['error'])) {
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
                    $error_detail = '';
                    if (is_array($response)) {
                        if (isset($response['message'])) {
                            $error_detail = ': ' . $response['message'];
                        } elseif (isset($response['detail'])) {
                            $error_detail = ': ' . (is_string($response['detail']) ? $response['detail'] : json_encode($response['detail']));
                        }
                    } elseif (is_string($response)) {
                        $error_detail = ': ' . substr($response, 0, 200);
                    }
                    
                    throw new Exception('Invalid response from LLM API' . $error_detail);
                }
            }

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle "Continue" action in form mode
     * 
     * When the AI response doesn't contain a form (e.g., summary or conclusion),
     * this action allows the user to prompt the AI to continue the conversation
     * by generating the next form or response.
     */
    private function handleContinueConversation()
    {
        $user_id = $this->model->getUserId();

        // Check if user is authenticated
        if (!$user_id) {
            $this->sendJsonResponse(['error' => 'User not authenticated'], 401);
            return;
        }

        $conversation_id = $_POST['conversation_id'] ?? null;
        $model = $_POST['model'] ?? $this->model->getConfiguredModel();
        $temperature = $_POST['temperature'] ?? $this->model->getLlmTemperature();
        $max_tokens = $_POST['max_tokens'] ?? $this->model->getLlmMaxTokens();

        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID required'], 400);
            return;
        }

        try {
            // Verify conversation exists and belongs to user
            $conversation = $this->llm_service->getConversation($conversation_id, $user_id, $this->getRequestSectionId());
            if (!$conversation) {
                $this->sendJsonResponse(['error' => 'Conversation not found'], 404);
                return;
            }

            // Check rate limiting
            $rate_data = $this->llm_service->checkRateLimit($user_id);

            // Create a system message to prompt the AI to continue
            $continue_message = "Please continue with the next step or form.";
            
            // Save the continue request as a user message
            $messageId = $this->llm_service->addMessage($conversation_id, 'user', $continue_message, null, $model);

            // Update rate limiting
            $this->llm_service->updateRateLimit($user_id, $rate_data, $conversation_id);

            // Check if this is a streaming preparation request
            $is_streaming_prep = isset($_POST['prepare_streaming']) && $_POST['prepare_streaming'] === '1';

            if ($is_streaming_prep) {
                $savedMessage = [
                    'id' => $messageId,
                    'role' => 'user',
                    'content' => $continue_message,
                    'attachments' => null,
                    'model' => $model,
                    'created_at' => date('Y-m-d H:i:s')
                ];

                $this->sendJsonResponse([
                    'status' => 'prepared',
                    'conversation_id' => $conversation_id,
                    'is_new_conversation' => false,
                    'user_message' => $savedMessage
                ]);
                return;
            }

            // Get conversation messages for LLM
            $messages = $this->llm_service->getConversationMessages($conversation_id, 50);
            
            // Get conversation context if configured
            $context_messages = $this->model->getParsedConversationContext();

            // Apply form mode context if enabled
            if ($this->model->isFormModeEnabled()) {
                $context_messages = $this->form_mode_service->buildFormModeContext($context_messages);
            }

            // Convert messages to API format with context prepended
            $api_messages = $this->api_formatter_service->convertToApiFormat($messages, $model, $context_messages);

            // Check if streaming is enabled
            $streaming_enabled = $this->model->isStreamingEnabled();

            if ($streaming_enabled) {
                $this->streaming_service->startStreamingResponse($conversation_id, $api_messages, $model, false, $context_messages);
                return;
            } else {
                // Get complete response
                $response = $this->llm_service->callLlmApi($api_messages, $model, $temperature, $max_tokens);

                if (is_array($response) && isset($response['choices'][0]['message']['content'])) {
                    $assistant_message = $response['choices'][0]['message']['content'];
                    $tokens_used = $response['usage']['total_tokens'] ?? null;

                    $this->llm_service->addMessage($conversation_id, 'assistant', trim($assistant_message), null, $model, $tokens_used, $response, $context_messages);

                    $this->sendJsonResponse([
                        'conversation_id' => $conversation_id,
                        'message' => $assistant_message,
                        'streaming' => false,
                        'is_new_conversation' => false
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

            $conversation_id = $this->llm_service->createConversation($user_id, $title, $model, null, null, $this->getRequestSectionId());

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
     * Send JSON response and log user activity
     */
    private function sendJsonResponse($data, $status_code = 200)
    {
        // Log user activity for API requests before exiting
        // This ensures React API calls are tracked in user_activity table
        $this->logApiActivity();

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

    /**
     * Log API request activity to user_activity table
     * This captures React frontend API calls that would otherwise be missed
     * because they exit early via sendJsonResponse() instead of completing
     * the normal page rendering flow.
     */
    private function logApiActivity()
    {
        // Skip logging for frequent read-only operations to reduce DB load
        $skip_logging_actions = ['get_conversations', 'get_conversation', 'get_config', 'get_auto_started'];
        if (in_array($this->current_action, $skip_logging_actions)) {
            return;
        }
        
        // Only log if we have a valid action and user
        if (empty($this->current_action)) {
            return;
        }

        $user_id = $this->model->getUserId();
        if (!$user_id) {
            return;
        }

        try {
            $db = $this->model->get_services()->get_db();
            $exec_time = microtime(true) - $this->request_start_time;

            // Build params array for logging
            $params = [];
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // Log relevant POST params (exclude sensitive data)
                if (isset($_POST['conversation_id'])) {
                    $params['conversation_id'] = $_POST['conversation_id'];
                }
                if (isset($_POST['action'])) {
                    $params['action'] = $_POST['action'];
                }
            } else {
                // Log GET params
                if (isset($_GET['conversation_id'])) {
                    $params['conversation_id'] = $_GET['conversation_id'];
                }
                if (isset($_GET['action'])) {
                    $params['action'] = $_GET['action'];
                }
            }

            $db->insert("user_activity", [
                "id_users" => $user_id,
                "url" => $_SERVER['REQUEST_URI'],
                "id_type" => 2, // API request type
                "exec_time" => $exec_time,
                "keyword" => 'llm_api_' . $this->current_action,
                "params" => json_encode($params),
                "mobile" => 0
            ]);
        } catch (Exception $e) {
            // Don't fail the API response if logging fails
            error_log('LLM API activity logging failed: ' . $e->getMessage());
        }
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
            // Get section_id from request, fallback to model's section_id
            $section_id = $this->getRequestSectionId();

            // If a specific section_id is requested, create a new model instance for that section
            // to get the correct configuration for that section
            $config_model = $this->model;
            if ($section_id && $section_id !== $this->model->getSectionId()) {
                $config_model = new LlmchatModel($this->model->get_services(), $section_id);
            }

            // Build complete configuration for React component
            $config = [
                'userId' => $user_id,
                'sectionId' => $section_id ?: $this->model->getSectionId(),
                'currentConversationId' => $config_model->getConversationId(),
                'configuredModel' => $config_model->getConfiguredModel(),
                'maxFilesPerMessage' => LLM_MAX_FILES_PER_MESSAGE,
                'maxFileSize' => LLM_MAX_FILE_SIZE,
                'streamingEnabled' => $config_model->isStreamingEnabled(),
                'enableConversationsList' => $config_model->isConversationsListEnabled(),
                'enableFileUploads' => $config_model->isFileUploadsEnabled(),
                'enableFullPageReload' => $config_model->isFullPageReloadEnabled(),
                'acceptedFileTypes' => implode(',', array_map(fn($ext) => ".{$ext}", $config_model->getAcceptedFileTypes())),
                'isVisionModel' => $config_model->isVisionModel(),
                'hasConversationContext' => $config_model->hasConversationContext(),
                'strictConversationMode' => $config_model->isStrictConversationModeEnabled(),
                'autoStartConversation' => $config_model->isAutoStartConversationEnabled(),
                'autoStartMessage' => $config_model->getAutoStartMessage(),
                'enableFormMode' => $config_model->isFormModeEnabled(),
                'formModeActiveTitle' => $config_model->getFormModeActiveTitle(),
                'formModeActiveDescription' => $config_model->getFormModeActiveDescription(),
                'continueButtonLabel' => $config_model->getContinueButtonLabel(),
                'enableDataSaving' => $config_model->isDataSavingEnabled(),
                'enableMediaRendering' => $config_model->isMediaRenderingEnabled(),
                // Floating button configuration
                'enableFloatingButton' => $config_model->isFloatingButtonEnabled(),
                'floatingButtonPosition' => $config_model->getFloatingButtonPosition(),
                'floatingButtonIcon' => $config_model->getFloatingButtonIcon(),
                'floatingButtonLabel' => $config_model->getFloatingButtonLabel(),
                'floatingChatTitle' => $config_model->getFloatingChatTitle(),
                // UI Labels
                'messagePlaceholder' => $config_model->getMessagePlaceholder(),
                'noConversationsMessage' => $config_model->getNoConversationsMessage(),
                'newConversationTitleLabel' => $config_model->getNewConversationTitleLabel(),
                'conversationTitleLabel' => $config_model->getConversationTitleLabel(),
                'cancelButtonLabel' => $config_model->getCancelButtonLabel(),
                'createButtonLabel' => $config_model->getCreateButtonLabel(),
                'deleteConfirmationTitle' => $config_model->getDeleteConfirmationTitle(),
                'deleteConfirmationMessage' => $config_model->getDeleteConfirmationMessage(),
                'confirmDeleteButtonLabel' => $config_model->getConfirmDeleteButtonLabel(),
                'cancelDeleteButtonLabel' => $config_model->getCancelDeleteButtonLabel(),
                'tokensSuffix' => $config_model->getTokensUsedSuffix(),
                'aiThinkingText' => $config_model->getAiThinkingText(),
                'conversationsHeading' => $config_model->getConversationsHeading(),
                'newChatButtonLabel' => $config_model->getNewChatButtonLabel(),
                'selectConversationHeading' => $config_model->getSelectConversationHeading(),
                'selectConversationDescription' => $config_model->getSelectConversationDescription(),
                'modelLabelPrefix' => $config_model->getModelLabelPrefix(),
                'noMessagesMessage' => $config_model->getNoMessagesMessage(),
                'loadingText' => $config_model->getLoadingText(),
                'uploadImageLabel' => $config_model->getUploadImageLabel(),
                'uploadHelpText' => $config_model->getUploadHelpText(),
                'clearButtonLabel' => $config_model->getClearButtonLabel(),
                'submitButtonLabel' => $config_model->getSubmitButtonLabel(),
                'emptyMessageError' => $config_model->getEmptyMessageError(),
                'streamingActiveError' => $config_model->getStreamingActiveError(),
                'defaultChatTitle' => $config_model->getDefaultChatTitle(),
                'deleteButtonTitle' => $config_model->getDeleteButtonTitle(),
                'conversationTitlePlaceholder' => $config_model->getConversationTitlePlaceholder(),
                'singleFileAttachedText' => $config_model->getSingleFileAttachedText(),
                'multipleFilesAttachedText' => $config_model->getMultipleFilesAttachedText(),
                'emptyStateTitle' => $config_model->getEmptyStateTitle(),
                'emptyStateDescription' => $config_model->getEmptyStateDescription(),
                'loadingMessagesText' => $config_model->getLoadingMessagesText(),
                'streamingInProgressPlaceholder' => $config_model->getStreamingInProgressPlaceholder(),
                'attachFilesTitle' => $config_model->getAttachFilesTitle(),
                'noVisionSupportTitle' => $config_model->getNoVisionSupportTitle(),
                'noVisionSupportText' => $config_model->getNoVisionSupportText(),
                'sendMessageTitle' => $config_model->getSendMessageTitle(),
                'removeFileTitle' => $config_model->getRemoveFileTitle(),
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
     * Verifies conversation belongs to this section to support multiple llmChat instances
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
        $section_id = $this->getRequestSectionId();

        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID required'], 400);
            return;
        }

        try {
            // Verify conversation belongs to this section (prevents cross-section access)
            $conversation = $this->llm_service->getConversation($conversation_id, $user_id, $section_id);

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
     * Filters by section_id to support multiple llmChat instances on the same page
     */
    public function getConversationsData()
    {
        // Set a timeout for this operation to prevent hanging
        set_time_limit(10);
        
        $user_id = $this->model->getUserId();

        // Check if user is authenticated
        if (!$user_id) {
            $this->sendJsonResponse(['error' => 'User not authenticated'], 401);
            return;
        }

        try {
            // Use configured model from model getter for consistency
            $configured_model = $this->model->getConfiguredModel();
            $conversation_limit = (int)$this->model->getConversationLimit();
            $section_id = $this->getRequestSectionId();
            
            // Ensure we have valid parameters
            if ($conversation_limit <= 0) {
                $conversation_limit = 50;
            }
            
            // Filter by section_id to ensure each llmChat section shows only its own conversations
            $conversations = $this->llm_service->getUserConversations(
                $user_id, 
                $conversation_limit, 
                $configured_model,
                $section_id
            );
            
            // Ensure we always return an array
            if (!is_array($conversations)) {
                $conversations = [];
            }
            
            $this->sendJsonResponse(['conversations' => $conversations]);
        } catch (Exception $e) {
            error_log('LLM getConversationsData error for user ' . $user_id . ': ' . $e->getMessage());
            $this->sendJsonResponse(['error' => 'Failed to load conversations'], 500);
        }
    }

    /**
     * Save form data to SelfHelp UserInput system
     * 
     * Uses the standard SelfHelp dataTables/dataRows/dataCells architecture
     * via UserInput::save_data() for consistent data storage.
     * 
     * @param array $form_values The form field values (field_id => value)
     * @param int $user_id The user ID
     * @param int $message_id The message ID to link
     * @param int $conversation_id The conversation ID
     */
    private function saveFormDataToUserInput($form_values, $user_id, $message_id, $conversation_id)
    {
        try {
            $section_id = $this->getRequestSectionId();
            $save_mode = $this->model->getDataSaveMode();

            // Save using the refactored service that uses UserInput::save_data()
            $record_id = $this->data_saving_service->saveFormData(
                $section_id,
                $user_id,
                $form_values,
                [], // form_definition - not needed for UserInput system
                $message_id,
                $conversation_id,
                $save_mode
            );

            if ($record_id) {
                // Update the message to link to the saved data record
                $this->llm_service->updateMessage($message_id, [
                    'id_dataRows' => $record_id
                ]);
                
                error_log("LLM: Form data saved to dataRow {$record_id} for message {$message_id}");
            }
        } catch (Exception $e) {
            // Log error but don't fail the form submission
            error_log('LLM saveFormDataToUserInput error: ' . $e->getMessage());
        }
    }

}
?>
