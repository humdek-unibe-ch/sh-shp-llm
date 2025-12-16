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
     * @param array $params
     *  The list of get parameters to propagate.
     * @param number $id_page
     *  The id of the parent page
     * @param array $entry_record
     *  An array that contains the entry record information.
     */
    public function __construct($services, $id, $params = array(), $id_page = -1, $entry_record = array())
    {
        $model = new LlmchatModel($services, $id, $params, $id_page, $entry_record);
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

    public function output_content_mobile()
    {
        // not implemented
        return;
    }
}
?>
