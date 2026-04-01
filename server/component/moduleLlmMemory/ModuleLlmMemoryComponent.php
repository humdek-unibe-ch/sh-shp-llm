<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseComponent.php";
require_once __DIR__ . "/ModuleLlmMemoryModel.php";
require_once __DIR__ . "/ModuleLlmMemoryView.php";
require_once __DIR__ . "/ModuleLlmMemoryController.php";

class ModuleLlmMemoryComponent extends BaseComponent
{
    public function __construct($services, $params = [], $id_page = null)
    {
        $model = new ModuleLlmMemoryModel($services);
        $controller = new ModuleLlmMemoryController($model);
        $view = new ModuleLlmMemoryView($model);
        parent::__construct($model, $view, $controller);
    }
}
?>
