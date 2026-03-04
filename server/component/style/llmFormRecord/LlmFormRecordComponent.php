<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/BaseComponent.php";
require_once __DIR__ . "/LlmFormModel.php";
require_once __DIR__ . "/LlmFormView.php";
require_once __DIR__ . "/LlmFormController.php";

/**
 * LLM Form Record component.
 * Works like formUserInputRecord (single record per user, continuously updated)
 * but adds LLM generation on submit with configurable context interpolation.
 */
class LlmFormRecordComponent extends BaseComponent
{
    /* Constructors ***********************************************************/

    /**
     * @param object $services
     * @param int $id
     * @param array $params
     * @param number $id_page
     * @param array $entry_record
     */
    public function __construct($services, $id, $params, $id_page, $entry_record)
    {
        $controller = null;
        $model = new LlmFormModel($services, $id, $params, $id_page, $entry_record);
        if (!$model->is_cms_page())
            $controller = new LlmFormController($model);
        $view = new LlmFormView($model, $controller);
        parent::__construct($model, $view, $controller);
    }
}
?>
