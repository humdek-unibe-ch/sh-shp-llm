<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>

<?php

// Ensure parent class is loaded
require_once __DIR__ . "/LlmService.php";

/**
 * Admin-specific LLM service extending the base LlmService
 * Contains all admin-related functionality for managing conversations
 */
class LlmAdminService extends LlmService
{
    /* Private Methods *********************************************************/

    /* Public Methods *********************************************************/

    /**
     * Get admin filter options (users and sections with conversations)
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

        return [
            'users' => $users ?: [],
            'sections' => $sections ?: []
        ];
    }

    /**
     * Get conversations for admin with filtering and pagination
     * Enhanced with validation_codes join for user data
     */
    public function getAdminConversations($filters = [], $page = 1, $per_page = 50)
    {
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

        $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        $offset = ($page - 1) * $per_page;

        // Get total count
        $count_sql = "SELECT COUNT(DISTINCT lc.id) as total
                      FROM llmConversations lc
                      LEFT JOIN users u ON lc.id_users = u.id
                      {$where_clause}";
        $total_result = $this->db->query_db_first($count_sql, $params);
        $total = $total_result['total'] ?? 0;

        // Get conversations with user, section details
        $sql = "SELECT lc.*,
                       u.name as user_name,
                       u.email as user_email,
                       s.name as section_name,
                       (SELECT COUNT(*) FROM llmMessages lm WHERE lm.id_llmConversations = lc.id) as message_count
                FROM llmConversations lc
                LEFT JOIN users u ON lc.id_users = u.id
                LEFT JOIN sections s ON lc.id_sections = s.id
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
     */
    public function getAdminConversationMessages($conversation_id)
    {
        // Get conversation details
        $conversation = $this->db->query_db_first(
            "SELECT lc.*,
                    u.name as user_name,
                    u.email as user_email,
                    s.name as section_name
             FROM llmConversations lc
             LEFT JOIN users u ON lc.id_users = u.id
             LEFT JOIN sections s ON lc.id_sections = s.id
             WHERE lc.id = ?",
            [$conversation_id]
        );

        if (!$conversation) {
            return null;
        }

        // Get ALL messages including unvalidated ones (admin view)
        // Use getAdminMessages instead of getConversationMessages to include failed attempts
        $messages = $this->getAdminMessages($conversation_id);

        return [
            'conversation' => $conversation,
            'messages' => $messages ?: []
        ];
    }

    /**
     * Count conversations for admin pagination
     */
    public function countAdminConversations(array $filters = [])
    {
        $where = [];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = "id_users = :user_id";
            $params[':user_id'] = (int)$filters['user_id'];
        }

        if (!empty($filters['section_id'])) {
            $where[] = "id_sections = :section_id";
            $params[':section_id'] = (int)$filters['section_id'];
        }

        if (!empty($filters['q'])) {
            $where[] = "(title LIKE :q)";
            $params[':q'] = '%' . $filters['q'] . '%';
        }

        $where_sql = implode(' AND ', $where);

        $row = $this->db->query_db_first(
            "SELECT COUNT(*) AS cnt FROM llmConversations WHERE {$where_sql}",
            $params
        );

        return isset($row['cnt']) ? (int)$row['cnt'] : 0;
    }

    /**
     * Get a conversation (admin view) including user and section info
     */
    public function getAdminConversation($conversation_id)
    {
        return $this->db->query_db_first(
            "SELECT
                c.id,
                c.title,
                c.model,
                c.temperature,
                c.max_tokens,
                c.id_users,
                c.id_sections,
                c.created_at,
                c.updated_at,
                u.name AS user_name,
                u.email AS user_email,
                s.name AS section_name
             FROM llmConversations c
             LEFT JOIN users u ON c.id_users = u.id
             LEFT JOIN sections s ON c.id_sections = s.id
             WHERE c.id = ?",
            [$conversation_id]
        );
    }

    /**
     * Get messages for admin view (no cache to ensure fresh auditing)
     * 
     * Returns ALL messages including unvalidated ones (failed schema validation attempts).
     * This is for debugging purposes - users only see validated messages.
     */
    public function getAdminMessages($conversation_id, $limit = LLM_DEFAULT_MESSAGE_LIMIT)
    {
        return $this->db->query_db(
            "SELECT
                m.id,
                m.role,
                m.content,
                m.attachments,
                m.model,
                m.tokens_used,
                m.timestamp,
                m.sent_context,
                m.is_validated,
                m.request_payload
             FROM llmMessages m
             WHERE m.id_llmConversations = :conversation_id
             ORDER BY m.timestamp ASC
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
            throw new Exception('Conversation not found');
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
            
            // Log the action
            $this->logTransaction(
                transactionTypes_delete,
                'llmConversations',
                $conversation_id,
                $conversation['id_users'],
                'Admin deleted conversation' . ($admin_user_id ? " (by admin user ID: {$admin_user_id})" : '')
            );
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
            throw new Exception('Conversation not found');
        }
        
        if ($conversation['blocked']) {
            throw new Exception('Conversation is already blocked');
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
            // Log the action
            $this->logTransaction(
                transactionTypes_update,
                'llmConversations',
                $conversation_id,
                $conversation['id_users'],
                "Admin blocked conversation. Reason: {$block_reason}" . ($admin_user_id ? " (by admin user ID: {$admin_user_id})" : '')
            );
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
            throw new Exception('Conversation not found');
        }
        
        if (!$conversation['blocked']) {
            throw new Exception('Conversation is not blocked');
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
            // Log the action
            $this->logTransaction(
                transactionTypes_update,
                'llmConversations',
                $conversation_id,
                $conversation['id_users'],
                'Admin unblocked conversation' . ($admin_user_id ? " (by admin user ID: {$admin_user_id})" : '')
            );
        }
        
        return $result;
    }

}
