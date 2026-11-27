<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . "/../../../../../../component/style/StyleView.php";
require_once __DIR__ . "/../../../service/LlmCsrfService.php";
require_once __DIR__ . "/LlmMemoryEditorModel.php";

/**
 * The view of the llmMemoryEditor style: the participant's memory as a list of
 * items, one block per section, with inline edit, delete (jquery-confirm) and "add".
 *
 * Server-rendered markup (tpl_llmMemoryEditor*.php) and a small script
 * (js/ext/llm-memory-editor.js) that posts the changes. Every memory key of the
 * section has its own body, one below the other; a body carries what the script
 * needs (section id, memory key, memory version, CSRF token) in data attributes, so
 * it works after a section refresh (refresh_sections) too, and an action re-renders
 * only the body of its key.
 *
 * The CMS field display_mode picks the layout of the sections (sections, accordion,
 * tabs, cards, flat). A key without sections is always one flat list. All layouts
 * render the same item rows; the look is CSS only.
 */
class LlmMemoryEditorView extends StyleView
{
    /* Constants **************************************************************/

    /** The layouts of the display_mode field; the first is the default and the fallback for any other value. */
    const DISPLAY_MODES = array('sections', 'accordion', 'tabs', 'cards', 'flat');

    /* Private Properties *****************************************************/

    /** @var string DB field display_mode, one of DISPLAY_MODES */
    private $display_mode;

    /** @var bool DB fields allow_add, allow_edit, allow_delete (default on) */
    private $allow_add;
    private $allow_edit;
    private $allow_delete;

    /** @var string DB fields label_add / label_edit / label_save / label_cancel / label_delete */
    private $label_add;
    private $label_edit;
    private $label_save;
    private $label_cancel;
    private $label_delete;

    /** @var string DB field confirm_delete_text */
    private $confirm_delete_text;

    /** @var string DB field empty_text: shown for a section without items */
    private $empty_text;

    /** @var string DB field read_only_text: the note under a free-text memory that is not converted to items yet */
    private $read_only_text;

    /* Constructors ***********************************************************/

    /**
     * @param object $model The model of the llmMemoryEditor component
     * @param object|null $controller The controller of the llmMemoryEditor component
     */
    public function __construct($model, $controller = null)
    {
        parent::__construct($model, $controller);
        $display_mode = trim((string)$model->get_db_field('display_mode', self::DISPLAY_MODES[0]));
        $this->display_mode = in_array($display_mode, self::DISPLAY_MODES, true) ? $display_mode : self::DISPLAY_MODES[0];
        $this->allow_add = $model->isActionAllowed('add_item');
        $this->allow_edit = $model->isActionAllowed('edit_item');
        $this->allow_delete = $model->isActionAllowed('delete_item');
        $this->label_add = $this->textField('label_add', 'Add');
        $this->label_edit = $this->textField('label_edit', 'Edit');
        $this->label_save = $this->textField('label_save', 'Save');
        $this->label_cancel = $this->textField('label_cancel', 'Cancel');
        $this->label_delete = $this->textField('label_delete', 'Delete');
        $this->confirm_delete_text = $this->textField('confirm_delete_text', 'Delete this item from your memory?');
        $this->empty_text = $this->textField('empty_text', 'Nothing saved yet.');
        $this->read_only_text = $this->textField('read_only_text', LlmMemoryEditorModel::MSG_READ_ONLY . ' It becomes an editable list the next time it is updated.');
    }

    /* Public Methods *********************************************************/

    /**
     * Render the style view. Guests see nothing: they share one account, so there is
     * no memory that belongs to them.
     */
    public function output_content()
    {
        if ($this->model->getUserId() <= 0) {
            return;
        }
        require __DIR__ . "/tpl_llmMemoryEditor.php";
    }

    /**
     * The body of one memory key: state attributes, the key heading (when the section shows
     * several keys), an optional message and the memory. The answer of an editor action carries
     * it, and the page renders one per key inside the section.
     *
     * @param string $memory_key One of the model's getMemoryKeys()
     * @param string $message A message to show on top ('' for none)
     * @param string $message_type Bootstrap alert type of the message: danger, warning, info or success
     * @return string HTML
     */
    public function render_body($memory_key, $message = '', $message_type = 'danger')
    {
        $memory = $this->model->loadMemory($memory_key);
        $key_sections = $this->model->getSections($memory_key);
        // a key without sections is one list whatever the layout
        $display_mode = empty($key_sections) ? 'flat' : $this->display_mode;
        $sections = $this->displaySections($memory, $memory_key);
        $key_label = count($this->model->getMemoryKeys()) > 1 ? $this->model->getKeyLabel($memory_key) : '';
        $csrf_token = LlmCsrfService::getToken();
        // the flat list adds to the key's only section ('' for a key without sections); with several
        // sections there is none to add to (null = no add button)
        $flat_add_section = null;
        if ($display_mode === 'flat' && $this->allow_add && count($key_sections) <= 1) {
            $flat_add_section = empty($key_sections) ? '' : $key_sections[0]['code'];
        }
        $dom_prefix = 'lme-' . (int)$this->id_section . '-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $memory_key);
        $message_type = in_array($message_type, array('danger', 'warning', 'info', 'success'), true) ? $message_type : 'danger';
        $legacy_html = '';
        if ($memory['kind'] === 'legacy' && $memory['legacy_text'] !== '') {
            // LLM-written text, not trusted CMS content: safe mode escapes raw HTML
            $parsedown = new ParsedownExtension();
            $parsedown->setSafeMode(true);
            $legacy_html = $parsedown->text($memory['legacy_text']);
        }

        ob_start();
        require __DIR__ . "/tpl_llmMemoryEditor_body.php";
        return ob_get_clean();
    }

