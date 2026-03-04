<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/BaseComponent.php";
require_once __DIR__ . "/../llmFormRecord/LlmFormModel.php";
require_once __DIR__ . "/../llmFormRecord/LlmFormView.php";
require_once __DIR__ . "/../llmFormRecord/LlmFormController.php";

/**
 * LLM Form Log component.
 * Works like formUserInputLog (append-only, new row per submission)
 * but adds LLM generation on submit with configurable context interpolation.
 */
class LlmFormLogComponent extends BaseComponent
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
        $model = new LlmFormModel($services, $id, $params, $id_page, $entry_record);
        $controller = null;
        if (!$model->is_cms_page())
            $controller = new LlmFormController($model);
        $view = new LlmFormView($model, $controller);
        parent::__construct($model, $view, $controller);
    }
}
?>
