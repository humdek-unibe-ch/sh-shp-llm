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
class LlmChatView extends StyleView
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
        if (
            (method_exists($this->model, 'is_cms_page') && $this->model->is_cms_page()) &&
            (method_exists($this->model, 'is_cms_page_editing') && $this->model->is_cms_page_editing())
        ) {
            return;
        }
        $user_id = $this->model->getUserId();
        $section_id = $this->model->getSectionId();

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
                $local = array($css_file . "?v=" . rtrim(shell_exec("git describe --tags") ?: ""));
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
                $local = array($js_file . "?v=" . rtrim(shell_exec("git describe --tags") ?: ""));
            }
        }
        return parent::get_js_includes($local);
    }

    /**
     * Get React configuration as JSON.
     * Delegates to the model's getChatConfig() as single source of truth.
     */
    public function getReactConfig()
    {
        return json_encode($this->model->getChatConfig());
    }

    public function output_content_mobile()
    {
        // Check CMS editing mode (same as web version)
        if (
            (method_exists($this->model, 'is_cms_page') && $this->model->is_cms_page()) &&
            (method_exists($this->model, 'is_cms_page_editing') && $this->model->is_cms_page_editing())
        ) {
            return []; // Return empty array for CMS editing
        }

        // Get all DB fields directly (this is what mobile expects)
        $style = parent::output_content_mobile();

        // Only add minimal additional data needed for mobile functionality
        // The mobile app gets all configuration from DB fields directly

        if ($this->model->getCurrentConversation()) {
            $style['current_conversation'] = $this->model->getCurrentConversation();
            $style['messages'] = $this->model->getConversationMessages();
            $style['conversations'] = $this->model->getUserConversations();
        }

        // Add user/section context for mobile app
        $style['user_id'] = $this->model->getUserId();
        $style['section_id'] = $this->model->getSectionId();

        return $style;
    }
}
?>