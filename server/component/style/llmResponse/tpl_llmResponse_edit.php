<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<div class="llm-response llm-response--editing <?php echo $this->css; ?>">
    <?php if ($this->field_name): ?>
    <input type="hidden" name="<?php echo htmlspecialchars($this->field_name); ?>[id]" value="<?php echo $this->id_section; ?>">
    <div class="form-group">
        <textarea
            class="form-control selfhelpInput llm-response-textarea"
            name="<?php echo htmlspecialchars($this->field_name); ?>[value]"
            rows="6"
        ><?php echo htmlspecialchars($this->text_md); ?></textarea>
    </div>
    <?php else: ?>
    <textarea class="form-control llm-response-textarea" rows="6" readonly><?php echo htmlspecialchars($this->text_md); ?></textarea>
    <?php endif; ?>
</div>
