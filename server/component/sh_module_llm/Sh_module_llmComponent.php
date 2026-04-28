<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseComponent.php";
require_once __DIR__ . "/Sh_module_llmModel.php";
require_once __DIR__ . "/Sh_module_llmView.php";
require_once __DIR__ . "/Sh_module_llmController.php";

/**
 * LLM Settings component.
 * Renders the General Settings tab in the unified LLM admin layout.
 * Reuses the existing sh_module_llm page and its pages_fields for configuration storage.
 */
class Sh_module_llmComponent extends BaseComponent
{
    public function __construct($services, $params = [], $id_page = null)
    {
        $model = new Sh_module_llmModel($services);
        $controller = new Sh_module_llmController($model);
        $view = new Sh_module_llmView($model);
        parent::__construct($model, $view, $controller);
    }
}
?>
