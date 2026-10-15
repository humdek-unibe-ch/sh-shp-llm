-- =====================================================
-- LLM Plugin v1.5.0 - Fix missing default model field
-- =====================================================
--
-- Root cause (v1.0.0): `llm_default_model` was inserted into `fields`
-- BEFORE `fieldType` `select-llm-model` existed, so the field (and its
-- pages_fields wiring) never landed on many installs. Saving Default Model
-- in Admin → LLM Configuration then silently failed (`saved: []`).
--
-- This migration:
--   1. Ensures the field type and `llm_default_model` field exist
--   2. Relinks the field to the sh_module_llm page type and page
--   3. Seeds a translation row when missing so get_page_fields can read it
--   4. Sets the plugin default model to gpt-oss-120b
--   5. Adds `llm_reasoning_effort` select field (OpenAI / Anthropic / future GPUStack)
--   6. Adds `llm_memory_rule_ids` multi-select on the form styles (and on SurveyJS
--      when that plugin is installed) plus the hooks that run the selected memory
--      rules after a finished submission
--   7. Adds `floating_shortcuts_hide_delay` (seconds, default 2) on llmChat for
--      how long the floating shortcut tray stays visible after mouse leave / blur
--   8. Adds `llm_scripts.combine_images` (optional labeled collage for vision scripts)
-- =====================================================

START TRANSACTION;

UPDATE plugins SET version = 'v1.5.0' WHERE `name` = 'llm';

INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`)
VALUES (NULL, 'select-llm-model', '7');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`)
VALUES (NULL, 'llm_default_model', get_field_type_id('select-llm-model'), '0');

-- Repair id_type if the field already existed with a NULL/invalid type
UPDATE `fields` f
INNER JOIN `fieldType` ft ON ft.name = 'select-llm-model'
SET f.`id_type` = ft.id
WHERE f.`name` = 'llm_default_model'
  AND (f.`id_type` IS NULL OR f.`id_type` = 0 OR f.`id_type` <> ft.id);

INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`)
VALUES (
    (SELECT id FROM pageType WHERE `name` = 'sh_module_llm'),
    get_field_id('llm_default_model'),
    'gpt-oss-120b',
    'Default LLM model to use'
);

SET @id_page_llm_config = (SELECT id FROM pages WHERE keyword = 'sh_module_llm');

INSERT IGNORE INTO `pages_fields` (`id_pages`, `id_fields`, `default_value`, `help`)
VALUES (
    @id_page_llm_config,
    get_field_id('llm_default_model'),
    'gpt-oss-120b',
    'Default LLM model to use'
);

-- Keep pages_fields / pageType_fields default_value in sync with the new plugin default
UPDATE `pages_fields`
SET `default_value` = 'gpt-oss-120b'
WHERE `id_pages` = @id_page_llm_config
  AND `id_fields` = get_field_id('llm_default_model')
  AND (`default_value` IS NULL OR `default_value` = '' OR `default_value` = 'qwen3-vl-8b-instruct');

UPDATE `pageType_fields`
SET `default_value` = 'gpt-oss-120b'
WHERE `id_pageType` = (SELECT id FROM pageType WHERE `name` = 'sh_module_llm')
  AND `id_fields` = get_field_id('llm_default_model')
  AND (`default_value` IS NULL OR `default_value` = '' OR `default_value` = 'qwen3-vl-8b-instruct');

-- Seed language-1 content only when no translation row exists yet
INSERT INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`)
SELECT
    @id_page_llm_config,
    get_field_id('llm_default_model'),
    '0000000001',
    'gpt-oss-120b'
WHERE get_field_id('llm_default_model') IS NOT NULL
  AND @id_page_llm_config IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM pages_fields_translation pft
      WHERE pft.id_pages = @id_page_llm_config
        AND pft.id_fields = get_field_id('llm_default_model')
        AND pft.id_languages = '0000000001'
  );

