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
    private $enable_conversations_list;
    private $enable_file_uploads;
    private $enable_full_page_reload;
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
    private $conversation_name;
    private $cancel_button_label;
    private $create_button_label;
    private $delete_confirmation_title;
    private $delete_confirmation_message;
    private $confirm_delete_button_label;
    private $cancel_delete_button_label;

    // Error messages
    private $empty_message_error;
    private $streaming_active_error;
    private $default_chat_title;

    // Additional UI labels
    private $delete_button_title;
    private $conversation_title_placeholder;

    // File attachment labels
    private $single_file_attached_text;
    private $multiple_files_attached_text;

    // Empty state labels
    private $empty_state_title;
    private $empty_state_description;
    private $loading_messages_text;

    // Message input labels
    private $streaming_in_progress_placeholder;
    private $attach_files_title;
    private $no_vision_support_title;
    private $no_vision_support_text;
    private $send_message_title;
    private $remove_file_title;

    // Conversation context
    private $conversation_context;

    // Auto-start conversation settings
    private $auto_start_conversation;
    private $auto_start_message;

    // Strict conversation mode
    private $strict_conversation_mode;

    // Form mode - LLM returns only forms, text input disabled
    private $enable_form_mode;

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

        // Initialize configuration properties first
        $this->conversation_limit = $this->get_db_field('conversation_limit', LLM_DEFAULT_CONVERSATION_LIMIT);
        $this->enable_conversations_list = $this->get_db_field('enable_conversations_list', '0');

        // If conversations list is disabled, always load the last conversation (ignore URL parameter)
        if ($this->enable_conversations_list !== '1') {
            $this->conversation_id = null;
        }

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
        $this->enable_file_uploads = $this->get_db_field('enable_file_uploads', '0');
        $this->enable_full_page_reload = $this->get_db_field('enable_full_page_reload', '0');
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
        $this->conversation_name = $this->get_db_field('conversation_name', 'My Chat');
        $this->cancel_button_label = $this->get_db_field('cancel_button_label', 'Cancel');
        $this->create_button_label = $this->get_db_field('create_button_label', 'Create Conversation');
        $this->delete_confirmation_title = $this->get_db_field('delete_confirmation_title', 'Delete Conversation');
        $this->delete_confirmation_message = $this->get_db_field('delete_confirmation_message', 'Are you sure you want to delete this conversation? This action cannot be undone.');
        $this->confirm_delete_button_label = $this->get_db_field('confirm_delete_button_label', 'Delete');
        $this->cancel_delete_button_label = $this->get_db_field('cancel_delete_button_label', 'Cancel');

        // Error messages
        $this->empty_message_error = $this->get_db_field('empty_message_error', 'Please enter a message');
        $this->streaming_active_error = $this->get_db_field('streaming_active_error', 'Please wait for the current response to complete');
        $this->default_chat_title = $this->get_db_field('default_chat_title', 'AI Chat');

        // Additional UI labels
        $this->delete_button_title = $this->get_db_field('delete_button_title', 'Delete conversation');
        $this->conversation_title_placeholder = $this->get_db_field('conversation_title_placeholder', 'Enter conversation title (optional)');

        // File attachment labels
        $this->single_file_attached_text = $this->get_db_field('single_file_attached_text', '1 file attached');
        $this->multiple_files_attached_text = $this->get_db_field('multiple_files_attached_text', '{count} files attached');

        // Empty state labels
        $this->empty_state_title = $this->get_db_field('empty_state_title', 'Start a conversation');
        $this->empty_state_description = $this->get_db_field('empty_state_description', 'Send a message to start chatting with the AI assistant.');
        $this->loading_messages_text = $this->get_db_field('loading_messages_text', 'Loading messages...');

        // Message input labels
        $this->streaming_in_progress_placeholder = $this->get_db_field('streaming_in_progress_placeholder', 'Streaming in progress...');
        $this->attach_files_title = $this->get_db_field('attach_files_title', 'Attach files');
        $this->no_vision_support_title = $this->get_db_field('no_vision_support_title', 'Current model does not support image uploads');
        $this->no_vision_support_text = $this->get_db_field('no_vision_support_text', 'No vision');
        $this->send_message_title = $this->get_db_field('send_message_title', 'Send message');
        $this->remove_file_title = $this->get_db_field('remove_file_title', 'Remove file');

        // Conversation context - system instructions sent to AI
        $this->conversation_context = $this->get_db_field('conversation_context', '');

        // Auto-start conversation settings
        $this->auto_start_conversation = $this->get_db_field('auto_start_conversation', '0');
        $this->auto_start_message = $this->get_db_field('auto_start_message', 'Hello! I\'m here to help you. What would you like to talk about?');

        // Strict conversation mode
        $this->strict_conversation_mode = $this->get_db_field('strict_conversation_mode', '0');

        // Form mode - LLM returns only forms, text input disabled
        $this->enable_form_mode = $this->get_db_field('enable_form_mode', '0');
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

    /**
     * Get section ID (for linking conversations to sections)
     */
    public function getSectionId()
    {
        return $this->section_id;
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

    public function isConversationsListEnabled()
    {
        return $this->enable_conversations_list === '1';
    }

    public function isFileUploadsEnabled()
    {
        return $this->enable_file_uploads == '1';
    }

    public function isFullPageReloadEnabled()
    {
        return $this->enable_full_page_reload == '1';
    }

    /**
     * Get accepted file types for the current model
     * Vision models accept images, text models accept text-based files and images
     *
     * @return array Array of accepted file extensions
     */
    public function getAcceptedFileTypes()
    {
        $model = $this->getConfiguredModel();

        // Vision models can understand images
        if (llm_is_vision_model($model)) {
            return LLM_ALLOWED_IMAGE_EXTENSIONS;
        }

        // Text models can process text-based files and may handle images
        return array_merge(LLM_ALLOWED_DOCUMENT_EXTENSIONS, LLM_ALLOWED_CODE_EXTENSIONS, LLM_ALLOWED_IMAGE_EXTENSIONS);
    }

    /**
     * Check if the current model supports vision/image processing
     *
     * @return bool True if model supports vision
     */
    public function isVisionModel()
    {
        return llm_is_vision_model($this->getConfiguredModel());
    }

    /**
     * Get the capabilities of the current model
     *
     * @return array Array of capability constants
     */
    public function getModelCapabilities()
    {
        return llm_get_model_capabilities($this->getConfiguredModel());
    }

    /**
     * Check if the current model has a specific capability
     *
     * @param string $capability Capability constant
     * @return bool True if model has the capability
     */
    public function modelHasCapability($capability)
    {
        return llm_model_has_capability($this->getConfiguredModel(), $capability);
    }

    /**
     * Get a human-readable description of the model's capabilities
     *
     * @return string Description of model capabilities
     */
    public function getModelCapabilityDescription()
    {
        $capabilities = $this->getModelCapabilities();
        $descriptions = [];

        if (in_array(LLM_CAPABILITY_VISION, $capabilities)) {
            $descriptions[] = 'Vision (image analysis)';
        }
        if (in_array(LLM_CAPABILITY_CODE, $capabilities)) {
            $descriptions[] = 'Code generation';
        }
        if (in_array(LLM_CAPABILITY_REASONING, $capabilities)) {
            $descriptions[] = 'Advanced reasoning';
        }
        if (in_array(LLM_CAPABILITY_TEXT, $capabilities)) {
            $descriptions[] = 'Text processing';
        }

        return implode(', ', $descriptions);
    }

    /**
     * Get model status indicator for UI display
     *
     * @return array Array with 'type', 'icon', 'text', and 'class' for UI display
     */
    public function getModelStatusIndicator()
    {
        $model = $this->getConfiguredModel();
        $capabilities = $this->getModelCapabilities();

        if (in_array(LLM_CAPABILITY_VISION, $capabilities)) {
            return [
                'type' => 'vision',
                'icon' => 'fas fa-eye',
                'text' => 'Vision Model',
                'class' => 'badge-success'
            ];
        } elseif (in_array(LLM_CAPABILITY_CODE, $capabilities)) {
            return [
                'type' => 'code',
                'icon' => 'fas fa-code',
                'text' => 'Code Model',
                'class' => 'badge-primary'
            ];
        } elseif (in_array(LLM_CAPABILITY_REASONING, $capabilities)) {
            return [
                'type' => 'reasoning',
                'icon' => 'fas fa-brain',
                'text' => 'Reasoning Model',
                'class' => 'badge-info'
            ];
        } else {
            return [
                'type' => 'text',
                'icon' => 'fas fa-file-alt',
                'text' => 'Text Model',
                'class' => 'badge-secondary'
            ];
        }
    }

    /**
     * Get file upload help text based on model capabilities
     *
     * @return string Appropriate help text for the current model
     */
    public function getModelSpecificUploadHelpText()
    {
        $model = $this->getConfiguredModel();

        if (llm_is_vision_model($model)) {
            // Vision models - focus on image capabilities
            $extensions = array_map('strtoupper', LLM_ALLOWED_IMAGE_EXTENSIONS);
            $maxSize = $this->formatFileSizeForDisplay(LLM_MAX_FILE_SIZE);
            $maxFiles = LLM_MAX_FILES_PER_MESSAGE;

            return "Vision model supports image analysis. Supported formats: " .
                   implode(', ', array_slice($extensions, 0, 6)) .
                   " (max {$maxSize}, up to {$maxFiles} files)";
        } else {
            // Text models - mention both text and image capabilities
            $textExtensions = array_merge(LLM_ALLOWED_DOCUMENT_EXTENSIONS, LLM_ALLOWED_CODE_EXTENSIONS);
            $imageExtensions = LLM_ALLOWED_IMAGE_EXTENSIONS;
            $allExtensions = array_merge($textExtensions, $imageExtensions);

            $maxSize = $this->formatFileSizeForDisplay(LLM_MAX_FILE_SIZE);
            $maxFiles = LLM_MAX_FILES_PER_MESSAGE;

            return "Text model can process documents and images. Supported: " .
                   implode(', ', array_slice(array_map('strtoupper', $allExtensions), 0, 8)) .
                   "... (max {$maxSize}, up to {$maxFiles} files)";
        }
    }

    /**
     * Get warning message for models with limited file processing capabilities
     *
     * @return string|null Warning message or null if no warning needed
     */
    public function getFileUploadWarningMessage()
    {
        if (!$this->isFileUploadsEnabled()) {
            return null;
        }

        $model = $this->getConfiguredModel();
        $capabilities = $this->getModelCapabilities();

        // If it's a vision model, no warning needed
        if (in_array(LLM_CAPABILITY_VISION, $capabilities)) {
            return null;
        }

        // For non-vision models, provide helpful guidance
        $warning = "This model has limited image processing capabilities. ";

        // Suggest better alternatives
        $visionModels = array_intersect(LLM_VISION_MODELS, ['qwen3-vl-8b-instruct', 'internvl3-8b-instruct', 'deepseek-r1-0528-qwen3-8b']);
        if (!empty($visionModels)) {
            $suggestions = array_slice($visionModels, 0, 2); // Show max 2 suggestions
            $warning .= "For better image analysis, consider: " . implode(', ', $suggestions) . ".";
        } else {
            $warning .= "Text-based files will work best with this model.";
        }

        return $warning;
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
        // If custom text is configured, use it; otherwise generate from constants
        if (!empty($this->upload_help_text) && $this->upload_help_text !== 'Supported formats: JPG, PNG, GIF, WebP (max 10MB)') {
            return $this->upload_help_text;
        }

        // Generate help text from constants
        $extensions = array_map('strtoupper', LLM_ALLOWED_EXTENSIONS);
        $maxSize = $this->formatFileSizeForDisplay(LLM_MAX_FILE_SIZE);
        $maxFiles = LLM_MAX_FILES_PER_MESSAGE;

        return "Supported formats: " . implode(', ', array_slice($extensions, 0, 8)) .
               (count($extensions) > 8 ? ', ...' : '') .
               " (max {$maxSize}, up to {$maxFiles} files)";
    }

    /**
     * Format file size for display in help text
     *
     * @param int $bytes File size in bytes
     * @return string Formatted file size
     */
    private function formatFileSizeForDisplay($bytes)
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 1) . 'GB';
        }
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 0) . 'MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 0) . 'KB';
        }
        return $bytes . 'B';
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

    public function getConversationName()
    {
        return $this->conversation_name;
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

    // ===== Error Messages =====

    public function getEmptyMessageError()
    {
        return $this->empty_message_error;
    }

    public function getStreamingActiveError()
    {
        return $this->streaming_active_error;
    }

    public function getDefaultChatTitle()
    {
        return $this->default_chat_title;
    }

    // ===== Additional UI Labels =====

    public function getDeleteButtonTitle()
    {
        return $this->delete_button_title;
    }

    public function getConversationTitlePlaceholder()
    {
        return $this->conversation_title_placeholder;
    }

    // ===== File Attachment Labels =====

    public function getSingleFileAttachedText()
    {
        return $this->single_file_attached_text;
    }

    public function getMultipleFilesAttachedText()
    {
        return $this->multiple_files_attached_text;
    }

    // ===== Empty State Labels =====

    public function getEmptyStateTitle()
    {
        return $this->empty_state_title;
    }

    public function getEmptyStateDescription()
    {
        return $this->empty_state_description;
    }

    public function getLoadingMessagesText()
    {
        return $this->loading_messages_text;
    }

    // ===== Message Input Labels =====

    public function getStreamingInProgressPlaceholder()
    {
        return $this->streaming_in_progress_placeholder;
    }

    public function getAttachFilesTitle()
    {
        return $this->attach_files_title;
    }

    public function getNoVisionSupportTitle()
    {
        return $this->no_vision_support_title;
    }

    public function getNoVisionSupportText()
    {
        return $this->no_vision_support_text;
    }

    public function getSendMessageTitle()
    {
        return $this->send_message_title;
    }

    public function getRemoveFileTitle()
    {
        return $this->remove_file_title;
    }

    // ===== Conversation Context =====

    /**
     * Get the conversation context/system instructions
     * 
     * This returns the raw context string which can be either:
     * - Free text/markdown: Will be converted to a single system message
     * - JSON array: Will be used as-is for multiple system messages
     * 
     * @return string Raw context string (may be empty)
     */
    public function getConversationContext()
    {
        return $this->conversation_context;
    }

    /**
     * Parse conversation context into API-ready format
     * 
     * Converts the configured context into an array of message objects
     * suitable for prepending to the API messages array.
     * 
     * Supports two formats:
     * 1. JSON array: [{"role": "system", "content": "..."}]
     * 2. Free text/markdown: Converted to single system message
     * 
     * @return array Array of message objects with 'role' and 'content' keys
     */
    public function getParsedConversationContext()
    {
        $context = trim($this->conversation_context);
        
        if (empty($context)) {
            return [];
        }
        
        // Try to parse as JSON first
        if (substr($context, 0, 1) === '[') {
            $parsed = json_decode($context, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                // Validate structure - each item should have role and content
                $validated = [];
                foreach ($parsed as $item) {
                    if (isset($item['role']) && isset($item['content'])) {
                        $validated[] = [
                            'role' => $item['role'],
                            'content' => $item['content']
                        ];
                    }
                }
                return $validated;
            }
        }
        
        // Treat as free text - convert to single system message
        return [
            [
                'role' => 'system',
                'content' => $context
            ]
        ];
    }

    /**
     * Check if conversation context is configured
     *
     * @return bool True if context is configured and not empty
     */
    public function hasConversationContext()
    {
        return !empty(trim($this->conversation_context));
    }

    /**
     * Generate a context-aware auto-start message based on conversation context
     *
     * Analyzes the conversation context and creates an engaging auto-start message
     * that references the topics and themes in the context.
     *
     * @return string Context-aware auto-start message
     */
    public function generateContextAwareAutoStartMessage()
    {
        $context = trim($this->conversation_context);

        if (empty($context)) {
            // Fallback to default message if no context
            return $this->getAutoStartMessage();
        }

        // Extract key topics and themes from context
        $topics = $this->extractTopicsFromContext($context);

        if (empty($topics)) {
            // If no specific topics found, use configured message
            return $this->getAutoStartMessage();
        }

        // Generate engaging message based on topics
        $topicList = implode(', ', array_slice($topics, 0, 3)); // Limit to 3 topics
        if (count($topics) > 3) {
            $topicList .= '...';
        }

        // Create context-aware greeting based on topic analysis
        if ($this->isAnxietyRelated($topics)) {
            return "Hello! I'm here to support you on your journey to better understand and manage anxiety. We can explore topics like {$topicList}. What specific area would you like to focus on today?";
        } elseif ($this->isEducational($topics)) {
            return "Hi there! I'm excited to help you learn about {$topicList}. This educational module covers these important topics. Which one interests you most, or shall we start from the beginning?";
        } elseif ($this->isHealthRelated($topics)) {
            return "Welcome! I'm here to provide helpful information about {$topicList}. Let's work through this together. What questions do you have, or would you like me to explain any specific topic?";
        } else {
            // Generic but context-aware message
            return "Hello! I'm here to help you with {$topicList}. What would you like to explore first, or do you have any specific questions about these topics?";
        }
    }

    /**
     * Extract key topics and themes from conversation context
     *
     * @param string $context Raw context content
     * @return array Array of extracted topics/themes
     */
    private function extractTopicsFromContext($context)
    {
        $topics = [];

        // Try to parse as JSON first
        if (substr($context, 0, 1) === '[') {
            $parsed = json_decode($context, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                // Extract topics from JSON context
                foreach ($parsed as $item) {
                    if (isset($item['content'])) {
                        $contentTopics = $this->extractTopicsFromText($item['content']);
                        $topics = array_merge($topics, $contentTopics);
                    }
                }
            }
        } else {
            // Extract topics from free text
            $topics = $this->extractTopicsFromText($context);
        }

        return array_unique(array_filter($topics));
    }

    /**
     * Extract topics from text content using keyword analysis
     *
     * @param string $text Text content to analyze
     * @return array Array of extracted topic keywords
     */
    private function extractTopicsFromText($text)
    {
        $topics = [];

        // Convert to lowercase for matching
        $lowerText = strtolower($text);

        // Define topic patterns and keywords
        $topicPatterns = [
            'anxiety' => ['anxiety', 'anxious', 'worry', 'panic', 'stress', 'fear', 'nervous'],
            'depression' => ['depression', 'depressed', 'mood', 'sadness', 'hopeless'],
            'therapy' => ['therapy', 'therapist', 'counseling', 'treatment', 'cognitive behavioral'],
            'coping' => ['coping', 'coping skills', 'strategies', 'techniques', 'tools'],
            'mindfulness' => ['mindfulness', 'meditation', 'breathing', 'relaxation'],
            'sleep' => ['sleep', 'insomnia', 'rest', 'tired', 'fatigue'],
            'relationships' => ['relationships', 'social', 'friends', 'family', 'communication'],
            'self-care' => ['self-care', 'wellness', 'healthy habits', 'routine'],
            'education' => ['learn', 'understand', 'knowledge', 'information', 'module', 'course'],
            'health' => ['health', 'wellbeing', 'mental health', 'physical health']
        ];

        // Check for topic matches
        foreach ($topicPatterns as $topic => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($lowerText, $keyword) !== false) {
                    $topics[] = $topic;
                    break; // Only add each topic once
                }
            }
        }

        // Extract specific topics from headings and structured content
        if (preg_match_all('/(?:^|\n)#+\s*([^\n]+)/m', $text, $matches) && isset($matches[1])) {
            foreach ($matches[1] as $heading) {
                $cleanHeading = trim($heading, '#* ');
                if (strlen($cleanHeading) > 3 && strlen($cleanHeading) < 50) {
                    $topics[] = $cleanHeading;
                }
            }
        }

        // Extract topics from bullet points or numbered lists
        if (preg_match_all('/(?:^|\n)[•\-\*]\s*([^\n]+)/m', $text, $matches) && isset($matches[1])) {
            foreach ($matches[1] as $item) {
                $cleanItem = trim($item);
                if (strlen($cleanItem) > 5 && strlen($cleanItem) < 40 && !preg_match('/^(what|how|why|when)/i', $cleanItem)) {
                    $topics[] = $cleanItem;
                }
            }
        }

        return array_slice($topics, 0, 10); // Limit to 10 topics
    }

    /**
     * Check if topics are anxiety-related
     *
     * @param array $topics Array of topic keywords
     * @return bool True if anxiety-related topics are detected
     */
    private function isAnxietyRelated($topics)
    {
        $anxietyTopics = ['anxiety', 'panic', 'worry', 'stress', 'fear'];
        return !empty(array_intersect($topics, $anxietyTopics));
    }

    /**
     * Check if topics are educational
     *
     * @param array $topics Array of topic keywords
     * @return bool True if educational topics are detected
     */
    private function isEducational($topics)
    {
        $educationTopics = ['learn', 'understand', 'education', 'module', 'course'];
        return !empty(array_intersect($topics, $educationTopics));
    }

    /**
     * Check if topics are health-related
     *
     * @param array $topics Array of topic keywords
     * @return bool True if health-related topics are detected
     */
    private function isHealthRelated($topics)
    {
        $healthTopics = ['health', 'wellbeing', 'mental health', 'therapy', 'treatment'];
        return !empty(array_intersect($topics, $healthTopics));
    }

    /**
     * Check if auto-start conversation is enabled
     *
     * @return bool True if auto-start is enabled
     */
    public function isAutoStartConversationEnabled()
    {
        return $this->auto_start_conversation === '1';
    }

    /**
     * Check if strict conversation mode is enabled
     *
     * @return bool True if strict conversation mode is enabled
     */
    public function isStrictConversationModeEnabled()
    {
        return $this->strict_conversation_mode === '1';
    }

    /**
     * Check if strict conversation mode should be applied
     * 
     * Strict mode only makes sense when both enabled AND context is configured
     *
     * @return bool True if strict mode should be applied
     */
    public function shouldApplyStrictMode()
    {
        return $this->isStrictConversationModeEnabled() && $this->hasConversationContext();
    }

    /**
     * Get the auto-start message content
     *
     * @return string The auto-start message
     */
    public function getAutoStartMessage()
    {
        return $this->auto_start_message;
    }

    /**
     * Check if form mode is enabled
     * When enabled, LLM returns only JSON Schema forms and text input is disabled
     *
     * @return bool True if form mode is enabled
     */
    public function isFormModeEnabled()
    {
        return $this->enable_form_mode === '1';
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

    // ===== UI Generation Helpers =====
    // These methods generate consistent HTML for different UI elements

    /**
     * Generate avatar HTML snippet for user/assistant messages
     * Creates a circular avatar with appropriate icon and styling
     *
     * @param string $role - Message sender role ('user' or 'assistant')
     * @param boolean $isRightAligned - Whether avatar should be right-aligned (for user messages)
     * @param string $additionalClasses - Additional CSS classes to apply
     * @returns string Complete avatar HTML snippet
     */
    public function generateAvatar($role, $isRightAligned = false, $additionalClasses = '')
    {
        $icon = $role === 'user' ? 'fa-user' : 'fa-robot';
        $bgClass = $role === 'user' ? 'bg-primary' : 'bg-success';
        $marginClass = $isRightAligned ? 'ml-3' : 'mr-3';
        return "<div class=\"rounded-circle d-flex align-items-center justify-content-center {$marginClass} flex-shrink-0 {$bgClass} {$additionalClasses}\" style=\"width: 38px; height: 38px;\"><i class=\"fas {$icon}\"></i></div>";
    }

    /**
     * Generate message meta information HTML
     * Shows timestamp and token usage for messages
     *
     * @param string $timestamp - ISO timestamp string or Date object
     * @param number|null $tokensUsed - Number of tokens used (null for user messages)
     * @param string $tokensSuffix - Suffix text for token display (e.g., ' tokens')
     * @param boolean $isUser - Whether message is from user (affects styling)
     * @returns string Meta information HTML snippet
     */
    public function generateMessageMeta($timestamp, $tokensUsed, $tokensSuffix, $isUser = false)
    {
        $timeStr = $this->formatTime($timestamp);
        $textClass = $isUser ? 'text-white-50' : 'text-muted';
        $tokensStr = $tokensUsed ? " • {$tokensUsed}{$tokensSuffix}" : '';

        return "<div class=\"mt-2\"><small class=\"{$textClass}\">{$timeStr}{$tokensStr}</small></div>";
    }

    /**
     * Generate complete user message HTML
     * Creates a right-aligned message bubble with user avatar and content
     *
     * @param string $content - The text content of the user's message
     * @param string $timestamp - Optional timestamp (defaults to current time)
     * @returns string Complete HTML for user message display
     */
    public function generateUserMessage($content, $timestamp = null)
    {
        $time = $timestamp || new Date();
        return "
            <div class=\"d-flex mb-3 justify-content-end\">
                {$this->generateAvatar('user', true)}
                <div class=\"llm-message-content bg-primary text-white p-3 rounded border\">
                    <div class=\"mb-2\">{$this->escapeHtml($content)}</div>
                    {$this->generateMessageMeta($time, null, '', true)}
                </div>
            </div>
        ";
    }

    /**
     * Generate complete assistant message HTML
     * Creates a left-aligned message bubble with assistant avatar, content, and optional image
     *
     * @param string $content - The text content of the assistant's message
     * @param string $timestamp - Optional timestamp (defaults to current time)
     * @param number|null $tokensUsed - Number of tokens used in generation
     * @param string $tokensSuffix - Suffix for token display (e.g., ' tokens')
     * @param string|null $imagePath - Optional path to attached image for vision responses
     * @returns string Complete HTML for assistant message display
     */
    public function generateAssistantMessage($content, $timestamp = null, $tokensUsed = null, $tokensSuffix = '', $imagePath = null)
    {
        $time = $timestamp || new Date();
        $imageHtml = $imagePath ? "<div class=\"mt-3\"><img src=\"?file_path={$imagePath}\" alt=\"Uploaded image\" class=\"img-fluid rounded\"></div>" : '';

        return "
            <div class=\"d-flex mb-3 justify-content-start\">
                {$this->generateAvatar('assistant')}
                <div class=\"llm-message-content bg-light p-3 rounded border\">
                    <div class=\"mb-2\">{$content}</div>
                    {$imageHtml}
                    {$this->generateMessageMeta($time, $tokensUsed, $tokensSuffix, false)}
                </div>
            </div>
        ";
    }

    /**
     * Generate thinking indicator HTML
     * Shows a loading animation while the AI is processing the user's message
     * Displays before streaming begins or for regular AJAX responses
     *
     * @returns string HTML for the thinking indicator with spinner
     */
    public function generateThinkingIndicator()
    {
        return "
            <div class=\"d-flex mb-3 justify-content-start\">
                {$this->generateAvatar('assistant')}
                <div class=\"llm-message-content bg-light p-3 rounded border\">
                    <div class=\"d-flex align-items-center\">
                        <div class=\"spinner-border spinner-border-sm text-primary mr-2\" role=\"status\">
                            <span class=\"sr-only\">Loading...</span>
                        </div>
                        <small class=\"text-muted\">{$this->getAiThinkingText()}</small>
                    </div>
                </div>
            </div>
        ";
    }

    /**
     * Generate streaming message HTML container
     * Creates the initial message structure for real-time streaming responses
     * The content gets updated dynamically as chunks arrive via EventSource
     *
     * @returns string HTML structure for streaming message with typing cursor
     */
    public function generateStreamingMessage()
    {
        return "
            <div class=\"d-flex mb-3 justify-content-start streaming\">
                {$this->generateAvatar('assistant')}
                <div class=\"llm-message-content bg-light p-3 rounded border\">
                    <div class=\"mb-2\"></div>
                    {$this->generateMessageMeta(new Date(), null, '', false)}
                </div>
            </div>
        ";
    }

    /**
     * Format time for display
     * @param string $timestamp - Timestamp to format
     * @returns string Formatted time string
     */
    public function formatTime($timestamp)
    {
        $date = new DateTime($timestamp);
        return $date->format('H:i');
    }

    /**
     * Format date for human-readable display
     * Returns relative time for recent dates, absolute date for older ones
     *
     * @param string $dateString - ISO date string to format
     * @returns string Human-readable date/time string
     */
    public function formatDate($dateString)
    {
        $date = new DateTime($dateString);
        $now = new DateTime();
        $diff = $now->diff($date);
        $diffDays = $diff->days;

        if ($diffDays === 0) {
            // Today - show just time
            return $date->format('H:i');
        } elseif ($diffDays === 1) {
            // Yesterday
            return 'Yesterday';
        } elseif ($diffDays < 7) {
            // Within last week - show relative days
            return "{$diffDays} days ago";
        } else {
            // Older - show full date
            return $date->format('M j');
        }
    }

    /**
     * Escape HTML for safe display
     * @param string $text - Text to escape
     * @returns string Escaped HTML
     */
    public function escapeHtml($text)
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
