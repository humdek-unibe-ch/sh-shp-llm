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
     * Includes validation_codes table join for user validation codes
     */
    public function getAdminFilterOptions()
    {
        $users = $this->db->query_db(
            "SELECT DISTINCT
                u.id,
                u.name,
                u.email,
                vc.code as validation_code
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
            $where[] = "(lc.title LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR vc.code LIKE ?)";
            $search = "%{$filters['query']}%";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        if (!empty($filters['date_from'])) {
            $where[] = "DATE(lc.created_at) >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "DATE(lc.created_at) <= ?";
            $params[] = $filters['date_to'];
        }

        $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        $offset = ($page - 1) * $per_page;

        // Get total count with validation_codes join
        $count_sql = "SELECT COUNT(DISTINCT lc.id) as total
                      FROM llmConversations lc
                      LEFT JOIN users u ON lc.id_users = u.id
                      LEFT JOIN validation_codes vc ON vc.id_users = u.id
                      {$where_clause}";
        $total_result = $this->db->query_db_first($count_sql, $params);
        $total = $total_result['total'] ?? 0;

        // Get conversations with user, section, and validation code details
        $sql = "SELECT lc.*,
                       u.name as user_name,
                       u.email as user_email,
                       vc.code as user_validation_code,
                       s.name as section_name,
                       (SELECT COUNT(*) FROM llmMessages lm WHERE lm.id_llmConversations = lc.id) as message_count
                FROM llmConversations lc
                LEFT JOIN users u ON lc.id_users = u.id
                LEFT JOIN validation_codes vc ON vc.id_users = u.id
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
        // Get conversation details with validation code
        $conversation = $this->db->query_db_first(
            "SELECT lc.*,
                    u.name as user_name,
                    u.email as user_email,
                    vc.code as user_validation_code,
                    s.name as section_name
             FROM llmConversations lc
             LEFT JOIN users u ON lc.id_users = u.id
             LEFT JOIN validation_codes vc ON vc.id_users = u.id
             LEFT JOIN sections s ON lc.id_sections = s.id
             WHERE lc.id = ?",
            [$conversation_id]
        );

        if (!$conversation) {
            return null;
        }

        // Get messages (reuse existing method from parent)
        $messages = $this->getConversationMessages($conversation_id);

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
                m.timestamp
             FROM llmMessages m
             WHERE m.id_llmConversations = :conversation_id
             ORDER BY m.timestamp ASC
             LIMIT " . (int)$limit,
            [':conversation_id' => $conversation_id]
        ) ?: [];
    }

    /**
     * Get conversation by ID (admin only)
     */
    public function getConversationById($conversation_id)
    {
        return $this->db->query_db_first(
            "SELECT c.*, u.name as user_name
             FROM llmConversations c
             INNER JOIN users u ON c.id_users = u.id
             WHERE c.id = ?",
            [$conversation_id]
        );
    }
}
