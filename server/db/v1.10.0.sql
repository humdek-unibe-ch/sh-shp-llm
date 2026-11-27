-- =====================================================
-- LLM Plugin v1.10.0 - Prompts hub + floating resize + structured memory
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
--   3. Adds CMS checkbox `allow_floating_chat_resize` on llmChat (default off).
--      When on (and floating button is enabled), desktop participants can
--      resize and move the floating panel; size/position are stored in
--      browser localStorage only (UI preference, no consent banner).
--   4. Structured memory (doc/structured-memory-design.md): a memory is a
--      list of items (items/v1 in memory_json) instead of free text. Adds
--      the column `llm_memory_keys.sections_json` (the optional sections of
--      a memory key, edited in the Keys tab of the LLM Memory page; empty =
--      one flat list of items), the memory source type `user_edit` and the
--      style `llmMemoryEditor` with its fields: the participant edits,
--      deletes and adds items of their own memory without an LLM call. The
--      editor shows one or more memory keys (multi select `memory_keys`,
--      options = the key registry) and translates key and section labels
--      with the translatable JSON field `memory_labels`. The editor's layout (sections, accordion, tabs, cards, flat)
--      is the CMS select `display_mode` (lookups `llmMemoryDisplayMode`);
--      its look is plain CSS (theme classes), not a field. No data is
--      converted here: a free-text memory is converted to items by the
--      memory worker on its next update and shown read-only by the editor
--      until then; memory_text is filled as before.
--   5. Bumps the plugin version LAST so a failed statement can never mark
--      v1.10.0 as installed. Every statement is idempotent (INSERT IGNORE):
--      the migration can be run again safely.
--
-- NOTE: pages, ACL, styles, fields and lookups are cached - clear the
-- SelfHelp cache (Admin > Cache > clear all) after running this migration,
-- or the new style, its fields (incl. the `display_mode` select) and the user_edit source
-- type are not found.
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

