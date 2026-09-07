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

COMMIT;
