-- =====================================================
-- LLM Plugin v1.3.0 - Polish & Correctness Pass
-- =====================================================
--
-- This migration:
--   1. Backfills the standard "default style fields" (`data_config`,
--      `condition`, `debug`; `css` and `css_mobile` were already linked
--      in v1.0.0 / v1.1.0) on every LLM-owned style so authors get the
--      same surface as core SelfHelp styles.
--   2. Adds a per-section toggle (`enable_hint_suggestions`) that lets
--      authors disable the LLM-generated quick-reply suggestion buttons
--      that the structured response schema otherwise emits.
--   3. Introduces a `llmConversationSourceType` lookup family and a
--      foreign-key column on `llmConversations` so back-end-only
--      conversations (Prompt Lab, Builder, Memory, Dataset Eval) can
--      be cleanly distinguished from the user-facing chat conversations
--      that the `llmChat` style sidebar exposes.
--   4. Replaces the legacy `llm_chat_colors` JSON field with a unified
--      `llm_chat_appearance` field that covers bubble colours, web
--      (FontAwesome) icons, mobile (Ionic) icons AND custom avatar
--      images per role in a single place. The legacy field is dropped
--      outright (no shim) per the v1.3.0 spec — see section 5 below.
--
-- Per request, no backfill is performed for existing conversation
-- rows. Legacy rows keep `id_llm_conversation_sources = NULL` and the
-- chat sidebar treats NULL as "chat" for backward compatibility.
-- New rows written by the v1.3.0 code paths populate the column
-- explicitly.
-- =====================================================

START TRANSACTION;

UPDATE plugins SET version = 'v1.3.0' WHERE `name` = 'llm';

-- =====================================================
-- 1. CONVERSATION SOURCE TYPE LOOKUPS
-- =====================================================
-- The `llmConversationSourceType` lookup family enumerates every
-- back-end producer that writes into `llmConversations`. Adding new
-- values here is the canonical way to register a new producer.
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES
('llmConversationSourceType', 'llm_conv_source_chat',         'chat',         'User-facing llmChat / floating chat conversation. Visible in the chat sidebar.'),
('llmConversationSourceType', 'llm_conv_source_playground',   'playground',   'Conversation produced by the Prompt Lab playground / compare modes. Hidden from the chat sidebar.'),
('llmConversationSourceType', 'llm_conv_source_builder',      'builder',      'Conversation produced by the Build With AI prompt builder. Hidden from the chat sidebar.'),
('llmConversationSourceType', 'llm_conv_source_memory',       'memory',       'Conversation produced by a memory-rule worker. Hidden from the chat sidebar.'),
('llmConversationSourceType', 'llm_conv_source_dataset_eval', 'dataset_eval', 'Conversation produced by dataset evaluation / replay runs. Hidden from the chat sidebar.'),
('llmConversationSourceType', 'llm_conv_source_form',         'form',         'Conversation produced by an llmFormRecord / llmFormLog submission. Hidden from the chat sidebar.'),
('llmConversationSourceType', 'llm_conv_source_script',       'script',       'Conversation produced by an LLM script (sync or async). Hidden from the chat sidebar.'),
('llmConversationSourceType', 'llm_conv_source_dataset_import', 'dataset_import', 'Conversation produced by AI-assisted dataset import parsing. Hidden from the chat sidebar.');

-- =====================================================
-- 2. NEW COLUMN ON llmConversations
-- =====================================================
-- Use the shared add_table_column helper so the migration stays
-- rerunnable and survives manual partial applications during testing.
CALL add_table_column('llmConversations', 'id_llm_conversation_sources',
    "INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'FK to lookups.id where type_code = llmConversationSourceType. NULL means legacy chat conversation.' AFTER `id_llm_scripts`");

CALL add_index('llmConversations', 'idx_llm_conversation_source', 'id_llm_conversation_sources');

CALL add_foreign_key('llmConversations', 'id_llm_conversation_sources', 'lookups', 'id');

