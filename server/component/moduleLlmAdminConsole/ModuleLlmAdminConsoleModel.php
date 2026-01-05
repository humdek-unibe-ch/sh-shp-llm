<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseModel.php";
require_once __DIR__ . "/../../service/LlmService.php";
require_once __DIR__ . "/../../service/LlmAdminService.php";


/**
 * The model class for the LLM admin console component.
 * Handles data retrieval for the comprehensive admin interface.
 */
class ModuleLlmAdminConsoleModel extends BaseModel
{
    private $llm_admin_service;
    private $page_fields;

    private $id_page;

    /* Constructors ***********************************************************/

    /**
     * The constructor.
     *
     * @param array $services
     *  An associative array holding the different available services.
     * @param array $params
     *  The GET parameters.
     * @param int $id_page
     *  The page ID.
     */
    public function __construct($services, $params = [], $id_page = null)
    {
        parent::__construct($services, $params, $id_page);
        $this->llm_admin_service = new LlmAdminService($services);
        $this->id_page = $id_page;
        // Load page fields using the stored procedure
        $this->page_fields = $this->getPageFields();
    }

    private function getPageFields()
    {
        return $this->services->get_db()->query_db_first(
            "CALL get_page_fields(:id_page, :id_languages, :id_default_languages, '','')",
            [
                'id_page' => $this->id_page,
                'id_languages' => isset($_SESSION['user_language']) ? $_SESSION['user_language'] : 2,
                'id_default_languages' => isset($_SESSION['language']) ? $_SESSION['language'] : 2,
            ]
        );
    }

    public function getUserId()
    {
        return $this->user_id;
    }

    public function getAdminPageSize()
    {
        return $this->page_fields['admin_page_size'] ?? '50';
    }

    public function getRefreshInterval()
    {
        return $this->page_fields['admin_refresh_interval'] ?? '300';
    }

    public function getDefaultView()
    {
        return $this->page_fields['admin_default_view'] ?? 'conversations';
    }

    public function getShowFilters()
    {
        return ($this->page_fields['admin_show_filters'] ?? '1') === '1';
    }

    public function getLlmAdminService()
    {
        return $this->llm_admin_service;
    }

    /**
     * Get admin filter options (users and sections with conversations)
     */
    public function getAdminFilters()
    {
        return $this->llm_admin_service->getAdminFilterOptions();
    }

    /**
     * Get conversations for admin with filtering and pagination
     */
    public function getAdminConversations($filters = [], $page = 1, $per_page = 50)
    {
        return $this->llm_admin_service->getAdminConversations($filters, $page, $per_page);
    }

    /**
     * Get messages for a specific conversation (admin view)
     */
    public function getAdminConversationMessages($conversation_id)
    {
        return $this->llm_admin_service->getAdminConversationMessages($conversation_id);
    }

    /**
     * Admin: Delete a conversation (soft delete)
     * 
     * @param int $conversation_id Conversation ID to delete
     * @param int|null $admin_user_id Admin user performing the action
     * @return bool True if deleted successfully
     */
    public function adminDeleteConversation($conversation_id, $admin_user_id = null)
    {
        return $this->llm_admin_service->adminDeleteConversation($conversation_id, $admin_user_id);
    }

    /**
     * Admin: Block a conversation
     * 
     * @param int $conversation_id Conversation ID to block
     * @param string|null $reason Reason for blocking
     * @param int|null $admin_user_id Admin user performing the action
     * @return bool True if blocked successfully
     */
    public function adminBlockConversation($conversation_id, $reason = null, $admin_user_id = null)
    {
        return $this->llm_admin_service->adminBlockConversation($conversation_id, $reason, $admin_user_id);
    }

    /**
     * Admin: Unblock a conversation
     * 
     * @param int $conversation_id Conversation ID to unblock
     * @param int|null $admin_user_id Admin user performing the action
     * @return bool True if unblocked successfully
     */
    public function adminUnblockConversation($conversation_id, $admin_user_id = null)
    {
        return $this->llm_admin_service->adminUnblockConversation($conversation_id, $admin_user_id);
    }

    /**
     * UI labels for the React admin console.
     */
    public function getLabels()
    {
        return [
            'heading' => 'LLM Conversations',
            'filtersTitle' => 'Filters',
            'userFilterLabel' => 'User',
            'sectionFilterLabel' => 'Section',
            'searchPlaceholder' => 'Search by title or user',
            'conversationsEmpty' => 'No conversations found for the selected filters.',
            'messagesEmpty' => 'Select a conversation to view its messages.',
            'refreshLabel' => 'Refresh',
            'loadingLabel' => 'Loading...',
            'dateFilterLabel' => 'Date Range',
            'dateFromLabel' => 'From',
            'dateToLabel' => 'To',
        ];
    }
}

