<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseView.php";

/**
 * The view class for the LLM conversation admin component.
 * Renders individual conversation details and messages.
 */
class LlmConversationView extends BaseView
{
    /* Constructors ***********************************************************/

    /**
     * The constructor.
     *
     * @param object $model
     *  The model instance of the component.
     */
    public function __construct($model)
    {
        parent::__construct($model, null);
    }

    /* Private Methods *********************************************************/

    /* Public Methods *********************************************************/

    /**
     * Render the LLM conversation admin interface
     */
    public function output_content()
    {
        $conversation = $this->model->getConversation();
        $messages = $this->model->getMessages();

        include __DIR__ . '/tpl/llm_conversation.php';
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
                $local = array(__DIR__ . "/js/llm_conversation.js");
            } else {
                $local = array(__DIR__ . "/../../../js/ext/llm.min.js?v=" . rtrim(shell_exec("git describe --tags")));
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