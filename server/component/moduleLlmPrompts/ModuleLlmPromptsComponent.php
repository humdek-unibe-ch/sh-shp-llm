<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseComponent.php";
require_once __DIR__ . "/ModuleLlmPromptsModel.php";
require_once __DIR__ . "/ModuleLlmPromptsView.php";
require_once __DIR__ . "/ModuleLlmPromptsController.php";

/**
 * LLM Prompts Hub Module Component.
 *
 * One admin page that lists every LLM prompt of the installation (CMS prompt
 * fields, scripts, memory rule instructions, built-in system prompts, agentic
 * chat personas), searches them, shows where each one is used, and lets an
 * editor change a prompt in place with version history, diff and restore.
 *
 * Renders the `module_llm_prompts.php` template which mounts the React
 * prompts hub app.
 *
 * @package LLM Plugin
 * @see ModuleLlmPromptsController For ?action= dispatch (CSRF, ACL)
 * @see LlmPromptCatalogService For the read side (list, search, get)
 * @see LlmPromptHubSaveService For the write side (save, restore)
 */
class ModuleLlmPromptsComponent extends BaseComponent
{
    /**
     * @param object $services SelfHelp services container
     * @param array $params GET parameters
     * @param int|null $id_page Current page ID
     */
    public function __construct($services, $params = [], $id_page = null)
    {
        $model = new ModuleLlmPromptsModel($services);
        $controller = new ModuleLlmPromptsController($model);
        $view = new ModuleLlmPromptsView($model);
        parent::__construct($model, $view, $controller);
    }
}
?>
