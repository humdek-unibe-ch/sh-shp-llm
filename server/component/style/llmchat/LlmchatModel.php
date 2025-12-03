<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/style/StyleModel.php";
require_once __DIR__ . "/../../../service/LlmService.php";

/**
 * The model class for the LLM chat component.
 * Handles data retrieval and component configuration.
 */
class LlmchatModel extends StyleModel
{
    private $llm_service;
    private $user_id;
    private $conversation_id;

    // Configuration properties
    private $conversation_limit;
    private $message_limit;
    private $llm_model;
    private $llm_temperature;
    private $llm_max_tokens;
    private $llm_streaming_enabled;
    private $submit_button_label;
    private $new_chat_button_label;
    private $chat_description;
    private $conversations_heading;
    private $no_conversations_message;
    private $select_conversation_heading;
    private $select_conversation_description;
    private $model_label_prefix;
    private $no_messages_message;
    private $tokens_used_suffix;
    private $loading_text;
    private $ai_thinking_text;
    private $upload_image_label;
    private $upload_help_text;
    private $message_placeholder;
    private $clear_button_label;
    private $new_conversation_title_label;
    private $conversation_title_label;
    private $cancel_button_label;
    private $create_button_label;
    private $delete_confirmation_title;
    private $delete_confirmation_message;
    private $confirm_delete_button_label;
    private $cancel_delete_button_label;

    /* Constructors ***********************************************************/

    /**
     * The constructor.
     *
     * @param object $services
     *  An associative array holding the different available services.
     * @param int $id
     *  The section id of the LLM chat component.
     */
    public function __construct($services, $id)
    {
        parent::__construct($services, $id);
        $this->llm_service = new LlmService($services);
        $this->user_id = $_SESSION['id_user'] ?? null;
        $this->conversation_id = $_GET['conversation'] ?? null;

        // If no conversation is specified, automatically select the last (most recent) conversation
        if (!$this->conversation_id && $this->user_id) {
            $conversations = $this->llm_service->getUserConversations(
                $this->user_id,
                1, // Get only the most recent conversation
                $this->getConfiguredModel()
            );
            if (!empty($conversations)) {
                $this->conversation_id = $conversations[0]['id'];
            }
        }

        // Initialize configuration properties
        $this->conversation_limit = $this->get_db_field('conversation_limit', LLM_DEFAULT_CONVERSATION_LIMIT);
        $this->message_limit = $this->get_db_field('message_limit', LLM_DEFAULT_MESSAGE_LIMIT);
        $this->llm_model = $this->get_db_field('llm_model', '');
        $this->llm_temperature = $this->get_db_field('llm_temperature', '0.7');
        $this->llm_max_tokens = $this->get_db_field('llm_max_tokens', '2048');
        $this->llm_streaming_enabled = $this->get_db_field('llm_streaming_enabled', '1');
        $this->submit_button_label = $this->get_db_field('submit_button_label', LLM_DEFAULT_SUBMIT_LABEL);
        $this->new_chat_button_label = $this->get_db_field('new_chat_button_label', LLM_DEFAULT_NEW_CHAT_LABEL);
        $this->chat_description = $this->get_db_field('chat_description', 'Chat with AI assistant');
        $this->conversations_heading = $this->get_db_field('conversations_heading', 'Conversations');
        $this->no_conversations_message = $this->get_db_field('no_conversations_message', 'No conversations yet. Start a new chat!');
        $this->select_conversation_heading = $this->get_db_field('select_conversation_heading', 'Select a conversation or start a new one');
        $this->select_conversation_description = $this->get_db_field('select_conversation_description', 'Choose from the sidebar or click "New Conversation" to begin chatting with AI.');
        $this->model_label_prefix = $this->get_db_field('model_label_prefix', 'Model: ');
        $this->no_messages_message = $this->get_db_field('no_messages_message', 'No messages yet. Send your first message!');
        $this->tokens_used_suffix = $this->get_db_field('tokens_used_suffix', ' tokens');
        $this->loading_text = $this->get_db_field('loading_text', 'Loading...');
        $this->ai_thinking_text = $this->get_db_field('ai_thinking_text', 'AI is thinking...');
        $this->upload_image_label = $this->get_db_field('upload_image_label', 'Upload Image (Vision Models)');
        $this->upload_help_text = $this->get_db_field('upload_help_text', 'Supported formats: JPG, PNG, GIF, WebP (max 10MB)');
        $this->message_placeholder = $this->get_db_field('message_placeholder', 'Type your message here...');
        $this->clear_button_label = $this->get_db_field('clear_button_label', 'Clear');
        $this->new_conversation_title_label = $this->get_db_field('new_conversation_title_label', 'New Conversation');
        $this->conversation_title_label = $this->get_db_field('conversation_title_label', 'Conversation Title (optional)');
        $this->cancel_button_label = $this->get_db_field('cancel_button_label', 'Cancel');
        $this->create_button_label = $this->get_db_field('create_button_label', 'Create Conversation');
        $this->delete_confirmation_title = $this->get_db_field('delete_confirmation_title', 'Delete Conversation');
        $this->delete_confirmation_message = $this->get_db_field('delete_confirmation_message', 'Are you sure you want to delete this conversation? This action cannot be undone.');
        $this->confirm_delete_button_label = $this->get_db_field('confirm_delete_button_label', 'Delete');
        $this->cancel_delete_button_label = $this->get_db_field('cancel_delete_button_label', 'Cancel');
    }

