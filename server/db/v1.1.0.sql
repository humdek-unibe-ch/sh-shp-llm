-- =====================================================
-- LLM Plugin v1.1.0 - LLM Form Styles
-- =====================================================
-- Adds llmFormRecord and llmFormLog styles that extend
-- core formUserInput with LLM generation capabilities.
-- =====================================================

-- Update plugin version
UPDATE plugins SET version = 'v1.1.0' WHERE `name` = 'llm';

-- =====================================================
-- LOOKUP ENUMS
-- =====================================================

-- LLM result placement options
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES
('llmResultPlacement', 'llmResultPlacement_top', 'top', 'Show LLM result above the form'),
('llmResultPlacement', 'llmResultPlacement_bottom', 'bottom', 'Show LLM result below the form'),
('llmResultPlacement', 'llmResultPlacement_left', 'left', 'Show LLM result to the left of the form'),
('llmResultPlacement', 'llmResultPlacement_right', 'right', 'Show LLM result to the right of the form');

-- LLM result panel type options
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES
('llmResultPanel', 'llmResultPanel_default', 'default', 'Default inline panel'),
('llmResultPanel', 'llmResultPanel_card', 'card', 'Bootstrap card panel'),
('llmResultPanel', 'llmResultPanel_modal', 'modal', 'Modal dialog panel'),
('llmResultPanel', 'llmResultPanel_collapse', 'collapse', 'Collapsible panel');

-- Transaction type for LLM form actions
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES ('transactionBy', 'by_llm_form', 'By LLM Form', 'Actions performed by LLM form submission');

-- =====================================================
-- NEW FIELD TYPE FOR LLM RESULT PLACEMENT
-- =====================================================

INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'select-llm-result-placement', '10');
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'select-llm-result-panel', '11');

-- =====================================================
-- NEW FIELDS FOR LLM FORM STYLES
-- =====================================================

-- LLM generation control
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'llm_enabled', get_field_type_id('checkbox'), '0'),
(NULL, 'llm_context', get_field_type_id('markdown'), '1'),
(NULL, 'llm_show_previous_result', get_field_type_id('checkbox'), '0'),
(NULL, 'llm_result_field_name', get_field_type_id('text'), '0'),
(NULL, 'llm_result_meta_field_name', get_field_type_id('text'), '0'),
(NULL, 'llm_result_placement', get_field_type_id('select-llm-result-placement'), '0'),
(NULL, 'llm_result_panel', get_field_type_id('select-llm-result-panel'), '0'),
(NULL, 'llm_result_title', get_field_type_id('text'), '1'),
(NULL, 'llm_result_closable', get_field_type_id('checkbox'), '0'),
(NULL, 'llm_result_css', get_field_type_id('text'), '0'),
(NULL, 'llm_result_css_mobile', get_field_type_id('text'), '0'),
(NULL, 'llm_show_errors', get_field_type_id('checkbox'), '0'),
(NULL, 'llm_retry_enabled', get_field_type_id('checkbox'), '0'),
(NULL, 'llm_retry_label', get_field_type_id('text'), '1'),
(NULL, 'llm_regenerate_enabled', get_field_type_id('checkbox'), '0'),
(NULL, 'llm_regenerate_label', get_field_type_id('text'), '1'),
(NULL, 'llm_generating_text', get_field_type_id('text'), '1'),
(NULL, 'use_small_buttons', get_field_type_id('checkbox'), '0'),
(NULL, 'llm_manual_feedback_enabled', get_field_type_id('checkbox'), '0'),
(NULL, 'llm_feedback_button_label', get_field_type_id('text'), '1'),
(NULL, 'llm_feedback_button_color', get_field_type_id('style-bootstrap'), '0');

-- =====================================================
-- REGISTER llmFormRecord STYLE
-- =====================================================

INSERT IGNORE INTO `styles` (`name`, `id_type`, `id_group`, `description`)
VALUES ('llmFormRecord', (SELECT id FROM styleType WHERE `name` = 'component'), (SELECT id FROM styleGroup WHERE `name` = 'Form'), 'LLM-enhanced form in record mode. On submit, stores data and sends an LLM request based on configurable context with field interpolation. Displays the LLM result in a configurable panel.');

