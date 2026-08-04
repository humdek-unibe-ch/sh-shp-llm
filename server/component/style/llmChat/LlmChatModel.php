<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/style/StyleModel.php";
require_once __DIR__ . "/../../../service/LlmService.php";
require_once __DIR__ . "/../../../service/LlmLanguageUtility.php";
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

    /** @return int Current session user ID. */
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

    /**
     * Get the CMS section ID this chat component belongs to.
     *
     * @return int Section ID from the sections table.
     */
    public function getSectionId()
    {
        return $this->section_id;
    }

    /* Configuration Getters — read directly from StyleModel field cache ********/

    /** @return int Maximum number of conversations per user. */
    public function getConversationLimit() { return $this->get_db_field('conversation_limit', LLM_DEFAULT_CONVERSATION_LIMIT); }
    /** @return int Maximum number of messages per conversation. */
    public function getMessageLimit() { return $this->get_db_field('message_limit', LLM_DEFAULT_MESSAGE_LIMIT); }
    /** @return string Scoped model identifier (e.g. "server/model-name"), empty if not set. */
    public function getLlmModel() { return $this->get_db_field('llm_model', ''); }
    /** @return string Temperature value as string (e.g. "0.7"). */
    public function getLlmTemperature() { return $this->get_db_field('llm_temperature', '0.7'); }
    /** @return string Max tokens value as string (e.g. "2048"). */
    public function getLlmMaxTokens() { return $this->get_db_field('llm_max_tokens', '2048'); }
    /** @return bool Whether the conversation list sidebar is shown to the user. */
    public function isConversationsListEnabled() { return $this->get_db_field('enable_conversations_list', '0') === '1'; }
    /** @return bool Whether file upload button is available in the chat input. */
    public function isFileUploadsEnabled() { return $this->get_db_field('enable_file_uploads', '0') == '1'; }
    /** @return bool Whether to reload the full page after conversation actions (delete, new). */
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

    /** @return bool Whether the configured model supports image/vision input. */
    public function isVisionModel() { return llm_is_vision_model($this->getConfiguredModel()); }

    /**
     * Build the upload help text shown below the file input.
     *
     * Returns the CMS-configured custom text if set, otherwise auto-generates
     * a summary from the allowed extensions, max file size, and file count.
     *
     * @return string Human-readable help text.
     */
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

    /**
     * Format a byte count into a human-readable size string (KB/MB/GB).
     *
     * @param int $bytes File size in bytes.
     * @return string Formatted size (e.g. "10MB").
     */
    private static function formatFileSizeForDisplay($bytes)
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . 'GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 0) . 'MB';
        if ($bytes >= 1024) return round($bytes / 1024, 0) . 'KB';
        return $bytes . 'B';
    }

    // ===== Conversation Context =====

    /** @return string Raw conversation context (system prompt) from the CMS field. */
    public function getConversationContext() { return $this->get_db_field('conversation_context', ''); }
    /** @return bool True if a non-empty conversation context / system prompt is configured. */
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
     * Resolve the opening assistant message for auto-start.
     * Always uses the CMS `auto_start_message` field as authored — never
     * mixes it with conversation-context topic extraction.
     *
     * @return string Auto-start message
     */
    public function generateContextAwareAutoStartMessage()
    {
        return $this->getAutoStartMessage();
    }

    // ===== Feature Flags =====

    /** @return bool Whether the chat auto-starts a conversation when opened. */
    public function isAutoStartConversationEnabled() { return $this->get_db_field('auto_start_conversation', '0') == '1'; }
    /** @return bool Whether strict conversation mode is enabled (topic enforcement). */
    public function isStrictConversationModeEnabled() { return $this->get_db_field('strict_conversation_mode', '0') === '1'; }
    /** @return bool True only when strict mode is enabled AND a conversation context is set. */
    public function shouldApplyStrictMode() { return $this->isStrictConversationModeEnabled() && $this->hasConversationContext(); }
    /** @return string The initial assistant message for auto-started conversations. */
    public function getAutoStartMessage() { return $this->get_db_field('auto_start_message', "Hello! I'm here to help you. What would you like to talk about?"); }
    /** @return bool Whether form mode (structured JSON responses) is enabled. */
    public function isFormModeEnabled() { return $this->get_db_field('enable_form_mode', '0') === '1'; }
    /** @return string Title shown in the form mode banner above the chat. */
    public function getFormModeActiveTitle() { return $this->get_db_field('form_mode_active_title', 'Form Mode Active'); }
    /** @return string Description shown in the form mode banner. */
    public function getFormModeActiveDescription() { return $this->get_db_field('form_mode_active_description', 'Please use the form above to respond.'); }

    // ===== Data Saving =====

    /** @return bool Whether LLM form values are persisted to dataTables. */
    public function isDataSavingEnabled() { return $this->get_db_field('enable_data_saving', '0') === '1'; }
    /** @return string 'log' for append-only or 'record' for upsert mode. */
    public function getDataSaveMode() { return $this->get_db_field('is_log', '0') === '1' ? 'log' : 'record'; }

    // ===== Memory =====

    /**
     * Get the comma-separated list of memory rule IDs configured for this chat section.
     * Empty array means "use auto-matching by source_type".
     *
     * @return int[]
     */
    public function getMemoryRuleIds()
    {
        $raw = $this->get_db_field('memory_rule_ids', '');
        if (empty($raw)) return [];
        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }

    // ===== Floating Chat Button =====

    /** @return bool Whether the floating chat button is displayed on the page. */
    public function isFloatingButtonEnabled() { return $this->get_db_field('enable_floating_button', '0') === '1'; }
    /** @return string CSS position class (e.g. 'bottom-right', 'bottom-left'). */
    public function getFloatingButtonPosition() { return $this->get_db_field('floating_button_position', 'bottom-right'); }
    /** @return string FontAwesome icon class for the floating button (e.g. 'fa-comments'). */
    public function getFloatingButtonIcon() { return $this->get_db_field('floating_button_icon', 'fa-comments'); }
    /** @return string Text label displayed on/beside the floating button. */
    public function getFloatingButtonLabel() { return $this->get_db_field('floating_button_label', ''); }
    /** @return string Title shown in the floating chat panel header. */
    public function getFloatingChatTitle() { return $this->get_db_field('floating_chat_title', 'AI Assistant'); }

    /**
     * Parse the llm_chat_shortcuts JSON field into a normalized array.
     *
     * Expected shape:
     * [
     *   {
     *     "label": "Where was that exercise with XY again?",
     *     "message": "Where was that exercise with XY again?"
     *   }
     * ]
     *
     * Rules:
     * - If message is empty or missing, use label as the message.
     * - Ignore entries without usable label text.
     * - Empty or missing shortcuts returns empty array.
     *
     * @return array Normalized shortcuts array with label and message keys.
     */
    public function getFloatingShortcuts()
    {
        $raw = $this->get_db_field('llm_chat_shortcuts', '');
        if (empty($raw)) {
            return [];
        }

        $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $shortcuts = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = isset($item['label']) ? trim((string)$item['label']) : '';
            if (empty($label)) {
                continue;
            }

            $message = isset($item['message']) ? trim((string)$item['message']) : '';
            if (empty($message)) {
                $message = $label;
            }

            $shortcuts[] = [
                'label' => $label,
                'message' => $message
            ];
        }

        return $shortcuts;
    }

    // ===== Media Rendering =====

    /** @return bool Whether media rendering instructions are appended to context. */
    public function isMediaRenderingEnabled() { return $this->get_db_field('enable_media_rendering', '1') === '1'; }
    /** @return string Label for the "Continue" button shown after LLM responses. */
    public function getContinueButtonLabel() { return $this->get_db_field('continue_button_label', 'Continue'); }

    // ===== Hint / Quick-reply Suggestions =====

    /**
     * Whether the LLM is allowed to emit and the React UI is allowed to render
     * the structured-response quick-reply suggestion buttons.
     *
     * The model emits suggestions at `content.suggestions` (array of objects
     * with a `text` property). The React layer remaps them into
     * `next_step.suggestions` for rendering — both layers respect this flag.
     *
     * Defaults to enabled so chats configured before v1.3.0 keep their
     * existing behaviour. When disabled, two things happen:
     *   1. `LlmContextService` appends the
     *      `core.response.suppress_suggestions` system prompt telling the
     *      model to leave `content.suggestions` empty (saves output tokens).
     *   2. The React `StructuredResponseRenderer` skips the suggestion
     *      buttons even if a legacy/cached response still contains any.
     *
     * @return bool
     */
    public function isHintSuggestionsEnabled() { return $this->get_db_field('enable_hint_suggestions', '1') === '1'; }

    // ===== Progress Tracking =====

    /** @return bool Whether topic-based progress tracking is active. */
    public function isProgressTrackingEnabled() { return $this->get_db_field('enable_progress_tracking', '0') === '1'; }
    /** @return string Label text shown above the progress bar. */
    public function getProgressBarLabel() { return $this->get_db_field('progress_bar_label', 'Progress'); }
    /** @return string Message displayed when all topics have been covered. */
    public function getProgressCompleteMessage() { return $this->get_db_field('progress_complete_message', 'Great job! You have covered all topics.'); }
    /** @return bool Whether individual topic names are shown in the progress UI. */
    public function shouldShowProgressTopics() { return $this->get_db_field('progress_show_topics', '0') === '1'; }

    /**
     * Get the context language for progress confirmation questions.
     * Auto-detected from session locale via LlmLanguageUtility.
     */
    public function getContextLanguage()
    {
        return LlmLanguageUtility::getUserLanguageCode('en');
    }

    /**
     * Assemble the progress tracking configuration for the React frontend.
     *
     * @return array{enabled: bool, barLabel: string, completeMessage: string, showTopics: bool, contextLanguage: string}
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

    // ===== Danger Detection =====

    /** @return bool Whether keyword-based danger/crisis detection is active. */
    public function isDangerDetectionEnabled() { return $this->get_db_field('enable_danger_detection', '0') === '1'; }
    /** @return string Newline-separated list of danger keywords/phrases. */
    public function getDangerKeywords() { return $this->get_db_field('danger_keywords', ''); }
    /** @return string Markdown message shown to the user when danger keywords are detected. */
    public function getDangerBlockedMessage() { return $this->get_db_field('danger_blocked_message', "I noticed some concerning content in your message. While I want to help, I'm not equipped to handle sensitive topics like this.\n\n**Please consider reaching out to:**\n- A trusted friend or family member\n- A mental health professional\n- Crisis hotlines in your area\n\nIf you're in immediate danger, please contact emergency services.\n\n*Your well-being is important. Take care of yourself.*"); }

    /**
     * Parse the semicolon/newline-separated list of notification email addresses.
     *
     * @return string[] Array of email addresses to notify on danger detection. Empty if none configured.
     */
    public function getDangerNotificationEmails()
    {
        $raw = $this->get_db_field('danger_notification_emails', '');
        if (empty($raw)) return [];
        return array_filter(array_map('trim', preg_split('/[\n;]+/', $raw)));
    }

    // ===== Speech-to-Text =====

    /**
     * Check whether speech-to-text is available.
     * Requires both the feature flag AND a configured audio model.
     *
     * @return bool True if speech-to-text is enabled and a model is set.
     */
    public function isSpeechToTextEnabled()
    {
        return $this->get_db_field('enable_speech_to_text', '0') === '1'
            && !empty($this->get_db_field('speech_to_text_model', ''));
    }

    /** @return string Whisper audio model identifier for speech transcription. */
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
            'floatingShortcuts' => $this->getFloatingShortcuts(),
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
            'enableHintSuggestions' => $this->isHintSuggestionsEnabled(),
            'chatAppearance' => $this->getChatAppearance()
        ];
    }

    /**
     * Default chat bubble appearance tree.
     *
     * This is the single source of truth used both as the v1.3.0
     * SQL `default_value` (kept in sync manually) and as the merge
     * floor for `getChatAppearance()` so partial JSON in the field
     * still produces a complete tree for the front-end.
     *
     * @return array Per-side keys: bg, text, border, icon (FontAwesome),
     *               iconMobile (Ionic), iconImage (custom URL).
     */
    public static function getDefaultChatAppearance()
    {
        return array(
            'user' => array(
                'bg'         => '#DCF8C6',
                'text'       => '#1b5e20',
                'border'     => '#a5d6a7',
                'icon'       => 'fa-user',
                'iconMobile' => 'person-circle',
                'iconImage'  => ''
            ),
            'ai' => array(
                'bg'         => '#F3E5F5',
                'text'       => '#4a148c',
                'border'     => '#ce93d8',
                'icon'       => 'fa-robot',
                'iconMobile' => 'chatbubble-ellipses',
                'iconImage'  => ''
            )
        );
    }

    /**
     * Build the unified chat appearance tree for the front-end.
     *
     * Reads the `llm_chat_appearance` JSON field, merges it on top of
     * `getDefaultChatAppearance()` (per-side, per-key) so partial
     * overrides work, and normalises every non-empty `iconImage`:
     *   * Absolute http(s) / data: / blob: URLs pass through verbatim.
     *   * Paths starting with `/` get `BASE_PATH` prepended so they
     *     resolve correctly on sub-directory installs.
     *   * Empty strings are kept empty so the React + mobile renderers
     *     fall through to the FontAwesome / Ionic icon for that side.
     *
     * `{{interpolation}}` is already resolved by `StyleModel` before
     * `get_db_field()` returns the value, so authors can drop dynamic
     * URLs straight into the JSON
     * (e.g. `{"user":{"iconImage":"{{user_avatar}}"}}`).
     *
     * @return array Shape `['user' => [...], 'ai' => [...]]`, fully merged with defaults.
     */
    public function getChatAppearance()
    {
        $defaults = self::getDefaultChatAppearance();

        $raw = $this->get_db_field('llm_chat_appearance', '');
        if ($raw === '' || $raw === null) {
            return $defaults;
        }

        $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        $out = array();
        foreach ($defaults as $role => $defaultEntry) {
            $override = (isset($decoded[$role]) && is_array($decoded[$role])) ? $decoded[$role] : array();
            $merged = $defaultEntry;
            foreach ($defaultEntry as $key => $_default) {
                if (isset($override[$key]) && $override[$key] !== '') {
                    $merged[$key] = $override[$key];
                } elseif (array_key_exists($key, $override) && $override[$key] === '') {
                    $merged[$key] = '';
                }
            }
            if (!empty($merged['iconImage'])) {
                $merged['iconImage'] = $this->normalizeIconUrl((string)$merged['iconImage']);
            }
            $out[$role] = $merged;
        }
        return $out;
    }

    /**
     * Normalise a configured icon image URL/path.
     *
     * @param string $url Already-interpolated value from the JSON field.
     * @return string The URL exactly as it should be emitted in `<img src>`.
     */
    private function normalizeIconUrl($url)
    {
        if ($url === '') {
            return $url;
        }
        if (preg_match('~^(https?:|data:|blob:)~i', $url)) {
            return $url;
        }
        if ($url[0] === '/') {
            $base = defined('BASE_PATH') ? BASE_PATH : '';
            return rtrim($base, '/') . $url;
        }
        return $url;
    }

    // ===== UI Generation Helpers =====

    /**
     * Output a JSON-encoded section of interpolation data and terminate the request.
     *
     * Used by the controller to serve specific data slices (e.g. conversation config)
     * without rendering the full page.
     *
     * @param string $key Key within `$this->interpolation_data['data_config_retrieved']`.
     * @return never Exits after echoing JSON.
     */
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
