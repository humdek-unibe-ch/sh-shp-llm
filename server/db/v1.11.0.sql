-- =====================================================
-- LLM Plugin v1.11.0 - Search in the llmMemoryEditor
-- =====================================================
--
-- Requires v1.10.0 (run server/db/v1.10.0.sql first).
--
-- This migration:
--   1. Adds the fields of the search box of the style `llmMemoryEditor`:
--      checkbox `show_search` (default on) and the translatable texts
--      `search_placeholder` and `search_empty_text`. The search filters the
--      items in the browser; nothing is sent to the server.
--   2. Updates the help of `display_mode`: the built-in theme
--      `lme-theme-forest` is removed from the plugin CSS; a project styles the
--      editor with the `--lme-*` CSS variables in its own CSS.
--   3. Bumps the plugin version LAST. Every statement is idempotent: the
--      migration can be run again safely.
--
-- NOTE: styles and fields are cached - clear the SelfHelp cache
-- (Admin > Cache > clear all) after running this migration, or the new
-- fields are not shown in the CMS.
-- =====================================================

START TRANSACTION;

-- =====================================================
-- 1. Search fields
-- =====================================================

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'show_search', get_field_type_id('checkbox'), '0'),
(NULL, 'search_placeholder', get_field_type_id('text'), '1'),
(NULL, 'search_empty_text', get_field_type_id('text'), '1');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmMemoryEditor'), get_field_id('show_search'), '1', 'Show a search box above the memory. While the participant types, only the items are shown whose text, section title or key heading contains every word of the query; the matched words are highlighted, sections and tabs without a match are hidden and the item counts show the matches. Escape or clearing the box shows everything again. The search runs in the browser. Default: on.'),
(get_style_id('llmMemoryEditor'), get_field_id('search_placeholder'), 'Search your memory', 'Placeholder and accessible label of the search box (plain text).'),
(get_style_id('llmMemoryEditor'), get_field_id('search_empty_text'), 'Nothing in your memory matches your search.', 'Shown when no item matches the search (plain text).');

-- =====================================================
-- 2. Help of display_mode without the removed theme
-- =====================================================

UPDATE `styles_fields`
SET `help` = 'How the sections of the memory are laid out:\n\n* `sections` (default) - a heading and a list for each section, one below the other\n* `accordion` - one collapsible panel per section, with the number of items; the first section that has items is open\n* `tabs` - one tab per section, with the number of items; the first section that has items is selected\n* `cards` - one card per section (heading with the number of items, the items and the add button inside)\n* `flat` - one list of all items, without section headings. The add button is only shown when the memory key has no sections or exactly one (otherwise the section to add to cannot be chosen); edit and delete work as usual.

A memory key without sections is always one list of items, whatever the layout.\n\nAn accordion or tab stays on the panel or tab the participant was working in after a change. Colours, corners and spacing are not set here: the editor follows the Bootstrap colours of the site; a project changes them with the `--lme-*` CSS variables in its own CSS (see the plugin README).'
WHERE `id_styles` = get_style_id('llmMemoryEditor') AND `id_fields` = get_field_id('display_mode');

-- Version bump last.
UPDATE `plugins` SET version = 'v1.11.0' WHERE `name` = 'llm';

COMMIT;