-- Link all base form fields to llmFormRecord (same as formUserInputRecord)
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmFormRecord'), get_field_id('children'), 0, 'Allows to nest children styles.'),
(get_style_id('llmFormRecord'), get_field_id('css'), NULL, 'Allows to assign CSS classes to the root item of the style.'),
(get_style_id('llmFormRecord'), get_field_id('css_mobile'), NULL, 'Allows to assign CSS classes to the root item of the style for the mobile version.'),
(get_style_id('llmFormRecord'), get_field_id('condition'), NULL, 'The field `condition` allows to specify a condition. Note that the field `condition` is of type `json` and requires a valid JSON string.'),
(get_style_id('llmFormRecord'), get_field_id('data_config'), '', 'The field `dataConfig` allows to configure data sources for the component.'),
(get_style_id('llmFormRecord'), get_field_id('type'), NULL, 'The type of the form element.'),
(get_style_id('llmFormRecord'), get_field_id('label'), '', 'The label to be rendered on the submit button. If no label is set, the submit button will not be rendered.'),
(get_style_id('llmFormRecord'), get_field_id('name'), '', 'The name of the form.'),
(get_style_id('llmFormRecord'), get_field_id('alert_success'), '', 'The alert message to be displayed on a successful form submission.'),
(get_style_id('llmFormRecord'), get_field_id('own_entries_only'), 0, 'If enabled, only the data of the logged-in user is loaded.'),
(get_style_id('llmFormRecord'), get_field_id('confirmation_title'), '', 'The title for the confirmation modal.'),
(get_style_id('llmFormRecord'), get_field_id('label_cancel'), 'Cancel', 'Label for cancel button in confirmation dialog.'),
(get_style_id('llmFormRecord'), get_field_id('label_continue'), 'OK', 'Label for continue/confirm button in confirmation dialog.'),
(get_style_id('llmFormRecord'), get_field_id('label_message'), '', 'Confirmation message body.'),
(get_style_id('llmFormRecord'), get_field_id('url_cancel'), '', 'URL to redirect to when cancel is clicked in the confirmation dialog.'),
(get_style_id('llmFormRecord'), get_field_id('use_small_buttons'), '1', 'Use Bootstrap small buttons (`btn-sm`) for all buttons in the form UI.');

