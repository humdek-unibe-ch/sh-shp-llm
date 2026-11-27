<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/*
 * The body of one memory key of an llmMemoryEditor section. Rendered by
 * LlmMemoryEditorView::render_body(), so $this is the view and $memory_key, $memory, $sections,
 * $display_mode (the layout of this key: a key without sections is always `flat`), $key_label
 * (the heading of the key, '' when the section shows only one key), $csrf_token, $message,
 * $message_type, $legacy_html, $flat_add_section (null: no add button) and $dom_prefix are set.
 * Item and section texts are user / LLM text and are escaped; the
 * only HTML that is not escaped here is $legacy_html, which the view made with markdown safe mode.
 *
 * The layout is $display_mode (sections, accordion, tabs, cards, flat): the body
 * gets the class lme-layout-<mode> and every block (one per section) is wrapped as the mode needs.
 * What is inside a block - the item rows and the add button - is the same partial in every layout
 * (tpl_llmMemoryEditor_items.php). Accordion panels and tabs are Bootstrap 4 collapse / tab markup;
 * their ids come from the dom_id of the block (section id + memory key + section code), so several
 * editors and keys on a page do not clash.
 */
$e = function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};
$delete_confirmation = json_encode(array(
    'confirmation_title' => $this->label_delete,
    'confirmation_message' => $this->confirm_delete_text,
    'confirmation_continue' => $this->label_delete,
    'label_cancel' => $this->label_cancel,
));
// accordion and tabs start on the first section that has items (on the first one if none has)
$first = 0;
foreach ($sections as $index => $section) {
    if (!empty($section['items'])) {
        $first = $index;
        break;
    }
}
$accordion_id = $dom_prefix . '-accordion';
?>
<div class="llm-memory-editor-body lme-layout-<?php echo $e($display_mode); ?>"
     data-section-id="<?php echo (int)$this->id_section; ?>"
     data-memory-key="<?php echo $e($memory_key); ?>"
     data-memory-version="<?php echo $e($memory['version']); ?>"
     data-csrf-token="<?php echo $e($csrf_token); ?>"
     data-error-text="<?php echo $e(LlmMemoryEditorModel::MSG_FAILED); ?>">
<?php if ($key_label !== '') { ?>
    <h4 class="lme-key-title"><?php echo $e($key_label); ?></h4>
<?php } ?>
    <div class="lme-message alert alert-<?php echo $e($message_type); ?>" role="alert"<?php echo $message === '' ? ' hidden' : ''; ?>><?php echo $e($message); ?></div>

