-- =====================================================
-- LLM Plugin v1.8.0 - one memory rule field, memory sources from lookups
-- =====================================================
--
-- Requires v1.7.0 (run server/db/v1.7.0.sql first).
--
-- This migration:
--   1. Labels the memory source types. The code now reads them from the
--      llmMemorySourceType lookups (lookup_code = source type, lookup_value =
--      label shown in the rule editor, Sources and Activity tabs), so another
--      plugin can register its own source with one lookup row.
--   2. Makes `memory_rule_ids` the one section field that selects memory
--      rules, on every style: the form styles' `llm_memory_rule_ids` values,
--      style links and field versions move to it and the field is deleted.
--      The picker lists every rule; a picked rule runs whatever its source.
--   3. llmChat: an empty `memory_rule_ids` now means off (it ran every
--      `llm_chat_form_submit` rule before).
--   4. SurveyJS: registers the survey save hook always (core skips a hook
--      whose class is not installed) and links `memory_rule_ids` to
--      `surveyJS` when that style exists. SurveyJS installed later: run
--      server/db/optional/enable_surveyjs_llm_memory_rules.sql once.
--   5. Drops the unused column llm_memory_rules.source_match_json.
--   6. Bumps the plugin version LAST.
--
-- Each label is only set while lookup_value still holds the code (as v1.2.0
-- inserted it), so a re-run or a label changed by an admin is left alone.
-- NOTE: lookups are cached - clear the SelfHelp cache
-- (Admin > Cache > clear all) after running this migration.
-- =====================================================

START TRANSACTION;

-- =====================================================
-- 1. Memory source type labels
-- =====================================================

UPDATE `lookups` SET `lookup_value` = 'Form / survey submit'
WHERE `type_code` = 'llmMemorySourceType' AND `lookup_code` = 'form_action_submit' AND `lookup_value` = 'form_action_submit';

UPDATE `lookups` SET `lookup_value` = 'llmChat form submit'
WHERE `type_code` = 'llmMemorySourceType' AND `lookup_code` = 'llm_chat_form_submit' AND `lookup_value` = 'llm_chat_form_submit';

UPDATE `lookups` SET `lookup_value` = 'Login'
WHERE `type_code` = 'llmMemorySourceType' AND `lookup_code` = 'login' AND `lookup_value` = 'login';

UPDATE `lookups` SET `lookup_value` = 'Profile name change'
WHERE `type_code` = 'llmMemorySourceType' AND `lookup_code` = 'profile_name_change' AND `lookup_value` = 'profile_name_change';

UPDATE `lookups` SET `lookup_value` = 'Admin action'
WHERE `type_code` = 'llmMemorySourceType' AND `lookup_code` = 'admin_manual' AND `lookup_value` = 'admin_manual';

-- =====================================================
-- 2. One field for picked memory rules: memory_rule_ids
-- =====================================================
-- `memory_rule_ids` (v1.2.0, llmChat) is kept; `llm_memory_rule_ids` (v1.5.0,
-- forms / SurveyJS / LogList / llmForm*) moves to it. No style has both, so
-- nothing collides; IGNORE keeps a re-run or a manual duplicate from failing
-- (the leftover rows are deleted with the field). All steps are no-ops once
-- `llm_memory_rule_ids` is gone.

UPDATE IGNORE `sections_fields_translation`
SET `id_fields` = get_field_id('memory_rule_ids')
WHERE `id_fields` = get_field_id('llm_memory_rule_ids');

UPDATE IGNORE `styles_fields`
SET `id_fields` = get_field_id('memory_rule_ids')
WHERE `id_fields` = get_field_id('llm_memory_rule_ids');

-- fields_versions: SelfHelp core v7.13.0, required since v1.7.0.
UPDATE `fields_versions`
SET `id_fields` = get_field_id('memory_rule_ids')
WHERE `id_fields` = get_field_id('llm_memory_rule_ids');

DELETE FROM `fields` WHERE `name` = 'llm_memory_rule_ids';

-- =====================================================
-- 3. llmChat: empty memory_rule_ids = off
-- =====================================================

UPDATE `styles_fields`
SET `help` = 'Memory rules to run in the background when a chat form is submitted and data saving is OFF on this section. Leave empty to disable. When data saving is ON, use a form action with job type `llm_memory_update` on the chat data table instead; this field is then ignored.'
WHERE `id_styles` = get_style_id('llmChat')
  AND `id_fields` = get_field_id('memory_rule_ids');

-- =====================================================
-- 4. SurveyJS: hook always, field link when the style exists
-- =====================================================

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES (
    (SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'),
    'llm-survey-save-memory-rules',
    'Run the selected LLM memory rules after a finished SurveyJS save',
    'SurveyJSModel',
    'save_survey',
    'LlmHooks',
    'onSurveySavedMemoryRules',
    20
);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`)
SELECT
    s.`id`,
    get_field_id('memory_rule_ids'),
    '',
    'Memory rules to run in the background after this survey is finished. Leave empty to disable. Form actions with job type llm_memory_update still run in addition.'
FROM `styles` s
WHERE s.`name` = 'surveyJS';

-- =====================================================
-- 5. Drop llm_memory_rules.source_match_json
-- =====================================================
-- Per-rule source criteria were never editable (the rule editor always saved
-- {}) and nothing matches on them any more.

CALL drop_table_column('llm_memory_rules', 'source_match_json');

-- Version bump last.
UPDATE plugins SET version = 'v1.8.0' WHERE `name` = 'llm';

COMMIT;