    /**
     * Mobile output: the memory as read-only data (the editor itself is a web feature).
     *
     * @return array The fields of the style plus display_mode (the layout, one of DISPLAY_MODES),
     *   editable (false) and memories: per memory key its memory_key, label (translated),
     *   legacy_text (a free-text memory) and sections (code, label, items with id and text; items
     *   without a section are a first section with code '' and no label)
     */
    public function output_content_mobile()
    {
        $style = parent::output_content_mobile();
        $style['display_mode'] = $this->display_mode;
        $style['editable'] = false;
        $style['memories'] = array();
        foreach ($this->model->getMemoryKeys() as $memory_key) {
            $memory = $this->model->loadMemory($memory_key);
            $sections = array();
            foreach ($this->displaySections($memory, $memory_key) as $section) {
                $items = array();
                foreach ($section['items'] as $item) {
                    $items[] = array('id' => $item['id'], 'text' => $item['text']);
                }
                $sections[] = array('code' => $section['code'], 'label' => $section['label'], 'items' => $items);
            }
            $style['memories'][] = array(
                'memory_key' => $memory_key,
                'label' => $this->model->getKeyLabel($memory_key),
                'legacy_text' => $memory['kind'] === 'legacy' ? $memory['legacy_text'] : '',
                'sections' => $sections,
            );
        }
        return $style;
    }

    /**
     * Include the style's CSS. The version is the file's modification time: a changed file is
     * fetched again.
     */
    public function get_css_includes($local = array())
    {
        if (empty($local)) {
            $css_file = __DIR__ . "/../../../../css/ext/llm-memory-editor.css";
            $local = array($css_file . "?v=" . (filemtime($css_file) ?: time()));
        }
        return parent::get_css_includes($local);
    }

    /** Include the style's script, versioned like the CSS. */
    public function get_js_includes($local = array())
    {
        if (empty($local)) {
            $js_file = __DIR__ . "/../../../../js/ext/llm-memory-editor.js";
            $local = array($js_file . "?v=" . (filemtime($js_file) ?: time()));
        }
        return parent::get_js_includes($local);
    }

    /* Private Methods ********************************************************/

    /**
     * A text field of the style: its content, the default from styles_fields, else $default.
     */
    private function textField($field, $default)
    {
        $value = trim((string)$this->model->get_db_field($field, $default));
        return $value !== '' ? $value : $default;
    }

    /**
     * The blocks to show: items without a section first (a key without sections: always this one
     * block, the whole memory); then every section of the key that has items (or that can be added
     * to), in the order of the key, with its translated label; then the items of a section the key
     * no longer defines, headed by their section code (they can still be edited and deleted, not
     * added to).
     *
     * @param array $memory LlmMemoryEditorModel::loadMemory()
     * @param string $memory_key The key of the memory
     * @return array List of ['code', 'label', 'items', 'can_add', 'dom_id'] (dom_id: the id the
     *   collapse / tab markup of the block is built on)
     */
    private function displaySections(array $memory, $memory_key)
    {
        $by_section = array();
        foreach ($memory['items'] as $item) {
            $by_section[$item['section']][] = $item;
        }

        $blocks = array();
        $key_sections = $this->model->getDisplaySections($memory_key);
        if (empty($key_sections) || !empty($by_section[''])) {
            // these items belong to the memory itself, not to a section (added before the key got
            // sections); where a layout needs a heading for them, it is the label of the key
            $blocks[] = array(
                'code' => '',
                'label' => empty($key_sections) ? '' : $this->model->getKeyLabel($memory_key),
                'items' => $by_section[''] ?? array(),
                'can_add' => empty($key_sections) && $this->allow_add,
            );
            unset($by_section['']);
        }
        foreach ($key_sections as $section) {
            $items = $by_section[$section['code']] ?? array();
            unset($by_section[$section['code']]);
            if (!empty($items) || $this->allow_add) {
                $blocks[] = array(
                    'code' => $section['code'],
                    'label' => $section['label'] !== '' ? $section['label'] : $section['code'],
                    'items' => $items,
                    'can_add' => $this->allow_add,
                );
            }
        }
        foreach ($by_section as $code => $items) {
            $blocks[] = array('code' => (string)$code, 'label' => (string)$code, 'items' => $items, 'can_add' => false);
        }

        // ids of the collapse and tab markup: unique per editor section, memory key and section code
        // (keys and the code of a section the key no longer defines can be anything: cleaned for an id)
        $used = array();
        foreach ($blocks as $index => $block) {
            $dom_id = 'lme-' . (int)$this->id_section . '-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $memory_key . '--' . $block['code']);
            while (isset($used[$dom_id])) {
                $dom_id .= '-' . $index;
            }
            $used[$dom_id] = true;
            $blocks[$index]['dom_id'] = $dom_id;
        }
        return $blocks;
    }
}
