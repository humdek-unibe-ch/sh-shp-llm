<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/style/StyleView.php";

/**
 * View for the LLM Response component.
 *
 * Display mode: Renders interpolated text as markdown (read-only).
 * Edit mode: Renders as a textarea with the interpolated content as default value.
 *            The textarea participates in form submission via hidden id field.
 *
 * Interpolation is handled by core SelfHelp's field loading mechanism
 * (replace_calced_values on all fields). The text_md field supports
 * {{field_name}} placeholders that are resolved from data_config sources.
 */
class LlmResponseView extends StyleView
{
    /** @var string The markdown/template text */
    private $text_md;

    /** @var string Data config for loading external data */
    private $data_config;

    /** @var bool Whether editing is enabled */
    private $enable_editing;

    /** @var string Field name for form submission */
    private $field_name;

    public function __construct($model)
    {
        parent::__construct($model);
        $this->text_md = $this->model->get_db_field('text_md');
        $this->data_config = $this->model->get_db_field('data_config');
        $this->enable_editing = $this->model->get_db_field('enable_editing');
        $this->field_name = $this->model->get_db_field('name');
    }

    public function output_content()
    {
        if ($this->enable_editing) {
            require __DIR__ . "/tpl_llmResponse_edit.php";
        } else {
            if (is_a($this->model, "BaseStyleModel")) {
                $pd = new ParsedownExtension();
                $md = $pd->text($this->text_md);
            } else {
                $md = $this->text_md;
            }
            require __DIR__ . "/tpl_llmResponse.php";
        }
    }

    public function output_content_mobile()
    {
        return array(
            "type" => "llmResponse",
            "content" => $this->text_md,
            "editable" => (bool)$this->enable_editing,
            "name" => $this->field_name
        );
    }
}
?>
