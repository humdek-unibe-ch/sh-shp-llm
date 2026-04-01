<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/BaseController.php";
require_once __DIR__ . "/../../LlmJsonResponseTrait.php";
require_once __DIR__ . "/../../../service/LlmService.php";
require_once __DIR__ . "/../../../service/LlmFileUploadService.php";
require_once __DIR__ . "/../../../service/LlmApiFormatterService.php";
require_once __DIR__ . "/../../../service/LlmStrictConversationService.php";
require_once __DIR__ . "/../../../service/LlmFormModeService.php";
require_once __DIR__ . "/../../../service/LlmFloatingModeService.php";
require_once __DIR__ . "/../../../service/LlmDataSavingService.php";
require_once __DIR__ . "/../../../service/LlmContextService.php";
require_once __DIR__ . "/../../../service/LlmProgressTrackingService.php";
require_once __DIR__ . "/../../../service/LlmResponseService.php";
require_once __DIR__ . "/../../../service/LlmDangerDetectionService.php";
require_once __DIR__ . "/../../../service/LlmSpeechToTextService.php";
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
class LlmChatController extends BaseController
{
    use LlmJsonResponseTrait;
    /** @var LlmService Core LLM service */
    private $llm_service;

    /** @var LlmContextService Context building service */
    private $context_service;

    /** @var LlmFileUploadService File upload service */
    private $file_upload_service;


    /** @var LlmFormModeService Form mode service */
    private $form_mode_service;

    /** @var LlmDataSavingService Data saving service */
    private $data_saving_service;

    /** @var LlmProgressTrackingService Progress tracking service */
    private $progress_tracking_service;

    /** @var LlmDangerDetectionService Danger detection service */
    private $danger_detection_service;

    /** @var LlmResponseService Unified response parsing and validation */
    private $response_service;

    /** @var LlmSpeechToTextService Speech-to-text transcription service */
    private $speech_service;

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

            // If no action, this is a page load - allow it
            if ($action === null) {
                return true;
            }

