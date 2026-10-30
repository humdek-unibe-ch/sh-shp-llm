<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . "/LlmFormLogListModel.php";
require_once __DIR__ . "/LlmFormLogListController.php";
require_once __DIR__ . "/LlmFormLogListView.php";

/**
 * llmFormLogList: core log form + list component with a native LLM
 * interview mounted over the form section.
 */
class LlmFormLogListComponent extends BaseComponent
{
    public function __construct($services, $id, $params, $id_page, $entry_record)
    {
        $model = new LlmFormLogListModel($services, $id, $params, $id_page, $entry_record);
        $controller = null;
        if (!$model->is_cms_page()) {
            $controller = new LlmFormLogListController($model);
        }
        $view = new LlmFormLogListView($model, $controller);
        parent::__construct($model, $view, $controller);
    }
}
?>
