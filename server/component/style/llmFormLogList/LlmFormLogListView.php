<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . "/../../../../../../component/style/formUserInputLogList/FormUserInputLogListView.php";
require_once __DIR__ . "/../../../service/LlmFormSchemaService.php";

/**
 * View for llmFormLogList: inherits the core list/actions/CMS rendering and
 * replaces the form mount with the interview mount. The mount is rendered
 * above the list: its launcher card is the single entry point (start or
 * continue), so the core New buttons are not rendered. The core form
 * section and form modal markup are not rendered either: the React
 * workspace is the form. It opens from the URL like the core list form
 * (?record=new|<id>&sid=<section>) - in place of the list, or, with
 * show_form_in_modal, as a real modal window over the visible list.
 */
class LlmFormLogListView extends FormUserInputLogListView
{
    /**
     * Adds the `llmFormLogList` root class: the scope of the plugin's default
     * list look (table/cards), which must not restyle the core lists.
     */
    public function __construct($model, $controller)
    {
        parent::__construct($model, $controller);
        $this->css = trim($this->css . ' llmFormLogList');
    }

    /**
     * Interview mount above the list for every valid non-CMS render (list
     * page, deep-linked record alike - the interview frontend decides
     * whether it shows the launcher card or the open workspace). Rendered
     * even when the list itself is hidden (`show_list` off).
     */
    protected function output_list_section()
    {
        if ($this->name !== "") {
            $interview_config = $this->get_interview_frontend_config();
            $native_fragment = $this->renderNativeControlsFragment();
            require __DIR__ . "/tpl/tpl_interview_mount.php";
        }
        parent::output_list_section();
    }

    /**
     * The interview mount is rendered by output_list_section().
     */
    protected function output_form_section($for_modal = false)
    {
    }

    /**
     * The interview frontend opens its own modal window when asked to; the
     * core form modal markup is never rendered.
     */
    protected function output_form_modal()
    {
    }

    /**
     * The launcher card inside the interview mount replaces the core New
     * buttons (toolbar and empty-state CTA).
     */
    protected function output_new_button()
    {
    }

    /**
     * CSS includes: the built interview bundle, cache-busted by mtime.
     */
    public function get_css_includes($local = array())
    {
        if (empty($local)) {
            $css_file = __DIR__ . "/../../../../css/ext/llm-form-interview.css";
            if (file_exists($css_file)) {
                $version = filemtime($css_file) ?: time();
                $local = array($css_file . "?v=" . $version);
            }
        }
        return parent::get_css_includes($local);
    }

    /**
     * JS includes: the built interview bundle, cache-busted by mtime.
     */
    public function get_js_includes($local = array())
    {
        if (empty($local)) {
            $js_file = __DIR__ . "/../../../../js/ext/llm-form-interview.umd.js";
            if (file_exists($js_file)) {
                $version = filemtime($js_file) ?: time();
                $local = array($js_file . "?v=" . $version);
            }
        }
        return parent::get_js_includes($local);
    }

    /**
     * Frontend config: core config with the modal flag forced off plus the
     * interview block (CSRF token, record scope, labels, layout).
     */
    protected function get_frontend_config()
    {
        $config = parent::get_frontend_config();
        $config['showFormInModal'] = false;
        $config['interview'] = $this->get_interview_frontend_config();
        return $config;
    }

    /**
     * Mobile JSON keeps the inherited LogList structure and adds the
     * interview config for clients that want to drive the same panel.
     */
    public function output_content_mobile()
    {
        $style = parent::output_content_mobile();
        $style['interview'] = $this->get_interview_frontend_config();
        return $style;
    }

