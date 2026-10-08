-- =====================================================
-- LLM Plugin - enable memory rules on SurveyJS surveys
-- =====================================================
--
-- WHEN TO RUN
--   Run this file when the SurveyJS plugin (sh-shp-survey_js) was installed
--   AFTER the LLM plugin migration v1.5.0 had already been applied.
--   If SurveyJS was installed before v1.5.0 ran, the migration did this
--   already and nothing has to be done.
--   The file is idempotent (INSERT IGNORE on unique keys): running it again,
--   or on an installation that already has it, changes nothing.
--
-- PREREQUISITES
--   1. LLM plugin migrated to v1.5.0 or later (creates the field
--      `llm_memory_rule_ids` and the plugin code behind the hook below)
--   2. SurveyJS plugin installed (style `surveyJS` exists)
--   When one of them is missing the statements below do not change anything.
--
-- WHAT IT DOES
--   1. Adds the optional field `llm_memory_rule_ids` (multi select of memory
--      rules) to the style `surveyJS`
--   2. Registers the hook SurveyJSModel::save_survey ->
--      LlmHooks::onSurveySavedMemoryRules that queues the selected rules in the
--      background after a finished survey was saved
--
-- AFTER RUNNING
--   Hooks, styles and fields are cached. Clear the SelfHelp cache (CMS cache
--   clear) so the new field and hook are picked up. Then open a SurveyJS
--   section in the CMS: "llm_memory_rule_ids" is listed in its fields.
--
-- HOW TO RUN
--   mysql -u <user> -p <database> < server/plugins/sh-shp-llm/server/db/optional/enable_surveyjs_llm_memory_rules.sql
--
-- CHECK (optional)
--   SELECT s.name FROM styles s
--   INNER JOIN styles_fields sf ON sf.id_styles = s.id
--   WHERE sf.id_fields = get_field_id('llm_memory_rule_ids');
--   SELECT name, class, function, exec_function FROM hooks
--   WHERE name = 'llm-survey-save-memory-rules';
-- =====================================================

START TRANSACTION;

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`)
SELECT
    s.`id`,
    get_field_id('llm_memory_rule_ids'),
    '',
    'Memory rules to run in the background after this survey is finished. Leave empty to disable. Form actions with job type llm_memory_update still run in addition.'
FROM `styles` s
WHERE s.`name` = 'surveyJS'
  AND get_field_id('llm_memory_rule_ids') IS NOT NULL;

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

COMMIT;
