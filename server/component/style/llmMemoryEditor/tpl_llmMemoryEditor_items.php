<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/*
 * The items of one block of the editor and, below them, its add button. The item rows are the same
 * in every layout (sections, accordion, tabs, cards, flat): a layout only wraps this partial.
 *
 * Included by tpl_llmMemoryEditor_body.php, so $this is the view and these are set: $e (escape),
 * $delete_confirmation, $items (the items to list) and $add_section (the code of the section the
 * add button adds to - '' for a memory without sections; null for no add button).
 */
if (empty($items)) { ?>
                <p class="lme-empty"><?php echo $e($this->empty_text); ?></p>
<?php } else { ?>
                <ul class="lme-items list-unstyled">
<?php foreach ($items as $item) { ?>
                    <li class="lme-item" data-item-id="<?php echo $e($item['id']); ?>">
                        <span class="lme-text"><?php echo $e($item['text']); ?></span>
<?php if ($this->allow_edit || $this->allow_delete) { ?>
                        <span class="lme-actions">
<?php if ($this->allow_edit) { ?>
                            <button type="button" class="lme-btn lme-edit" data-lme-action="edit"><?php echo $e($this->label_edit); ?></button>
<?php } ?>
<?php if ($this->allow_delete) { ?>
                            <button type="button" class="lme-btn lme-delete" data-lme-action="delete" data-confirmation="<?php echo $e($delete_confirmation); ?>"><?php echo $e($this->label_delete); ?></button>
<?php } ?>
                        </span>
<?php } ?>
                    </li>
<?php } ?>
                </ul>
<?php } ?>
<?php if ($add_section !== null) { ?>
                <div class="lme-add" data-section="<?php echo $e($add_section); ?>">
                    <button type="button" class="lme-btn lme-add-btn" data-lme-action="add">+ <?php echo $e($this->label_add); ?></button>
                </div>
<?php } ?>
