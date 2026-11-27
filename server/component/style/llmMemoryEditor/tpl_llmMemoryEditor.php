<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<div id="section-<?php echo (int)$this->id_section; ?>" class="llm-memory-editor <?php echo $this->css; ?>">
<?php if ($this->show_search) { ?>
    <div class="lme-search" role="search">
        <input type="search" class="form-control lme-search-input" placeholder="<?php echo htmlspecialchars($this->search_placeholder, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($this->search_placeholder, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
    </div>
    <p class="lme-search-empty" role="status" hidden><?php echo htmlspecialchars($this->search_empty_text, ENT_QUOTES, 'UTF-8'); ?></p>
<?php } ?>
<?php foreach ($this->model->getMemoryKeys() as $memory_key) { ?>
    <?php echo $this->render_body($memory_key); ?>
<?php } ?>
</div>
