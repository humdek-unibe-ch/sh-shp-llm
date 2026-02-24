<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/BaseComponent.php";
require_once __DIR__ . "/../../../../../../component/style/StyleModel.php";
require_once __DIR__ . "/LlmResponseView.php";

/**
 * LLM Response component.
 * Displays LLM response data with markdown rendering and optional editing.
 * Extends the markdown pattern with {{}} interpolation from loaded data.
 *
 * When enable_editing is off: renders as read-only markdown (like markdown style).
 * When enable_editing is on: renders as an editable textarea that participates
 * in form submission (like a FormField).
 */
class LlmResponseComponent extends BaseComponent
{
    public function __construct($services, $id, $params, $id_page, $entry_record)
    {
        $model = new StyleModel($services, $id, $params, $id_page, $entry_record);
        $view = new LlmResponseView($model);
        parent::__construct($model, $view, $id_page, $entry_record);
    }
}
?>
