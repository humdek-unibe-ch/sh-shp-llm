<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/style/StyleModel.php";
require_once __DIR__ . "/../../../service/LlmService.php";
require_once __DIR__ . "/../../../service/LlmLanguageUtility.php";
require_once __DIR__ . "/../../../service/LlmAutoStartService.php";
require_once __DIR__ . "/../../../service/prompt/LlmPromptAssetLoader.php";

/**
 * The model class for the LLM chat component.
 *
 * Configuration fields are read directly from the DB field cache (StyleModel::get_db_field)
 * rather than stored as private properties — this eliminates 80+ trivial property declarations
 * and getter methods. The cache is populated by the parent constructor.
 */
class LlmChatModel extends StyleModel
{
    private $llm_service;
    private $prompt_assets;
    private $user_id;

    /** Lazy-loaded conversation ID — resolved on first access, not in constructor */
    private $conversation_id;
    private $conversation_id_resolved = false;

    /* Constructors ***********************************************************/

    /**
     * @param object $services
     * @param int $id Section id of the LLM chat component
     * @param array $params GET parameters
     * @param number $id_page Parent page id
     * @param array $entry_record Entry record data
     */
    public function __construct($services, $id, $params = array(), $id_page = -1, $entry_record = array())
    {
        parent::__construct($services, $id, $params, $id_page, $entry_record);
        $this->llm_service = new LlmService($services);
        $this->prompt_assets = new LlmPromptAssetLoader();
        $this->user_id = $_SESSION['id_user'] ?? null;

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
        if ($this->isDataSavingEnabled()) {
            require_once __DIR__ . "/../../../service/LlmDataSavingService.php";
            $data_saving_service = new LlmDataSavingService($this->services);
            
            $data_table_name = $this->get_db_field('data_table_name', '');
            $display_name = !empty($data_table_name)
                ? $data_table_name
                : "LLM Chat Data ({$this->section_id})";
            
            $data_saving_service->initializeDataTable($this->section_id, $display_name);
        }
    }

    /* Private Methods *********************************************************/

    /* Public Methods *********************************************************/

    /**
     * Get the LlmService instance (shared with controller to avoid duplicate instantiation).
     * @return LlmService
     */
    public function getLlmService()
    {
        return $this->llm_service;
    }

    /**
     * Get user conversations filtered by the configured model and section.
     */
    public function getUserConversations()
    {
        if (!$this->user_id) {
            return [];
        }

        return $this->llm_service->getUserConversations(
            $this->user_id,
            (int)$this->getConversationLimit(),
            $this->getConfiguredModel(),
            $this->section_id
        );
    }

    /**
     * Get current conversation
     */
    public function getCurrentConversation()
    {
        $cid = $this->getConversationId();
        if (!$cid || !$this->user_id) {
            return null;
        }

        return $this->llm_service->getConversation($cid, $this->user_id);
    }

    /**
     * Get conversation messages
     */
    public function getConversationMessages()
    {
        $cid = $this->getConversationId();
        if (!$cid) {
            return [];
        }

        return $this->llm_service->getConversationMessages(
            $cid,
            (int)$this->getMessageLimit()
        );
    }

    /**
     * Get the configured model for this chat component.
     * Falls back to global default if not configured.
     */
    public function getConfiguredModel()
    {
        return $this->get_db_field('llm_model', 'qwen3-vl-8b-instruct');
    }

    public function getUserId()
    {
        return $this->user_id;
    }

    /**
     * Get conversation ID with lazy loading.
     * Resolves the most recent conversation on first access instead of in the constructor,
     * avoiding a DB query on every page render.
     */
    public function getConversationId()
    {
        if (!$this->conversation_id_resolved) {
            $this->conversation_id_resolved = true;
            $this->conversation_id = $_GET['conversation'] ?? null;

            if (!$this->isConversationsListEnabled()) {
                $this->conversation_id = null;
            }

            if (!$this->conversation_id && $this->user_id) {
                $conversations = $this->llm_service->getUserConversations(
                    $this->user_id,
                    1,
                    $this->getConfiguredModel(),
                    $this->section_id
                );
                if (!empty($conversations)) {
                    $this->conversation_id = $conversations[0]['id'];
                }
            }
        }
        return $this->conversation_id;
    }