-- =====================================================
-- 2. allow_floating_chat_resize on llmChat
-- =====================================================
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`)
VALUES (NULL, 'allow_floating_chat_resize', get_field_type_id('checkbox'), '0');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(
    get_style_id('llmChat'),
    get_field_id('allow_floating_chat_resize'),
    '0',
    'Allow participants to resize and move the floating chat panel on desktop. Only applies when `enable_floating_button` is on. Mobile stays full-screen. The chosen size and position are remembered in the browser (`localStorage`) as a UI preference — no personal data and no cookie consent. Default: off.'
);

-- =====================================================
-- 3. Structured memory (doc/structured-memory-design.md)
-- =====================================================

-- 3a. The sections of a memory key live on the key itself (llm_memory_keys, the key registry
--     of the Keys tab): a JSON list [{"code", "label", "hint"}, ...]. NULL / empty = the key has
--     no sections, its memory is one flat list of items. Read by the memory worker and the editor.
CALL add_table_column('llm_memory_keys', 'sections_json', 'TEXT DEFAULT NULL AFTER `description`');

-- 3b. Memory source type of a change the participant makes in the llmMemoryEditor.
--     The label is what the Activity and History tabs show. Rules cannot listen to it.
INSERT IGNORE INTO `lookups` (`type_code`, `lookup_code`, `lookup_value`, `lookup_description`) VALUES
('llmMemorySourceType', 'user_edit', 'User edit', 'The participant added, edited or deleted a memory item in the llmMemoryEditor style');

-- 3c. Style llmMemoryEditor: the logged-in participant's memory as a list of items they can
--     add to, edit and delete. A change is saved without an LLM call.
INSERT IGNORE INTO `styles` (`name`, `id_type`, `id_group`, `description`)
VALUES ('llmMemoryEditor', (SELECT id FROM styleType WHERE `name` = 'component'), (SELECT id FROM styleGroup WHERE `name` = 'Form'), 'The logged-in participant''s memory (one or more memory keys) as a list of items, one block per section. Participants can edit, delete and add items without an LLM call; every change is saved as a history entry. A free-text memory is shown read-only until the next memory update converts it to items. Free-text change requests stay a separate form + memory rule.');

-- Fields. memory_keys (multi select of the key registry, rendered by LlmHooks like memory_rule_ids),
-- memory_labels, the three allow_* flags, confirm_delete_text, read_only_text and label_save are new;
-- the empty_text and label_* fields exist in the core (the IGNORE leaves them as they are).
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES
(NULL, 'select-llm-memory-keys', '17');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'memory_keys', get_field_type_id('select-llm-memory-keys'), '0'),
(NULL, 'memory_labels', get_field_type_id('json'), '1'),
(NULL, 'allow_add', get_field_type_id('checkbox'), '0'),
(NULL, 'allow_edit', get_field_type_id('checkbox'), '0'),
(NULL, 'allow_delete', get_field_type_id('checkbox'), '0'),
(NULL, 'confirm_delete_text', get_field_type_id('text'), '1'),
(NULL, 'read_only_text', get_field_type_id('text'), '1'),
(NULL, 'label_save', get_field_type_id('text'), '1'),
(NULL, 'empty_text', get_field_type_id('text'), '1'),
(NULL, 'label_add', get_field_type_id('text'), '1'),
(NULL, 'label_edit', get_field_type_id('text'), '1'),
(NULL, 'label_cancel', get_field_type_id('text'), '1'),
(NULL, 'label_delete', get_field_type_id('text'), '1');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmMemoryEditor'), get_field_id('memory_keys'), '', 'The memory keys to show and edit (the keys of the Keys tab of the LLM Memory page). Several keys are shown one below the other, each with its own heading and sections, and each is saved on its own. Empty: the default memory key of the LLM module settings. The editor always shows the memory of the logged-in participant.'),
(get_style_id('llmMemoryEditor'), get_field_id('memory_labels'), '', 'Optional JSON object that translates the labels of memory keys and sections for the participant. Translatable per language. A key `<memory key>` is the heading of a key (only shown when several keys are selected), `<memory key>.<section code>` the heading of a section of that key, `<section code>` the heading of that section in every key. Not found: the label of the Keys tab, else the code.

Example:

```json
{
  "global": "Mein Gedächtnis",
  "global.goals": "Meine Ziele",
  "person": "Über mich"
}
```'),
(get_style_id('llmMemoryEditor'), get_field_id('allow_add'), '1', 'Show a "+ add" button in each section so participants can add an item. Default: on.'),
(get_style_id('llmMemoryEditor'), get_field_id('allow_edit'), '1', 'Let participants edit the text of an item. Default: on.'),
(get_style_id('llmMemoryEditor'), get_field_id('allow_delete'), '1', 'Let participants delete an item (after a confirmation). The history keeps the earlier memory. Default: on.'),
(get_style_id('llmMemoryEditor'), get_field_id('confirm_delete_text'), 'Delete this item from your memory?', 'Text of the confirmation dialog before an item is deleted.'),
(get_style_id('llmMemoryEditor'), get_field_id('empty_text'), 'Nothing saved yet.', 'Shown when the memory, or a section, has no items yet (plain text).'),
(get_style_id('llmMemoryEditor'), get_field_id('read_only_text'), 'This memory has not been converted to a list of items yet, so it cannot be changed here. It becomes an editable list the next time it is updated.', 'Note under a free-text memory (written before v1.10.0) that is shown read-only until the next memory update converts it to items (plain text).'),
(get_style_id('llmMemoryEditor'), get_field_id('label_add'), 'Add', 'Label of the button that adds an item to a section.'),
(get_style_id('llmMemoryEditor'), get_field_id('label_edit'), 'Edit', 'Label of the button that edits an item.'),
(get_style_id('llmMemoryEditor'), get_field_id('label_save'), 'Save', 'Label of the button that saves an added or edited item.'),
(get_style_id('llmMemoryEditor'), get_field_id('label_cancel'), 'Cancel', 'Label of the button that leaves the add / edit form and of the cancel button in the delete confirmation.'),
(get_style_id('llmMemoryEditor'), get_field_id('label_delete'), 'Delete', 'Label of the button that deletes an item and of the confirm button in the delete confirmation.')
ON DUPLICATE KEY UPDATE `help` = VALUES(`help`);

-- Standard links (every style)
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmMemoryEditor'), get_field_id('css'), NULL, 'Allows to assign CSS classes to the root item of the style.'),
(get_style_id('llmMemoryEditor'), get_field_id('css_mobile'), NULL, 'Allows to assign CSS classes to the root item of the style for the mobile version.'),
(get_style_id('llmMemoryEditor'), get_field_id('condition'), NULL, 'The field `condition` allows to specify a condition. Only if the condition resolves to true the style is rendered.'),
(get_style_id('llmMemoryEditor'), get_field_id('data_config'), NULL, 'Define data_config to load data that can be used in the style fields via `{{...}}`.'),
(get_style_id('llmMemoryEditor'), get_field_id('debug'), 0, 'If *checked*, debug messages will be rendered to the screen. These might help to understand the result of a condition evaluation. **Make sure that this field is *unchecked* once the page is productive**.')
ON DUPLICATE KEY UPDATE `help` = VALUES(`help`);

-- 3d. Layout of the editor: the CMS select `display_mode`. Its options are lookups (type_code
--     llmMemoryDisplayMode, the lookup_code is the stored value, the lookup_value is the label of
--     the dropdown); LlmHooks renders the select like the llm_interview_* option fields. The look
--     (colours, corners, spacing) is CSS - theme classes in the `css` field - not a field.
INSERT IGNORE INTO `lookups` (`type_code`, `lookup_code`, `lookup_value`, `lookup_description`) VALUES
('llmMemoryDisplayMode', 'sections', 'Sections (default)', 'A heading and a list for each section, one below the other.'),
('llmMemoryDisplayMode', 'accordion', 'Accordion (collapsible panels)', 'One collapsible panel per section with the number of items; the first section that has items is open.'),
('llmMemoryDisplayMode', 'tabs', 'Tabs', 'One tab per section with the number of items; the first section that has items is selected.'),
('llmMemoryDisplayMode', 'cards', 'Cards', 'One card per section: the heading with the number of items on top, the items and the add button inside.'),
('llmMemoryDisplayMode', 'flat', 'Flat list (no section headings)', 'One list of all items without headings. The add button is only shown when the memory key has no sections or exactly one.');

INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES
(NULL, 'select-llm-memory-display-mode', '16');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'display_mode', get_field_type_id('select-llm-memory-display-mode'), '0');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmMemoryEditor'), get_field_id('display_mode'), 'sections', 'How the sections of the memory are laid out:\n\n* `sections` (default) - a heading and a list for each section, one below the other\n* `accordion` - one collapsible panel per section, with the number of items; the first section that has items is open\n* `tabs` - one tab per section, with the number of items; the first section that has items is selected\n* `cards` - one card per section (heading with the number of items, the items and the add button inside)\n* `flat` - one list of all items, without section headings. The add button is only shown when the memory key has no sections or exactly one (otherwise the section to add to cannot be chosen); edit and delete work as usual.

A memory key without sections is always one list of items, whatever the layout.\n\nAn accordion or tab stays on the panel or tab the participant was working in after a change. Colours, corners and spacing are not set here: put `lme-theme-forest` in the `css` field for the built-in theme, or define the `--lme-*` CSS variables for your own (see the plugin README).')
ON DUPLICATE KEY UPDATE `help` = VALUES(`help`);

-- Version bump last.
UPDATE `plugins` SET version = 'v1.10.0' WHERE `name` = 'llm';

COMMIT;
