<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/BaseController.php";
require_once __DIR__ . "/../../../service/LlmService.php";
require_once __DIR__ . "/../../../service/LlmFileUploadService.php";
require_once __DIR__ . "/../../../service/LlmApiFormatterService.php";
require_once __DIR__ . "/../../../service/LlmStreamingService.php";
require_once __DIR__ . "/../../../service/StrictConversationService.php";
require_once __DIR__ . "/../../../service/LlmFormModeService.php";
require_once __DIR__ . "/../../../service/LlmFloatingModeService.php";
require_once __DIR__ . "/../../../service/LlmDataSavingService.php";
require_once __DIR__ . "/../../../service/LlmRequestService.php";
require_once __DIR__ . "/../../../service/LlmContextService.php";
require_once __DIR__ . "/../../../service/LlmProgressTrackingService.php";
require_once __DIR__ . "/../../../service/LlmStructuredResponseService.php";

/**
 * LLM Chat Controller
 * 
 * Handles API requests for the LLM chat component.
 * 
 * IMPORTANT: Section ID Validation
 * ================================
 * Every request (GET/POST) must include section_id parameter.
 * The controller validates that the requested section_id matches
 * this model's section_id before processing. This ensures that
 * when multiple llmChat instances exist on the same page, each
 * controller only handles requests meant for its section.
 * 
 * Request Flow:
 * 1. Constructor checks if section_id matches this model
 * 2. If no match, constructor returns early (another controller will handle)
 * 3. If match, initialize services and process the request
 * 
 * @author SelfHelp Team
 */
class LlmchatController extends BaseController
{
    /** @var LlmService Core LLM service */
    private $llm_service;
    
    /** @var LlmRequestService Request handling service */
    private $request_service;
    
    /** @var LlmContextService Context building service */
    private $context_service;
    
    /** @var LlmFileUploadService File upload service */
    private $file_upload_service;
    
    /** @var LlmStreamingService Streaming service */
    private $streaming_service;
    
    /** @var LlmFormModeService Form mode service */
    private $form_mode_service;
    
    /** @var LlmDataSavingService Data saving service */
    private $data_saving_service;

    /** @var LlmProgressTrackingService Progress tracking service */
    private $progress_tracking_service;

    /** @var float Request start time for activity logging */
    private $request_start_time;

    /** @var string|null Current API action being processed */
    private $current_action;

    /* Constructors ***********************************************************/

    /**
     * Constructor
     * 
     * Validates section_id and routes requests to appropriate handlers.
     * 
     * @param object $model The model instance
     */
    public function __construct($model)
    {
        parent::__construct($model);

        // CRITICAL: Validate section_id FIRST
        // This ensures only the correct controller handles the request
        if (!$this->isRequestForThisSection() || $model->get_services()->get_router()->current_keyword == 'admin') {
            return; // Another controller will handle this request
        }

        // Track request timing for activity logging
        $this->request_start_time = microtime(true);
        $this->current_action = null;

        // Initialize services
        $this->initializeServices();

        // Handle data requests (special case - early return)
        $router = $model->get_services()->get_router();
        if (is_array($router->route['params']) && isset($router->route['params']['data'])) {
            $model->return_data($router->route['params']['data']);
            return;
        }

        // Route the request
        $this->handleRequest();
    }

    /**
     * Check if the incoming request is meant for this section
     * 
     * Every API request must include section_id. This method validates
     * that the requested section matches this controller's model section.
     * 
     * @return bool True if request should be handled by this controller
     */
    private function isRequestForThisSection()
    {
        $requested_section_id = $_GET['section_id'] ?? $_POST['section_id'] ?? null;
        $model_section_id = $this->model->getSectionId();

        // For regular page loads (no section_id param), check if model section matches
        if ($requested_section_id === null) {
            // Allow regular page loads - no API action
            $action = $_GET['action'] ?? $_POST['action'] ?? null;
            $is_streaming = ($action === 'streaming');

            // If no action and not streaming, this is a page load - allow it
            if ($action === null && !$is_streaming) {
                return true;
            }
            
            // API request without section_id - reject
            return false;
        }

        // Validate section_id matches
        return (int)$requested_section_id === (int)$model_section_id;
    }

