<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseModel.php";

/**
 * Model for the LLM Prompts hub module.
 *
 * Thin wrapper around BaseModel. The data operations are delegated to
 * LlmPromptCatalogService (read side) and LlmPromptHubSaveService (write
 * side), which the controller creates.
 *
 * @package LLM Plugin
 */
class ModuleLlmPromptsModel extends BaseModel
{
    /**
     * @param object $services SelfHelp services container
     */
    public function __construct($services)
    {
        parent::__construct($services);
    }
}
?>
