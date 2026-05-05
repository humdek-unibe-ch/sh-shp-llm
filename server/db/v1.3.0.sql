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

COMMIT;