-- Migrate only the legacy stock default; leave custom admin choices untouched
UPDATE `pages_fields_translation`
SET `content` = 'gpt-oss-120b'
WHERE `id_pages` = @id_page_llm_config
  AND `id_fields` = get_field_id('llm_default_model')
  AND `id_languages` = '0000000001'
  AND `content` IN ('', 'qwen3-vl-8b-instruct');

-- Prefill style defaults for new llmChat / llmForm* sections when still empty/legacy
UPDATE `styles_fields` sf
INNER JOIN `styles` s ON s.id = sf.id_styles
INNER JOIN `fields` f ON f.id = sf.id_fields
SET sf.`default_value` = 'gpt-oss-120b'
WHERE s.`name` IN ('llmChat', 'llmFormRecord', 'llmFormLog')
  AND f.`name` = 'llm_model'
  AND (sf.`default_value` IS NULL OR sf.`default_value` = '' OR sf.`default_value` = 'qwen3-vl-8b-instruct');

-- =====================================================
-- Reasoning effort (OpenAI / Anthropic / future GPUStack)
-- Options are SelfHelp lookups (type_code = llmReasoningEffort)
-- =====================================================

INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES
('llmReasoningEffort', 'default', 'Default (provider)', 'Omit the parameter and use the provider default effort'),
('llmReasoningEffort', 'none', 'None (no thinking)', 'Disable or minimize internal reasoning when the model supports it'),
('llmReasoningEffort', 'minimal', 'Minimal', 'Minimal reasoning effort'),
('llmReasoningEffort', 'low', 'Low', 'Low reasoning effort — faster and cheaper'),
('llmReasoningEffort', 'medium', 'Medium', 'Balanced reasoning effort'),
('llmReasoningEffort', 'high', 'High', 'High reasoning effort — deeper thinking'),
('llmReasoningEffort', 'xhigh', 'Extra high', 'Extra-high reasoning effort where supported'),
('llmReasoningEffort', 'max', 'Max', 'Maximum reasoning effort where supported');

INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`)
VALUES (NULL, 'select-llm-reasoning-effort', '10');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`)
VALUES (NULL, 'llm_reasoning_effort', get_field_type_id('select-llm-reasoning-effort'), '0');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`)
VALUES
(
    get_style_id('llmChat'),
    get_field_id('llm_reasoning_effort'),
    'default',
    'Reasoning / thinking effort from lookups (llmReasoningEffort). OpenAI: reasoning.effort. Anthropic: output_config.effort. GPUStack ignores until supported. Use Default for the provider default.'
),
(
    get_style_id('llmFormRecord'),
    get_field_id('llm_reasoning_effort'),
    'default',
    'Reasoning / thinking effort from lookups (llmReasoningEffort). Default = provider default.'
),
(
    get_style_id('llmFormLog'),
    get_field_id('llm_reasoning_effort'),
    'default',
    'Reasoning / thinking effort from lookups (llmReasoningEffort). Default = provider default.'
);

INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`)
VALUES (
    (SELECT id FROM pageType WHERE `name` = 'sh_module_llm'),
    get_field_id('llm_reasoning_effort'),
    'default',
    'Default reasoning effort (lookups type_code llmReasoningEffort) when a style leaves the field empty.'
);

INSERT IGNORE INTO `pages_fields` (`id_pages`, `id_fields`, `default_value`, `help`)
VALUES (
    @id_page_llm_config,
    get_field_id('llm_reasoning_effort'),
    'default',
    'Default reasoning effort (lookups type_code llmReasoningEffort) when a style leaves the field empty.'
);

