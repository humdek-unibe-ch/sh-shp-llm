<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/BaseComponent.php";
require_once __DIR__ . "/LlmchatModel.php";
require_once __DIR__ . "/LlmchatView.php";
require_once __DIR__ . "/LlmchatController.php";

/**
 * The LLM chat component for real-time conversations with AI models.
 */
class LlmchatComponent extends BaseComponent
{
    /* Constructors ***********************************************************/

    /**
     * The constructor creates an instance of the LLM chat component.
     *
     * @param object $services
     *  An associative array holding the different available services.
     * @param int $id
     *  The section id of this component.
     */
    public function __construct($services, $id)
    {
        $model = new LlmchatModel($services, $id);
        $controller = new LlmchatController($model);
        $view = new LlmchatView($model, $controller);
        parent::__construct($model, $view, $controller);
    }

    /* Private Methods *********************************************************/

    /* Public Methods *********************************************************/

    /**
     * Check if user has access to this component
     */
    public function has_access()
    {
        // Check if user is logged in
        if (!isset($_SESSION['id_user'])) {
            return false;
        }

        return parent::has_access();
    }

    /**
     * Get CSS includes for this component
     */
    public function get_css_includes($local = array())
    {
        if (empty($local)) {
            if (DEBUG) {
                $local = array(__DIR__ . '/css/llmchat.css');
            } else {
                $local = array(__DIR__ . '/../../../css/ext/llm.min.css?v=' . rtrim(shell_exec("git describe --tags")));
            }
        }
        return parent::get_css_includes($local);
    }

    /**
     * Get JS includes for this component
     */
    public function get_js_includes($local = array())
    {
        if (empty($local)) {
            if (DEBUG) {
                $local = array(__DIR__ . '/js/llmchat.js');
            } else {
                $local = array(__DIR__ . '/../../../js/ext/llm.min.js?v=' . rtrim(shell_exec("git describe --tags")));
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