<?php if ($memory['kind'] === 'legacy') { ?>
    <div class="lme-legacy">
<?php if ($legacy_html !== '') { ?>
        <div class="lme-legacy-text"><?php echo $legacy_html; ?></div>
<?php } ?>
        <p class="lme-note small"><?php echo $e($this->read_only_text); ?></p>
    </div>
<?php } elseif (empty($sections)) { ?>
    <p class="lme-empty"><?php echo $e($this->empty_text); ?></p>
<?php } elseif ($display_mode === 'flat') { ?>
    <div class="lme-flat lme-section-body">
<?php
$items = array();
foreach ($sections as $section) {
    $items = array_merge($items, $section['items']);
}
$add_section = $flat_add_section;
require __DIR__ . "/tpl_llmMemoryEditor_items.php";
?>
    </div>
<?php } elseif ($display_mode === 'tabs') { ?>
    <div class="lme-sections">
        <div class="nav nav-tabs lme-tabs" role="tablist">
<?php foreach ($sections as $index => $section) { ?>
            <button type="button" class="nav-link lme-tab<?php echo $index === $first ? ' active' : ''; ?>" id="<?php echo $e($section['dom_id']); ?>-tab" role="tab" data-toggle="tab" data-target="#<?php echo $e($section['dom_id']); ?>-panel" data-section="<?php echo $e($section['code']); ?>" aria-controls="<?php echo $e($section['dom_id']); ?>-panel" aria-selected="<?php echo $index === $first ? 'true' : 'false'; ?>">
                <span class="lme-section-title"><?php echo $e($section['label']); ?></span>
                <span class="lme-count badge badge-pill"><?php echo count($section['items']); ?></span>
            </button>
<?php } ?>
        </div>
        <div class="tab-content">
<?php foreach ($sections as $index => $section) { ?>
            <section class="tab-pane fade lme-section<?php echo $index === $first ? ' show active' : ''; ?>" id="<?php echo $e($section['dom_id']); ?>-panel" role="tabpanel" aria-labelledby="<?php echo $e($section['dom_id']); ?>-tab" data-section="<?php echo $e($section['code']); ?>">
                <div class="lme-section-body">
<?php
$items = $section['items'];
$add_section = $section['can_add'] ? $section['code'] : null;
require __DIR__ . "/tpl_llmMemoryEditor_items.php";
?>
                </div>
            </section>
<?php } ?>
        </div>
    </div>
<?php } elseif ($display_mode === 'accordion') { ?>
    <div class="lme-sections" id="<?php echo $e($accordion_id); ?>">
<?php foreach ($sections as $index => $section) { ?>
        <section class="lme-section" data-section="<?php echo $e($section['code']); ?>">
            <h5 class="lme-section-header" id="<?php echo $e($section['dom_id']); ?>-header">
                <button type="button" class="lme-section-toggle<?php echo $index === $first ? '' : ' collapsed'; ?>" data-toggle="collapse" data-target="#<?php echo $e($section['dom_id']); ?>-panel" aria-expanded="<?php echo $index === $first ? 'true' : 'false'; ?>" aria-controls="<?php echo $e($section['dom_id']); ?>-panel">
                    <span class="lme-section-title"><?php echo $e($section['label']); ?></span>
                    <span class="lme-count badge badge-pill"><?php echo count($section['items']); ?></span>
                </button>
            </h5>
            <div class="lme-section-panel collapse<?php echo $index === $first ? ' show' : ''; ?>" id="<?php echo $e($section['dom_id']); ?>-panel" role="region" aria-labelledby="<?php echo $e($section['dom_id']); ?>-header" data-parent="#<?php echo $e($accordion_id); ?>">
                <div class="lme-section-body">
<?php
$items = $section['items'];
$add_section = $section['can_add'] ? $section['code'] : null;
require __DIR__ . "/tpl_llmMemoryEditor_items.php";
?>
                </div>
            </div>
        </section>
<?php } ?>
    </div>
<?php } elseif ($display_mode === 'cards') { ?>
    <div class="lme-sections">
<?php foreach ($sections as $section) { ?>
        <section class="lme-section card" data-section="<?php echo $e($section['code']); ?>">
            <div class="lme-section-header">
                <h5 class="lme-section-title"><?php echo $e($section['label']); ?></h5>
                <span class="lme-count badge badge-pill"><?php echo count($section['items']); ?></span>
            </div>
            <div class="lme-section-body">
<?php
$items = $section['items'];
$add_section = $section['can_add'] ? $section['code'] : null;
require __DIR__ . "/tpl_llmMemoryEditor_items.php";
?>
            </div>
        </section>
<?php } ?>
    </div>
<?php } else { ?>
    <div class="lme-sections">
<?php foreach ($sections as $section) { ?>
        <section class="lme-section" data-section="<?php echo $e($section['code']); ?>">
            <div class="lme-section-header">
                <h5 class="lme-section-title"><?php echo $e($section['label']); ?></h5>
            </div>
            <div class="lme-section-body">
<?php
$items = $section['items'];
$add_section = $section['can_add'] ? $section['code'] : null;
require __DIR__ . "/tpl_llmMemoryEditor_items.php";
?>
            </div>
        </section>
<?php } ?>
    </div>
<?php } ?>

<?php if ($memory['kind'] !== 'legacy' && ($this->allow_edit || $this->allow_add)) { ?>
    <div class="lme-form-template" hidden>
        <span class="lme-edit-form">
            <input type="text" class="form-control form-control-sm lme-input" maxlength="<?php echo (int)LlmMemoryItems::MAX_TEXT_LENGTH; ?>" autocomplete="off">
            <button type="button" class="lme-btn lme-save" data-lme-action="save"><?php echo $e($this->label_save); ?></button>
            <button type="button" class="lme-btn lme-cancel" data-lme-action="cancel"><?php echo $e($this->label_cancel); ?></button>
        </span>
    </div>
<?php } ?>
</div>
