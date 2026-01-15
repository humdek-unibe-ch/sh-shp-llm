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
class LlmChatModel extends StyleModel
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
    private $form_mode_active_title;
    private $form_mode_active_description;

    // Structured response mode is MANDATORY
    // All LLM responses use standardized JSON schema (see doc/response-schema.md)

    // Data saving - save form data to SelfHelp UserInput system
    private $enable_data_saving;
    private $data_table_name;
    private $is_log;

    // Floating chat button
    private $enable_floating_button;
    private $floating_button_position;
    private $floating_button_icon;
    private $floating_button_label;
    private $floating_chat_title;

    // Media rendering - enable images/videos in chat
    private $enable_media_rendering;
    private $allowed_media_domains;

    // Continue button for form mode
    private $continue_button_label;

    // Progress tracking - show context coverage progress
    private $enable_progress_tracking;
    private $progress_bar_label;
    private $progress_complete_message;
    private $progress_show_topics;
    
    // Context language - language for progress confirmation questions
    // Supported: en, de, fr, es, it, pt, nl (default: en)
    private $context_language;

    // Danger word detection - critical safety feature
    // Monitors messages for dangerous keywords and blocks/notifies
    private $enable_danger_detection;
    private $danger_keywords;
    private $danger_notification_emails;
    private $danger_blocked_message;

    // Speech-to-text (Whisper) configuration
    // Enables voice input for easier message composition
    private $enable_speech_to_text;
    private $speech_to_text_model;

    /* Constructors ***********************************************************/

    /**
     * The constructor.
     *
     * @param object $services
     *  An associative array holding the different available services.
     * @param int $id
     *  The section id of the LLM chat component.
     * @param array $params
     *  The list of get parameters to propagate.
     * @param number $id_page
     *  The id of the parent page
     * @param array $entry_record
     *  An array that contains the entry record information.
     */
    public function __construct($services, $id, $params = array(), $id_page = -1, $entry_record = array())
    {
        parent::__construct($services, $id, $params, $id_page, $entry_record);
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
        // Filter by section_id to ensure each llmChat section shows only its own conversations
        if (!$this->conversation_id && $this->user_id) {
            $conversations = $this->llm_service->getUserConversations(
                $this->user_id,
                1, // Get only the most recent conversation
                $this->getConfiguredModel(),
                $this->section_id // Filter by section
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
        $this->form_mode_active_title = $this->get_db_field('form_mode_active_title', 'Form Mode Active');
        $this->form_mode_active_description = $this->get_db_field('form_mode_active_description', 'Please use the form above to respond.');

        // Structured response mode is now MANDATORY (no configuration needed)
        // All responses automatically use standardized JSON schema

        // Data saving - save form data to SelfHelp UserInput system
        $this->enable_data_saving = $this->get_db_field('enable_data_saving', '0');
        $this->data_table_name = $this->get_db_field('data_table_name', '');
        $this->is_log = $this->get_db_field('is_log', '0');

        // Floating chat button
        $this->enable_floating_button = $this->get_db_field('enable_floating_button', '0');
        $this->floating_button_position = $this->get_db_field('floating_button_position', 'bottom-right');
        $this->floating_button_icon = $this->get_db_field('floating_button_icon', 'fa-comments');
        $this->floating_button_label = $this->get_db_field('floating_button_label', 'Chat');
        $this->floating_chat_title = $this->get_db_field('floating_chat_title', 'AI Assistant');

        // Media rendering - enable images/videos in chat
        $this->enable_media_rendering = $this->get_db_field('enable_media_rendering', '1');
        $this->allowed_media_domains = $this->get_db_field('allowed_media_domains', '');

        // Continue button for form mode
        $this->continue_button_label = $this->get_db_field('continue_button_label', 'Continue');

        // Progress tracking - show context coverage progress
        $this->enable_progress_tracking = $this->get_db_field('enable_progress_tracking', '0');
        $this->progress_bar_label = $this->get_db_field('progress_bar_label', 'Progress');
        $this->progress_complete_message = $this->get_db_field('progress_complete_message', 'Great job! You have covered all topics.');
        $this->progress_show_topics = $this->get_db_field('progress_show_topics', '0');
        
        // Context language for progress confirmation questions
        // Auto-detect from context or use explicit setting
        $this->context_language = $this->get_db_field('context_language', 'auto');

        // Danger word detection - critical safety feature
        $this->enable_danger_detection = $this->get_db_field('enable_danger_detection', '0');
        $this->danger_keywords = $this->get_db_field('danger_keywords', '');
        $this->danger_notification_emails = $this->get_db_field('danger_notification_emails', '');
        $this->danger_blocked_message = $this->get_db_field('danger_blocked_message', 
            "I noticed some concerning content in your message. While I want to help, I'm not equipped to handle sensitive topics like this.\n\n**Please consider reaching out to:**\n- A trusted friend or family member\n- A mental health professional\n- Crisis hotlines in your area\n\nIf you're in immediate danger, please contact emergency services.\n\n*Your well-being is important. Take care of yourself.*");

        // Speech-to-text configuration
        $this->enable_speech_to_text = $this->get_db_field('enable_speech_to_text', '0');
        $this->speech_to_text_model = $this->get_db_field('speech_to_text_model', '');

        // Initialize dataTable for this section if data saving is enabled
        $this->initializeDataTableIfNeeded();
    }

    /**
     * Initialize the dataTable for this section if data saving is enabled
     * 
     * This ensures the dataTable exists when the component is loaded,
     * rather than waiting until the first form submission.
     */
    private function initializeDataTableIfNeeded()
    {
        if ($this->enable_data_saving === '1') {
            require_once __DIR__ . "/../../../service/LlmDataSavingService.php";
            $data_saving_service = new LlmDataSavingService($this->services);
            
            // Use the configured display name or fallback to section name
            $display_name = !empty($this->data_table_name) 
                ? $this->data_table_name 
                : "LLM Chat Data ({$this->section_id})";
            
            $data_saving_service->initializeDataTable($this->section_id, $display_name);
        }
    }

    /* Private Methods *********************************************************/

    /* Public Methods *********************************************************/

    /**
     * Get user conversations filtered by the configured model and section
     * Each llmChat section only shows its own conversations
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
            $configured_model,
            $this->section_id // Filter by section to support multiple llmChat instances on same page
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
     * When media rendering is enabled, automatically appends media formatting instructions.
     * 
     * @return array Array of message objects with 'role' and 'content' keys
     */
    public function getParsedConversationContext()
    {
        $context = trim($this->conversation_context);
        $messages = [];
        
        if (!empty($context)) {
            // Try to parse as JSON first
            if (substr($context, 0, 1) === '[') {
                $parsed = json_decode($context, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                    // Validate structure - each item should have role and content
                    foreach ($parsed as $item) {
                        if (isset($item['role']) && isset($item['content'])) {
                            $messages[] = [
                                'role' => $item['role'],
                                'content' => $item['content']
                            ];
                        }
                    }
                } else {
                    // Treat as free text - convert to single system message
                    $messages[] = [
                        'role' => 'system',
                        'content' => $context
                    ];
                }
            } else {
                // Treat as free text - convert to single system message
                $messages[] = [
                    'role' => 'system',
                    'content' => $context
                ];
            }
        }
        
        // Append media rendering instructions if enabled
        if ($this->isMediaRenderingEnabled()) {
            $messages[] = [
                'role' => 'system',
                'content' => $this->getMediaRenderingInstructions()
            ];
        }
        
        return $messages;
    }
    
    /**
     * Get media rendering instructions for the LLM
     * 
     * These instructions tell the LLM how to format media content
     * so that our UI can properly render images and videos.
     * 
     * @return string Media rendering instructions
     */
    private function getMediaRenderingInstructions()
    {
        return <<<EOT
MEDIA RENDERING INSTRUCTIONS:
When including images or videos in your responses, use these exact formats for proper rendering:

FOR IMAGES - Use Markdown syntax:
![Description of image](image_url)

Example:
![Beautiful sunset over mountains](https://example.com/sunset.jpg)

FOR VIDEOS - Place the video URL on its own line (must end in .mp4, .webm, or .ogg):
https://example.com/video.mp4

IMPORTANT RULES:
1. Never use HTML tags like <img>, <video>, <p>, <div>, etc.
2. Always use Markdown syntax for formatting (bold: **text**, italic: *text*, headers: # text)
3. For images, always include descriptive alt text in the brackets
4. For videos, the URL must be on its own line and end with a video extension
5. Images and videos will be rendered inline in the chat interface
EOT;
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
     * Get form mode active title
     * Shown when form mode is enabled and text input is disabled
     *
     * @return string The form mode active title
     */
    public function getFormModeActiveTitle()
    {
        return $this->form_mode_active_title;
    }

    /**
     * Get form mode active description
     * Shown when form mode is enabled and text input is disabled
     *
     * @return string The form mode active description
     */
    public function getFormModeActiveDescription()
    {
        return $this->form_mode_active_description;
    }

    /**
     * Check if structured response mode is enabled
     * 
     * ALWAYS RETURNS TRUE - Structured response mode is now mandatory.
     * All LLM responses use the standardized JSON schema defined in doc/response-schema.md
     * 
     * Features:
     * - Safety detection integrated at LLM level
     * - Flexible forms + free text interaction
     * - Progress tracking integration
     * - Rich content with text blocks, forms, media
     * - Predictable parsing and validation
     *
     * @return bool Always returns true
     */
    public function isStructuredResponseEnabled()
    {
        return true;
    }

    // ===== Data Saving Methods =====

    /**
     * Check if data saving is enabled
     * When enabled, form submissions are saved to SelfHelp UserInput system
     *
     * @return bool True if data saving is enabled
     */
    public function isDataSavingEnabled()
    {
        return $this->enable_data_saving === '1';
    }

    /**
     * Get the data table display name
     *
     * @return string The display name for the data table
     */
    public function getDataTableName()
    {
        return $this->data_table_name;
    }

    /**
     * Get the data save mode
     * Returns 'log' when is_log is enabled, 'record' otherwise
     *
     * @return string 'log' or 'record'
     */
    public function getDataSaveMode()
    {
        return $this->is_log === '1' ? 'log' : 'record';
    }

    /**
     * Check if log mode is enabled for data saving
     *
     * @return bool True if log mode is enabled
     */
    public function isLogModeEnabled()
    {
        return $this->is_log === '1';
    }

    // ===== Floating Chat Button Methods =====

    /**
     * Check if floating button mode is enabled
     *
     * @return bool True if floating button is enabled
     */
    public function isFloatingButtonEnabled()
    {
        return $this->enable_floating_button === '1';
    }

    /**
     * Get floating button position
     *
     * @return string Position (bottom-right, bottom-left, top-right, top-left)
     */
    public function getFloatingButtonPosition()
    {
        return $this->floating_button_position;
    }

    /**
     * Get floating button icon class
     *
     * @return string Font Awesome icon class
     */
    public function getFloatingButtonIcon()
    {
        return $this->floating_button_icon;
    }

    /**
     * Get floating button label
     *
     * @return string Button label text
     */
    public function getFloatingButtonLabel()
    {
        return $this->floating_button_label;
    }

    /**
     * Get floating chat modal title
     *
     * @return string Modal title
     */
    public function getFloatingChatTitle()
    {
        return $this->floating_chat_title;
    }

    // ===== Media Rendering Methods =====

    /**
     * Check if media rendering is enabled
     *
     * @return bool True if media rendering is enabled
     */
    public function isMediaRenderingEnabled()
    {
        return $this->enable_media_rendering === '1';
    }

    /**
     * Get allowed media domains
     *
     * @return array Array of allowed domains
     */
    public function getAllowedMediaDomains()
    {
        if (empty($this->allowed_media_domains)) {
            return [];
        }
        return array_filter(array_map('trim', explode("\n", $this->allowed_media_domains)));
    }

    /**
     * Get continue button label for form mode
     *
     * @return string The continue button label
     */
    public function getContinueButtonLabel()
    {
        return $this->continue_button_label;
    }

    // ===== Progress Tracking Methods =====

    /**
     * Check if progress tracking is enabled
     *
     * @return bool True if progress tracking is enabled
     */
    public function isProgressTrackingEnabled()
    {
        return $this->enable_progress_tracking === '1';
    }

    /**
     * Get progress bar label
     *
     * @return string The progress bar label
     */
    public function getProgressBarLabel()
    {
        return $this->progress_bar_label;
    }

    /**
     * Get progress complete message
     *
     * @return string The message shown when progress is complete
     */
    public function getProgressCompleteMessage()
    {
        return $this->progress_complete_message;
    }

    /**
     * Check if topic list should be shown
     *
     * @return bool True if topics should be displayed
     */
    public function shouldShowProgressTopics()
    {
        return $this->progress_show_topics === '1';
    }

    /**
     * Get the context language for progress confirmation questions
     * 
     * Uses $_SESSION["user_language_locale"] directly (e.g., "de-CH", "en-GB")
     * and extracts the first 2 characters as the language code.
     * 
     * Supported languages: en, de, fr, es, it, pt, nl
     *
     * @return string Language code (e.g., 'en', 'de', 'fr')
     */
    public function getContextLanguage()
    {
        // If explicitly set in CMS (not 'auto'), use that
        if ($this->context_language !== 'auto' && !empty($this->context_language)) {
            return $this->context_language;
        }

        // Get language from session locale (e.g., "de-CH" -> "de", "en-GB" -> "en")
        $locale = $_SESSION['user_language_locale'] ?? 'en-GB';
        $lang = substr($locale, 0, 2);
        
        // Validate it's a supported language, otherwise default to 'en'
        $supported = ['en', 'de', 'fr', 'es', 'it', 'pt', 'nl'];
        return in_array($lang, $supported) ? $lang : 'en';
    }

    /**
     * Get progress tracking configuration
     * 
     * Returns all progress-related settings as an array
     *
     * @return array Progress configuration
     */
    public function getProgressTrackingConfig()
    {
        return [
            'enabled' => $this->isProgressTrackingEnabled(),
            'barLabel' => $this->getProgressBarLabel(),
            'completeMessage' => $this->getProgressCompleteMessage(),
            'showTopics' => $this->shouldShowProgressTopics(),
            'contextLanguage' => $this->getContextLanguage()
        ];
    }

    // ===== Danger Word Detection Methods =====

    /**
     * Check if danger word detection is enabled
     *
     * @return bool True if danger detection is enabled
     */
    public function isDangerDetectionEnabled()
    {
        return $this->enable_danger_detection === '1';
    }

    /**
     * Get danger keywords as a raw string
     *
     * @return string Comma-separated danger keywords
     */
    public function getDangerKeywords()
    {
        return $this->danger_keywords;
    }

    /**
     * Get notification email addresses as an array
     *
     * Supports both newline and semicolon separators.
     *
     * @return array Array of email addresses
     */
    public function getDangerNotificationEmails()
    {
        if (empty($this->danger_notification_emails)) {
            return [];
        }
        // Support both newline and semicolon separators
        $emails = preg_split('/[\n;]+/', $this->danger_notification_emails);
        return array_filter(array_map('trim', $emails));
    }

    /**
     * Get the blocked message shown to users when danger keywords are detected
     *
     * @return string The safety message (supports markdown)
     */
    public function getDangerBlockedMessage()
    {
        return $this->danger_blocked_message;
    }

    /**
     * Get danger detection configuration
     * 
     * Returns all danger detection settings as an array
     *
     * @return array Danger detection configuration
     */
    public function getDangerDetectionConfig()
    {
        return [
            'enabled' => $this->isDangerDetectionEnabled(),
            'keywords' => $this->getDangerKeywords(),
            'notificationEmails' => $this->getDangerNotificationEmails(),
            'blockedMessage' => $this->getDangerBlockedMessage()
        ];
    }

    // ===== Speech-to-Text Configuration =====

    /**
     * Check if speech-to-text is enabled
     * 
     * Speech-to-text is only functional when:
     * 1. The enable checkbox is checked
     * 2. An audio model is selected
     *
     * @return bool True if speech-to-text is enabled and configured
     */
    public function isSpeechToTextEnabled()
    {
        return $this->enable_speech_to_text === '1' && !empty($this->speech_to_text_model);
    }

    /**
     * Get the selected speech-to-text model
     *
     * @return string The Whisper model identifier (e.g., 'faster-whisper-large-v3')
     */
    public function getSpeechToTextModel()
    {
        return $this->speech_to_text_model;
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
