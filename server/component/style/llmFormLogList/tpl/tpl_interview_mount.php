<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<div class="llmFormLogList-interview"
     id="llmFormLogList-interview-<?php echo intval($this->id_section); ?>"
     data-config="<?php echo htmlspecialchars(json_encode($interview_config), ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($native_fragment !== '') { ?>
        <div class="llmFormLogList-interview-native"><?php echo $native_fragment; ?></div>
    <?php } ?>
</div>