INSERT INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`)
SELECT
    @id_page_llm_config,
    get_field_id('llm_reasoning_effort'),
    '0000000001',
    'default'
WHERE get_field_id('llm_reasoning_effort') IS NOT NULL
  AND @id_page_llm_config IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM pages_fields_translation pft
      WHERE pft.id_pages = @id_page_llm_config
        AND pft.id_fields = get_field_id('llm_reasoning_effort')
        AND pft.id_languages = '0000000001'
  );

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES
(
    (SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'),
    'field-llm-reasoning-effort-edit',
    'Output select LLM reasoning effort field - edit mode',
    'CmsView',
    'create_field_form_item',
    'LlmHooks',
    'outputFieldLlmReasoningEffortEdit',
    5
),
(
    (SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'),
    'field-llm-reasoning-effort-view',
    'Output select LLM reasoning effort field - view mode',
    'CmsView',
    'create_field_item',
    'LlmHooks',
    'outputFieldLlmReasoningEffortView',
    5
);

-- =====================================================
-- Direct memory-rule selection on form and SurveyJS styles
-- =====================================================
-- `llm_memory_rule_ids` stores comma-separated llm_memory_rules.id values.
-- After a finished form / survey save, LlmHooks hands the selected rules to the
-- existing async memory pipeline (empty = off; missing or disabled rules are
-- skipped at runtime). Form actions with job type llm_memory_update are not
-- touched and still run in addition.
-- NOTE: hooks, styles and fields are cached - clear the SelfHelp cache after
-- running this migration.

INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`)
VALUES (NULL, 'select-llm-memory-rules', '14');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`)
VALUES (NULL, 'llm_memory_rule_ids', get_field_type_id('select-llm-memory-rules'), '0');

-- Form styles (styles that do not exist are skipped)
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`)
SELECT
    s.`id`,
    get_field_id('llm_memory_rule_ids'),
    '',
    'Memory rules to run in the background after this form is finished. Leave empty to disable. Form actions with job type llm_memory_update still run in addition.'
FROM `styles` s
WHERE s.`name` IN ('formUserInput', 'formUserInputRecord', 'formUserInputLog', 'llmFormRecord', 'llmFormLog');

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES
(
    (SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'),
    'field-llm-memory-rules-edit',
    'Output multi select LLM memory rules field - edit mode',
    'CmsView',
    'create_field_form_item',
    'LlmHooks',
    'outputFieldLlmMemoryRulesEdit',
    5
),
(
    (SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'),
    'field-llm-memory-rules-view',
    'Output multi select LLM memory rules field - view mode',
    'CmsView',
    'create_field_item',
    'LlmHooks',
    'outputFieldLlmMemoryRulesView',
    5
),
(
    (SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'),
    'llm-form-save-memory-rules',
    'Run the selected LLM memory rules after a finished form save',
    'FormUserInputModel',
    'save_user_input',
    'LlmHooks',
    'onFormSavedMemoryRules',
    20
),
(
    (SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'),
    'llm-form-update-memory-rules',
    'Run the selected LLM memory rules after a finished form update',
    'FormUserInputModel',
    'update_user_input',
    'LlmHooks',
    'onFormSavedMemoryRules',
    20
);

-- SurveyJS is a separate plugin: link the field and register the save hook only
-- when its style already exists. If SurveyJS is installed after this plugin, run
-- server/db/optional/enable_surveyjs_llm_memory_rules.sql (the same two inserts).
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`)
SELECT
    s.`id`,
    get_field_id('llm_memory_rule_ids'),
    '',
    'Memory rules to run in the background after this survey is finished. Leave empty to disable. Form actions with job type llm_memory_update still run in addition.'
FROM `styles` s
WHERE s.`name` = 'surveyJS';

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
SELECT
    (SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'),
    'llm-survey-save-memory-rules',
    'Run the selected LLM memory rules after a finished SurveyJS save',
    'SurveyJSModel',
    'save_survey',
    'LlmHooks',
    'onSurveySavedMemoryRules',
    20
FROM `styles` s
WHERE s.`name` = 'surveyJS';

-- Optional labeled collage for multi-image LLM scripts (off = send first 4 only)
CALL add_table_column(
    'llm_scripts',
    'combine_images',
    "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'When 1, stitch script images into one labeled collage with layout/EXIF guide; when 0, send at most LLM_MAX_FILES_PER_MESSAGE (4) separate images'"
);

COMMIT;