    /**
     * Initialize all required services
     */
    private function initializeServices()
    {
        $services = $this->model->get_services();
        
        // Core services
        $this->llm_service = new LlmService($services);
        $this->file_upload_service = new LlmFileUploadService($this->llm_service);
        $this->form_mode_service = new LlmFormModeService();
        $this->data_saving_service = new LlmDataSavingService($services);
        
        // Context and streaming services
        $floating_mode_service = new LlmFloatingModeService();
        $strict_conversation_service = new StrictConversationService($this->llm_service);
        $api_formatter_service = new LlmApiFormatterService();
        
        $this->streaming_service = new LlmStreamingService($this->llm_service);

        $structured_response_service = new LlmStructuredResponseService();
        
        // Composite services
        $this->request_service = new LlmRequestService($this->llm_service, $this->model);
        $this->context_service = new LlmContextService(
            $this->model,
            $this->form_mode_service,
            $floating_mode_service,
            $strict_conversation_service,
            $api_formatter_service,
            $structured_response_service
        );
        
        // Progress tracking service
        $this->progress_tracking_service = new LlmProgressTrackingService($services);
    }

    /**
     * Route incoming request to appropriate handler
     */
    private function handleRequest()
    {
        // Handle POST requests
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? 'send_message';
            $this->current_action = $action;
            $this->handlePostRequest($action);
            return;
        }

