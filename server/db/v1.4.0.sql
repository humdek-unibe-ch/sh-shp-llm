-- =====================================================
-- LLM Plugin v1.4.0 - Floating Chat Shortcuts
-- =====================================================
--
-- This migration:
--   1. Adds a translatable llm_chat_shortcuts JSON field to the
--      llmChat style for configuring quick-start message shortcuts
--      that appear as pills around the floating chat button.
--   2. When floating chat is enabled and shortcuts are configured,
--      web shows shortcut pills on FAB hover/focus, and mobile shows
--      them on first FAB tap. Selecting a shortcut opens chat and
--      sends the configured message through the existing chat send flow.
-- =====================================================

START TRANSACTION;

UPDATE plugins SET version = 'v1.4.0' WHERE `name` = 'llm';

-- =====================================================
-- FLOATING CHAT SHORTCUTS FIELD
-- =====================================================
-- JSON field containing an array of shortcut objects with label and message.
-- Each shortcut is rendered as a pill button around the floating chat FAB.
-- When clicked, the chat panel opens and the message is sent automatically.
-- The field is translatable (display=1) like floating_button_label.
-- Default is an empty array, meaning no shortcuts are shown.
--
-- Schema:
-- [
--   {
--     "label": "Where was that exercise with XY again?",
--     "message": "Where was that exercise with XY again?"
--   }
-- ]
--
-- If message is empty, the label is sent as the message.
-- Entries without usable text are ignored.
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`)
VALUES (NULL, 'llm_chat_shortcuts', get_field_type_id('json'), '1');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmChat'), get_field_id('llm_chat_shortcuts'), '[]',
'Quick-start message shortcuts that appear as pills around the floating chat button. Each shortcut has a `label` (shown on the pill) and a `message` (sent when clicked). If `message` is empty, the `label` is used as the message. Shortcuts are only shown when `enable_floating_button` is enabled.\n\nFormat (JSON array):\n```json\n[\n  {\n    "label": "Where was that exercise with XY again?",\n    "message": "Where was that exercise with XY again?"\n  }\n]\n```\n\n**Web behavior:** Shortcuts appear on hover/focus over the floating button. Clicking a shortcut opens the chat panel and sends the message immediately.\n\n**Mobile behavior:** First tap on the floating button shows the shortcut tray (if shortcuts are configured). Tapping a shortcut opens the chat and sends the message. Tapping the floating button again when the tray is open opens the chat normally.\n\nThe field is translatable — configure different shortcuts per language in the CMS. Empty array (default) means no shortcuts are shown.');

-- Update existing floating_button_label values from 'Chat' to empty string
-- 1. Update the default value in styles_fields (affects new instances)
UPDATE `styles_fields`
SET `default_value` = ''
WHERE `id_styles` = get_style_id('llmChat')
  AND `id_fields` = get_field_id('floating_button_label');

COMMIT;
