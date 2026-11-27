<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<div id="section-<?php echo (int)$this->id_section; ?>" class="llm-memory-editor <?php echo $this->css; ?>">
<?php foreach ($this->model->getMemoryKeys() as $memory_key) { ?>
    <?php echo $this->render_body($memory_key); ?>
<?php } ?>
</div>
