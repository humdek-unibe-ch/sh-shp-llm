<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . "/../../../../../../component/BaseComponent.php";
require_once __DIR__ . "/LlmMemoryEditorModel.php";
require_once __DIR__ . "/LlmMemoryEditorView.php";
require_once __DIR__ . "/LlmMemoryEditorController.php";

/**
 * The llmMemoryEditor style: the logged-in participant's memory as a list of items
 * that they can edit, delete and add to, without an LLM call.
 */
class LlmMemoryEditorComponent extends BaseComponent
{
    /**
     * @param object $services The service container
     * @param int $id The section id of this component
     * @param array $params The get parameters to propagate
     * @param int $id_page The id of the parent page
     * @param array $entry_record The entry record information
     */
    public function __construct($services, $id, $params = array(), $id_page = -1, $entry_record = array())
    {
        $model = new LlmMemoryEditorModel($services, $id, $params, $id_page, $entry_record);
        $controller = null;
        if (!$model->is_cms_page()) {
            $controller = new LlmMemoryEditorController($model);
        }
        $view = new LlmMemoryEditorView($model, $controller);
        parent::__construct($model, $view, $controller);
    }
}
