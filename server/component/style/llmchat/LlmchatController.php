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

            // Save user message
            $this->llm_service->addMessage($conversation_id, 'user', $message, null, $model);

            // Update rate limiting with the current rate data
            $this->llm_service->updateRateLimit($user_id, $rate_data, $conversation_id);

            // Get conversation messages for LLM
            $messages = $this->llm_service->getConversationMessages($conversation_id, 50);
            $api_messages = $this->convertToApiFormat($messages);

            // Call LLM API
            if ($this->model->isStreamingEnabled()) {
                // Start streaming response
                $this->sendJsonResponse([
                    'conversation_id' => $conversation_id,
                    'streaming' => true
                ]);
            } else {
                // Get complete response
                $response = $this->llm_service->callLlmApi($api_messages, $model);

                if (isset($response['choices'][0]['message']['content'])) {
                    $assistant_message = $response['choices'][0]['message']['content'];
                    $tokens_used = $response['usage']['total_tokens'] ?? null;

                    // Save assistant message with full response for debugging
                    $this->llm_service->addMessage($conversation_id, 'assistant', $assistant_message, null, $model, $tokens_used, json_encode($response));

                    $this->sendJsonResponse([
                        'conversation_id' => $conversation_id,
                        'message' => $assistant_message,
                        'streaming' => false
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
     * Send JSON response
     */
    private function sendJsonResponse($data, $status_code = 200)
    {
        http_response_code($status_code);
        header('Content-Type: application/json');
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

            $messages = $this->llm_service->getConversationMessages($conversation_id);

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
            $conversations = $this->llm_service->getUserConversations($this->model->getUserId(), 50);
            $this->sendJsonResponse(['conversations' => $conversations]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
?>
