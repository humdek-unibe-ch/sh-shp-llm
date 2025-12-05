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
        $chat_description = $this->model->getChatDescription();

        if (!$user_id) {
            echo '<div class="alert alert-warning">Please log in to use the chat feature.</div>';
            return;
        }

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

        include __DIR__ . '/tpl/llm_chat_main.php';
    }

    /**
     * Get CSS includes
     */
    public function get_css_includes($local = array())
    {
        if (empty($local)) {
            if (DEBUG) {
                $local = array(
                    __DIR__ . "/../../../../css/ext/llm-chat.css",
                );
            } else {
                $local = array(
                    __DIR__ . "/../../../../css/ext/llm-chat.css?v=" . rtrim(shell_exec("git describe --tags")),
                );
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
            if (DEBUG) {
                $local = array(
                    __DIR__ . "/../../../../js/ext/llm-chat.umd.js",
                );
            } else {
                $local = array(
                    __DIR__ . "/../../../../js/ext/llm-chat.umd.js?v=" . rtrim(shell_exec("git describe --tags")),
                );
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
            'currentConversationId' => $this->model->getConversationId(),
            'configuredModel' => $this->model->getConfiguredModel(),
            'maxFilesPerMessage' => LLM_MAX_FILES_PER_MESSAGE,
            'maxFileSize' => LLM_MAX_FILE_SIZE,
            'streamingEnabled' => $this->model->isStreamingEnabled(),
            'enableConversationsList' => $this->model->isConversationsListEnabled(),
            'enableFileUploads' => $this->model->isFileUploadsEnabled(),
            'enableFullPageReload' => $this->model->isFullPageReloadEnabled(),
            'acceptedFileTypes' => implode(',', array_map(fn($ext) => ".{$ext}", $this->model->getAcceptedFileTypes())),
            // UI Labels
            'messagePlaceholder' => $this->model->getMessagePlaceholder(),
            'noConversationsMessage' => $this->model->getNoConversationsMessage(),
            'newConversationTitleLabel' => $this->model->getNewConversationTitleLabel(),
            'conversationTitleLabel' => $this->model->getConversationTitleLabel(),
            'cancelButtonLabel' => $this->model->getCancelButtonLabel(),
            'createButtonLabel' => $this->model->getCreateButtonLabel(),
            'deleteConfirmationTitle' => $this->model->getDeleteConfirmationTitle(),
            'deleteConfirmationMessage' => $this->model->getDeleteConfirmationMessage(),
            'tokensSuffix' => $this->model->getTokensUsedSuffix(),
            'aiThinkingText' => $this->model->getAiThinkingText(),
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
