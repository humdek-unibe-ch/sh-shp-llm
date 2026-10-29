<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseController.php";
require_once __DIR__ . "/../LlmJsonResponseTrait.php";
require_once __DIR__ . "/ModuleLlmAdminConsoleModel.php";
require_once __DIR__ . "/../../service/LlmCsrfService.php";
require_once __DIR__ . "/../../service/LlmErrorReporter.php";
require_once __DIR__ . "/../../service/exception/LlmException.php";

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
 * Access: the page ACL (`select`) lets a user in and read. Each action then asks
 * for its own ACL mode on the page - `update` to block/unblock, `delete` to
 * delete - and the changing actions must be POSTs carrying the session CSRF token.
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

        // ACL mode every action needs on this page (the page itself only asks for `select`)
        $required_mode = [
            'admin_filters' => 'select',
            'admin_conversations' => 'select',
            'admin_messages' => 'select',
            'admin_block_conversation' => 'update',
            'admin_unblock_conversation' => 'update',
            'admin_delete_conversation' => 'delete',
        ];
        if (!is_string($action) || !isset($required_mode[$action])) {
            $this->sendJsonResponse(['error' => 'Unknown action'], 400);
            return;
        }
        if (!$this->model->hasPageAccess($required_mode[$action])) {
            $this->sendJsonResponse(['error' => 'Access denied'], 403);
            return;
        }
        if ($required_mode[$action] !== 'select') {
            // Changing actions: POST only, with the session CSRF token
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                $this->sendJsonResponse(['error' => 'Method not allowed'], 405);
                return;
            }
            if (!LlmCsrfService::verifyRequest()) {
                $this->sendJsonResponse(['error' => 'Invalid CSRF token'], 403);
                return;
            }
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
            $this->sendFailure('Admin console filters', $e);
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
        if (!empty($_GET['source_type'])) {
            if (!is_scalar($_GET['source_type'])) {
                $this->sendJsonResponse(['error' => 'Invalid source_type filter'], 400);
                return;
            }
            $source_type = (string)$_GET['source_type'];
            if (!preg_match('/^[a-z0-9_]+$/', $source_type)) {
                $this->sendJsonResponse(['error' => 'Invalid source_type filter'], 400);
                return;
            }
            $filters['source_type'] = $source_type;
        }
        if (isset($_GET['record_id']) && $_GET['record_id'] !== '') {
            $record_id = $_GET['record_id'];
            if ($record_id === 'draft') {
                $filters['record_id'] = 'draft';
            } elseif (is_scalar($record_id) && preg_match('/^\d+$/', (string)$record_id)
                && (int)$record_id > 0
            ) {
                $filters['record_id'] = (int)$record_id;
            } else {
                $this->sendJsonResponse(['error' => 'Invalid record_id filter'], 400);
                return;
            }
        }

        try {
            $result = $this->model->getAdminConversations($filters, $page, $per_page);
            $this->sendJsonResponse($result);
        } catch (Exception $e) {
            $this->sendFailure('Admin console conversations', $e);
        }
    }

    /**
     * Return the full message history for a specific conversation.
     *
     * Includes all messages (validated and unvalidated). The sent context and
     * request payload of each message (the prompts and settings behind the
     * answers) are only included for users with `update` access.
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
            $result = $this->model->getAdminConversationMessages($conversation_id, $this->model->hasPageAccess('update'));
            if ($result === null) {
                $this->sendJsonResponse(['error' => 'Conversation not found'], 404);
                return;
            }
            $this->sendJsonResponse($result);
        } catch (Exception $e) {
            $this->sendFailure('Admin console messages', $e, $conversation_id);
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
            $this->sendFailure('Admin console delete', $e, $conversation_id);
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
            $this->sendFailure('Admin console block', $e, $conversation_id);
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
            $this->sendFailure('Admin console unblock', $e, $conversation_id);
        }
    }

    /**
     * Answer a failed action.
     *
     * An LlmException with a 4xx code carries a deliberate message meant for
     * the moderator (not found, already blocked) and is passed on with that
     * status. Anything else - including an LlmException with a 5xx code, which
     * is a failure, not a message - is unexpected: it is logged and answered
     * with a generic message, so SQL, paths and row values never reach the
     * browser.
     *
     * @param string $context What was being done (for the log)
     * @param Exception $e The failure
     * @param int|string|null $conversation_id Conversation the action was about
     */
    private function sendFailure($context, $e, $conversation_id = null)
    {
        if ($e instanceof LlmException) {
            $status = (int)$e->getCode();
            if ($status >= 400 && $status < 500) {
                $this->sendJsonResponse(['error' => $e->getMessage()], $status);
                return;
            }
        }
        $this->sendJsonResponse(['error' => LlmErrorReporter::report($context, $e, $conversation_id)], 500);
    }
}
?>