    protected function get_interview_frontend_config()
    {
        $record_id = $this->model->get_url_record_id();
        return array(
            'sectionId' => $this->id_section,
            'recordId' => $record_id > 0 ? $record_id : null,
            // The workspace opens from the URL (?record=new|<id>&sid=<section>)
            // like the core list form, or at once when the list is hidden.
            'autoOpen' => $this->model->get_url_record_raw() !== null
                || !$this->model->get_db_field('show_list', 1),
            // Real modal window (header + close button) over the visible list;
            // off = the workspace replaces the list while it is open.
            'modal' => $this->model->isInterviewModal(),
            'showList' => (bool)(int)$this->model->get_db_field('show_list', 1),
            'formName' => $this->model->cmsText('name'),
            'urls' => array(
                'new' => $this->model->get_record_url('new'),
                'list' => $this->model->get_list_url(),
            ),
            // The list is rendered into the page only while no record is open.
            'listRendered' => $this->model->should_show_list(),
            'retention' => $this->model->getInterviewChatRetention(),
            // `mode` (llm_interview_confirm) is the only switch of the
            // confirmation dialog; the core confirmation fields only change
            // its texts (empty = built-in, translated defaults).
            'confirm' => array(
                'mode' => $this->model->getInterviewConfirm(),
                'title' => $this->model->cmsText('confirmation_title', ''),
                'message' => $this->plainText($this->model->get_db_field('confirmation_message', '')),
                'continue' => $this->model->cmsText('confirmation_continue', ''),
                'cancel' => $this->model->cmsText('label_cancel', ''),
            ),
            'csrfToken' => $this->getCsrfToken(),
            'chatPosition' => $this->model->getInterviewChatPosition(),
            'progressLayout' => $this->model->getInterviewProgressLayout(),
            'allowManualEdit' => $this->model->isInterviewAllowManualEdit(),
            'labels' => array(
                'title' => $this->model->getInterviewTitle(),
                'subtitle' => $this->model->getInterviewSubtitle(),
                'inputPlaceholder' => $this->model->getInterviewInputPlaceholder(),
                'send' => $this->model->getInterviewSendLabel(),
                'approve' => $this->model->getInterviewApproveLabel(),
                // List button labels: also the header of the modal window.
                'start' => $this->model->cmsText('label_new', 'New'),
                'edit' => $this->model->cmsText('label_edit', 'Edit'),
                'ui' => $this->model->getInterviewLabels(),
            ),
            'appearance' => $this->model->getInterviewChatAppearance(),
            'speech' => array('enabled' => $this->model->isInterviewSpeechEnabled()),
        );
    }

