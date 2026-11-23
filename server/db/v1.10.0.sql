-- =====================================================
-- LLM Plugin v1.10.0 - Prompts hub
-- =====================================================
--
-- Requires v1.9.0 (run server/db/v1.9.0.sql first; v1.9.1 has no SQL).
--
-- This migration:
--   1. Adds the admin page `moduleLlmPrompts` ("LLM Prompts",
--      /admin/module_llm/prompts): one list of every LLM prompt (CMS prompt
--      fields, scripts, memory rule instructions, built-in system prompts,
--      agentic chat personas) with search, filters, in-place editing, version
--      history, diff and restore (doc/prompts-hub.md).
--   2. Gives the admin group full access to the new page. Further groups get
--      access through the CMS page ACL like for the other LLM module pages;
--      on top of the page ACL, the hub always checks the ACL of the page a
--      prompt belongs to (CMS page of the section, Scripts page, Memory page).
--   3. Bumps the plugin version LAST so a failed statement can never mark
--      v1.10.0 as installed. Every statement is idempotent (INSERT IGNORE):
--      the migration can be run again safely.
--
-- No new tables, fields, styles or hooks: the hub reads the existing prompt
-- registry (llm_prompt_*) and writes through the same services as the CMS,
-- the Scripts page and the Memory page.
--
-- NOTE: pages and their ACL are cached - clear the SelfHelp cache
-- (Admin > Cache > clear all) after running this migration.
-- =====================================================

START TRANSACTION;

-- =====================================================
-- 1. Prompts page
-- =====================================================

INSERT IGNORE INTO `pages` (`id`, `keyword`, `url`, `protocol`, `id_actions`, `id_navigation_section`, `parent`, `is_headless`, `nav_position`, `footer_position`, `id_type`, `id_pageAccessTypes`)
VALUES (NULL, 'moduleLlmPrompts', '/admin/module_llm/prompts', 'GET|POST', (SELECT id FROM actions WHERE `name` = 'component' LIMIT 1), NULL, NULL, '0', NULL, NULL, '0000000001', (SELECT id FROM lookups WHERE type_code = 'pageAccessTypes' AND lookup_code = 'mobile_and_web'));
SET @id_page_llm_prompts = (SELECT id FROM pages WHERE keyword = 'moduleLlmPrompts');

-- Page title (used by the admin sidebar label)
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES
(@id_page_llm_prompts, get_field_id('title'), '0000000001', 'LLM Prompts'),
(@id_page_llm_prompts, get_field_id('title'), '0000000002', 'LLM Prompts');

-- Admin group: full access
INSERT IGNORE INTO `acl_groups` (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`)
VALUES ('0000000001', @id_page_llm_prompts, '1', '1', '1', '1');

-- Version bump last.
UPDATE `plugins` SET version = 'v1.10.0' WHERE `name` = 'llm';

COMMIT;
