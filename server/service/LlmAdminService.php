<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>

<?php

// Ensure parent class is loaded
require_once __DIR__ . "/LlmService.php";
require_once __DIR__ . "/exception/LlmException.php";

/**
 * Admin-specific LLM service extending the base LlmService
 * Contains all admin-related functionality for managing conversations
 */
class LlmAdminService extends LlmService
{
    /**
     * `llmConversations` columns the admin console may read.
     *
     * `state` (the interview draft: participant answers, revisions, opening
     * context) is deliberately NOT listed. Read-only console users must not
     * receive it, and the console never needs it. The list is explicit on
     * purpose: a column added by a later migration stays hidden until someone
     * decides it is safe to show.
     */
    const ADMIN_CONVERSATION_COLUMNS = 'lc.id, lc.id_users, lc.id_sections, lc.id_llm_scripts,
                       lc.id_llm_conversation_sources, lc.title, lc.model, lc.temperature, lc.max_tokens,
                       lc.deleted, lc.blocked, lc.blocked_reason, lc.blocked_at, lc.blocked_by,
                       lc.id_dataRows, lc.completed_at, lc.context_key, lc.created_at, lc.updated_at';

    /* Private Methods *********************************************************/

    /**
     * Audit entry for a moderation action. The transaction's user is the actor,
     * so it is the admin who acted - not the participant who owns the conversation
     * (the owner is named in the text instead).
     *
     * @param string $operation Transaction type constant
     * @param array $conversation Row with at least id and id_users
     * @param int|null $admin_user_id The acting admin (session user when empty)
     * @param string $details What happened
     */
    private function logAdminAction($operation, $conversation, $admin_user_id, $details)
    {
        $actor = $admin_user_id ?: ($_SESSION['id_user'] ?? null);
        $this->logTransaction(
            $operation,
            'llmConversations',
            $conversation['id'],
            $actor ?: $conversation['id_users'],
            $details . " (owner user ID: {$conversation['id_users']})"
        );
    }

    /* Public Methods *********************************************************/

    /**
     * Get admin filter options (users, sections, and scripts with conversations)
     */
    public function getAdminFilterOptions()
    {
        $users = $this->db->query_db(
            "SELECT DISTINCT
                u.id,
                u.name,
                u.email,
                vc.code as user_validation_code
             FROM users u
             INNER JOIN llmConversations lc ON lc.id_users = u.id
             LEFT JOIN validation_codes vc ON vc.id_users = u.id
             ORDER BY u.name ASC"
        );

        $sections = $this->db->query_db(
            "SELECT DISTINCT s.id, s.name
             FROM sections s
             INNER JOIN llmConversations lc ON lc.id_sections = s.id
             WHERE s.name IS NOT NULL AND s.name != ''
             ORDER BY s.name ASC"
        );

        $scripts = $this->db->query_db(
            "SELECT DISTINCT ls.id, ls.name
             FROM llm_scripts ls
             INNER JOIN llmConversations lc ON lc.id_llm_scripts = ls.id
             WHERE ls.name IS NOT NULL AND ls.name != ''
             ORDER BY ls.name ASC"
        );

        $sources = $this->db->query_db(
            "SELECT l.id, l.lookup_value AS code, l.lookup_description AS label
             FROM lookups l
             INNER JOIN llmConversations lc ON lc.id_llm_conversation_sources = l.id
             WHERE l.type_code = 'llmConversationSourceType'
             GROUP BY l.id, l.lookup_value, l.lookup_description
             ORDER BY l.lookup_value ASC"
        );

        return [
            'users' => $users ?: [],
            'sections' => $sections ?: [],
            'scripts' => $scripts ?: [],
            'sources' => $sources ?: []
        ];
    }