-- Link LLM-specific fields to llmFormRecord
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmFormRecord'), get_field_id('llm_enabled'), '1', 'Enable LLM generation on form submit. When disabled, the form behaves as a normal record form.'),
(get_style_id('llmFormRecord'), get_field_id('llm_model'), '', 'LLM model to use for generation. Leave empty to use the global default from LLM module config.'),
(get_style_id('llmFormRecord'), get_field_id('llm_temperature'), '1', 'Controls randomness (0-2). Lower values produce more deterministic output.'),
(get_style_id('llmFormRecord'), get_field_id('llm_max_tokens'), '2048', 'Maximum tokens for the LLM response.'),
(get_style_id('llmFormRecord'), get_field_id('llm_context'), '', 'System prompt / instructions sent to the LLM. Supports {{field_name}} interpolation with submitted form values. The form data is sent separately as a structured user message. Example: "You are a supportive teacher-coach. Give short constructive feedback on the students reflection: {{reflection}}"'),
(get_style_id('llmFormRecord'), get_field_id('llm_show_previous_result'), '1', 'When enabled and the form reloads, the previously generated LLM result is displayed.'),
(get_style_id('llmFormRecord'), get_field_id('llm_result_field_name'), 'llm_result', 'Field name where the LLM result text is stored in the data record.'),
(get_style_id('llmFormRecord'), get_field_id('llm_result_meta_field_name'), 'llm_result_meta', 'Field name where LLM result metadata (model, timestamp, status, token usage) is stored as JSON.'),
(get_style_id('llmFormRecord'), get_field_id('llm_result_placement'), 'bottom', 'Where to display the LLM result relative to the form: top, bottom, left, right.'),
(get_style_id('llmFormRecord'), get_field_id('llm_result_panel'), 'default', 'Panel type for displaying the LLM result: default (inline), card (Bootstrap card), modal (dialog), collapse (collapsible).'),
(get_style_id('llmFormRecord'), get_field_id('llm_result_title'), 'Result', 'Title/label for the LLM result panel.'),
(get_style_id('llmFormRecord'), get_field_id('llm_result_closable'), '1', 'Allow users to dismiss/close the LLM result panel.'),
(get_style_id('llmFormRecord'), get_field_id('llm_result_css'), '', 'Additional Bootstrap 4.6 / custom CSS classes for the LLM result container.'),
(get_style_id('llmFormRecord'), get_field_id('llm_result_css_mobile'), '', 'Additional CSS classes for the LLM result container on mobile.'),
(get_style_id('llmFormRecord'), get_field_id('llm_show_errors'), '1', 'Show a clean bootstrap alert when LLM generation fails.'),
(get_style_id('llmFormRecord'), get_field_id('llm_retry_enabled'), '1', 'Allow retrying the LLM call without resubmitting the form (uses last saved data).'),
(get_style_id('llmFormRecord'), get_field_id('llm_retry_label'), 'Retry', 'Label for the retry button.'),
(get_style_id('llmFormRecord'), get_field_id('llm_regenerate_enabled'), '1', 'Allow regenerating the LLM result. Uses the same saved data and context to produce a new result, updating both record and log.'),
(get_style_id('llmFormRecord'), get_field_id('llm_regenerate_label'), 'Regenerate', 'Label for the regenerate button.'),
(get_style_id('llmFormRecord'), get_field_id('llm_generating_text'), 'Generating response...', 'Text shown while waiting for LLM response.'),
(get_style_id('llmFormRecord'), get_field_id('llm_manual_feedback_enabled'), '0', 'Enable manual feedback mode. When enabled, the form Save button only saves data (no LLM call). A separate Generate Feedback button allows the user to trigger LLM feedback on demand without saving. This overrides and hides the regenerate button. Only available for llmFormRecord.'),
(get_style_id('llmFormRecord'), get_field_id('llm_feedback_button_label'), 'Generate Feedback', 'Label for the manual Generate Feedback button.'),
(get_style_id('llmFormRecord'), get_field_id('llm_feedback_button_color'), 'primary', 'Bootstrap color class for the Generate Feedback button (primary, secondary, success, danger, warning, info, light, dark).');

-- =====================================================
-- REGISTER llmFormLog STYLE
-- =====================================================

INSERT IGNORE INTO `styles` (`name`, `id_type`, `id_group`, `description`)
VALUES ('llmFormLog', (SELECT id FROM styleType WHERE `name` = 'component'), (SELECT id FROM styleGroup WHERE `name` = 'Form'), 'LLM-enhanced form in log mode. Each submission creates a new log entry with form data and LLM response. Useful for tracking multiple interactions over time.');

-- Link all base form fields to llmFormLog (same as formUserInputLog)
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmFormLog'), get_field_id('children'), 0, 'Allows to nest children styles.'),
(get_style_id('llmFormLog'), get_field_id('css'), NULL, 'Allows to assign CSS classes to the root item of the style.'),
(get_style_id('llmFormLog'), get_field_id('css_mobile'), NULL, 'Allows to assign CSS classes to the root item of the style for the mobile version.'),
(get_style_id('llmFormLog'), get_field_id('condition'), NULL, 'The field `condition` allows to specify a condition.'),
(get_style_id('llmFormLog'), get_field_id('data_config'), '', 'The field `dataConfig` allows to configure data sources for the component.'),
(get_style_id('llmFormLog'), get_field_id('type'), NULL, 'The type of the form element.'),
(get_style_id('llmFormLog'), get_field_id('label'), '', 'The label to be rendered on the submit button.'),
(get_style_id('llmFormLog'), get_field_id('name'), '', 'The name of the form.'),
(get_style_id('llmFormLog'), get_field_id('alert_success'), '', 'The alert message to be displayed on a successful form submission.'),
(get_style_id('llmFormLog'), get_field_id('own_entries_only'), 0, 'If enabled, only the data of the logged-in user is loaded.'),
(get_style_id('llmFormLog'), get_field_id('confirmation_title'), '', 'The title for the confirmation modal.'),
(get_style_id('llmFormLog'), get_field_id('label_cancel'), 'Cancel', 'Label for cancel button in confirmation dialog.'),
(get_style_id('llmFormLog'), get_field_id('label_continue'), 'OK', 'Label for continue/confirm button in confirmation dialog.'),
(get_style_id('llmFormLog'), get_field_id('label_message'), '', 'Confirmation message body.'),
(get_style_id('llmFormLog'), get_field_id('url_cancel'), '', 'URL to redirect to when cancel is clicked in the confirmation dialog.'),
(get_style_id('llmFormLog'), get_field_id('use_small_buttons'), '1', 'Use Bootstrap small buttons (`btn-sm`) for all buttons in the form UI.');