    public function getSectionId()
    {
        return $this->section_id;
    }

    /* Configuration Getters — read directly from StyleModel field cache ********/

    public function getConversationLimit() { return $this->get_db_field('conversation_limit', LLM_DEFAULT_CONVERSATION_LIMIT); }
    public function getMessageLimit() { return $this->get_db_field('message_limit', LLM_DEFAULT_MESSAGE_LIMIT); }
    public function getLlmModel() { return $this->get_db_field('llm_model', ''); }
    public function getLlmTemperature() { return $this->get_db_field('llm_temperature', '0.7'); }
    public function getLlmMaxTokens() { return $this->get_db_field('llm_max_tokens', '2048'); }
    public function isConversationsListEnabled() { return $this->get_db_field('enable_conversations_list', '0') === '1'; }
    public function isFileUploadsEnabled() { return $this->get_db_field('enable_file_uploads', '0') == '1'; }
    public function isFullPageReloadEnabled() { return $this->get_db_field('enable_full_page_reload', '0') == '1'; }

    /**
     * Get accepted file types for the current model.
     * Vision models accept images; text models accept text-based files and images.
     */
    public function getAcceptedFileTypes()
    {
        if (llm_is_vision_model($this->getConfiguredModel())) {
            return LLM_ALLOWED_IMAGE_EXTENSIONS;
        }
        return array_merge(LLM_ALLOWED_DOCUMENT_EXTENSIONS, LLM_ALLOWED_CODE_EXTENSIONS, LLM_ALLOWED_IMAGE_EXTENSIONS);
    }

    public function isVisionModel() { return llm_is_vision_model($this->getConfiguredModel()); }

    public function getUploadHelpText()
    {
        $custom = $this->get_db_field('upload_help_text', '');
        if (!empty($custom) && $custom !== 'Supported formats: JPG, PNG, GIF, WebP (max 10MB)') {
            return $custom;
        }
        $extensions = array_map('strtoupper', LLM_ALLOWED_EXTENSIONS);
        $maxSize = self::formatFileSizeForDisplay(LLM_MAX_FILE_SIZE);
        $maxFiles = LLM_MAX_FILES_PER_MESSAGE;
        return "Supported formats: " . implode(', ', array_slice($extensions, 0, 8))
            . (count($extensions) > 8 ? ', ...' : '')
            . " (max {$maxSize}, up to {$maxFiles} files)";
    }