        // Handle GET requests
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $action = $_GET['action'] ?? null;
            $this->current_action = $action;
            $this->handleGetRequest($action);
            return;
        }
    }

    /**
     * Handle POST requests
     * 
     * @param string $action The action to perform
     */
    private function handlePostRequest($action)
    {
        switch ($action) {
            case 'send_message':
                $this->handleSendMessage();
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
                // Legacy: handle direct message POST
                if (isset($_POST['message'])) {
                    $this->handleSendMessage();
                }
                break;
        }
    }

    /**
     * Handle GET requests
     *
     * @param string|null $action The action to perform
     */
    private function handleGetRequest($action)
    {
        switch ($action) {
            case 'get_config':
                $this->handleGetConfig();
                break;
            case 'get_conversation':
                $this->handleGetConversation();
                break;
            case 'get_conversations':
                $this->handleGetConversations();
                break;
            case 'get_auto_started':
                $this->handleGetAutoStarted();
                break;
            case 'get_progress':
                $this->handleGetProgress();
                break;
            case 'debug_progress':
                $this->handleDebugProgress();
                break;
            case 'streaming':
                $this->handleStreaming();
                break;
            default:
                // Regular page load - check for auto-start
                $this->checkAndAutoStartConversation();
                break;
        }
    }

    /* Message Handling *******************************************************/

    /**
     * Handle send message request
     * 
     * Supports both regular and streaming preparation modes.
     */
    private function handleSendMessage()
    {
        $user_id = $this->validateUserOrFail();
        
        $message = trim($_POST['message'] ?? '');
        if (empty($message)) {
            $this->sendJsonResponse(['error' => 'Message cannot be empty'], 400);
            return;
        }

        $conversation_id = $_POST['conversation_id'] ?? null;
        $is_streaming_prep = ($_POST['prepare_streaming'] ?? '') === '1';
        $section_id = $this->model->getSectionId();

        try {
            // Check rate limiting
            $rate_data = $this->request_service->checkRateLimit($user_id);

            // Get or create conversation
            $conv_result = $this->request_service->getOrCreateConversation(
                $user_id,
                $conversation_id,
                $rate_data,
                $section_id
            );
            $conversation_id = $conv_result['conversation_id'];
            $is_new_conversation = $conv_result['is_new'];

            // Update title for new conversations
            if ($is_new_conversation) {
                $this->request_service->updateNewConversationTitle($conversation_id, $user_id, $message);
            }

            // Handle file uploads
            $uploaded_files = $this->file_upload_service->handleFileUploads($conversation_id);

            // Save user message
            $message_id = $this->request_service->addUserMessage($conversation_id, $message, $uploaded_files);

            // Update file names with message ID
            if ($uploaded_files && $message_id) {
                $this->file_upload_service->updateFileNamesWithMessageId($conversation_id, $message_id, $uploaded_files);
            }

            // Update rate limiting
            $this->request_service->updateRateLimit($user_id, $rate_data, $conversation_id);

            // If streaming preparation, return early
            if ($is_streaming_prep) {
                $this->sendStreamingPrepResponse($conversation_id, $is_new_conversation, $message_id, $message, $uploaded_files);
                return;
            }

            // Send to LLM and get response
            $this->sendLlmRequestAndRespond($conversation_id, $is_new_conversation);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle form submission
     */
    private function handleFormSubmission()
    {
        $user_id = $this->validateUserOrFail();
        
        $form_values_json = $_POST['form_values'] ?? '{}';
        $readable_text = trim($_POST['readable_text'] ?? '');
        $conversation_id = $_POST['conversation_id'] ?? null;
        $is_streaming_prep = ($_POST['prepare_streaming'] ?? '') === '1';
        $section_id = $this->model->getSectionId();

        // Parse and validate form values
        $form_values = $this->form_mode_service->parseFormValues($form_values_json);
        if ($form_values === null) {
            $this->sendJsonResponse(['error' => 'Invalid form values'], 400);
            return;
        }

        if (!$this->form_mode_service->hasSelections($form_values)) {
            $this->sendJsonResponse(['error' => 'Please select at least one option before submitting'], 400);
            return;
        }

        // Generate readable text if not provided
        if (empty($readable_text)) {
            $readable_text = $this->form_mode_service->generateReadableTextFromFormValues($form_values);
        }

        if (empty($readable_text)) {
            $this->sendJsonResponse(['error' => 'Could not generate form submission text'], 400);
            return;
        }

        try {
            // Check rate limiting
            $rate_data = $this->request_service->checkRateLimit($user_id);

            // Get or create conversation
            $conv_result = $this->request_service->getOrCreateConversation(
                $user_id,
                $conversation_id,
                $rate_data,
                $section_id
            );
            $conversation_id = $conv_result['conversation_id'];
            $is_new_conversation = $conv_result['is_new'];

            // Update title for new conversations
            if ($is_new_conversation) {
                $this->request_service->updateNewConversationTitle($conversation_id, $user_id, $readable_text);
            }

            // Save user message with form metadata
            $form_metadata = $this->form_mode_service->createFormMetadata($form_values);
            $message_id = $this->request_service->addUserMessage($conversation_id, $readable_text, $form_metadata);

            // Save form data to SelfHelp UserInput if enabled
            if ($this->model->isDataSavingEnabled()) {
                $this->saveFormDataToUserInput($form_values, $user_id, $message_id, $conversation_id);
            }

            // Update rate limiting
            $this->request_service->updateRateLimit($user_id, $rate_data, $conversation_id);

            // If streaming preparation, return early
            if ($is_streaming_prep) {
                $this->sendStreamingPrepResponse($conversation_id, $is_new_conversation, $message_id, $readable_text, $form_metadata);
                return;
            }

            // Send to LLM and get response
            $this->sendLlmRequestAndRespond($conversation_id, $is_new_conversation);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle continue conversation (for form mode when no form is present)
     */
    private function handleContinueConversation()
    {
        $user_id = $this->validateUserOrFail();
        
        $conversation_id = $_POST['conversation_id'] ?? null;
        $is_streaming_prep = ($_POST['prepare_streaming'] ?? '') === '1';
        $section_id = $this->model->getSectionId();

        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID required'], 400);
            return;
        }

        try {
            // Verify conversation exists
            $conversation = $this->request_service->getConversation($conversation_id, $user_id, $section_id);
            if (!$conversation) {
                $this->sendJsonResponse(['error' => 'Conversation not found'], 404);
                return;
            }

            // Check rate limiting
            $rate_data = $this->request_service->checkRateLimit($user_id);

            // Add continue message
            $continue_message = "Please continue with the next step or form.";
            $message_id = $this->request_service->addUserMessage($conversation_id, $continue_message);

            // Update rate limiting
            $this->request_service->updateRateLimit($user_id, $rate_data, $conversation_id);

            // If streaming preparation, return early
            if ($is_streaming_prep) {
                $this->sendStreamingPrepResponse($conversation_id, false, $message_id, $continue_message, null);
                return;
            }

            // Send to LLM and get response
            $this->sendLlmRequestAndRespond($conversation_id, false);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Send request to LLM and respond (non-streaming or streaming)
     * 
     * @param int $conversation_id The conversation ID
     * @param bool $is_new_conversation Whether this is a new conversation
     */
    private function sendLlmRequestAndRespond($conversation_id, $is_new_conversation)
    {
        // Get messages and build API request
        $messages = $this->request_service->getConversationMessages($conversation_id, 50);
        if (empty($messages)) {
            $this->sendJsonResponse(['error' => 'No messages found in conversation'], 400);
            return;
        }

        $api_messages = $this->context_service->buildApiMessages($messages);
        if (empty($api_messages)) {
            $this->sendJsonResponse(['error' => 'No valid messages to send'], 400);
            return;
        }

        $context_messages = $this->context_service->getContextForTracking();

        // Check if streaming is enabled
        if ($this->context_service->isStreamingEnabled()) {
            $model = $this->context_service->getConfiguredModel();

            // Prepare progress data if progress tracking is enabled
            $progress_data = null;
            if ($this->model->isProgressTrackingEnabled()) {
                $progress_data = $this->calculateConversationProgress($conversation_id, $messages);
            }

            $this->streaming_service->startStreamingResponse(
                $conversation_id,
                $api_messages,
                $model,
                $is_new_conversation,
                $context_messages,
                $progress_data
            );
            return;
        }

        // Non-streaming: call API and save response
        $response = $this->request_service->callLlmApi($api_messages);
        
        // Handle API errors
        if (is_array($response) && isset($response['error'])) {
            $error_message = $this->extractApiErrorMessage($response);
            throw new Exception($error_message);
        }

        // Handle successful response
        if (is_array($response) && isset($response['choices'][0]['message']['content'])) {
            $assistant_message = $response['choices'][0]['message']['content'];
            $tokens_used = $response['usage']['total_tokens'] ?? null;

            $this->request_service->addAssistantMessage(
                $conversation_id,
                $assistant_message,
                $tokens_used,
                $response,
                $context_messages
            );

            $response_data = [
                'conversation_id' => $conversation_id,
                'message' => $assistant_message,
                'streaming' => false,
                'is_new_conversation' => $is_new_conversation
            ];

            // Include progress data if progress tracking is enabled
            if ($this->model->isProgressTrackingEnabled()) {
                $response_data['progress'] = $this->calculateConversationProgress($conversation_id, $messages);
            }

            $this->sendJsonResponse($response_data);
        } else {
            throw new Exception('Invalid response from LLM API');
        }
    }

    /**
     * Send streaming preparation response
     */
    private function sendStreamingPrepResponse($conversation_id, $is_new_conversation, $message_id, $content, $attachments)
    {
        $_SESSION['streaming_conversation_id'] = $conversation_id;
        $_SESSION['streaming_message'] = $content;
        $_SESSION['streaming_model'] = $this->model->getConfiguredModel();

        $this->sendJsonResponse([
            'status' => 'prepared',
            'conversation_id' => $conversation_id,
            'is_new_conversation' => $is_new_conversation,
            'user_message' => [
                'id' => $message_id,
                'role' => 'user',
                'content' => $content,
                'attachments' => $attachments,
                'model' => $this->model->getConfiguredModel(),
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
    }

    /* Streaming **************************************************************/

    /**
     * Handle streaming request
     */
    private function handleStreaming()
    {
        $conversation_id = $_GET['conversation_id'] ?? null;
        if (!$conversation_id) {
            $this->sendSSE(['type' => 'error', 'message' => 'Conversation ID required']);
            exit;
        }

        $user_id = $this->model->getUserId();
        if (!$user_id) {
            $this->sendSSE(['type' => 'error', 'message' => 'User not authenticated']);
            exit;
        }

        $section_id = $this->model->getSectionId();
        $conversation = $this->request_service->getConversation($conversation_id, $user_id, $section_id);
        if (!$conversation) {
            $this->sendSSE(['type' => 'error', 'message' => 'Conversation not found']);
            exit;
        }

        $messages = $this->request_service->getConversationMessages($conversation_id, 50);
        if (empty($messages)) {
            $this->sendSSE(['type' => 'error', 'message' => 'No messages found in conversation']);
            exit;
        }

        $api_messages = $this->context_service->buildApiMessages($messages);
        if (empty($api_messages)) {
            $this->sendSSE(['type' => 'error', 'message' => 'No valid messages to send']);
            exit;
        }

        $context_messages = $this->context_service->getContextForTracking();
        $model = $this->context_service->getConfiguredModel();

        // Prepare progress data if progress tracking is enabled
        $progress_data = null;
        if ($this->model->isProgressTrackingEnabled()) {
            $progress_data = $this->calculateConversationProgress($conversation_id, $messages);
        }

        $this->streaming_service->startStreamingResponse(
            $conversation_id,
            $api_messages,
            $model,
            false,
            $context_messages,
            $progress_data
        );
    }

    /* Conversation Management ************************************************/

    /**
     * Handle new conversation creation
     */
    private function handleNewConversation()
    {
        $user_id = $this->validateUserOrFail();
        
        if (!$this->model->isConversationsListEnabled()) {
            $this->sendJsonResponse(['error' => 'Creating new conversations is not allowed when conversations list is disabled'], 403);
            return;
        }

        $title = trim($_POST['title'] ?? 'New Conversation');
        $section_id = $this->model->getSectionId();

        try {
            $rate_data = $this->request_service->checkRateLimit($user_id);
            $conversation_id = $this->request_service->createConversation($user_id, $title, $section_id);
            $this->request_service->updateRateLimit($user_id, $rate_data, $conversation_id);

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
        $user_id = $this->validateUserOrFail();
        
        $conversation_id = $_POST['conversation_id'] ?? null;
        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID required'], 400);
            return;
        }

        try {
            $this->request_service->deleteConversation($conversation_id, $user_id);
            $this->sendJsonResponse(['success' => true]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /* GET Request Handlers ***************************************************/

    /**
     * Handle get config request
     */
    private function handleGetConfig()
    {
        $user_id = $this->validateUserOrFail();

        try {
            $config = $this->buildChatConfig();
            $this->sendJsonResponse(['config' => $config]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle get conversation request
     */
    private function handleGetConversation()
    {
        $user_id = $this->validateUserOrFail();
        
        $conversation_id = $_GET['conversation_id'] ?? null;
        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID required'], 400);
            return;
        }

        $section_id = $this->model->getSectionId();

        try {
            $conversation = $this->request_service->getConversation($conversation_id, $user_id, $section_id);
            if (!$conversation) {
                $this->sendJsonResponse(['error' => 'Conversation not found'], 404);
                return;
            }

            $messages = $this->request_service->getConversationMessages($conversation_id) ?: [];

            // Send raw markdown content - React will handle markdown rendering
            // Keep formatted_content for backward compatibility with vanilla JS implementation

            $response = [
                'conversation' => $conversation,
                'messages' => $messages
            ];

            // Include progress data if progress tracking is enabled
            if ($this->model->isProgressTrackingEnabled()) {
                $response['progress'] = $this->calculateConversationProgress($conversation_id, $messages);
            }

            $this->sendJsonResponse($response);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle get progress request
     */
    private function handleGetProgress()
    {
        $user_id = $this->validateUserOrFail();
        
        $conversation_id = $_GET['conversation_id'] ?? null;
        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID required'], 400);
            return;
        }

        $section_id = $this->model->getSectionId();

        try {
            // Verify conversation ownership
            $conversation = $this->request_service->getConversation($conversation_id, $user_id, $section_id);
            if (!$conversation) {
                $this->sendJsonResponse(['error' => 'Conversation not found'], 404);
                return;
            }

            // Check if progress tracking is enabled
            if (!$this->model->isProgressTrackingEnabled()) {
                $this->sendJsonResponse(['error' => 'Progress tracking is not enabled'], 400);
                return;
            }

            // Get messages for progress calculation
            $messages = $this->request_service->getConversationMessages($conversation_id) ?: [];

            // Calculate progress
            $progress = $this->calculateConversationProgress($conversation_id, $messages);

            $this->sendJsonResponse(['progress' => $progress]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle debug progress request - for troubleshooting topic extraction
     * This endpoint doesn't require authentication for debugging purposes
     */
    private function handleDebugProgress()
    {
        $conversation_id = $_GET['conversation_id'] ?? null;
        $section_id = $this->model->getSectionId();

        try {
            $context = $this->model->getConversationContext();
            
            // Get debug info from service
            $debug = $this->progress_tracking_service->debugTopicExtraction($context);
            
            // Add additional info
            $debug['progress_tracking_enabled'] = $this->model->isProgressTrackingEnabled();
            $debug['section_id'] = $section_id;
            $debug['conversation_id'] = $conversation_id;
            $debug['raw_context_full'] = $context; // Show full context for debugging
            
            // If we have a conversation and user is logged in, get messages
            if ($conversation_id) {
                try {
                    $user_id = $this->validateUserOrFail();
                    $conversation = $this->request_service->getConversation($conversation_id, $user_id, $section_id);
                    if ($conversation) {
                        $messages = $this->request_service->getConversationMessages($conversation_id) ?: [];
                        $userMessages = array_filter($messages, function($m) {
                            return isset($m['role']) && $m['role'] === 'user';
                        });
                        $debug['total_messages'] = count($messages);
                        $debug['user_messages'] = count($userMessages);
                        $debug['user_message_contents'] = array_map(function($m) {
                            return substr($m['content'], 0, 200);
                        }, array_values($userMessages));
                    }
                } catch (Exception $e) {
                    $debug['message_error'] = $e->getMessage();
                }
            }
            
            $this->sendJsonResponse(['debug' => $debug]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Calculate progress for a conversation
     * 
     * @param int $conversation_id Conversation ID
     * @param array $messages Conversation messages
     * @param bool $include_debug Include debug information in response
     * @return array Progress data
     */
    private function calculateConversationProgress($conversation_id, $messages, $include_debug = false)
    {
        $section_id = $this->model->getSectionId();
        $context = $this->model->getConversationContext();

        // Extract topics from context
        $topics = $this->progress_tracking_service->extractTopicsFromContext($context);

        // Get previous progress to ensure monotonic increase
        $existing_progress = $this->progress_tracking_service->getConversationProgress($conversation_id, $section_id);
        $previous_percentage = $existing_progress ? (float)$existing_progress['progress_percentage'] : 0;

        // Calculate current progress
        $progress = $this->progress_tracking_service->calculateProgress(
            $conversation_id,
            $topics,
            $messages,
            $previous_percentage
        );

        // Update progress in database (only if topics exist)
        if (!empty($topics)) {
            $this->progress_tracking_service->updateConversationProgress(
                $conversation_id,
                $section_id,
                $progress['percentage'],
                $progress['topic_coverage']
            );
        }

        // Add configuration to response
        $progress['config'] = $this->model->getProgressTrackingConfig();

        // Add debug info if requested or if no topics found
        if ($include_debug || empty($topics)) {
            // Check for both markdown and HTML format
            $hasMarkdownSection = (bool)preg_match('/#{1,3}\s*TRACKABLE_TOPICS/i', $context);
            $hasHtmlSection = (bool)preg_match('/<h[1-3][^>]*>\s*TRACKABLE_TOPICS\s*<\/h[1-3]>/i', $context);
            
            $progress['debug'] = [
                'context_length' => strlen($context),
                'context_preview' => substr($context, 0, 500),
                'topics_found' => count($topics),
                'has_trackable_topics_section' => $hasMarkdownSection || $hasHtmlSection,
                'has_trackable_topics_section_markdown' => $hasMarkdownSection,
                'has_trackable_topics_section_html' => $hasHtmlSection,
                'has_topic_markers' => (bool)preg_match('/\[TOPIC:/i', $context),
                'user_messages_count' => count(array_filter($messages, fn($m) => ($m['role'] ?? '') === 'user')),
                'is_html_content' => strpos($context, '<h') !== false || strpos($context, '<p') !== false,
            ];
            
            if (empty($topics)) {
                $progress['debug']['error'] = 'No topics found in context. Use ## TRACKABLE_TOPICS section or [TOPIC: Name | keywords] markers.';
            }
        }

        return $progress;
    }

    /**
     * Handle get conversations list request
     */
    private function handleGetConversations()
    {
        set_time_limit(10);
        
        $user_id = $this->validateUserOrFail();
        $section_id = $this->model->getSectionId();

        try {
            $conversation_limit = (int)$this->model->getConversationLimit();
            if ($conversation_limit <= 0) {
                $conversation_limit = 50;
            }

            $conversations = $this->request_service->getUserConversations($user_id, $conversation_limit, $section_id);
            
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
     * Handle get auto-started conversation request
     */
    private function handleGetAutoStarted()
    {
        $user_id = $this->model->getUserId();
        if (!$user_id) {
            $this->sendJsonResponse(['auto_started' => false]);
            return;
        }

        $session_key = 'llm_auto_started_' . $this->model->getSectionId();
        if (!isset($_SESSION[$session_key]) || empty($_SESSION[$session_key])) {
            $this->sendJsonResponse(['auto_started' => false]);
            return;
        }

        $auto_started_conversation_id = $_SESSION[$session_key];
        $conversation = $this->llm_service->getConversation($auto_started_conversation_id, $user_id);
        
        if (!$conversation) {
            unset($_SESSION[$session_key]);
            $this->sendJsonResponse(['auto_started' => false]);
            return;
        }

        $messages = $this->llm_service->getConversationMessages($auto_started_conversation_id, 50);
        if (empty($messages)) {
            $this->sendJsonResponse(['auto_started' => false]);
            return;
        }

        // Clear session flag after returning data
        unset($_SESSION[$session_key]);

        $this->sendJsonResponse([
            'auto_started' => true,
            'conversation' => $conversation,
            'messages' => $messages
        ]);
    }

    /* Auto-Start *************************************************************/

    /**
     * Check and perform auto-start conversation if needed
     */
    private function checkAndAutoStartConversation()
    {
        if (!$this->model->isAutoStartConversationEnabled()) {
            return;
        }

        $user_id = $this->model->getUserId();
        if (!$user_id) {
            return;
        }

        // Check if conversation already exists
        if ($this->model->getCurrentConversation()) {
            return;
        }

        // Check existing conversations
        if ($this->model->isConversationsListEnabled()) {
            $user_conversations = $this->llm_service->getUserConversations(
                $user_id,
                1,
                $this->model->getConfiguredModel()
            );
            if (!empty($user_conversations)) {
                return;
            }
        }

        $this->performAutoStartConversation();
    }

    /**
     * Perform auto-start conversation
     */
    private function performAutoStartConversation()
    {
        $user_id = $this->model->getUserId();
        if (!$user_id) {
            return;
        }

        try {
            $rate_data = $this->llm_service->checkRateLimit($user_id);
            $section_id = $this->model->getSectionId();

            $conversation_id = $this->llm_service->getOrCreateConversationForModel(
                $user_id,
                $this->model->getConfiguredModel(),
                $this->model->getLlmTemperature(),
                $this->model->getLlmMaxTokens(),
                $section_id
            );

            // Generate title
            $title = 'AI Assistant - ' . date('M j, H:i');
            $this->llm_service->updateConversation($conversation_id, $user_id, ['title' => $title]);

            // Get context messages
            $context_messages = $this->context_service->buildContextMessages();

            if ($this->model->isFormModeEnabled()) {
                $this->performFormModeAutoStart($conversation_id, $user_id, $context_messages, $rate_data);
            } else {
                $auto_start_message = $this->model->generateContextAwareAutoStartMessage();

                $this->llm_service->addMessage(
                    $conversation_id,
                    'assistant',
                    $auto_start_message,
                    null,
                    $this->model->getConfiguredModel(),
                    null,
                    null,
                    $context_messages
                );

                $this->llm_service->updateRateLimit($user_id, $rate_data, $conversation_id);

                $session_key = 'llm_auto_started_' . $section_id;
                $_SESSION[$session_key] = $conversation_id;
            }

        } catch (Exception $e) {
            error_log('LLM Auto-start failed: ' . $e->getMessage());
        }
    }

    /**
     * Perform form mode auto-start
     */
    private function performFormModeAutoStart($conversation_id, $user_id, $context_messages, $rate_data)
    {
        try {
            $initial_prompt = [
                [
                    'role' => 'user',
                    'content' => 'Please start the conversation by providing the first form for me to fill out.'
                ]
            ];

            $api_messages = array_merge($context_messages, $initial_prompt);
            $model = $this->model->getConfiguredModel();
            $temperature = $this->model->getLlmTemperature();
            $max_tokens = $this->model->getLlmMaxTokens();

            $response = $this->llm_service->callLlmApi($api_messages, $model, $temperature, $max_tokens);

            if (is_array($response) && isset($response['choices'][0]['message']['content'])) {
                $assistant_message = $response['choices'][0]['message']['content'];
                $tokens_used = $response['usage']['total_tokens'] ?? null;

                $this->llm_service->addMessage(
                    $conversation_id,
                    'assistant',
                    $assistant_message,
                    null,
                    $model,
                    $tokens_used,
                    $response,
                    $context_messages
                );

                $this->llm_service->updateRateLimit($user_id, $rate_data, $conversation_id);

                $session_key = 'llm_auto_started_' . $this->model->getSectionId();
                $_SESSION[$session_key] = $conversation_id;
            } else {
                error_log('LLM Form Mode Auto-start: Invalid LLM response');
                $this->llm_service->addMessage(
                    $conversation_id,
                    'assistant',
                    'I apologize, but I was unable to generate the initial form. Please try refreshing the page.',
                    null,
                    $model,
                    null,
                    null,
                    $context_messages
                );

                $session_key = 'llm_auto_started_' . $this->model->getSectionId();
                $_SESSION[$session_key] = $conversation_id;
            }

        } catch (Exception $e) {
            error_log('LLM Form Mode Auto-start failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /* Helper Methods *********************************************************/

    /**
     * Validate user is authenticated or send error response
     * 
     * @return int User ID
     */
    private function validateUserOrFail()
    {
        $user_id = $this->model->getUserId();
        if (!$user_id) {
            $this->sendJsonResponse(['error' => 'User not authenticated'], 401);
            exit;
        }
        return $user_id;
    }

    /**
     * Build chat configuration for React component
     * 
     * @return array Configuration array
     */
    private function buildChatConfig()
    {
        $section_id = $this->model->getSectionId();
        
        return [
            'userId' => $this->model->getUserId(),
            'sectionId' => $section_id,
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
            'strictConversationMode' => $this->model->isStrictConversationModeEnabled(),
            'autoStartConversation' => $this->model->isAutoStartConversationEnabled(),
            'autoStartMessage' => $this->model->getAutoStartMessage(),
            'enableFormMode' => $this->model->isFormModeEnabled(),
            'formModeActiveTitle' => $this->model->getFormModeActiveTitle(),
            'formModeActiveDescription' => $this->model->getFormModeActiveDescription(),
            'continueButtonLabel' => $this->model->getContinueButtonLabel(),
            'enableDataSaving' => $this->model->isDataSavingEnabled(),
            'enableMediaRendering' => $this->model->isMediaRenderingEnabled(),
            // Floating button configuration
            'enableFloatingButton' => $this->model->isFloatingButtonEnabled(),
            'floatingButtonPosition' => $this->model->getFloatingButtonPosition(),
            'floatingButtonIcon' => $this->model->getFloatingButtonIcon(),
            'floatingButtonLabel' => $this->model->getFloatingButtonLabel(),
            'floatingChatTitle' => $this->model->getFloatingChatTitle(),
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
            ],
            // Progress tracking config
            'enableProgressTracking' => $this->model->isProgressTrackingEnabled(),
            'progressBarLabel' => $this->model->getProgressBarLabel(),
            'progressCompleteMessage' => $this->model->getProgressCompleteMessage(),
            'progressShowTopics' => $this->model->shouldShowProgressTopics()
        ];
    }

    /**
     * Extract error message from API response
     * 
     * @param array $response API response with error
     * @return string Error message
     */
    private function extractApiErrorMessage($response)
    {
        if (is_array($response['error'])) {
            if (isset($response['error']['message'])) {
                return $response['error']['message'];
            }
            if (isset($response['error']['type'])) {
                return 'LLM API error: ' . $response['error']['type'];
            }
        }
        if (is_string($response['error'])) {
            return $response['error'];
        }
        return 'LLM API error';
    }

    /**
     * Save form data to SelfHelp UserInput system
     */
    private function saveFormDataToUserInput($form_values, $user_id, $message_id, $conversation_id)
    {
        try {
            $section_id = $this->model->getSectionId();
            $save_mode = $this->model->getDataSaveMode();

            $record_id = $this->data_saving_service->saveFormData(
                $section_id,
                $user_id,
                $form_values,
                [],
                $message_id,
                $conversation_id,
                $save_mode
            );

            if ($record_id) {
                $this->request_service->updateMessage($message_id, ['id_dataRows' => $record_id]);
                error_log("LLM: Form data saved to dataRow {$record_id} for message {$message_id}");
            }
        } catch (Exception $e) {
            error_log('LLM saveFormDataToUserInput error: ' . $e->getMessage());
        }
    }

    /**
     * Send SSE event
     * 
     * @param array $data Event data
     */
    private function sendSSE($data)
    {
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();

        if (isset($data['type']) && $data['type'] === 'chunk') {
            usleep(5000);
        }
    }

    /**
     * Send JSON response and log activity
     * 
     * @param array $data Response data
     * @param int $status_code HTTP status code
     */
    private function sendJsonResponse($data, $status_code = 200)
    {
        $this->logApiActivity();

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
     * Log API activity
     */
    private function logApiActivity()
    {
        // Skip logging for frequent read-only operations
        $skip_logging_actions = ['get_conversations', 'get_conversation', 'get_config', 'get_auto_started'];
        if (in_array($this->current_action, $skip_logging_actions)) {
            return;
        }

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

            $params = [];
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if (isset($_POST['conversation_id'])) {
                    $params['conversation_id'] = $_POST['conversation_id'];
                }
                if (isset($_POST['action'])) {
                    $params['action'] = $_POST['action'];
                }
            } else {
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
                "id_type" => 2,
                "exec_time" => $exec_time,
                "keyword" => 'llm_api_' . $this->current_action,
                "params" => json_encode($params),
                "mobile" => 0
            ]);
        } catch (Exception $e) {
            error_log('LLM API activity logging failed: ' . $e->getMessage());
        }
    }
}
?>