    /**
     * Markdown-typed CMS fields arrive as HTML; the React modal renders
     * text only, so paragraphs/line breaks become newlines and tags go.
     *
     * @param mixed $html Field value
     * @return string
     */
    private function plainText($html)
    {
        $text = preg_replace('~<(?:br\s*/?|/p)>~i', "\n", (string)$html);
        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Session CSRF token for interview mutations; generated once per session.
     *
     * @return string
     */
    private function getCsrfToken()
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Render one field's native control fragment by name - used by the
     * interview service for per-field native_html projections. Returns
     * an empty string when the field is not a renderable native control
     * in the current child tree. Output is wrapped in a stable
     * data-field-name container for later DOM updates.
     *
     * Called once per native field in a response: the schema comes from
     * the schema service's memo and only the rendered field's view is
     * prepared, so the per-field cost does not grow with the form.
     *
     * @param string $name Field name
     * @return string HTML fragment
     */
    public function renderNativeFieldFragment($name)
    {
        if (!is_string($name) || $name === '') {
            return '';
        }
        $schema_service = new LlmFormSchemaService();
        $schema = $schema_service->build($this->model);
        $def = isset($schema[$name]) && is_array($schema[$name]) ? $schema[$name] : null;
        if ($def === null || !empty($def['hidden'])
            || (empty($def['native_only']) && empty($def['manual_writable']))
        ) {
            return '';
        }
        $child = $this->findFieldChild($this->model->get_children(), $name);
        if ($child === null) {
            return '';
        }
        $entry = $this->model->get_entry_record();
        $this->propagate_input_field_settings(array($child), false);
        if (is_array($entry)) {
            $this->propagateInterviewValues(array($child), $entry, $schema);
        }
        ob_start();
        $child->output_content();
        $html = ob_get_clean();
        return '<div class="llm-native-field" data-field-name="'
            . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">' . $html . '</div>';
    }

    /**
     * Server-rendered native controls for the currently renderable
     * fields: native_only controls always, supported controls only when
     * manual editing is enabled (manual_writable). Interview-only fields
     * are collected through the chat instead. Hidden/ineligible fields
     * are never rendered. Draft/entry values for raw-echo styles (input,
     * textarea) are context-escaped before they reach the original views
     * because the core templates emit the value unescaped.
     *
     * @return string HTML fragment
     */
    protected function renderNativeControlsFragment()
    {
        $schema_service = new LlmFormSchemaService();
        $schema = $schema_service->build($this->model);
        $children = $this->model->get_children();
        if (empty($children)) {
            return '';
        }
        $entry = $this->model->get_entry_record();
        $this->propagate_input_field_settings($children, false);
        if (is_array($entry)) {
            $this->propagateInterviewValues($children, $entry, $schema);
        }
        $html = '';
        foreach ($children as $child) {
            $html .= $this->renderFieldChild($child, $schema);
        }
        return $html;
    }

    /**
     * Find the child component whose FormFieldComponent renders $name.
     *
     * @param array $children
     * @param string $name
     * @return object|null
     */
    private function findFieldChild($children, $name)
    {
        foreach ($children as $child) {
            if (!is_object($child) || !method_exists($child, 'get_style_instance')) {
                continue;
            }
            $style = $child->get_style_instance();
            if (is_a($style, 'FormFieldComponent')) {
                if ($style->get_view()->get_name_base() === $name) {
                    return $child;
                }
                continue;
            }
            $found = $this->findFieldChild($child->get_children(), $name);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    /**
     * Render one child subtree when its field is a renderable native
     * control (native_only or manual_writable, non-hidden); walk
     * containers for nested fields. Each control is wrapped in a stable
     * data-field-name container.
     */
    private function renderFieldChild($child, array $schema)
    {
        $html = '';
        if (!is_object($child) || !method_exists($child, 'get_style_instance')) {
            return $html;
        }
        $style = $child->get_style_instance();
        if (is_a($style, 'FormFieldComponent')) {
            $name = $style->get_view()->get_name_base();
            $def = isset($schema[$name]) && is_array($schema[$name]) ? $schema[$name] : null;
            if ($def !== null && empty($def['hidden'])
                && (!empty($def['native_only']) || !empty($def['manual_writable']))
            ) {
                ob_start();
                $child->output_content();
                $html .= '<div class="llm-native-field" data-field-name="'
                    . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">'
                    . ob_get_clean() . '</div>';
            }
            return $html;
        }
        foreach ($child->get_children() as $nested) {
            $html .= $this->renderFieldChild($nested, $schema);
        }
        return $html;
    }

    /**
     * propagate_input_fields with context escaping for raw-echo styles:
     * the core input/textarea templates print the value attribute/body
     * without escaping, so participant/LLM values must be escaped here.
     * Native selection values keep their raw option keys (SelectView
     * escapes at render).
     */
    private function propagateInterviewValues($children, array $entry, array $schema)
    {
        foreach ($children as $child) {
            if (!is_object($child) || !method_exists($child, 'get_style_instance')) {
                continue;
            }
            $style = $child->get_style_instance();
            if (is_a($style, 'FormFieldComponent')) {
                $view = $style->get_view();
                $name = $view->get_name_base();
                if (isset($entry[$name]) || array_key_exists($name, $entry)) {
                    $view->set_value($this->escapeFieldValue($style, $entry[$name]));
                }
            }
            $this->propagateInterviewValues($child->get_children(), $entry, $schema);
        }
    }

    /**
     * @param FormFieldComponent $style
     * @param mixed $value
     * @return mixed Escaped string for raw-echo styles, raw value otherwise
     */
    private function escapeFieldValue($style, $value)
    {
        if ($value === null || !is_scalar($value)) {
            return $value;
        }
        $field_model = $style->get_model();
        $style_name = is_object($field_model) && method_exists($field_model, 'get_style_name')
            ? (string)$field_model->get_style_name()
            : '';
        if ($style_name === 'input' || $style_name === 'textarea') {
            return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        }
        return $value;
    }
}
?>
