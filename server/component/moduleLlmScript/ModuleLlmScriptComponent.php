<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseComponent.php";
require_once __DIR__ . "/ModuleLlmScriptModel.php";
require_once __DIR__ . "/ModuleLlmScriptView.php";
require_once __DIR__ . "/ModuleLlmScriptController.php";

/**
 * LLM Scripts component.
 * Single component handles list + editor views via React.
 * Controller handles ?action= API requests from the React ScriptsManager.
 * URL parameter ?sid=123 opens a specific script for editing.
 * ACL is checked per-action in the controller.
 */
class ModuleLlmScriptComponent extends BaseComponent
{
    public function __construct($services, $params = [], $id_page = null)
    {
        $model = new ModuleLlmScriptModel($services);
        $controller = new ModuleLlmScriptController($model);
        $view = new ModuleLlmScriptView($model);
        parent::__construct($model, $view, $controller);
    }
}
?>
