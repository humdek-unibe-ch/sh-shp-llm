<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

$uid = $fields['uid'];
$fieldNamePrefix = $fields['fieldNamePrefix'];
$inputName = $fields['inputName'];
$metaInputName = $fields['metaInputName'];
$jsonConfig = $fields['jsonConfig'];
$contentValue = $fields['contentValue'];
$metaValue = $fields['metaValue'];
$disabled = $fields['disabled'];
$fieldId = $fields['fieldId'];
$fieldType = $fields['fieldType'];
$fieldRelation = $fields['fieldRelation'];
$readonlyAttr = $disabled ? 'readonly' : '';
?>
<div id="<?php echo htmlspecialchars($uid, ENT_QUOTES, 'UTF-8'); ?>"
     class="llm-prompt-field mt-2"
     data-config="<?php echo htmlspecialchars($jsonConfig, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="<?php echo htmlspecialchars($fieldNamePrefix, ENT_QUOTES, 'UTF-8'); ?>[id]" value="<?php echo htmlspecialchars((string)$fieldId, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="<?php echo htmlspecialchars($fieldNamePrefix, ENT_QUOTES, 'UTF-8'); ?>[type]" value="<?php echo htmlspecialchars((string)$fieldType, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="<?php echo htmlspecialchars($metaInputName, ENT_QUOTES, 'UTF-8'); ?>" class="llm-prompt-meta-input" value="<?php echo htmlspecialchars((string)$metaValue, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="<?php echo htmlspecialchars($fieldNamePrefix, ENT_QUOTES, 'UTF-8'); ?>[relation]" value="<?php echo htmlspecialchars((string)$fieldRelation, ENT_QUOTES, 'UTF-8'); ?>">
    <textarea name="<?php echo htmlspecialchars($inputName, ENT_QUOTES, 'UTF-8'); ?>"
              class="llm-prompt-content-input d-none"
              <?php echo $readonlyAttr; ?>><?php echo htmlspecialchars((string)$contentValue, ENT_QUOTES, 'UTF-8'); ?></textarea>
    <div class="llm-prompt-field-root"></div>
</div>