-- Link LLM-specific fields to llmFormLog (same defaults as llmFormRecord)
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmFormLog'), get_field_id('llm_enabled'), '1', 'Enable LLM generation on form submit. When disabled, the form behaves as a normal log form.'),
(get_style_id('llmFormLog'), get_field_id('llm_model'), '', 'LLM model to use for generation. Leave empty to use the global default from LLM module config.'),
(get_style_id('llmFormLog'), get_field_id('llm_temperature'), '1', 'Controls randomness (0-2). Lower values produce more deterministic output.'),
(get_style_id('llmFormLog'), get_field_id('llm_max_tokens'), '2048', 'Maximum tokens for the LLM response.'),
(get_style_id('llmFormLog'), get_field_id('llm_context'), '', 'System prompt / instructions sent to the LLM. Supports {{field_name}} interpolation with submitted form values. The form data is sent separately as a structured user message.'),
(get_style_id('llmFormLog'), get_field_id('llm_show_previous_result'), '0', 'When enabled and the form reloads, the last generated LLM result is displayed. Default off for log mode.'),
(get_style_id('llmFormLog'), get_field_id('llm_result_field_name'), 'llm_result', 'Field name where the LLM result text is stored in the data record.'),
(get_style_id('llmFormLog'), get_field_id('llm_result_meta_field_name'), 'llm_result_meta', 'Field name where LLM result metadata is stored as JSON.'),
(get_style_id('llmFormLog'), get_field_id('llm_result_placement'), 'bottom', 'Where to display the LLM result relative to the form.'),
(get_style_id('llmFormLog'), get_field_id('llm_result_panel'), 'default', 'Panel type for displaying the LLM result: default (inline), card (Bootstrap card), modal (dialog), collapse (collapsible).'),
(get_style_id('llmFormLog'), get_field_id('llm_result_title'), 'Result', 'Title/label for the LLM result panel.'),
(get_style_id('llmFormLog'), get_field_id('llm_result_closable'), '1', 'Allow users to dismiss/close the LLM result panel.'),
(get_style_id('llmFormLog'), get_field_id('llm_result_css'), '', 'Additional CSS classes for the LLM result container.'),
(get_style_id('llmFormLog'), get_field_id('llm_result_css_mobile'), '', 'Additional CSS classes for the LLM result container on mobile.'),
(get_style_id('llmFormLog'), get_field_id('llm_show_errors'), '1', 'Show a clean bootstrap alert when LLM generation fails.'),
(get_style_id('llmFormLog'), get_field_id('llm_retry_enabled'), '1', 'Allow retrying the LLM call without resubmitting the form.'),
(get_style_id('llmFormLog'), get_field_id('llm_retry_label'), 'Retry', 'Label for the retry button.'),
(get_style_id('llmFormLog'), get_field_id('llm_regenerate_enabled'), '1', 'Allow regenerating the LLM result. On regenerate in log mode, only the LLM result column is updated for that log entry.'),
(get_style_id('llmFormLog'), get_field_id('llm_regenerate_label'), 'Regenerate', 'Label for the regenerate button.'),
(get_style_id('llmFormLog'), get_field_id('llm_generating_text'), 'Generating response...', 'Text shown while waiting for LLM response.');

