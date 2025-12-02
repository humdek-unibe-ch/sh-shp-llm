<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseModel.php";
require_once __DIR__ . "/../../service/LlmService.php";

/**
 * The model class for the LLM conversations admin component.
 * Handles data retrieval for the conversations list.
 */
class LlmConversationsModel extends BaseModel
{
    private $llm_service;

    /* Constructors ***********************************************************/

    /**
     * The constructor.
     *
     * @param array $services
     *  An associative array holding the different available services.
     */
    public function __construct($services)
    {
        parent::__construct($services, null, [], null);
        $this->llm_service = new LlmService($services);
    }

    /* Private Methods *********************************************************/

    /* Public Methods *********************************************************/

    /**
     * Get all conversations for admin view
     */
    public function getConversations()
    {
        $page = intval($_GET['page'] ?? 1);
        $limit = 50;
        $offset = ($page - 1) * $limit;

        return $this->llm_service->getAllConversations($limit, $offset);
    }

    /**
     * Get current page number
     */
    public function getCurrentPage()
    {
        return intval($_GET['page'] ?? 1);
    }

    /**
     * Get message count for a conversation
     */
    public function getMessageCount($conversation_id)
    {
        return $this->db->query_db_first(
            "SELECT COUNT(*) as count FROM llmMessages WHERE id_llmConversations = ?",
            [$conversation_id]
        )['count'];
    }

    /**
     * Get total conversations count for pagination
     */
    public function getTotalConversations()
    {
        $result = $this->db->query_db_first("SELECT COUNT(*) as count FROM llmConversations");
        return $result['count'];
    }

    /**
     * Format timestamp for display
     */
    public function formatTimestamp($timestamp)
    {
        return date('Y-m-d H:i', strtotime($timestamp));
    }
}
?>