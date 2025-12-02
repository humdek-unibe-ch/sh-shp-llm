<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseView.php";

/**
 * The view class for the LLM conversations admin component.
 * Renders the conversations list interface.
 */
class LlmConversationsView extends BaseView
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
     * Render the LLM conversations admin interface
     */
    public function output_content()
    {
        $conversations = $this->model->getConversations();
        $current_page = $this->model->getCurrentPage();
        $total_conversations = $this->model->getTotalConversations();
        $per_page = 50;
        $total_pages = ceil($total_conversations / $per_page);

        include __DIR__ . '/tpl/llm_conversations.php';
    }

    /**
     * Get CSS includes
     */
    public function get_css_includes($local = array())
    {
        if (empty($local)) {
            if (DEBUG) {
                $local = array('/css/ext/bootstrap.min.css', '/css/ext/datatables.min.css');
            } else {
                $local = array('/css/ext/bootstrap.min.css?v=' . rtrim(shell_exec("git describe --tags")),
                              '/css/ext/datatables.min.css?v=' . rtrim(shell_exec("git describe --tags")));
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
                $local = array('/js/ext/jquery.min.js', '/js/ext/bootstrap.bundle.min.js', '/js/ext/datatables.min.js');
            } else {
                $local = array('/js/ext/jquery.min.js?v=' . rtrim(shell_exec("git describe --tags")),
                              '/js/ext/bootstrap.bundle.min.js?v=' . rtrim(shell_exec("git describe --tags")),
                              '/js/ext/datatables.min.js?v=' . rtrim(shell_exec("git describe --tags")));
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