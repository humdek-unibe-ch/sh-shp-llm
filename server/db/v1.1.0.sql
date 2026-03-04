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
(NULL, 'llm_generating_text', get_field_type_id('text'), '1');

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
(get_style_id('llmFormRecord'), get_field_id('url_cancel'), '', 'URL to redirect to when cancel is clicked in the confirmation dialog.');

-- Link LLM-specific fields to llmFormRecord
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmFormRecord'), get_field_id('llm_enabled'), '1', 'Enable LLM generation on form submit. When disabled, the form behaves as a normal record form.'),
(get_style_id('llmFormRecord'), get_field_id('llm_model'), '', 'LLM model to use for generation. Leave empty to use the global default from LLM module config.'),
(get_style_id('llmFormRecord'), get_field_id('llm_temperature'), '1', 'Controls randomness (0-2). Lower values produce more deterministic output.'),
(get_style_id('llmFormRecord'), get_field_id('llm_max_tokens'), '2048', 'Maximum tokens for the LLM response.'),
(get_style_id('llmFormRecord'), get_field_id('llm_context'), '', 'System prompt / instructions sent to the LLM. Supports {{field_name}} interpolation with submitted form values. The form data is sent separately as a structured user message. Example: "You are a supportive teacher-coach. Give short constructive feedback on the student''s reflection: {{reflection}}"'),
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
(get_style_id('llmFormRecord'), get_field_id('llm_generating_text'), 'Generating response...', 'Text shown while waiting for LLM response.');

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
(get_style_id('llmFormLog'), get_field_id('url_cancel'), '', 'URL to redirect to when cancel is clicked in the confirmation dialog.');

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
