-- =====================================================
-- LLM Plugin v1.9.0 - llmChat show_model_info
-- =====================================================
--
-- Requires v1.8.0 (run server/db/v1.8.0.sql first).
--
-- This migration:
--   1. llmChat: new checkbox `show_model_info` (default on = unchanged). Off
--      hides the model id in the chat header.
--   2. Updates the help of `default_chat_title` on llmChat: it now also
--      names an auto-started conversation (before: "AI Assistant - <date>").
--   3. Bumps the plugin version LAST.
--
-- Code-only changes of this version (no SQL): llmChat prints its section
-- `css` on the chat root; built-in German interview texts use "ss" for a
-- Swiss locale (de-CH); the interview opener follows the author context
-- when it says how the first message opens.
--
-- NOTE: fields and styles are cached - clear the SelfHelp cache
-- (Admin > Cache > clear all) after running this migration.
-- =====================================================

START TRANSACTION;

-- =====================================================
-- 1. llmChat: show_model_info
-- =====================================================

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'show_model_info', get_field_type_id('checkbox'), '0');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmChat'), get_field_id('show_model_info'), '1', 'Show the model id (for example `OpenAI :: gpt-5`) under the title of the chat header. On by default. Turn it off where participants should not see which model answers, for example in a study.');

-- =====================================================
-- 2. llmChat: help of default_chat_title
-- =====================================================

UPDATE `styles_fields`
SET `help` = 'Title of a new chat. An auto-started chat (`auto_start_conversation`) is named after it too; with the conversation list on, the date is appended so the chats can be told apart. The chat header shows this title.'
WHERE `id_styles` = get_style_id('llmChat') AND `id_fields` = get_field_id('default_chat_title');

-- Version bump last.
UPDATE plugins SET version = 'v1.9.0' WHERE `name` = 'llm';

COMMIT;
