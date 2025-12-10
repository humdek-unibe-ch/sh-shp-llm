<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseComponent.php";
require_once __DIR__ . "/ModuleLlmAdminConsoleModel.php";
require_once __DIR__ . "/ModuleLlmAdminConsoleView.php";
require_once __DIR__ . "/ModuleLlmAdminConsoleController.php";

/**
 * The LLM admin console component - comprehensive interface for managing conversations
 */
class ModuleLlmAdminConsoleComponent extends BaseComponent
{
    /* Constructors ***********************************************************/

    /**
     * The constructor creates an instance of the ModuleLlmAdminConsoleModel and
     * ModuleLlmAdminConsoleView classes.
     *
     * @param array $services
     *  An associative array holding the different available services.
     * @param array $params
     *  The GET parameters.
     * @param int $id_page
     *  The page ID.
     */
    public function __construct($services,  $params = [], $id_page = null)
    {
        $model = new ModuleLlmAdminConsoleModel($services, $params, $id_page);
        $controller = new ModuleLlmAdminConsoleController($model);
        $view = new ModuleLlmAdminConsoleView($model);
        parent::__construct($model, $view, $controller);
    }

    /* Private Methods *********************************************************/

    /* Public Methods *********************************************************/
}
?>
