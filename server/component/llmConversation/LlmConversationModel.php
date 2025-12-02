<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseModel.php";
require_once __DIR__ . "/../../service/LlmService.php";

/**
 * The model class for the LLM conversation admin component.
 * Handles data retrieval for individual conversation details.
 */
class LlmConversationModel extends BaseModel
{
    private $llm_service;
    private $conversation_id;
    private $conversation;
    private $messages;

    /* Constructors ***********************************************************/

    /**
     * The constructor.
     *
     * @param array $services
     *  An associative array holding the different available services.
     * @param int $conversation_id
     *  The ID of the conversation to display
     */
    public function __construct($services, $conversation_id)
    {
        parent::__construct($services, null, [], null);
        $this->llm_service = new LlmService($services);
        $this->conversation_id = $conversation_id;

        if ($conversation_id) {
            $this->conversation = $this->llm_service->getConversationById($conversation_id);
            $this->messages = $this->llm_service->getConversationMessages($conversation_id);
        }
    }

    /* Private Methods *********************************************************/

    /* Public Methods *********************************************************/

    /**
     * Get the conversation data
     */
    public function getConversation()
    {
        return $this->conversation;
    }

    /**
     * Get the conversation messages
     */
    public function getMessages()
    {
        return $this->messages;
    }

    /**
     * Check if conversation exists
     */
    public function conversationExists()
    {
        return $this->conversation !== null;
    }

    /**
     * Get conversation ID
     */
    public function getConversationId()
    {
        return $this->conversation_id;
    }

    /**
     * Get message count
     */
    public function getMessageCount()
    {
        return count($this->messages);
    }

    /**
     * Format timestamp for display
     */
    public function formatTimestamp($timestamp)
    {
        return date('Y-m-d H:i:s', strtotime($timestamp));
    }

    /**
     * Get user name for conversation
     */
    public function getUserName()
    {
        if (!$this->conversation) {
            return 'Unknown';
        }

        return $this->conversation['user_name'] ?? 'Unknown';
    }

    /**
     * Calculate total tokens used in conversation
     */
    public function getTotalTokens()
    {
        $total = 0;
        foreach ($this->messages as $message) {
            if (isset($message['tokens_used'])) {
                $total += intval($message['tokens_used']);
            }
        }
        return $total;
    }
}
?>