    private static function formatFileSizeForDisplay($bytes)
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . 'GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 0) . 'MB';
        if ($bytes >= 1024) return round($bytes / 1024, 0) . 'KB';
        return $bytes . 'B';
    }

    // ===== Conversation Context =====

    public function getConversationContext() { return $this->get_db_field('conversation_context', ''); }
    public function hasConversationContext() { return !empty(trim($this->getConversationContext())); }

    /**
     * Parse conversation context into API-ready message array.
     * Supports JSON array or free text/markdown formats.
     */
    public function getParsedConversationContext()
    {
        $context = trim($this->getConversationContext());
        $messages = [];

        if (!empty($context)) {
            if (substr($context, 0, 1) === '[') {
                $parsed = json_decode($context, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                    foreach ($parsed as $item) {
                        if (isset($item['role']) && isset($item['content'])) {
                            $messages[] = ['role' => $item['role'], 'content' => $item['content']];
                        }
                    }
                } else {
                    $messages[] = ['role' => 'system', 'content' => $context];
                }
            } else {
                $messages[] = ['role' => 'system', 'content' => $context];
            }
        }

        if ($this->isMediaRenderingEnabled()) {
            $messages[] = ['role' => 'system', 'content' => $this->prompt_assets->load('core.chat.media_rendering_instructions')];
        }

        return $messages;
    }

    /**
     * Generate a context-aware auto-start message.
     * Delegates to LlmAutoStartService for topic extraction and message generation.
     */
    public function generateContextAwareAutoStartMessage()
    {
        return LlmAutoStartService::generateAutoStartMessage(
            $this->getConversationContext(),
            $this->getAutoStartMessage()
        );
    }

    // ===== Feature Flags =====

    public function isAutoStartConversationEnabled() { return $this->get_db_field('auto_start_conversation', '0') === '1'; }
    public function isStrictConversationModeEnabled() { return $this->get_db_field('strict_conversation_mode', '0') === '1'; }
    public function shouldApplyStrictMode() { return $this->isStrictConversationModeEnabled() && $this->hasConversationContext(); }
    public function getAutoStartMessage() { return $this->get_db_field('auto_start_message', "Hello! I'm here to help you. What would you like to talk about?"); }
    public function isFormModeEnabled() { return $this->get_db_field('enable_form_mode', '0') === '1'; }
    public function getFormModeActiveTitle() { return $this->get_db_field('form_mode_active_title', 'Form Mode Active'); }
    public function getFormModeActiveDescription() { return $this->get_db_field('form_mode_active_description', 'Please use the form above to respond.'); }

    // ===== Data Saving =====

    public function isDataSavingEnabled() { return $this->get_db_field('enable_data_saving', '0') === '1'; }
    public function getDataSaveMode() { return $this->get_db_field('is_log', '0') === '1' ? 'log' : 'record'; }

    // ===== Memory =====

    /**
     * Get the comma-separated list of memory rule keys configured for this chat section.
     * Empty string means "use auto-matching by source_type".
     */
    public function getMemoryRuleKeys()
    {
        $raw = $this->get_db_field('memory_rule_keys', '');
        if (empty($raw)) return [];
        return array_filter(array_map('trim', explode(',', $raw)));
    }

    // ===== Floating Chat Button =====

    public function isFloatingButtonEnabled() { return $this->get_db_field('enable_floating_button', '0') === '1'; }
    public function getFloatingButtonPosition() { return $this->get_db_field('floating_button_position', 'bottom-right'); }
    public function getFloatingButtonIcon() { return $this->get_db_field('floating_button_icon', 'fa-comments'); }
    public function getFloatingButtonLabel() { return $this->get_db_field('floating_button_label', 'Chat'); }
    public function getFloatingChatTitle() { return $this->get_db_field('floating_chat_title', 'AI Assistant'); }

    // ===== Media Rendering =====

    public function isMediaRenderingEnabled() { return $this->get_db_field('enable_media_rendering', '1') === '1'; }
    public function getContinueButtonLabel() { return $this->get_db_field('continue_button_label', 'Continue'); }

    // ===== Progress Tracking =====

    public function isProgressTrackingEnabled() { return $this->get_db_field('enable_progress_tracking', '0') === '1'; }
    public function getProgressBarLabel() { return $this->get_db_field('progress_bar_label', 'Progress'); }
    public function getProgressCompleteMessage() { return $this->get_db_field('progress_complete_message', 'Great job! You have covered all topics.'); }
    public function shouldShowProgressTopics() { return $this->get_db_field('progress_show_topics', '0') === '1'; }

    /**
     * Get the context language for progress confirmation questions.
     * Auto-detected from session locale via LlmLanguageUtility.
     */
    public function getContextLanguage()
    {
        return LlmLanguageUtility::getUserLanguageCode('en');
    }

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

    // ===== Danger Detection =====

    public function isDangerDetectionEnabled() { return $this->get_db_field('enable_danger_detection', '0') === '1'; }
    public function getDangerKeywords() { return $this->get_db_field('danger_keywords', ''); }
    public function getDangerBlockedMessage() { return $this->get_db_field('danger_blocked_message', "I noticed some concerning content in your message. While I want to help, I'm not equipped to handle sensitive topics like this.\n\n**Please consider reaching out to:**\n- A trusted friend or family member\n- A mental health professional\n- Crisis hotlines in your area\n\nIf you're in immediate danger, please contact emergency services.\n\n*Your well-being is important. Take care of yourself.*"); }

    public function getDangerNotificationEmails()
    {
        $raw = $this->get_db_field('danger_notification_emails', '');
        if (empty($raw)) return [];
        return array_filter(array_map('trim', preg_split('/[\n;]+/', $raw)));
    }

    // ===== Speech-to-Text =====

    public function isSpeechToTextEnabled()
    {
        return $this->get_db_field('enable_speech_to_text', '0') === '1'
            && !empty($this->get_db_field('speech_to_text_model', ''));
    }

    public function getSpeechToTextModel() { return $this->get_db_field('speech_to_text_model', ''); }

    // ===== Aggregate Config =====

    /**
     * Get the full chat configuration array for the React frontend.
     * 
     * @return array Chat configuration key-value pairs
     */
    public function getChatConfig()
    {
        $f = function ($name, $default = '') { return $this->get_db_field($name, $default); };
        return [
            'userId' => $this->getUserId(),
            'sectionId' => $this->getSectionId(),
            'currentConversationId' => $this->getConversationId(),
            'configuredModel' => $this->getConfiguredModel(),
            'maxFilesPerMessage' => LLM_MAX_FILES_PER_MESSAGE,
            'maxFileSize' => LLM_MAX_FILE_SIZE,
            'enableConversationsList' => $this->isConversationsListEnabled(),
            'enableFileUploads' => $this->isFileUploadsEnabled(),
            'enableFullPageReload' => $this->isFullPageReloadEnabled(),
            'acceptedFileTypes' => implode(',', array_map(fn($ext) => ".{$ext}", $this->getAcceptedFileTypes())),
            'isVisionModel' => $this->isVisionModel(),
            'hasConversationContext' => $this->hasConversationContext(),
            'strictConversationMode' => $this->isStrictConversationModeEnabled(),
            'autoStartConversation' => $this->isAutoStartConversationEnabled(),
            'autoStartMessage' => $this->getAutoStartMessage(),
            'enableFormMode' => $this->isFormModeEnabled(),
            'formModeActiveTitle' => $this->getFormModeActiveTitle(),
            'formModeActiveDescription' => $this->getFormModeActiveDescription(),
            'continueButtonLabel' => $this->getContinueButtonLabel(),
            'enableDataSaving' => $this->isDataSavingEnabled(),
            'enableMediaRendering' => $this->isMediaRenderingEnabled(),
            'enableSpeechToText' => $this->isSpeechToTextEnabled(),
            'speechToTextModel' => $this->getSpeechToTextModel(),
            'enableFloatingButton' => $this->isFloatingButtonEnabled(),
            'floatingButtonPosition' => $this->getFloatingButtonPosition(),
            'floatingButtonIcon' => $this->getFloatingButtonIcon(),
            'floatingButtonLabel' => $this->getFloatingButtonLabel(),
            'floatingChatTitle' => $this->getFloatingChatTitle(),
            'messagePlaceholder' => $f('message_placeholder', 'Type your message here...'),
            'noConversationsMessage' => $f('no_conversations_message', 'No conversations yet. Start a new chat!'),
            'newConversationTitleLabel' => $f('new_conversation_title_label', 'New Conversation'),
            'conversationTitleLabel' => $f('conversation_title_label', 'Conversation Title (optional)'),
            'cancelButtonLabel' => $f('cancel_button_label', 'Cancel'),
            'createButtonLabel' => $f('create_button_label', 'Create Conversation'),
            'deleteConfirmationTitle' => $f('delete_confirmation_title', 'Delete Conversation'),
            'deleteConfirmationMessage' => $f('delete_confirmation_message', 'Are you sure you want to delete this conversation? This action cannot be undone.'),
            'confirmDeleteButtonLabel' => $f('confirm_delete_button_label', 'Delete'),
            'cancelDeleteButtonLabel' => $f('cancel_delete_button_label', 'Cancel'),
            'tokensSuffix' => $f('tokens_used_suffix', ' tokens'),
            'aiThinkingText' => $f('ai_thinking_text', 'AI is thinking...'),
            'conversationsHeading' => $f('conversations_heading', 'Conversations'),
            'newChatButtonLabel' => $f('new_chat_button_label', LLM_DEFAULT_NEW_CHAT_LABEL),
            'selectConversationHeading' => $f('select_conversation_heading', 'Select a conversation or start a new one'),
            'selectConversationDescription' => $f('select_conversation_description', 'Choose from the sidebar or click "New Conversation" to begin chatting with AI.'),
            'modelLabelPrefix' => $f('model_label_prefix', 'Model: '),
            'noMessagesMessage' => $f('no_messages_message', 'No messages yet. Send your first message!'),
            'loadingText' => $f('loading_text', 'Loading...'),
            'uploadImageLabel' => $f('upload_image_label', 'Upload Image (Vision Models)'),
            'uploadHelpText' => $this->getUploadHelpText(),
            'clearButtonLabel' => $f('clear_button_label', 'Clear'),
            'submitButtonLabel' => $f('submit_button_label', LLM_DEFAULT_SUBMIT_LABEL),
            'emptyMessageError' => $f('empty_message_error', 'Please enter a message'),
            'defaultChatTitle' => $f('default_chat_title', 'AI Chat'),
            'deleteButtonTitle' => $f('delete_button_title', 'Delete conversation'),
            'conversationTitlePlaceholder' => $f('conversation_title_placeholder', 'Enter conversation title (optional)'),
            'singleFileAttachedText' => $f('single_file_attached_text', '1 file attached'),
            'multipleFilesAttachedText' => $f('multiple_files_attached_text', '{count} files attached'),
            'emptyStateTitle' => $f('empty_state_title', 'Start a conversation'),
            'emptyStateDescription' => $f('empty_state_description', 'Send a message to start chatting with the AI assistant.'),
            'loadingMessagesText' => $f('loading_messages_text', 'Loading messages...'),
            'attachFilesTitle' => $f('attach_files_title', 'Attach files'),
            'noVisionSupportTitle' => $f('no_vision_support_title', 'Current model does not support image uploads'),
            'noVisionSupportText' => $f('no_vision_support_text', 'No vision'),
            'sendMessageTitle' => $f('send_message_title', 'Send message'),
            'removeFileTitle' => $f('remove_file_title', 'Remove file'),
            'conversationBlockedMessage' => $this->getDangerBlockedMessage(),
            'fileConfig' => [
                'maxFileSize' => LLM_MAX_FILE_SIZE,
                'maxFilesPerMessage' => LLM_MAX_FILES_PER_MESSAGE,
                'allowedImageExtensions' => LLM_ALLOWED_IMAGE_EXTENSIONS,
                'allowedDocumentExtensions' => LLM_ALLOWED_DOCUMENT_EXTENSIONS,
                'allowedCodeExtensions' => LLM_ALLOWED_CODE_EXTENSIONS,
                'allowedExtensions' => LLM_ALLOWED_EXTENSIONS,
                'visionModels' => LLM_VISION_MODELS
            ],
            'enableProgressTracking' => $this->isProgressTrackingEnabled(),
            'progressBarLabel' => $this->getProgressBarLabel(),
            'progressCompleteMessage' => $this->getProgressCompleteMessage(),
            'progressShowTopics' => $this->shouldShowProgressTopics(),
            'chatColors' => $this->getChatColors()
        ];
    }

    /**
     * Get chat color palette from the database field.
     */
    public function getChatColors()
    {
        $default = '{}';
        $raw = $this->get_db_field('llm_chat_colors', $default);
        if (empty($raw)) return array();
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : array();
        }
        return is_array($raw) ? $raw : array();
    }

    // ===== UI Generation Helpers =====

    public function return_data($key)
    {
        $result = array();
        if (isset($this->interpolation_data['data_config_retrieved']) && isset($this->interpolation_data['data_config_retrieved'][$key])) {
            $result = $this->interpolation_data['data_config_retrieved'][$key];
        }
        header('Content-Type: application/json');
        echo json_encode($result);
        if (function_exists('uopz_allow_exit')) {
            uopz_allow_exit(true);
        }
        exit(0);
    }

    /**
     * Check if own entries only is enabled
     *
     * @return bool True if own entries only is enabled
     */
    public function getOwnEntriesOnly()
    {
        return $this->get_db_field('own_entries_only', '0') === '1';
    }

}
?>