-- =====================================================
-- HOOKS FOR NEW FIELD TYPES
-- =====================================================

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-llm-result-placement-edit', 'Output select LLM result placement field - edit mode', 'CmsView', 'create_field_form_item', 'LlmHooks', 'outputFieldLlmResultPlacementEdit', 5);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-llm-result-placement-view', 'Output select LLM result placement field - view mode', 'CmsView', 'create_field_item', 'LlmHooks', 'outputFieldLlmResultPlacementView', 5);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-llm-result-panel-edit', 'Output select LLM result panel type field - edit mode', 'CmsView', 'create_field_form_item', 'LlmHooks', 'outputFieldLlmResultPanelEdit', 5);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-llm-result-panel-view', 'Output select LLM result panel type field - view mode', 'CmsView', 'create_field_item', 'LlmHooks', 'outputFieldLlmResultPanelView', 5);

-- =====================================================
-- MULTI-SERVER API KEYS CONFIGURATION
-- =====================================================
-- Adds a JSON field that stores multiple server configurations:
-- [{"name":"GPUStack","base_url":"https://...","api_key":"sk-..."},...]

INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'json-llm-api-keys', '10');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'llm_api_keys', get_field_type_id('json-llm-api-keys'), '0');

INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_api_keys'), '[]', 'JSON array of LLM server configurations. Each entry has name, base_url, and api_key. Models from all configured servers are aggregated and prefixed with the server name.');

INSERT IGNORE INTO `pages_fields` (`id_pages`, `id_fields`, `default_value`, `help`) VALUES
((SELECT id FROM pages WHERE keyword = 'sh_module_llm'), get_field_id('llm_api_keys'), '[]', 'JSON array of LLM server configurations.');

-- Register hooks for the custom API keys field UI
INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-llm-api-keys-edit', 'Output custom LLM API keys manager - edit mode', 'CmsView', 'create_field_form_item', 'LlmHooks', 'outputFieldLlmApiKeysEdit', 5);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-llm-api-keys-view', 'Output custom LLM API keys manager - view mode', 'CmsView', 'create_field_item', 'LlmHooks', 'outputFieldLlmApiKeysView', 5);

-- Ensure API keys manager assets are included on CMS pages (CSP-safe external files)
INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'llm-cms-apikeys-css-includes', 'Append LLM API keys CSS include on CMS pages', 'CmsView', 'get_css_includes', 'LlmHooks', 'addCmsApiKeysCssIncludes', 5);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'llm-cms-apikeys-js-includes', 'Append LLM API keys JS include on CMS pages', 'CmsView', 'get_js_includes', 'LlmHooks', 'addCmsApiKeysJsIncludes', 5);

DELETE FROM fields WHERE `name` = 'llm_base_url';
DELETE FROM fields WHERE `name` = 'llm_api_key';

-- =====================================================
-- PROMPT REGISTRY, VERSIONING, AND PLAYGROUND
-- =====================================================

INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES
('llm_prompt_owner_types', 'style_field', 'style_field', 'Prompt owner is a style-backed CMS field'),
('llm_prompt_owner_types', 'llm_script', 'llm_script', 'Prompt owner is an llm_scripts row');

INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES
('llm_prompt_run_modes', 'playground', 'playground', 'Single-model playground run'),
('llm_prompt_run_modes', 'builder', 'builder', 'Prompt builder assistant run'),
('llm_prompt_run_modes', 'compare', 'compare', 'Multi-model playground comparison run');

INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'llm_prompt', '12');

UPDATE `fields`
SET `id_type` = get_field_type_id('llm_prompt')
WHERE `name` IN ('conversation_context', 'llm_context');

