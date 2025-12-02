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
        $conversations = $this->model->getUserConversations();
        $configured_model = $this->model->getConfiguredModel();
        $llm_temperature = $this->model->getLlmTemperature();
        $llm_max_tokens = $this->model->getLlmMaxTokens();
        $current_conversation_id = $this->model->getConversationId();

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
                $local = array('/css/ext/bootstrap.min.css');
            } else {
                $local = array('/css/ext/bootstrap.min.css?v=' . rtrim(shell_exec("git describe --tags")));
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
                $local = array('/js/ext/jquery.min.js', '/js/ext/bootstrap.bundle.min.js');
            } else {
                $local = array('/js/ext/jquery.min.js?v=' . rtrim(shell_exec("git describe --tags")),
                              '/js/ext/bootstrap.bundle.min.js?v=' . rtrim(shell_exec("git describe --tags")));
            }
        }
        return parent::get_js_includes($local);
    }

    public function output_content_mobile()
    {
        // not implemented
        return;
    }
}
?>
