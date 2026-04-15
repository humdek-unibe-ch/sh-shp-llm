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

/**
 * LLM Memory Administration Module Component.
 *
 * Provides a React-powered admin interface for managing the per-user memory
 * subsystem. Admins can create/edit memory rules, browse per-user memory
 * entries, view update history, and configure memory storage settings.
 *
 * Renders the `module_llm_memory.php` template which mounts the React
 * MemoryAdminPanel / MemoryRulesEditorApp components.
 *
 * @package LLM Plugin
 * @see ModuleLlmMemoryController For AJAX action dispatch
 * @see LlmMemoryRuleService For rule CRUD logic
 * @see LlmMemoryAdminService For admin data queries
 */
class ModuleLlmMemoryComponent extends BaseComponent
{
    /**
     * @param object $services SelfHelp services container
     * @param array $params GET parameters
     * @param int|null $id_page Current page ID
     */
    public function __construct($services, $params = [], $id_page = null)
    {
        $model = new ModuleLlmMemoryModel($services);
        $controller = new ModuleLlmMemoryController($model);
        $view = new ModuleLlmMemoryView($model);
        parent::__construct($model, $view, $controller);
    }
}
?>