    /**
     * Get conversations for admin with filtering and pagination
     * Enhanced with validation_codes join for user data
     */
    public function getAdminConversations($filters = [], $page = 1, $per_page = 50)
    {
        // Both end up in LIMIT/OFFSET: keep them positive and bounded
        $page = max(1, min((int)$page, 100000));
        $per_page = max(1, min((int)$per_page, 100));

        $where = [];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = "lc.id_users = ?";
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['section_id'])) {
            $where[] = "lc.id_sections = ?";
            $params[] = $filters['section_id'];
        }

        if (!empty($filters['script_id'])) {
            $where[] = "lc.id_llm_scripts = ?";
            $params[] = $filters['script_id'];
        }

        if (!empty($filters['query'])) {
            $where[] = "(lc.title LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
            $search = "%{$filters['query']}%";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        if (!empty($filters['date_from'])) {
            $where[] = "DATE(lc.updated_at) >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "DATE(lc.updated_at) <= ?";
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['source_type']) && is_string($filters['source_type'])) {
            $where[] = "cs.lookup_value = ?";
            $params[] = $filters['source_type'];
        }

        if (isset($filters['record_id']) && $filters['record_id'] !== '' && $filters['record_id'] !== null) {
            if ($filters['record_id'] === 'draft') {
                $where[] = "lc.id_dataRows IS NULL";
            } else {
                $where[] = "lc.id_dataRows = ?";
                $params[] = (int)$filters['record_id'];
            }
        }

        $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        $offset = ($page - 1) * $per_page;

        // Get total count
        $count_sql = "SELECT COUNT(DISTINCT lc.id) as total
                      FROM llmConversations lc
                      LEFT JOIN users u ON lc.id_users = u.id
                      LEFT JOIN sections s ON lc.id_sections = s.id
                      LEFT JOIN llm_scripts ls ON lc.id_llm_scripts = ls.id
                      LEFT JOIN lookups cs ON cs.id = lc.id_llm_conversation_sources
                      {$where_clause}";
        $total_result = $this->db->query_db_first($count_sql, $params);
        $total = $total_result['total'] ?? 0;

        // Get conversations with user, section, and script details
        $sql = "SELECT " . self::ADMIN_CONVERSATION_COLUMNS . ",
                       u.name as user_name,
                       u.email as user_email,
                       s.name as section_name,
                       ls.name as script_name,
                       cs.lookup_value as source_type,
                       cs.lookup_description as source_label,
                       (SELECT COUNT(*) FROM llmMessages lm WHERE lm.id_llmConversations = lc.id) as message_count
                FROM llmConversations lc
                LEFT JOIN users u ON lc.id_users = u.id
                LEFT JOIN sections s ON lc.id_sections = s.id
                LEFT JOIN llm_scripts ls ON lc.id_llm_scripts = ls.id
                LEFT JOIN lookups cs ON cs.id = lc.id_llm_conversation_sources
                {$where_clause}
                ORDER BY lc.updated_at DESC
                LIMIT {$per_page} OFFSET {$offset}";

        $conversations = $this->db->query_db($sql, $params);

        return [
            'items' => $conversations ?: [],
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total
        ];
    }

    /**
     * Get messages for a specific conversation (admin view)
     * Enhanced with validation code information
     *
     * @param int $conversation_id
     * @param bool $include_debug Include the sent context and request payload of each
     *  message (the prompts and settings behind the answers); only for users who may
     *  change the console's data.
     */
    public function getAdminConversationMessages($conversation_id, $include_debug = false)
    {
        // Get conversation details
        $conversation = $this->db->query_db_first(
            "SELECT " . self::ADMIN_CONVERSATION_COLUMNS . ",
                    u.name as user_name,
                    u.email as user_email,
                    s.name as section_name,
                    cs.lookup_value as source_type,
                    cs.lookup_description as source_label
             FROM llmConversations lc
             LEFT JOIN users u ON lc.id_users = u.id
             LEFT JOIN sections s ON lc.id_sections = s.id
             LEFT JOIN lookups cs ON cs.id = lc.id_llm_conversation_sources
             WHERE lc.id = ?",
            [$conversation_id]
        );

        if (!$conversation) {
            return null;
        }

        // Get ALL messages including unvalidated ones (admin view)
        // Use getAdminMessages instead of getConversationMessages to include failed attempts
        $messages = $this->getAdminMessages($conversation_id, LLM_DEFAULT_MESSAGE_LIMIT, $include_debug);

        return [
            'conversation' => $conversation,
            'messages' => $messages ?: []
        ];
    }

    /**
     * Get messages for admin view (no cache to ensure fresh auditing)
     * 
     * Returns ALL messages including unvalidated ones (failed schema validation attempts).
     * This is for debugging purposes - users only see validated messages.
     *
     * @param bool $include_debug Add sent_context and request_payload to every row
     */
    public function getAdminMessages($conversation_id, $limit = LLM_DEFAULT_MESSAGE_LIMIT, $include_debug = false)
    {
        $debug_columns = $include_debug ? ", m.sent_context, m.request_payload" : "";
        return $this->db->query_db(
            "SELECT
                m.id,
                m.role,
                m.content,
                m.attachments,
                m.model,
                m.tokens_used,
                m.prompt_tokens,
                m.completion_tokens,
                m.timestamp,
                m.is_validated
                {$debug_columns}
             FROM llmMessages m
             WHERE m.id_llmConversations = :conversation_id
             ORDER BY m.timestamp ASC, m.id ASC
             LIMIT " . (int)$limit,
            [':conversation_id' => $conversation_id]
        ) ?: [];
    }

    /**
     * Admin: Soft delete a conversation
     * Sets deleted flag but keeps data in database for audit purposes
     * 
     * @param int $conversation_id Conversation ID to delete
     * @param int|null $admin_user_id Admin user performing the action
     * @return bool True if deleted successfully
     */
    public function adminDeleteConversation($conversation_id, $admin_user_id = null)
    {
        // Verify conversation exists
        $conversation = $this->db->query_db_first(
            "SELECT id, id_users FROM llmConversations WHERE id = ?",
            [$conversation_id]
        );
        
        if (!$conversation) {
            throw new LlmException('Conversation not found', 404);
        }
        
        // Soft delete conversation
        $result = $this->db->update_by_ids(
            'llmConversations',
            ['deleted' => 1],
            ['id' => $conversation_id]
        );
        
        if ($result) {
            // Also soft delete all messages in this conversation
            $this->db->update_by_ids(
                'llmMessages',
                ['deleted' => 1],
                ['id_llmConversations' => $conversation_id]
            );
            
            $this->logAdminAction(transactionTypes_delete, $conversation, $admin_user_id, 'Admin deleted conversation');
        }
        
        return $result;
    }

    /**
     * Admin: Block a conversation
     * Prevents users from continuing the conversation
     * 
     * @param int $conversation_id Conversation ID to block
     * @param string|null $reason Reason for blocking
     * @param int|null $admin_user_id Admin user performing the action
     * @return bool True if blocked successfully
     */
    public function adminBlockConversation($conversation_id, $reason = null, $admin_user_id = null)
    {
        // Verify conversation exists
        $conversation = $this->db->query_db_first(
            "SELECT id, id_users, blocked FROM llmConversations WHERE id = ?",
            [$conversation_id]
        );
        
        if (!$conversation) {
            throw new LlmException('Conversation not found', 404);
        }
        
        if ($conversation['blocked']) {
            throw new LlmException('Conversation is already blocked', 409);
        }
        
        $block_reason = $reason ?: 'Manually blocked by administrator';
        
        $result = $this->db->update_by_ids(
            'llmConversations',
            [
                'blocked' => 1,
                'blocked_reason' => $block_reason,
                'blocked_at' => date('Y-m-d H:i:s'),
                'blocked_by' => $admin_user_id
            ],
            ['id' => $conversation_id]
        );
        
        if ($result) {
            $this->logAdminAction(transactionTypes_update, $conversation, $admin_user_id, "Admin blocked conversation. Reason: {$block_reason}");
        }
        
        return $result;
    }

    /**
     * Admin: Unblock a conversation
     * Allows users to continue the conversation again
     * 
     * @param int $conversation_id Conversation ID to unblock
     * @param int|null $admin_user_id Admin user performing the action
     * @return bool True if unblocked successfully
     */
    public function adminUnblockConversation($conversation_id, $admin_user_id = null)
    {
        // Verify conversation exists
        $conversation = $this->db->query_db_first(
            "SELECT id, id_users, blocked FROM llmConversations WHERE id = ?",
            [$conversation_id]
        );
        
        if (!$conversation) {
            throw new LlmException('Conversation not found', 404);
        }
        
        if (!$conversation['blocked']) {
            throw new LlmException('Conversation is not blocked', 409);
        }
        
        $result = $this->db->update_by_ids(
            'llmConversations',
            [
                'blocked' => 0,
                'blocked_reason' => null,
                'blocked_at' => null,
                'blocked_by' => null
            ],
            ['id' => $conversation_id]
        );
        
        if ($result) {
            $this->logAdminAction(transactionTypes_update, $conversation, $admin_user_id, 'Admin unblocked conversation');
        }
        
        return $result;
    }

}