CREATE TABLE IF NOT EXISTS `llm_prompt_entries` (
    `id` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `id_llm_prompt_owner_types` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `owner_id` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `prompt_slot` VARCHAR(64) NOT NULL,
    `title` VARCHAR(255) DEFAULT NULL,
    `id_users_created` INT(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `id_users_updated` INT(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_prompt_owner_slot` (`id_llm_prompt_owner_types`, `owner_id`, `prompt_slot`),
    KEY `idx_prompt_owner_type` (`id_llm_prompt_owner_types`),
    CONSTRAINT `fk_llm_prompt_entries_owner_type` FOREIGN KEY (`id_llm_prompt_owner_types`) REFERENCES `lookups` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_llm_prompt_entries_user_created` FOREIGN KEY (`id_users_created`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_llm_prompt_entries_user_updated` FOREIGN KEY (`id_users_updated`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `llm_prompt_locales` (
    `id` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `id_llm_prompt_entries` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `id_languages` INT(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `active_version_id` INT(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `active_version_no` INT NOT NULL DEFAULT 0,
    `id_users_created` INT(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `id_users_updated` INT(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_prompt_entry_language` (`id_llm_prompt_entries`, `id_languages`),
    KEY `idx_prompt_locale_language` (`id_languages`),
    KEY `idx_prompt_locale_active_version` (`active_version_id`),
    CONSTRAINT `fk_llm_prompt_locales_entry` FOREIGN KEY (`id_llm_prompt_entries`) REFERENCES `llm_prompt_entries` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_llm_prompt_locales_language` FOREIGN KEY (`id_languages`) REFERENCES `languages` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_llm_prompt_locales_user_created` FOREIGN KEY (`id_users_created`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_llm_prompt_locales_user_updated` FOREIGN KEY (`id_users_updated`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `llm_prompt_versions` (
    `id` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `id_llm_prompt_locales` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `version_no` INT NOT NULL,
    `template_raw` LONGTEXT,
    `template_hash` VARCHAR(64) NOT NULL,
    `config_json` LONGTEXT DEFAULT NULL,
    `metadata_json` LONGTEXT DEFAULT NULL,
    `variables_schema_json` LONGTEXT DEFAULT NULL,
    `tags_json` LONGTEXT DEFAULT NULL,
    `change_note` VARCHAR(255) DEFAULT NULL,
    `based_on_version_id` INT(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `id_users_created` INT(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_prompt_locale_version` (`id_llm_prompt_locales`, `version_no`),
    KEY `idx_prompt_version_hash` (`template_hash`),
    KEY `idx_prompt_version_based_on` (`based_on_version_id`),
    CONSTRAINT `fk_llm_prompt_versions_locale` FOREIGN KEY (`id_llm_prompt_locales`) REFERENCES `llm_prompt_locales` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_llm_prompt_versions_based_on` FOREIGN KEY (`based_on_version_id`) REFERENCES `llm_prompt_versions` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_llm_prompt_versions_user_created` FOREIGN KEY (`id_users_created`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Keep this FK outside CREATE TABLE because `llm_prompt_locales` and
-- `llm_prompt_versions` reference each other, so one side must be added after
-- both tables exist.
CALL add_foreign_key('llm_prompt_locales', 'fk_llm_prompt_locales_active_version', 'active_version_id', '`llm_prompt_versions` (`id`)');

CREATE TABLE IF NOT EXISTS `llm_prompt_playground_runs` (
    `id` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `id_llm_prompt_entries` INT(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `id_llm_prompt_locales` INT(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `id_llm_prompt_versions` INT(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `id_llmConversations` INT(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `id_llmMessages_request` INT(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `id_llmMessages_response` INT(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `id_lookups_run_mode` INT(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `comparison_group_id` VARCHAR(64) DEFAULT NULL,
    `variables_json` LONGTEXT DEFAULT NULL,
    `config_snapshot_json` LONGTEXT DEFAULT NULL,
    `id_users_created` INT(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_prompt_runs_entry` (`id_llm_prompt_entries`),
    KEY `idx_prompt_runs_locale` (`id_llm_prompt_locales`),
    KEY `idx_prompt_runs_version` (`id_llm_prompt_versions`),
    KEY `idx_prompt_runs_conversation` (`id_llmConversations`),
    KEY `idx_prompt_runs_group` (`comparison_group_id`),
    CONSTRAINT `fk_prompt_runs_entry` FOREIGN KEY (`id_llm_prompt_entries`) REFERENCES `llm_prompt_entries` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_prompt_runs_locale` FOREIGN KEY (`id_llm_prompt_locales`) REFERENCES `llm_prompt_locales` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_prompt_runs_version` FOREIGN KEY (`id_llm_prompt_versions`) REFERENCES `llm_prompt_versions` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_prompt_runs_conversation` FOREIGN KEY (`id_llmConversations`) REFERENCES `llmConversations` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_prompt_runs_request_message` FOREIGN KEY (`id_llmMessages_request`) REFERENCES `llmMessages` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_prompt_runs_response_message` FOREIGN KEY (`id_llmMessages_response`) REFERENCES `llmMessages` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_prompt_runs_run_mode` FOREIGN KEY (`id_lookups_run_mode`) REFERENCES `lookups` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_prompt_runs_user_created` FOREIGN KEY (`id_users_created`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_llm_scripts_prompt_entry_column := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'llm_scripts'
      AND COLUMN_NAME = 'id_llm_prompt_entries'
);
SET @sql_llm_scripts_prompt_entry_column := IF(
    @has_llm_scripts_prompt_entry_column = 0,
    'ALTER TABLE `llm_scripts` ADD COLUMN `id_llm_prompt_entries` INT(10) UNSIGNED ZEROFILL DEFAULT NULL AFTER `id`',
    'SELECT 1'
);
PREPARE stmt_llm_scripts_prompt_entry_column FROM @sql_llm_scripts_prompt_entry_column;
EXECUTE stmt_llm_scripts_prompt_entry_column;
DEALLOCATE PREPARE stmt_llm_scripts_prompt_entry_column;

SET @has_llm_scripts_prompt_entry_index := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'llm_scripts'
      AND INDEX_NAME = 'idx_llm_scripts_prompt_entry'
);
SET @sql_llm_scripts_prompt_entry_index := IF(
    @has_llm_scripts_prompt_entry_index = 0,
    'ALTER TABLE `llm_scripts` ADD KEY `idx_llm_scripts_prompt_entry` (`id_llm_prompt_entries`)',
    'SELECT 1'
);
PREPARE stmt_llm_scripts_prompt_entry_index FROM @sql_llm_scripts_prompt_entry_index;
EXECUTE stmt_llm_scripts_prompt_entry_index;
DEALLOCATE PREPARE stmt_llm_scripts_prompt_entry_index;

CALL add_foreign_key('llm_scripts', 'fk_llm_scripts_prompt_entry', 'id_llm_prompt_entries', '`llm_prompt_entries` (`id`)');

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-llm-prompt-edit', 'Output custom LLM prompt field - edit mode', 'CmsView', 'create_field_form_item', 'LlmHooks', 'outputFieldLlmPromptEdit', 5);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-llm-prompt-view', 'Output custom LLM prompt field - view mode', 'CmsView', 'create_field_item', 'LlmHooks', 'outputFieldLlmPromptView', 5);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'llm-cms-prompt-css-includes', 'Append LLM prompt field CSS include on CMS pages', 'CmsView', 'get_css_includes', 'LlmHooks', 'addCmsPromptCssIncludes', 5);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'llm-cms-prompt-js-includes', 'Append LLM prompt field JS include on CMS pages', 'CmsView', 'get_js_includes', 'LlmHooks', 'addCmsPromptJsIncludes', 5);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'llm-sync-prompt-version-on-save', 'Sync prompt registry after CMS save', 'CmsModel', 'update_db', 'LlmHooks', 'syncPromptVersionOnCmsSave', 5);

INSERT IGNORE INTO `pages` (`id`, `keyword`, `url`, `protocol`, `id_actions`, `id_navigation_section`, `parent`, `is_headless`, `nav_position`, `footer_position`, `id_type`, `id_pageAccessTypes`)
VALUES (NULL, 'ajax_llm_prompt_lab', '/request/[AjaxLlmPromptLab:class]/[dispatch:method]', 'GET|POST', (SELECT id FROM actions WHERE `name` = 'ajax' LIMIT 1), NULL, NULL, '0', NULL, NULL, '0000000001', (SELECT id FROM lookups WHERE type_code = 'pageAccessTypes' AND lookup_code = 'mobile_and_web'));

INSERT IGNORE INTO `acl_groups` (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`)
VALUES ('0000000001', (SELECT id FROM pages WHERE keyword = 'ajax_llm_prompt_lab'), '1', '1', '1', '1');
