<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/style/StyleView.php";

/**
 * The view class for the LLM chat component.
 * Handles HTML rendering and template loading.
 */
class LlmchatView extends StyleView
{
    /* Constructors ***********************************************************/

    /**
     * The constructor.
     *
     * @param object $model
     *  The model instance of the component.
     * @param object $controller
     *  The controller instance of the component.
     */
    public function __construct($model, $controller)
    {
        parent::__construct($model, $controller);
    }

    /* Private Methods *********************************************************/

    /**
     * Render conversations sidebar
     */

    /* Public Methods *********************************************************/

    /**
     * Render the LLM chat component
     */
    public function output_content()
    {
        $user_id = $this->model->getUserId();
        $section_id = $this->model->getSectionId();
        $chat_description = $this->model->getChatDescription();

        // Get conversation and message data
        $conversation = $this->model->getCurrentConversation();
        $messages = $this->model->getConversationMessages();

        // Format message content with markdown parsing
        foreach ($messages as &$message) {
            $message['formatted_content'] = $this->model->formatMessageContent($message['content']);
        }

        $conversations = $this->model->getUserConversations();
        $configured_model = $this->model->getConfiguredModel();
        $llm_temperature = $this->model->getLlmTemperature();
        $llm_max_tokens = $this->model->getLlmMaxTokens();
        $current_conversation_id = $this->model->getConversationId();
        $conversation_name = $this->model->getConversationName();

        // Get UI labels
        $conversations_heading = $this->model->getConversationsHeading();
        $no_conversations_message = $this->model->getNoConversationsMessage();
        $new_chat_button_label = $this->model->getNewChatButtonLabel();
        $select_conversation_heading = $this->model->getSelectConversationHeading();
        $select_conversation_description = $this->model->getSelectConversationDescription();
        $model_label_prefix = $this->model->getModelLabelPrefix();
        $no_messages_message = $this->model->getNoMessagesMessage();
        $tokens_used_suffix = $this->model->getTokensUsedSuffix();
        $loading_text = $this->model->getLoadingText();
        $ai_thinking_text = $this->model->getAiThinkingText();
        $upload_image_label = $this->model->getUploadImageLabel();
        $upload_help_text = $this->model->getUploadHelpText();
        $message_placeholder = $this->model->getMessagePlaceholder();
        $clear_button_label = $this->model->getClearButtonLabel();
        $new_conversation_title_label = $this->model->getNewConversationTitleLabel();
        $conversation_title_label = $this->model->getConversationTitleLabel();
        $cancel_button_label = $this->model->getCancelButtonLabel();
        $create_button_label = $this->model->getCreateButtonLabel();
        $delete_confirmation_title = $this->model->getDeleteConfirmationTitle();
        $delete_confirmation_message = $this->model->getDeleteConfirmationMessage();
        $confirm_delete_button_label = $this->model->getConfirmDeleteButtonLabel();
        $cancel_delete_button_label = $this->model->getCancelDeleteButtonLabel();
        $submit_button_label = $this->model->getSubmitButtonLabel();

        // Error messages
        $empty_message_error = $this->model->getEmptyMessageError();
        $streaming_active_error = $this->model->getStreamingActiveError();
        $default_chat_title = $this->model->getDefaultChatTitle();

        // Additional UI labels
        $delete_button_title = $this->model->getDeleteButtonTitle();
        $conversation_title_placeholder = $this->model->getConversationTitlePlaceholder();

        // File attachment labels
        $single_file_attached = $this->model->getSingleFileAttachedText();
        $multiple_files_attached = $this->model->getMultipleFilesAttachedText();

        // Empty state labels
        $empty_state_title = $this->model->getEmptyStateTitle();
        $empty_state_description = $this->model->getEmptyStateDescription();
        $loading_messages_text = $this->model->getLoadingMessagesText();

        // Message input labels
        $streaming_in_progress_placeholder = $this->model->getStreamingInProgressPlaceholder();
        $attach_files_title = $this->model->getAttachFilesTitle();
        $no_vision_support_title = $this->model->getNoVisionSupportTitle();
        $no_vision_support_text = $this->model->getNoVisionSupportText();
        $send_message_title = $this->model->getSendMessageTitle();
        $remove_file_title = $this->model->getRemoveFileTitle();

        include __DIR__ . '/tpl/llm_chat_main.php';
    }

    /**
     * Get CSS includes
     */
    public function get_css_includes($local = array())
    {
        if (empty($local)) {
            $css_file = __DIR__ . "/../../../../css/ext/llm-chat.css";
            if (DEBUG) {
                // Use file modification time for cache busting in debug mode
                $version = filemtime($css_file) ?: time();
                $local = array($css_file . "?v=" . $version);
            } else {
                $local = array($css_file . "?v=" . rtrim(shell_exec("git describe --tags")));
            }
        }
        return parent::get_css_includes($local);
    }

    /**
     * Get JS includes
     */
    public function get_js_includes($local = array())
    {
        if (empty($local)) {
            $js_file = __DIR__ . "/../../../../js/ext/llm-chat.umd.js";
            if (DEBUG) {
                // Use file modification time for cache busting in debug mode
                $version = filemtime($js_file) ?: time();
                $local = array($js_file . "?v=" . $version);
            } else {
                $local = array($js_file . "?v=" . rtrim(shell_exec("git describe --tags")));
            }
        }
        return parent::get_js_includes($local);
    }