    /* Private Methods *********************************************************/

    /* Public Methods *********************************************************/

    /**
     * Get user conversations filtered by the configured model
     */
    public function getUserConversations()
    {
        if (!$this->user_id) {
            return [];
        }

        $configured_model = $this->getConfiguredModel();

        return $this->llm_service->getUserConversations(
            $this->user_id,
            (int)$this->getConversationLimit(),
            $configured_model
        );
    }

    /**
     * Get current conversation
     */
    public function getCurrentConversation()
    {
        if (!$this->conversation_id || !$this->user_id) {
            return null;
        }

        return $this->llm_service->getConversation($this->conversation_id, $this->user_id);
    }

    /**
     * Get conversation messages
     */
    public function getConversationMessages()
    {
        if (!$this->conversation_id) {
            return [];
        }

        return $this->llm_service->getConversationMessages(
            $this->conversation_id,
            (int)$this->getMessageLimit()
        );
    }

    /**
     * Get the configured model for this chat component
     * Falls back to global default if not configured
     */
    public function getConfiguredModel()
    {
        return $this->get_db_field('llm_model', 'qwen3-vl-8b-instruct');
    }

    /**
     * Get LLM configuration
     */
    public function getLlmConfig()
    {
        return $this->llm_service->getLlmConfig();
    }


    /**
     * Get user ID
     */
    public function getUserId()
    {
        return $this->user_id;
    }

    /**
     * Get conversation ID
     */
    public function getConversationId()
    {
        return $this->conversation_id;
    }

    /* Configuration Getters *********************************************************/

    public function getConversationLimit()
    {
        return $this->conversation_limit;
    }

    public function getMessageLimit()
    {
        return $this->message_limit;
    }

    public function getLlmModel()
    {
        return $this->llm_model;
    }

    public function getLlmTemperature()
    {
        return $this->llm_temperature;
    }

    public function getLlmMaxTokens()
    {
        return $this->llm_max_tokens;
    }

    public function isStreamingEnabled()
    {
        return $this->llm_streaming_enabled === '1';
    }

    public function getSubmitButtonLabel()
    {
        return $this->submit_button_label;
    }

    public function getNewChatButtonLabel()
    {
        return $this->new_chat_button_label;
    }

    public function getChatDescription()
    {
        return $this->chat_description;
    }

    public function getConversationsHeading()
    {
        return $this->conversations_heading;
    }

    public function getNoConversationsMessage()
    {
        return $this->no_conversations_message;
    }

    public function getSelectConversationHeading()
    {
        return $this->select_conversation_heading;
    }

    public function getSelectConversationDescription()
    {
        return $this->select_conversation_description;
    }

    public function getModelLabelPrefix()
    {
        return $this->model_label_prefix;
    }

    public function getNoMessagesMessage()
    {
        return $this->no_messages_message;
    }

    public function getTokensUsedSuffix()
    {
        return $this->tokens_used_suffix;
    }

    public function getLoadingText()
    {
        return $this->loading_text;
    }

    public function getAiThinkingText()
    {
        return $this->ai_thinking_text;
    }

    public function getUploadImageLabel()
    {
        return $this->upload_image_label;
    }

    public function getUploadHelpText()
    {
        return $this->upload_help_text;
    }

    public function getMessagePlaceholder()
    {
        return $this->message_placeholder;
    }

    public function getClearButtonLabel()
    {
        return $this->clear_button_label;
    }

    public function getNewConversationTitleLabel()
    {
        return $this->new_conversation_title_label;
    }

    public function getConversationTitleLabel()
    {
        return $this->conversation_title_label;
    }

    public function getCancelButtonLabel()
    {
        return $this->cancel_button_label;
    }

    public function getCreateButtonLabel()
    {
        return $this->create_button_label;
    }

    public function getDeleteConfirmationTitle()
    {
        return $this->delete_confirmation_title;
    }

    public function getDeleteConfirmationMessage()
    {
        return $this->delete_confirmation_message;
    }

    public function getConfirmDeleteButtonLabel()
    {
        return $this->confirm_delete_button_label;
    }

    public function getCancelDeleteButtonLabel()
    {
        return $this->cancel_delete_button_label;
    }

    /**
     * Format message content with markdown parsing
     * Uses Parsedown to convert markdown to HTML with safe mode enabled
     */
    public function formatMessageContent($content)
    {
        if (empty($content)) {
            return '';
        }

        // Use Parsedown to parse markdown to HTML
        $parsedown = $this->parsedown;
        $parsedown->setSafeMode(true); // Enable safe mode for security
        return $parsedown->text($content);
    }

    public function return_data($key)
    {
        $result = array();
        if (isset($this->interpolation_data['data_config_retrieved']) && isset($this->interpolation_data['data_config_retrieved'][$key])) {
            $result = $this->interpolation_data['data_config_retrieved'][$key];
        }
        header('Content-Type: application/json');
        echo json_encode($result);
        exit(0);
    }

}
?>
