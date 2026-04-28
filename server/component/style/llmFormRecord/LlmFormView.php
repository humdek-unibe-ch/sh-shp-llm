<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/style/formUserInput/FormUserInputView.php";

/**
 * View for LLM form styles (llmFormRecord and llmFormLog).
 * Extends the core FormUserInputView with an LLM result panel
 * rendered via a React component.
 */
class LlmFormView extends FormUserInputView
{
    /* Constructors ***********************************************************/

    public function __construct($model, $controller)
    {
        parent::__construct($model, $controller);
    }

    /* Public Methods *********************************************************/

    /**
     * Render the LLM form component.
     * Outputs the standard form plus an LLM React container div.
     *
     * In CMS editing mode we bypass the parent FormUserInputView render path
     * because it returns early when the form `name` field is empty, which
     * suppresses the `.section-children-ui-cms` wrapper that the CMS UI relies
     * on to attach the "Add new section" placeholder. Calling
     * `output_children()` directly mirrors how the `div` style behaves in CMS
     * edit mode and ensures `llmFormRecord` / `llmFormLog` always expose their
     * children area, regardless of whether a form name has been configured.
     */
    public function output_content()
    {
        $is_cms_editing =
            method_exists($this->model, 'is_cms_page') && $this->model->is_cms_page() &&
            method_exists($this->model, 'is_cms_page_editing') && $this->model->is_cms_page_editing();

        if ($is_cms_editing) {
            $this->output_children();
            return;
        }
        include __DIR__ . '/tpl/llm_form.php';
    }

    /**
     * Mobile output: extends the core FormUserInputView mobile payload with
     * dynamic LLM data (previous result, previous meta, section id, user
     * language). All static config fields (`llm_enabled`, `llm_model`,
     * `llm_result_placement`, etc.) are already included by the parent via
     * `get_db_fields()`.
     */
    public function output_content_mobile()
    {
        $style = parent::output_content_mobile();

        $previous_result = $this->model->getPreviousLlmResult();
        $previous_meta = $this->model->getPreviousLlmMeta();

        $style['llm_previous_result'] = array('content' => $previous_result);
        $style['llm_previous_meta'] = array('content' => $previous_meta);
        $style['current_record_id'] = array('content' => $this->model->getCurrentRecordId());
        $style['section_id'] = $this->model->get_section_id();
        $style['user_language'] = $this->model->getUserLanguage();
        return $style;
    }

    /**
     * Get CSS includes (loads the LLM form CSS bundle).
     */
    public function get_css_includes($local = array())
    {
        if (empty($local)) {
            $css_file = __DIR__ . "/../../../../css/ext/llm-form.css";
            if (file_exists($css_file)) {
                $version = filemtime($css_file) ?: time();
                $local = array($css_file . "?v=" . $version);
            }
        }
        return parent::get_css_includes($local);
    }

    /**
     * Get JS includes (loads the LLM form React bundle).
     */
    public function get_js_includes($local = array())
    {
        if (empty($local)) {
            $js_file = __DIR__ . "/../../../../js/ext/llm-form.umd.js";
            if (file_exists($js_file)) {
                $version = filemtime($js_file) ?: time();
                $local = array($js_file . "?v=" . $version);
            }
        }
        return parent::get_js_includes($local);
    }

    /**
     * Get the LLM configuration as JSON for React.
     *
     * @return string JSON-encoded config
     */
    public function getLlmReactConfig()
    {
        return htmlspecialchars(json_encode($this->model->getLlmConfig()), ENT_QUOTES, 'UTF-8');
    }
}
?>
