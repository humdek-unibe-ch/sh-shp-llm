<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseController.php";
require_once __DIR__ . "/../LlmJsonResponseTrait.php";
require_once __DIR__ . "/ModuleLlmAdminConsoleModel.php";

/**
 * Controller for the LLM Admin Console module.
 *
 * Handles all AJAX requests from the React-based admin console UI
 * for moderating and inspecting LLM conversations. Supports filtering,
 * pagination, message inspection, and moderation actions (block/unblock/delete).
 *
 * Actions are dispatched via the `action` GET/POST parameter. All responses
 * are returned as JSON through LlmJsonResponseTrait.
 *
 * @package LLM Plugin
 * @see ModuleLlmAdminConsoleModel For data retrieval logic
 */
class ModuleLlmAdminConsoleController extends BaseController
{
    use LlmJsonResponseTrait;

    /**
     * @param ModuleLlmAdminConsoleModel $model Model providing admin data operations
     */
    public function __construct($model)
    {
        parent::__construct($model);
        $this->handleRequest();
    }

    /**
     * Dispatch incoming request to the appropriate handler based on `action` parameter.
     *
     * Supported actions:
     * - admin_filters: Retrieve available filter options (users, sections, scripts)
     * - admin_conversations: Paginated, filterable conversation list
     * - admin_messages: Full message history for a single conversation
     * - admin_delete_conversation: Soft-delete a conversation (POST)
     * - admin_block_conversation: Block a conversation with optional reason (POST)
     * - admin_unblock_conversation: Remove block from a conversation (POST)
     */
    private function handleRequest()
    {
        $action = $_GET['action'] ?? $_POST['action'] ?? null;
        if (!$action) {
            return;
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
            case 'admin_delete_conversation':
                $this->handleAdminDeleteConversation();
                break;
            case 'admin_block_conversation':
                $this->handleAdminBlockConversation();
                break;
            case 'admin_unblock_conversation':
                $this->handleAdminUnblockConversation();
                break;
            default:
                $this->sendJsonResponse(['error' => 'Unknown action'], 400);
                break;
        }
    }

    /**
     * Return the set of available filter options for the admin conversation list.
     *
     * Provides users, sections, and scripts so the React UI can populate
     * its filter dropdowns without a separate configuration endpoint.
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
     * Return a paginated, filterable list of conversations for admin review.
     *
     * Accepts optional GET parameters: user_id, section_id, script_id, q (search),
     * date_from, date_to, page, per_page (max 100).
     */
    private function handleAdminConversations()
    {
        $page = (int)($_GET['page'] ?? 1);
        $per_page = (int)($_GET['per_page'] ?? $this->model->getAdminPageSize());
        $per_page = min($per_page > 0 ? $per_page : 50, 100);

        $filters = [];
        if (!empty($_GET['user_id'])) {
            $filters['user_id'] = $_GET['user_id'];
        }
        if (!empty($_GET['section_id'])) {
            $filters['section_id'] = $_GET['section_id'];
        }
        if (!empty($_GET['script_id'])) {
            $filters['script_id'] = $_GET['script_id'];
        }
        if (!empty($_GET['q'])) {
            $filters['query'] = $_GET['q'];
        }
        if (!empty($_GET['date_from'])) {
            $filters['date_from'] = $_GET['date_from'];
        }
        if (!empty($_GET['date_to'])) {
            $filters['date_to'] = $_GET['date_to'];
        }

        try {
            $result = $this->model->getAdminConversations($filters, $page, $per_page);
            $this->sendJsonResponse($result);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Return the full message history for a specific conversation.
     *
     * Includes all messages (validated and unvalidated) with raw_response,
     * sent_context, reasoning, and request_payload for admin inspection.
     *
     * Requires GET parameter: conversation_id.
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
     * Soft-delete a conversation and all its messages (POST).
     *
     * Requires POST parameter: conversation_id.
     * Logs the admin user who performed the deletion.
     */
    private function handleAdminDeleteConversation()
    {
        $conversation_id = $_POST['conversation_id'] ?? null;
        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID required'], 400);
            return;
        }

        try {
            $admin_user_id = isset($_SESSION['id_user']) ? $_SESSION['id_user'] : null;
            $result = $this->model->adminDeleteConversation($conversation_id, $admin_user_id);
            if ($result) {
                $this->sendJsonResponse(['success' => true, 'message' => 'Conversation deleted successfully']);
            } else {
                $this->sendJsonResponse(['error' => 'Failed to delete conversation'], 500);
            }
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Block a conversation with an optional reason (POST).
     *
     * Blocked conversations prevent the user from sending further messages.
     * Requires POST parameter: conversation_id. Optional: reason.
     */
    private function handleAdminBlockConversation()
    {
        $conversation_id = $_POST['conversation_id'] ?? null;
        $reason = $_POST['reason'] ?? null;
        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID required'], 400);
            return;
        }

        try {
            $admin_user_id = isset($_SESSION['id_user']) ? $_SESSION['id_user'] : null;
            $result = $this->model->adminBlockConversation($conversation_id, $reason, $admin_user_id);
            if ($result) {
                $this->sendJsonResponse(['success' => true, 'message' => 'Conversation blocked successfully']);
            } else {
                $this->sendJsonResponse(['error' => 'Failed to block conversation'], 500);
            }
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove block status from a conversation (POST).
     *
     * Allows the user to resume sending messages in the conversation.
     * Requires POST parameter: conversation_id.
     */
    private function handleAdminUnblockConversation()
    {
        $conversation_id = $_POST['conversation_id'] ?? null;
        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID required'], 400);
            return;
        }

        try {
            $admin_user_id = isset($_SESSION['id_user']) ? $_SESSION['id_user'] : null;
            $result = $this->model->adminUnblockConversation($conversation_id, $admin_user_id);
            if ($result) {
                $this->sendJsonResponse(['success' => true, 'message' => 'Conversation unblocked successfully']);
            } else {
                $this->sendJsonResponse(['error' => 'Failed to unblock conversation'], 500);
            }
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
?>