            // API request without section_id - reject
            return false;
        }

        // Validate section_id matches
        return (int) $requested_section_id === (int) $model_section_id;
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

        // Context services
        $floating_mode_service = new LlmFloatingModeService();
        $strict_conversation_service = new LlmStrictConversationService($this->llm_service);
        $api_formatter_service = new LlmApiFormatterService($services);


        // Progress tracking service - created before context service so it can be injected
        $this->progress_tracking_service = new LlmProgressTrackingService($services);

        // Danger detection service - handles notifications when LLM detects danger
        // Safety detection is performed by LLM via structured response schema
        $this->danger_detection_service = new LlmDangerDetectionService($services, $this->model);

        // Response service - unified response parsing and validation
        $this->response_service = new LlmResponseService($this->model, $services);

        // Composite services
        $this->context_service = new LlmContextService(
            $this->model,
            $this->form_mode_service,
            $floating_mode_service,
            $strict_conversation_service,
            $api_formatter_service,
            $this->progress_tracking_service  // Pass progress tracking service for context building
        );

        // Speech-to-text service (standalone, separate from LLM chat)
        $this->speech_service = new LlmSpeechToTextService($services);
    }

    /**
     * Route incoming request to appropriate handler
     */
    private function handleRequest()
    {
        // Check if this is a mobile rendering request (no action, just structure)
        if (isset($_POST['mobile']) && $_POST['mobile'] && !isset($_POST['action']) && !isset($_GET['action'])) {
            // This is a mobile request for component structure, not an action
            // Skip request handling and let the view render normally
            return;
        }

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
            case 'confirm_topic':
                $this->handleConfirmTopic();
                break;
            case 'speech_transcribe':
                $this->handleSpeechTranscribe();
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
            case 'start_auto_conversation':
                $this->handleStartAutoConversation();
                break;
            case 'get_progress':
                $this->handleGetProgress();
                break;
            case 'debug_progress':
                $this->handleDebugProgress();
                break;
            default:
                // Regular page load - no auto-start logic here anymore
                // Auto-start is now handled client-side after page load
                break;
        }
    }

    /* Message Handling *******************************************************/

    /**
     * Resolve conversation with rate limiting: checks rate limit, resolves or creates
     * conversation, updates title for new conversations, and updates rate limit.
     *
     * @param int $user_id User ID
     * @param string|null $conversation_id Existing conversation ID (may be null)
     * @param string|null $title_hint Text to use for auto-titling new conversations
     * @return array ['conversation_id' => int, 'is_new' => bool]
     * @throws Exception on rate limit or DB errors
     */
    private function resolveConversationWithRateLimit($user_id, $conversation_id, $title_hint = null)
    {
        $section_id = $this->model->getSectionId();
        $rate_data = $this->llm_service->checkRateLimit($user_id);

        $conv_result = $this->llm_service->resolveConversation(
            $user_id,
            $conversation_id,
            $rate_data,
            $this->model->getConfiguredModel(),
            $this->model->getLlmTemperature(),
            $this->model->getLlmMaxTokens(),
            $section_id
        );

        if ($conv_result['is_new'] && $title_hint) {
            $this->llm_service->updateNewConversationTitle($conv_result['conversation_id'], $user_id, $title_hint);
        }

        $this->llm_service->updateRateLimit($user_id, $rate_data, $conv_result['conversation_id']);

        return $conv_result;
    }

    /**
     * Handle send message request
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
        $section_id = $this->model->getSectionId();

        if ($this->guardConversationBlocked($conversation_id)) {
            return;
        }

        try {
            $conv = $this->resolveConversationWithRateLimit($user_id, $conversation_id, $message);
            $conversation_id = $conv['conversation_id'];

            $uploaded_files = $this->file_upload_service->handleFileUploads($user_id, $section_id, $conversation_id);

            $message_id = $this->llm_service->addMessage(
                $conversation_id,
                'user',
                $message,
                $uploaded_files,
                $this->model->getConfiguredModel()
            );

            if ($uploaded_files && $message_id) {
                $this->file_upload_service->updateFileNamesWithMessageId($user_id, $section_id, $conversation_id, $message_id, $uploaded_files);
            }

            $this->sendLlmRequestAndRespond($conversation_id, $conv['is_new']);

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
        $section_id = $this->model->getSectionId();

        if ($this->guardConversationBlocked($conversation_id)) {
            return;
        }

        $form_values = $this->form_mode_service->parseFormValues($form_values_json);
        if ($form_values === null) {
            $this->sendJsonResponse(['error' => 'Invalid form values'], 400);
            return;
        }

        if (!$this->form_mode_service->hasSelections($form_values)) {
            $this->sendJsonResponse(['error' => 'Please select at least one option before submitting'], 400);
            return;
        }

        if (empty($readable_text)) {
            $readable_text = $this->form_mode_service->generateReadableTextFromFormValues($form_values);
        }

        if (empty($readable_text)) {
            $this->sendJsonResponse(['error' => 'Could not generate form submission text'], 400);
            return;
        }

        try {
            $conv = $this->resolveConversationWithRateLimit($user_id, $conversation_id, $readable_text);
            $conversation_id = $conv['conversation_id'];

            $form_metadata = $this->form_mode_service->createFormMetadata($form_values);
            $message_id = $this->llm_service->addMessage(
                $conversation_id,
                'user',
                $readable_text,
                $form_metadata,
                $this->model->getConfiguredModel()
            );

            if ($this->model->isDataSavingEnabled()) {
                $this->saveFormDataToUserInput($form_values, $user_id, $message_id, $conversation_id);
            } else {
                // Direct memory dispatch is a fallback only when the canonical
                // UserInput/form-action pipeline is unavailable.
                $this->dispatchMemoryUpdateIfEnabled($form_values, $readable_text, $user_id, $section_id, $conversation_id, $message_id);
            }

            if ($this->model->isProgressTrackingEnabled()) {
                $this->handleTopicConfirmationIfApplicable($form_values, $conversation_id, $section_id);
            }

            $this->sendLlmRequestAndRespond($conversation_id, $conv['is_new']);

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
        $section_id = $this->model->getSectionId();

        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID required'], 400);
            return;
        }

        try {
            $conversation = $this->llm_service->getConversation($conversation_id, $user_id, $section_id);
            if (!$conversation) {
                $this->sendJsonResponse(['error' => 'Conversation not found'], 404);
                return;
            }

            $rate_data = $this->llm_service->checkRateLimit($user_id);

            $this->llm_service->addMessage(
                $conversation_id,
                'user',
                "Please continue with the next step or form.",
                null,
                $this->model->getConfiguredModel()
            );

            $this->llm_service->updateRateLimit($user_id, $rate_data, $conversation_id);

            $this->sendLlmRequestAndRespond($conversation_id, false);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Send request to LLM and respond
     * 
     * Saves ALL LLM response attempts (including failed validation attempts) to the database.
     * Failed attempts are marked with is_validated=0 and are only visible in admin mode.
     * 
     * @param int $conversation_id The conversation ID
     * @param bool $is_new_conversation Whether this is a new conversation
     */
    private function sendLlmRequestAndRespond($conversation_id, $is_new_conversation)
    {
        // Get messages and build API request
        $messages = $this->llm_service->getConversationMessages($conversation_id, 50);
        if (empty($messages)) {
            $this->sendJsonResponse(['error' => 'No messages found in conversation'], 400);
            return;
        }

        // Get section ID for progress tracking context
        $section_id = $this->model->getSectionId();

        // Build API messages with progress tracking context if enabled
        $api_messages = $this->context_service->buildApiMessages($messages, $conversation_id, $section_id);
        if (empty($api_messages)) {
            $this->sendJsonResponse(['error' => 'No valid messages to send'], 400);
            return;
        }

        $context_messages = $this->context_service->getContextForTracking();

        // Call API with schema validation and retry logic
        $llm_callable = function ($messages) use ($conversation_id, $context_messages) {
            return $this->llm_service->callLlmApi(
                $messages,
                $this->model->getConfiguredModel(),
                $this->model->getLlmTemperature(),
                $this->model->getLlmMaxTokens(),
                [
                    'conversation_id' => $conversation_id,
                    'sent_context' => $context_messages,
                    // mark false initially; final validated attempt is updated below
                    'is_validated' => false
                ]
            );
        };

        $result = $this->response_service->callLlmWithSchemaValidation($llm_callable, $api_messages);
        $parsed = $result['response'];
        $response = $result['raw_response'];
        $response_data = $this->buildLlmResponseData(
            $parsed,
            $response,
            $result,
            $conversation_id,
            $is_new_conversation,
            $messages,
            $api_messages,
            $context_messages
        );

        $this->sendJsonResponse($response_data);
    }

    /**
     * Build the response data array from LLM result
     *
     * @param array $parsed Parsed response from schema validation
     * @param array|null $response Raw API response
     * @param array $result Full result from callLlmWithSchemaValidation
     * @param int $conversation_id Conversation ID
     * @param bool $is_new_conversation Whether this is a new conversation
     * @param array $messages Conversation messages
     * @param array $api_messages API messages sent to LLM
     * @param array $context_messages Context messages for tracking
     * @return array Response data for JSON response
     */
    private function buildLlmResponseData($parsed, $response, $result, $conversation_id, $is_new_conversation, $messages, $api_messages, $context_messages)
    {
        if ($response && isset($response['content'])) {
            $assistant_message = $response['content'];
            $tokens_used = $response['usage']['total_tokens'] ?? null;
            $reasoning = $response['reasoning'] ?? null;
            $raw_response = $response['raw_response'] ?? $response;
        } else {
            $fallback_data = $parsed['data'] ?? $parsed;
            $assistant_message = $this->extractMessageFromFallback($fallback_data);
            $tokens_used = null;
            $reasoning = null;
            $raw_response = ['error' => $result['error'] ?? 'LLM API request failed'];
        }
        $request_payload = $result['request_payload'] ?? $api_messages;

        if ($parsed['valid'] && isset($parsed['data'])) {
            $user_id = $this->model->getUserId();
            $this->handleSafetyDetection($parsed['data'], $conversation_id, $user_id);
        }

        $logged_message_id = $response['logged_message_id']
            ?? ($result['raw_response']['logged_message_id'] ?? null);
        if ($logged_message_id) {
            $this->llm_service->updateMessage($logged_message_id, [
                'is_validated' => $result['valid'] ? 1 : 0
            ]);
        }

        $response_data = [
            'conversation_id' => $conversation_id,
            'message' => $assistant_message,
            'is_new_conversation' => $is_new_conversation
        ];

        if ($parsed['valid'] && isset($parsed['data'])) {
            $response_data['structured'] = $parsed['data'];
            $safety = $this->response_service->assessSafety($parsed['data']);
            if (!$safety['is_safe'] || $safety['danger_level'] !== null) {
                $response_data['safety'] = $safety;
            }
        }

        if ($this->model->isProgressTrackingEnabled()) {
            $response_data['progress'] = $this->calculateConversationProgress($conversation_id, $messages);
        }

        return $response_data;
    }

    /**
     * Delegate safety detection to the danger detection service
     */
    private function handleSafetyDetection($parsed_response, $conversation_id, $user_id)
    {
        $this->danger_detection_service->processSafetyDetection(
            $this->response_service,
            $parsed_response,
            $conversation_id,
            $user_id,
            $this->model->getSectionId()
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
            $rate_data = $this->llm_service->checkRateLimit($user_id);
            $conversation_id = $this->llm_service->createConversation(
                $user_id,
                $title,
                $this->model->getConfiguredModel(),
                null,
                null,
                $section_id
            );
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
        $user_id = $this->validateUserOrFail();

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
     * Handle confirm topic request
     * 
     * Marks a topic as confirmed/understood by the user.
     * This is part of the confirmation-based progress tracking system.
     */
    private function handleConfirmTopic()
    {
        $user_id = $this->validateUserOrFail();

        $conversation_id = $_POST['conversation_id'] ?? null;
        $topic_id = $_POST['topic_id'] ?? null;

        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID required'], 400);
            return;
        }

        if (!$topic_id) {
            $this->sendJsonResponse(['error' => 'Topic ID required'], 400);
            return;
        }

        try {
            $section_id = $this->model->getSectionId();

            // Verify user owns this conversation
            $conversation = $this->llm_service->getConversation($conversation_id, $user_id, $section_id);
            if (!$conversation) {
                $this->sendJsonResponse(['error' => 'Conversation not found'], 404);
                return;
            }

            // Get all topics from context for proper percentage calculation
            $context = $this->model->getConversationContext();
            $all_topics = $this->progress_tracking_service->extractTopicsFromContext($context);

            // Confirm the topic
            $success = $this->progress_tracking_service->confirmTopic($conversation_id, $section_id, $topic_id, $all_topics);

            if ($success) {
                // Return updated progress
                $messages = $this->llm_service->getConversationMessages($conversation_id, 50);
                $progress = $this->calculateConversationProgress($conversation_id, $messages);

                $this->sendJsonResponse([
                    'success' => true,
                    'topic_id' => $topic_id,
                    'progress' => $progress
                ]);
            } else {
                $this->sendJsonResponse(['error' => 'Failed to confirm topic'], 500);
            }
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
            $config = $this->model->getChatConfig();
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
            $conversation = $this->llm_service->getConversation($conversation_id, $user_id, $section_id);
            if (!$conversation) {
                $this->sendJsonResponse(['error' => 'Conversation not found'], 404);
                return;
            }

            $messages = $this->llm_service->getConversationMessages($conversation_id) ?: [];

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
            $conversation = $this->llm_service->getConversation($conversation_id, $user_id, $section_id);
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
            $messages = $this->llm_service->getConversationMessages($conversation_id) ?: [];

            // Calculate progress
            $progress = $this->calculateConversationProgress($conversation_id, $messages);

            $this->sendJsonResponse(['progress' => $progress]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle debug progress request - for troubleshooting topic extraction.
     * Only available when DEBUG mode is enabled (security: prevents system prompt exposure).
     */
    private function handleDebugProgress()
    {
        if (!defined('DEBUG') || !DEBUG) {
            $this->sendJsonResponse(['error' => 'Debug endpoint is only available in DEBUG mode'], 403);
            return;
        }

        $user_id = $this->validateUserOrFail();
        $conversation_id = $_GET['conversation_id'] ?? null;
        $section_id = $this->model->getSectionId();

        try {
            $context = $this->model->getConversationContext();
            $debug = $this->progress_tracking_service->debugTopicExtraction($context);

            $debug['progress_tracking_enabled'] = $this->model->isProgressTrackingEnabled();
            $debug['section_id'] = $section_id;
            $debug['conversation_id'] = $conversation_id;
            $debug['raw_context_full'] = $context;

            if ($conversation_id) {
                $conversation = $this->llm_service->getConversation($conversation_id, $user_id, $section_id);
                if ($conversation) {
                    $messages = $this->llm_service->getConversationMessages($conversation_id) ?: [];
                    $userMessages = array_filter($messages, function ($m) {
                        return isset($m['role']) && $m['role'] === 'user';
                    });
                    $debug['total_messages'] = count($messages);
                    $debug['user_messages'] = count($userMessages);
                    $debug['user_message_contents'] = array_map(function ($m) {
                        return substr($m['content'], 0, 200);
                    }, array_values($userMessages));
                }
            }

            $this->sendJsonResponse(['debug' => $debug]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /* Speech-to-Text *********************************************************/

    /**
     * Handle speech-to-text transcription request
     * 
     * Receives audio data from the client and returns transcribed text.
     * This is completely separate from the LLM chat functionality and
     * only provides voice-to-text conversion for easier message input.
     * 
     * No permanent storage of audio data (privacy-first approach).
     */
    private function handleSpeechTranscribe()
    {
        // Validate user is logged in
        $user_id = $this->validateUserOrFail();

        // Check if speech-to-text is enabled for this section
        if (!$this->model->isSpeechToTextEnabled()) {
            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Speech-to-text is not enabled for this chat'
            ], 400);
            return;
        }

        // Check if audio model is configured
        $speechModel = $this->model->getSpeechToTextModel();
        if (empty($speechModel)) {
            $this->sendJsonResponse([
                'success' => false,
                'error' => 'No speech-to-text model configured'
            ], 400);
            return;
        }

        // Validate audio file was uploaded
        if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
            $uploadError = isset($_FILES['audio']) ? $_FILES['audio']['error'] : 'No file';
            $this->sendJsonResponse([
                'success' => false,
                'error' => 'No audio file provided or upload failed (error: ' . $uploadError . ')'
            ], 400);
            return;
        }

        $audioFile = $_FILES['audio'];

        // Validate file size
        if ($audioFile['size'] > LLM_MAX_AUDIO_SIZE) {
            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Audio file too large. Maximum size is 25MB.'
            ], 400);
            return;
        }

        // Validate MIME type
        $mimeType = $audioFile['type'] ?? '';
        if (!$this->speech_service->isValidAudioType($mimeType)) {
            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Invalid audio format. Supported: WebM/Opus, OGG/Opus, M4A/MP4, MP3, FLAC'
            ], 400);
            return;
        }

        try {
            // Get context information for file naming
            $section_id = $this->model->getSectionId();
            $conversation_id = $_POST['conversation_id'] ?? null;

            // Get user's language from session for better transcription accuracy
            $language = $this->speech_service->getUserLanguage();

            // Save audio file and transcribe
            // Audio files are saved with naming: {user_id}_{section_id}_{conversation_id}_audio_{timestamp}_{random}.{ext}
            $result = $this->speech_service->saveAndTranscribeAudio(
                $audioFile,
                $user_id,
                $section_id,
                $conversation_id,
                $speechModel,
                $language,
                true  // Keep the audio file after transcription
            );

            // Return the result (includes audio_file info)
            $this->sendJsonResponse($result);

        } catch (Exception $e) {
            error_log("Speech transcription error: " . $e->getMessage());
            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Speech transcription failed: ' . $e->getMessage()
            ], 500);
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
        $config = $this->model->getProgressTrackingConfig();
        $config['section_id'] = $this->model->getSectionId();

        return $this->progress_tracking_service->calculateAndUpdateProgress(
            $conversation_id,
            $messages,
            $this->model->getConversationContext(),
            $config,
            $include_debug
        );
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
            $conversation_limit = (int) $this->model->getConversationLimit();
            if ($conversation_limit <= 0) {
                $conversation_limit = 50;
            }

            $conversations = $this->llm_service->getUserConversations(
                $user_id,
                $conversation_limit,
                $this->model->getConfiguredModel(),
                $section_id
            );

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
     * Handle start auto conversation request (client-initiated auto-start)
     */
    private function handleStartAutoConversation()
    {
        $user_id = $this->model->getUserId();
        if (!$user_id) {
            $this->sendJsonResponse(['error' => 'User not authenticated'], 401);
            return;
        }

        if (!$this->model->isAutoStartConversationEnabled()) {
            $this->sendJsonResponse(['error' => 'Auto-start conversation is not enabled'], 400);
            return;
        }

        // Check if conversation already exists
        if ($this->model->getCurrentConversation()) {
            $this->sendJsonResponse(['error' => 'Conversation already exists'], 400);
            return;
        }

        // Check existing conversations in this section
        if ($this->model->isConversationsListEnabled()) {
            $section_id = $this->model->getSectionId();
            $user_conversations = $this->llm_service->getUserConversations(
                $user_id,
                1,
                $this->model->getConfiguredModel(),
                $section_id
            );
            if (!empty($user_conversations)) {
                $this->sendJsonResponse(['error' => 'Conversations already exist'], 400);
                return;
            }
        }

        try {
            // Perform the auto-start conversation logic
            $this->performAutoStartConversation();

            // Return success - the conversation should now be available via get_auto_started
            $this->sendJsonResponse(['success' => true]);
        } catch (Exception $e) {
            error_log('Client-initiated auto-start failed: ' . $e->getMessage());
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
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

            $response = $this->llm_service->callLlmApi(
                $api_messages,
                $model,
                $temperature,
                $max_tokens,
                [
                    'conversation_id' => $conversation_id,
                    'sent_context' => $context_messages,
                    'is_validated' => true
                ]
            );

            // Response is normalized by provider
            if (isset($response['content'])) {
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
     * Check if conversation is blocked and send error response if so
     * 
     * @param string|null $conversation_id The conversation ID to check
     * @return bool True if conversation is blocked (response already sent)
     */
    private function guardConversationBlocked($conversation_id)
    {
        if ($conversation_id && $this->danger_detection_service->isConversationBlocked($conversation_id)) {
            $this->sendJsonResponse([
                'blocked' => true,
                'type' => 'conversation_blocked',
                'message' => 'This conversation has been blocked due to safety concerns. Please start a new conversation.',
                'error' => 'Conversation blocked'
            ]);
            return true;
        }
        return false;
    }

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
            $this->model->get_services()->get_router()->log_user_activity();
            if (function_exists('uopz_allow_exit')) {
                uopz_allow_exit(true);
            }
            exit;
        }
        return $user_id;
    }

    /**
     * Save form data to SelfHelp UserInput system
     */
    /**
     * Dispatch a memory update from this chat form submission if the memory system is enabled.
     * When data saving is disabled on this section, this is the only memory trigger path;
     * otherwise form-actions on the saved data can also trigger memory updates.
     */
    private function dispatchMemoryUpdateIfEnabled($form_values, $readable_text, $user_id, $section_id, $conversation_id, $message_id)
    {
        try {
            require_once __DIR__ . "/../../../service/LlmMemoryConfigService.php";
            require_once __DIR__ . "/../../../service/LlmMemoryTriggerService.php";

            $config_service = new LlmMemoryConfigService($this->services);
            if (!$config_service->isMemoryEnabled()) {
                return;
            }

            $trigger_service = new LlmMemoryTriggerService($this->services, $config_service);
            $normalized = $trigger_service->normalizeLlmChatFormPayload(
                $form_values, $readable_text, $user_id, $section_id, $conversation_id, $message_id
            );

            $rule_keys = $this->model->getMemoryRuleKeys();
            if (!empty($rule_keys)) {
                $trigger_service->dispatchForRuleKeys($rule_keys, $normalized);
            } else {
                $trigger_service->dispatchMemoryUpdate($normalized);
            }
        } catch (Exception $e) {
            error_log('LLM memory dispatch error in chat form: ' . $e->getMessage());
        }
    }

    private function saveFormDataToUserInput($form_values, $user_id, $message_id, $conversation_id)
    {
        try {
            $section_id = $this->model->getSectionId();
            $save_mode = $this->model->getDataSaveMode();
            $own_entries_only = $this->model->getOwnEntriesOnly();

            $record_id = $this->data_saving_service->saveFormData(
                $section_id,
                $user_id,
                $form_values,
                [],
                $message_id,
                $conversation_id,
                $save_mode,
                $own_entries_only
            );

            if ($record_id) {
                $this->llm_service->updateMessage($message_id, ['id_dataRows' => $record_id]);
                if (defined('DEBUG') && DEBUG) {
                    error_log("LLM: Form data saved to dataRow {$record_id} for message {$message_id}");
                }
            }
        } catch (Exception $e) {
            error_log('LLM saveFormDataToUserInput error: ' . $e->getMessage());
        }
    }

    /**
     * Delegate topic confirmation processing to the progress tracking service
     */
    private function handleTopicConfirmationIfApplicable($form_values, $conversation_id, $section_id)
    {
        $this->progress_tracking_service->processTopicConfirmation(
            $form_values,
            $conversation_id,
            $section_id,
            $this->model->getConversationContext()
        );
    }

    /**
     * Extract message content from a fallback response structure
     * 
     * When LLM API fails completely, LlmResponseService creates a fallback
     * structure that needs to be converted to a displayable message.
     * 
     * @param array $fallback_data The fallback response data
     * @return string Message content to display
     */
    private function extractMessageFromFallback($fallback_data)
    {
        // Try to get text from text_blocks
        if (isset($fallback_data['content']['text_blocks'])) {
            $texts = [];
            foreach ($fallback_data['content']['text_blocks'] as $block) {
                if (isset($block['content'])) {
                    $texts[] = $block['content'];
                }
            }
            if (!empty($texts)) {
                return implode("\n\n", $texts);
            }
        }

        // Try direct content field
        if (isset($fallback_data['content']) && is_string($fallback_data['content'])) {
            return $fallback_data['content'];
        }

        // Fallback to generic error message
        return '[API ERROR] LLM API request failed - no response received';
    }

    /**
     * Hook: log API activity before sending response
     */
    protected function beforeSendJsonResponse()
    {
        $this->logApiActivity();
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

            $url = $_SERVER['REQUEST_URI'] ?? '';
            if (strlen($url) > 200) {
                $url = substr($url, 0, 197) . '...';
            }

            $db->insert("user_activity", [
                "id_users" => $user_id,
                "url" => $url,
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
