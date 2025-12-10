<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseController.php";
require_once __DIR__ . "/ModuleLlmAdminConsoleModel.php";

/**
 * Controller for the LLM admin console component.
 * Handles AJAX-style requests for admin filters, conversations and messages.
 */
class ModuleLlmAdminConsoleController extends BaseController
{
    /**
     * Constructor.
     *
     * @param object $model
     *  The model instance of the component.
     */
    public function __construct($model)
    {
        parent::__construct($model);

        // Handle incoming requests immediately (GET/POST action based)
        $this->handleRequest();
    }

    /**
     * Route incoming requests based on action parameter.
     */
    private function handleRequest()
    {
        $action = $_GET['action'] ?? $_POST['action'] ?? null;

        if (!$action) {
            return; // No special handling required; continue with normal rendering
        }

        switch ($action) {
            case 'admin_filters':
                $this->handleAdminFilters();
                break;
            case 'admin_conversations':
                $this->handleAdminConversations();
                break;
            case 'admin_messages':
                $this->handleAdminMessages();
                break;
            default:
                $this->sendJsonResponse(['error' => 'Unknown action'], 400);
                break;
        }
    }

    /**
     * Handle admin filters request (users and sections).
     */
    private function handleAdminFilters()
    {
        try {
            $filters = $this->model->getAdminFilters();
            $this->sendJsonResponse(['filters' => $filters]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle admin conversations request with pagination and filters.
     */
    private function handleAdminConversations()
    {
        $page = (int)($_GET['page'] ?? 1);
        $per_page = (int)($_GET['per_page'] ?? $this->model->getAdminPageSize());
        $per_page = min($per_page > 0 ? $per_page : 50, 100); // sensible defaults, cap at 100

        $filters = [];
        if (!empty($_GET['user_id'])) {
            $filters['user_id'] = $_GET['user_id'];
        }
        if (!empty($_GET['section_id'])) {
            $filters['section_id'] = $_GET['section_id'];
        }
        if (!empty($_GET['q'])) {
            $filters['query'] = $_GET['q'];
        }

        try {
            $result = $this->model->getAdminConversations($filters, $page, $per_page);
            $this->sendJsonResponse($result);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle admin conversation messages request.
     */
    private function handleAdminMessages()
    {
        $conversation_id = $_GET['conversation_id'] ?? null;

        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID required'], 400);
            return;
        }

        try {
            $result = $this->model->getAdminConversationMessages($conversation_id);

            if ($result === null) {
                $this->sendJsonResponse(['error' => 'Conversation not found'], 404);
                return;
            }

            $this->sendJsonResponse($result);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Send JSON response and exit.
     */
    private function sendJsonResponse($data, $status_code = 200)
    {
        if (!headers_sent()) {
            http_response_code($status_code);
            header('Content-Type: application/json');
        }

        echo json_encode($data);
        exit;
    }
}
?>