-- =====================================================
-- 3. HINT-SUGGESTION TOGGLE FIELD
-- =====================================================
-- When disabled, the React frontend hides the quick-reply suggestion
-- buttons rendered by `StructuredResponseRenderer.NextStepRenderer`,
-- AND the schema instruction is augmented (via the
-- `core.response.suppress_suggestions` prompt asset) to tell the model
-- not to emit `content.suggestions` so we do not waste output tokens.
-- The React layer remaps the schema's `content.suggestions` into
-- `next_step.suggestions` for rendering — both layers respect this flag.
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`)
VALUES (NULL, 'enable_hint_suggestions', get_field_type_id('checkbox'), '0');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmChat'), get_field_id('enable_hint_suggestions'), '1',
'Show the AI-generated quick-reply suggestion buttons (the small "hint" buttons that appear under each AI message). When disabled, the buttons are hidden in the UI **and** the LLM is instructed not to generate them, which produces faster, cheaper responses.\n\nDefaults to enabled so existing chats are unchanged. Disable for guided / linear flows where free-form replies are preferred.');

-- =====================================================
-- 4. BACKFILL DEFAULT STYLE FIELDS
-- =====================================================
-- Every SelfHelp style is expected to expose `data_config`, `condition`,
-- `debug`, `css`, and `css_mobile` so authors get a consistent surface.
-- The LLM-owned styles were registered before this convention was
-- complete, so we add the missing entries here. INSERT IGNORE keeps
-- this idempotent; previously-set values are preserved.
--
-- Audit (v1.3.0):
--   * llmChat       (v1.0.0): had css + css_mobile. Missing data_config / condition / debug.
--   * llmResponse   (v1.0.0): had all five — no-op below.
--   * llmFormRecord (v1.1.0): had css + css_mobile + data_config + condition. Missing debug.
--   * llmFormLog    (v1.1.0): had css + css_mobile + data_config + condition. Missing debug.

-- llmChat: was missing data_config, condition, debug.
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmChat'), get_field_id('data_config'), '',
'The field `dataConfig` allows to configure data sources for the component. The retrieved values can be referenced inside `conversation_context` and other markdown fields with the standard `{{field_name}}` interpolation. Useful for surfacing per-user state (memory, profile, last submission) into the AI system prompt without writing PHP.'),
(get_style_id('llmChat'), get_field_id('condition'), NULL,
'Visibility condition. JSON expression evaluated by the SelfHelp condition engine. When the condition is false the chat is not rendered at all (no React mount, no API calls).'),
(get_style_id('llmChat'), get_field_id('debug'), '0',
'Enable to print debug information for this style (rendered context, resolved fields, raw config) in the editor. Has no effect on production output.');

-- llmFormRecord: was missing debug.
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmFormRecord'), get_field_id('debug'), '0',
'Enable to print debug information for this style (rendered context, resolved fields, raw config) in the editor. Has no effect on production output.');

-- llmFormLog: was missing debug.
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmFormLog'), get_field_id('debug'), '0',
'Enable to print debug information for this style (rendered context, resolved fields, raw config) in the editor. Has no effect on production output.');

-- llmResponse already exposes all five (registered in v1.0.0). No-op
-- INSERT IGNORE rows are intentionally omitted for clarity.

-- =====================================================
-- 5. UNIFIED CHAT APPEARANCE FIELD (replaces llm_chat_colors)
-- =====================================================
-- v1.3.0 collapses the legacy `llm_chat_colors` JSON field (and the
-- short-lived `llm_chat_icons` field that was prototyped earlier in
-- this branch) into a single `llm_chat_appearance` field. The new
-- shape covers everything authors used to configure on either side
-- in one place: bubble colours, web (FontAwesome) icons, mobile
-- (Ionic) icons, and custom avatar images per role.
--
-- Per the v1.3.0 spec, no backward-compatibility shim is provided.
-- The two old fields are dropped from `fields` and `styles_fields`,
-- which means any author who customised `llm_chat_colors` on a
-- pre-v1.3.0 install will lose those customisations. They can paste
-- the equivalent JSON into the new field — the `bg` / `text` /
-- `border` keys are unchanged.
--
-- The default value below is stored on the styles_fields row so the
-- chat looks polished even when the author never opens the field.
-- Both PHP (`LlmChatModel::getChatAppearance()`) and the React UI
-- merge the saved JSON over this same default tree, so partial
-- overrides ("only change the user bubble background") work as
-- expected.
--
-- Schema (per side):
--   bg          (#hex)   bubble background colour
--   text        (#hex)   bubble text colour
--   border      (#hex)   accent border colour (left rail on AI, right on user)
--   icon        (string) FontAwesome class (web) — e.g. "fa-user", "fa-robot"
--   iconMobile  (string) Ionic icon name (mobile) — e.g. "person-circle"
--   iconImage   (string) Custom avatar URL — wins over icon/iconMobile on
--                        BOTH platforms when set. Relative paths starting
--                        with "/" are normalised against BASE_PATH server
--                        side; absolute URLs / data:/blob: pass through.
--
-- Resolution priority (per side, both web and mobile):
--   1. iconImage (rendered as <img>, wins everywhere)
--   2. icon         on web → <i className="fas {icon}">
--      iconMobile   on mobile → <ion-icon name="{iconMobile}">
--   3. If FontAwesome is missing at runtime, the React layer falls
--      back to a coloured letter avatar so the layout never breaks.
--
-- Interpolation: `StyleModel::get_db_field()` runs
-- `replace_calced_values()` on the value, so `{{user_avatar}}` (and
-- any other placeholder backed by a `dataConfig` source) expands
-- inline — useful for per-user dynamic avatars.

-- 5a. Drop both legacy fields (and their styles_fields wiring).
--     `llm_chat_icons` only ever existed in pre-release v1.3.0 builds
--     of this branch, so on production installs the IGNORE is the
--     no-op. `llm_chat_colors` shipped in v1.0.0; we delete it here.
DELETE FROM `styles_fields`
 WHERE id_fields IN (SELECT id FROM `fields` WHERE name IN ('llm_chat_colors', 'llm_chat_icons'));

DELETE FROM `fields` WHERE name IN ('llm_chat_colors', 'llm_chat_icons');

-- 5b. Register the unified field.
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`)
VALUES (NULL, 'llm_chat_appearance', get_field_type_id('json'), '0');

