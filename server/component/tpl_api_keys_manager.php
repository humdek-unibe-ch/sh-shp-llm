<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

$uid = $fields['uid'];
$inputName = $fields['inputName'];
$jsonValue = $fields['jsonValue'];
$disabled = $fields['disabled'];
$escapedJson = htmlspecialchars($jsonValue, ENT_QUOTES, 'UTF-8');
$readonlyAttr = $disabled ? 'readonly' : '';
$decoded = json_decode($jsonValue, true);
if (!is_array($decoded)) {
    $decoded = array();
}

if (!function_exists('llm_mask_api_key')) {
    function llm_mask_api_key($key)
    {
        if (!is_string($key) || strlen($key) < 8) {
            return '********';
        }
        return substr($key, 0, 4) . '****' . substr($key, -4);
    }
}
?>
<div id="<?php echo $uid; ?>"
     class="llm-api-keys-manager mt-2"
     data-disabled="<?php echo $disabled ? '1' : '0'; ?>">
    <textarea name="<?php echo $inputName; ?>"
              class="llm-api-keys-value d-none"
              <?php echo $readonlyAttr; ?>><?php echo $escapedJson; ?></textarea>

    <div class="llm-api-keys-list mb-2">
        <?php if (count($decoded) === 0): ?>
            <div class="llm-apk-empty">
                <?php if ($disabled): ?>
                    No servers configured.
                <?php else: ?>
                    No servers configured. Click "Add Server" to add your first LLM endpoint.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($decoded as $entry): ?>
                <?php
                $entryName = isset($entry['name']) ? (string) $entry['name'] : '';
                $entryBaseUrl = isset($entry['base_url']) ? (string) $entry['base_url'] : '';
                $entryApiKey = isset($entry['api_key']) ? (string) $entry['api_key'] : '';
                ?>
                <div class="llm-apk-card">
                    <div class="llm-apk-header">
                        <span class="llm-apk-name"><?php echo htmlspecialchars($entryName !== '' ? $entryName : 'Unnamed', ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="llm-apk-url"><?php echo htmlspecialchars($entryBaseUrl, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="llm-apk-key">Key: <?php echo htmlspecialchars(llm_mask_api_key($entryApiKey), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if (!$disabled): ?>
    <button type="button" class="btn btn-sm btn-outline-primary llm-api-keys-add">
        <i class="fa fa-plus mr-1"></i> Add Server
    </button>
    <?php endif; ?>
</div>
