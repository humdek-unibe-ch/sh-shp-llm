-- =====================================================
-- LLM Plugin - enable memory rules on SurveyJS surveys
-- =====================================================
--
-- WHEN TO RUN
--   Run this file once when the SurveyJS plugin (sh-shp-survey_js) is
--   installed AFTER the LLM plugin migration v1.8.0. If SurveyJS was installed
--   before, v1.8.0 did this already and nothing has to be done.
--   The file is idempotent (INSERT IGNORE): running it again, or on an
--   installation that already has it, changes nothing.
--
-- PREREQUISITES
--   1. LLM plugin migrated to v1.8.0 or later (the field `memory_rule_ids` and
--      the survey save hook LlmHooks::onSurveySavedMemoryRules)
--   2. SurveyJS plugin installed (style `surveyJS` exists)
--   When one of them is missing the statement below does not change anything.
--
-- WHAT IT DOES
--   Adds the field `memory_rule_ids` (multi select of memory rules) to the
--   style `surveyJS`. The save hook is registered by v1.8.0 already; it runs
--   the selected rules in the background after a finished survey was saved.
--
-- AFTER RUNNING
--   Styles and fields are cached. Clear the SelfHelp cache (CMS cache clear),
--   then open a SurveyJS section in the CMS: "memory_rule_ids" is listed in
--   its fields.
--
-- HOW TO RUN
--   mysql -u <user> -p <database> < server/plugins/sh-shp-llm/server/db/optional/enable_surveyjs_llm_memory_rules.sql
--
-- CHECK (optional)
--   SELECT s.name FROM styles s
--   INNER JOIN styles_fields sf ON sf.id_styles = s.id
--   WHERE sf.id_fields = get_field_id('memory_rule_ids');
-- =====================================================

START TRANSACTION;

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`)
SELECT
    s.`id`,
    get_field_id('memory_rule_ids'),
    '',
    'Memory rules to run in the background after this survey is finished. Leave empty to disable. Form actions with job type llm_memory_update still run in addition.'
FROM `styles` s
WHERE s.`name` = 'surveyJS'
  AND get_field_id('memory_rule_ids') IS NOT NULL;

COMMIT;