-- 5c. Link to llmChat with a fully-populated default + paste-ready help.
--     The default is intentionally a complete tree so the chat looks
--     polished out of the box. The help text contains a JSON snippet
--     the author can copy verbatim into the field for customisation.
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmChat'), get_field_id('llm_chat_appearance'),
 '{\n  "user": {\n    "bg": "#DCF8C6",\n    "text": "#1b5e20",\n    "border": "#a5d6a7",\n    "icon": "fa-user",\n    "iconMobile": "person-circle",\n    "iconImage": ""\n  },\n  "ai": {\n    "bg": "#F3E5F5",\n    "text": "#4a148c",\n    "border": "#ce93d8",\n    "icon": "fa-robot",\n    "iconMobile": "chatbubble-ellipses",\n    "iconImage": ""\n  }\n}',
 'Visual appearance of the user and AI chat bubbles. Each side accepts:\n\n* `bg` — bubble background colour (hex, rgb(), etc.)\n* `text` — text colour\n* `border` — accent border colour (drawn as a left rail on AI bubbles, right rail on user bubbles)\n* `icon` — FontAwesome class for **web** (e.g. `fa-user`, `fa-robot`, `fa-comments`). Both `fa-` shorthand and full `fas fa-foo` syntax are accepted.\n* `iconMobile` — Ionic icon name for **mobile** (e.g. `person-circle`, `chatbubble-ellipses`). See https://ionic.io/ionicons.\n* `iconImage` — Custom avatar URL. **Wins over both `icon` and `iconMobile` on every platform when set.** Absolute URLs (https://…, data:, blob:) pass through verbatim. Paths starting with `/` are normalised against the SelfHelp `BASE_PATH` server-side, so `/assets/coach.png` works on both root and sub-directory installs.\n\n`{{interpolation}}` works inside any value (e.g. `"iconImage": "{{user_avatar}}"`) and is resolved from `dataConfig` before path normalisation, so the URL can come from a per-user source. Partial overrides are fine — anything you omit falls back to the default below.\n\nFontAwesome availability is detected at runtime; if the page does not load FontAwesome, the chat falls back to a coloured letter avatar so the layout never breaks. On mobile, `iconMobile` is rendered through Ionic — if you supply a custom `iconImage` it works on every platform without the FA / Ionic split.\n\nPaste-ready example (this is the field default — tweak any subset):\n\n```json\n{\n  "user": {\n    "bg": "#DCF8C6",\n    "text": "#1b5e20",\n    "border": "#a5d6a7",\n    "icon": "fa-user",\n    "iconMobile": "person-circle",\n    "iconImage": ""\n  },\n  "ai": {\n    "bg": "#F3E5F5",\n    "text": "#4a148c",\n    "border": "#ce93d8",\n    "icon": "fa-robot",\n    "iconMobile": "chatbubble-ellipses",\n    "iconImage": "/assets/avatars/coach.png"\n  }\n}\n```');

COMMIT;