    /**
     * Get React configuration as JSON
     */
    public function getReactConfig()
    {
        return json_encode([
            'userId' => $this->model->getUserId(),
            'sectionId' => $this->model->getSectionId(),
            'currentConversationId' => $this->model->getConversationId(),
            'configuredModel' => $this->model->getConfiguredModel(),
            'maxFilesPerMessage' => LLM_MAX_FILES_PER_MESSAGE,
            'maxFileSize' => LLM_MAX_FILE_SIZE,
            'streamingEnabled' => $this->model->isStreamingEnabled(),
            'enableConversationsList' => $this->model->isConversationsListEnabled(),
            'enableFileUploads' => $this->model->isFileUploadsEnabled(),
            'enableFullPageReload' => $this->model->isFullPageReloadEnabled(),
            'acceptedFileTypes' => implode(',', array_map(fn($ext) => ".{$ext}", $this->model->getAcceptedFileTypes())),
            'isVisionModel' => $this->model->isVisionModel(),
            'hasConversationContext' => $this->model->hasConversationContext(),
            // Floating button configuration
            'enableFloatingButton' => $this->model->isFloatingButtonEnabled(),
            'floatingButtonPosition' => $this->model->getFloatingButtonPosition(),
            'floatingButtonIcon' => $this->model->getFloatingButtonIcon(),
            'floatingButtonLabel' => $this->model->getFloatingButtonLabel(),
            'floatingChatTitle' => $this->model->getFloatingChatTitle(),
            // UI Labels
            'messagePlaceholder' => $this->model->getMessagePlaceholder(),
            'noConversationsMessage' => $this->model->getNoConversationsMessage(),
            'newConversationTitleLabel' => $this->model->getNewConversationTitleLabel(),
            'conversationTitleLabel' => $this->model->getConversationTitleLabel(),
            'cancelButtonLabel' => $this->model->getCancelButtonLabel(),
            'createButtonLabel' => $this->model->getCreateButtonLabel(),
            'deleteConfirmationTitle' => $this->model->getDeleteConfirmationTitle(),
            'deleteConfirmationMessage' => $this->model->getDeleteConfirmationMessage(),
            'confirmDeleteButtonLabel' => $this->model->getConfirmDeleteButtonLabel(),
            'cancelDeleteButtonLabel' => $this->model->getCancelDeleteButtonLabel(),
            'tokensSuffix' => $this->model->getTokensUsedSuffix(),
            'aiThinkingText' => $this->model->getAiThinkingText(),
            'conversationsHeading' => $this->model->getConversationsHeading(),
            'newChatButtonLabel' => $this->model->getNewChatButtonLabel(),
            'selectConversationHeading' => $this->model->getSelectConversationHeading(),
            'selectConversationDescription' => $this->model->getSelectConversationDescription(),
            'modelLabelPrefix' => $this->model->getModelLabelPrefix(),
            'noMessagesMessage' => $this->model->getNoMessagesMessage(),
            'loadingText' => $this->model->getLoadingText(),
            'uploadImageLabel' => $this->model->getUploadImageLabel(),
            'uploadHelpText' => $this->model->getUploadHelpText(),
            'clearButtonLabel' => $this->model->getClearButtonLabel(),
            'submitButtonLabel' => $this->model->getSubmitButtonLabel(),
            'emptyMessageError' => $this->model->getEmptyMessageError(),
            'streamingActiveError' => $this->model->getStreamingActiveError(),
            'defaultChatTitle' => $this->model->getDefaultChatTitle(),
            'deleteButtonTitle' => $this->model->getDeleteButtonTitle(),
            'conversationTitlePlaceholder' => $this->model->getConversationTitlePlaceholder(),
            'singleFileAttachedText' => $this->model->getSingleFileAttachedText(),
            'multipleFilesAttachedText' => $this->model->getMultipleFilesAttachedText(),
            'emptyStateTitle' => $this->model->getEmptyStateTitle(),
            'emptyStateDescription' => $this->model->getEmptyStateDescription(),
            'loadingMessagesText' => $this->model->getLoadingMessagesText(),
            'streamingInProgressPlaceholder' => $this->model->getStreamingInProgressPlaceholder(),
            'attachFilesTitle' => $this->model->getAttachFilesTitle(),
            'noVisionSupportTitle' => $this->model->getNoVisionSupportTitle(),
            'noVisionSupportText' => $this->model->getNoVisionSupportText(),
            'sendMessageTitle' => $this->model->getSendMessageTitle(),
            'removeFileTitle' => $this->model->getRemoveFileTitle(),
            // File config
            'fileConfig' => [
                'maxFileSize' => LLM_MAX_FILE_SIZE,
                'maxFilesPerMessage' => LLM_MAX_FILES_PER_MESSAGE,
                'allowedImageExtensions' => LLM_ALLOWED_IMAGE_EXTENSIONS,
                'allowedDocumentExtensions' => LLM_ALLOWED_DOCUMENT_EXTENSIONS,
                'allowedCodeExtensions' => LLM_ALLOWED_CODE_EXTENSIONS,
                'allowedExtensions' => LLM_ALLOWED_EXTENSIONS,
                'visionModels' => LLM_VISION_MODELS
            ]
        ]);
    }

    public function output_content_mobile()
    {
        // not implemented
        return;
    }

    /**
     * Format file size in human-readable format
     *
     * @param int $bytes File size in bytes
     * @return string Formatted file size
     */
    public function formatFileSize($bytes)
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 1) . ' ' . $units[$unitIndex];
    }
}
?>
