-- =====================================================
-- LLM Plugin v1.6.0 - llmFormLogList interview style +
-- shared producer-neutral conversation state contract +
-- global "use the participant's language" switch
-- =====================================================
--
-- This migration:
--   1. Registers the `llmFormLogList` style in the Form group and clones
--      every installed `formUserInputLogList` styles_fields row
--      (default/help/disabled/hidden) so the plugin style keeps full core
--      list parity. Only interview-specific help text is overridden.
--      Requires SelfHelp core v7.12.0 (`formUserInputLogList`) and prior
--      plugin migrations (run in order).
--   2. Reuses llm_model / llm_temperature / llm_max_tokens /
--      llm_reasoning_effort / llm_memory_rule_ids on the new style.
--   3. Registers the interview fields: translated `llm_interview_context`
--      prompt (field type `llm_prompt`), history/progress/retention/
--      confirmation/manual-edit/hidden-write-allowlist/chat-position
--      options, the participant-facing translatable UI labels (single
--      labels + the translatable `llm_interview_labels` JSON for the
--      remaining strings), the core `extra_params` URL-parameter
--      declaration with the interview help (the URL parameters partition
--      the unsaved drafts and pin same-named form fields)
--      and the shared llmChat fields `llm_chat_appearance` (bubble
--      colours/icons) and `enable_speech_to_text` / `speech_to_text_model`
--      (Whisper microphone input) on the new style.
--   4. Registers the `form_interview` conversation source and the generic
--      conversation columns `id_dataRows`, `completed_at`, `context_key`,
--      `state` on llmConversations plus scoped lookup indexes. These are
--      producer-neutral: no interview-specific columns, no backfill,
--      existing producers/reads unchanged.
--   5. Adds `floating_shortcuts_hide_delay` (seconds, default 2) on llmChat
--      for how long the floating shortcut tray stays visible after mouse
--      leave / blur.
--   6. Adds `always_start_new_conversation` on llmChat (default off) and
--      refreshes the help of the released `llm_chat_shortcuts` field so
--      clicks prefill the composer instead of auto-sending.
--   7. Adds the checkbox `llm_use_user_language` (default on) to llmChat,
--      llmFormRecord, llmFormLog and llmFormLogList: when ticked (default),
--      the participant's selected system language is appended to the
--      prompt as a language rule - matching the always-on 1.5.0 behaviour.
--      When unticked, only the author's context decides the language.
--   8. Memory rule picker UX:
--      - Converts llmChat `memory_rule_ids` from free-text to the shared
--        `select-llm-memory-rules` multi-select (lists `llm_chat_form_submit`
--        rules only; values stay comma-separated ids).
--      - Attaches `llm_memory_rule_ids` to core `formUserInputLogList`
--        (SelfHelp 7.12+) so the same form-finish picker works there; the
--        existing FormUserInputModel save/update hooks already cover it
--        because LogList calls parent::save_user_input / update_user_input.
--   9. Adds nullable `prompt_tokens` / `completion_tokens` on llmMessages
--      for new assistant logs (no backfill). `tokens_used` stays the total.
--      Admin conversation monitor reads the split; participant chat does not.
--  10. Bumps the plugin version LAST so a failed DDL statement can never
--      mark v1.6.0 as installed.
--
-- NOTE: Apart from the help refresh of the released `llm_chat_shortcuts`
-- field and the version bump this script only inserts: v1.6.0 was never
-- released, so there is nothing to convert.
-- NOTE: MySQL DDL (CREATE/ALTER/CALL add_table_column) is not rolled back
-- by the surrounding transaction; the script is idempotent so re-running
-- after a partial failure is safe.
-- NOTE: styles, fields, hooks and lookups are cached - clear the SelfHelp
-- cache (Admin > Cache > clear all) after running this migration.
-- =====================================================

START TRANSACTION;

-- =====================================================
-- 1. llmFormLogList STYLE + CORE LOG-LIST FIELD CLONE
-- =====================================================

INSERT IGNORE INTO `styles` (`name`, `id_type`, `id_group`, `description`)
VALUES (
    'llmFormLogList',
    (SELECT id FROM styleType WHERE `name` = 'component'),
    (SELECT id FROM styleGroup WHERE `name` = 'Form'),
    'LLM-guided interview built on formUserInputLogList: keeps the core list/create/edit/delete ecosystem (fields_map, values_map, layouts, quick actions, history_log) while the participant is interviewed against the CMS form children and the draft is only persisted after explicit approval. Requires the LLM plugin.'
);

-- URL parameters: the core field `extra_params` (core v7.12.0, shared with the
-- qualtricsSurvey style) declares the names, with defaults, the style accepts
-- from the URL. Inserted before the clone below so the interview help - what
-- the interview does with the values - wins over the generic core help; the
-- field row is guarded so the plugin stays installable on its own.
INSERT IGNORE INTO `fields` (`name`, `id_type`, `display`)
VALUES ('extra_params', get_field_type_id('text'), 0);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`)
VALUES (
    get_style_id('llmFormLogList'),
    get_field_id('extra_params'),
    '',
    'URL parameters of this interview, as `name=default&name2=default2` (the notation of `extra_params` of the Qualtrics survey, but incoming), for example `module_id=&list_status=active`. Per name the value is the route parameter, else `?name=` of the page URL, else the default; an empty value counts as absent. A default may use `{{...}}` placeholders. Every declared name is always defined (empty if absent): use `{{__module_id__}}` in all fields of this style (`filter`, `llm_interview_context`, titles, `data_config`, `css`) and in its children, and `#module_id` in a `data_config`.\n\n* New entries: an unsaved draft belongs to the exact set of values, frozen into `context_key` when the interview opens, so `?module_id=module_1` and `?module_id=module_2` each resume their own draft. Links that open New / Continue keep the query of the page.\n* Pinned field: a parameter with a value whose name is also a field of this form (for example a hidden `module_id`) fixes that field. It is hidden, neither the coach nor the participant can change it, and a new entry starts with the URL value (a saved entry keeps the value it was saved with).\n\nNames: letters, digits and `_`, starting with a letter. Values: letters, digits, `_`, `.` and `-` (max 64), anything else is ignored. Reserved names: `record`, `sid`, `section_id`, `parent_id`, `relation`, `conversation_id`, `peek`, `revision`, `record_id`, `action`.'
);

-- Clone every installed core LogList styles_fields row so the plugin style
-- stays in lockstep with the core contract (children, form controls, alerts,
-- redirects, ownership, css/css_mobile, condition, data_config,
-- confirmations, list layout/action placement, empty state, maps, filter,
-- debug, history_log, quick actions). A few rows change meaning in the
-- interview style and are inserted with their interview help text (and the
-- workspace card as default presentation) instead of being patched later.
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`)
SELECT
    get_style_id('llmFormLogList'),
    sf.`id_fields`,
    CASE f.`name`
        WHEN 'show_form_in_modal' THEN '0'
        -- Empty = built-in texts of the interview dialog (the core defaults
        -- `Do you want to continue?` / `OK` would override them everywhere).
        WHEN 'confirmation_message' THEN ''
        WHEN 'confirmation_continue' THEN ''
        ELSE sf.`default_value`
    END,
    CASE f.`name`
        WHEN 'show_form_in_modal' THEN 'Where the interview opens, like the core list form.\n\n* Disabled (default): page mode. The workspace (the answers and the chat side by side, see `llm_interview_chat_position`) replaces the list while it is open and the list returns when it is closed.\n* Enabled: a real modal window with a header (`label_new` / `label_edit`) and a close button. The list stays on the page behind it. Closing it keeps the unfinished draft.\n\nEither way the interview is addressed by the URL: `?record=new&sid=<section id>` opens a new entry, `?record=<id>&sid=<section id>` revises a saved one, and the plain page URL only shows the list. Narrow screens stack the panels.'
        WHEN 'label_new' THEN 'Label of the button that starts a new interview, for example `New entry`. It is also the start button of the launcher when there is nothing to continue (see `llm_interview_chat_retention`) and the header of the modal window (see `show_form_in_modal`).'
        WHEN 'label_edit' THEN 'Label of the list action that opens a saved entry in the interview to revise it, for example `Edit`. It is also the header of the modal window (see `show_form_in_modal`).'
        WHEN 'confirmation_title' THEN 'Title of the confirmation dialog shown before saving, for example `Save WOOP?`. Empty = built-in, translated title (`reviewTitleNew` / `reviewTitleEdit` in `llm_interview_labels`). Whether the dialog appears at all is decided by `llm_interview_confirm`.'
        WHEN 'confirmation_message' THEN 'Text shown above the record in the confirmation dialog, for example `Please check your answers before they are saved.` Empty = no extra text. Whether the dialog appears at all is decided by `llm_interview_confirm`.'
        WHEN 'confirmation_continue' THEN 'Text of the button that saves in the confirmation dialog. Empty = the label of the approve button (`llm_interview_approve_label`).'
        WHEN 'label_cancel' THEN 'Text of the button that leaves the confirmation dialog without saving. Empty = `Back` (translatable through `llm_interview_labels`).'
        WHEN 'label' THEN 'Not used by the interview style - the save button is labelled by `llm_interview_approve_label`.'
        WHEN 'type' THEN 'Not used by the interview style - the save button follows the accent colour of the interview (see the `--llm-iv-accent` CSS variable).'
        WHEN 'url_cancel' THEN 'Not used by the interview style - closing the interview returns to the plain page URL.'
        WHEN 'ajax' THEN 'Applies to the list actions (delete and quick actions): checked = they are sent in the background and the list refreshes in place. Saving through the interview is always asynchronous, whatever this says.'
        WHEN 'alert_success' THEN 'Text shown after a successful list action (delete or quick action). After a save through the interview the launcher shows its own note instead (`savedTitle` / `savedText` in `llm_interview_labels`). Leave empty to show no alert.'
        WHEN 'alert_fail' THEN 'Text shown after a failed list action, and added to the error the interview shows when a save fails. Leave empty to use the built-in fallback messages.'
        WHEN 'show_list' THEN 'If enabled (default), the list is shown below the launcher card. Disable it to hide the list: the interview then opens as soon as the page loads.'
        WHEN 'list_layout' THEN 'How the list is rendered: `table` (default) or `cards` (one card per row with the labelled values). Both come with a default look that follows the theme variables of the interview; restyle it with your own CSS (root class `llmFormLogList`).'
        ELSE sf.`help`
    END,
    sf.`disabled`,
    sf.`hidden`
FROM `styles_fields` sf
INNER JOIN `fields` f ON f.`id` = sf.`id_fields`
WHERE sf.`id_styles` = get_style_id('formUserInputLogList');

-- =====================================================
-- 2. REUSED LLM MODEL + MEMORY FIELDS
-- =====================================================

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmFormLogList'), get_field_id('llm_model'), '', 'LLM model used for the interview. Leave empty to use the global default from the LLM module config.'),
(get_style_id('llmFormLogList'), get_field_id('llm_temperature'), '1', 'Controls randomness (0-2). Lower values produce more deterministic interview answers.'),
(get_style_id('llmFormLogList'), get_field_id('llm_max_tokens'), '2048', 'Maximum tokens for each interview LLM response.'),
(get_style_id('llmFormLogList'), get_field_id('llm_reasoning_effort'), 'default', 'Reasoning / thinking effort from lookups (`llmReasoningEffort`). `default` = provider default.'),
(get_style_id('llmFormLogList'), get_field_id('llm_memory_rule_ids'), '', 'Memory rules to run in the background after an approved interview save finishes the form. Leave empty to disable. Form actions with job type `llm_memory_update` still run in addition.');

-- =====================================================
-- 3. INTERVIEW FIELDS
-- =====================================================

-- Conversation source + interview option lookups. The `lookup_value` is
-- what the CMS dropdown shows, so it describes the behaviour in words.
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('llmConversationSourceType', 'llm_conv_source_form_interview', 'form_interview', 'Conversation produced by an llmFormLogList guided form interview. Hidden from the chat sidebar.'),
('llmInterviewProgressLayouts', 'stepper', 'Steps (numbered, default)', 'Numbered steps connected by a line, with the collected values.'),
('llmInterviewProgressLayouts', 'cards', 'Cards (responsive grid)', 'One card per question in a responsive multi-column grid (e.g. 2×2 for four questions); more questions wrap into extra rows.'),
('llmInterviewProgressLayouts', 'list', 'Compact list', 'Plain rows with the status and value of each question.'),
('llmInterviewProgressLayouts', 'none', 'Chat only (no progress, no answers)', 'No progress overview and no collected answers; hand-editable fields (if enabled) are listed without steps. Without those the chat is shown alone and carries the approve button.'),
('llmInterviewRetentionPolicies', 'continue', 'Continue the unfinished chat (default)', 'Opening the interview resumes the unfinished draft of the same scope.'),
('llmInterviewRetentionPolicies', 'new_on_open', 'Fresh chat every time it is opened', 'Every explicit open starts a new chat; an older unfinished draft is discarded.'),
('llmInterviewRetentionPolicies', 'new_after_save', 'Continue until saved, then fresh', 'Unfinished drafts are resumed; after a successful save the next open starts a new chat.'),
('llmInterviewChatPositions', 'right', 'Right of the answers (default)', 'Chat panel to the right of the answers.'),
('llmInterviewChatPositions', 'left', 'Left of the answers', 'Chat panel to the left of the answers.'),
('llmInterviewChatPositions', 'top', 'Above the answers', 'Chat panel above the answers.'),
('llmInterviewChatPositions', 'bottom', 'Below the answers', 'Chat panel below the answers.'),
('llmInterviewConfirmModes', 'edit', 'Confirm when editing (default)', 'Saving a revised entry first shows what changed, before and after. New entries are saved with one click.'),
('llmInterviewConfirmModes', 'always', 'Confirm when creating and editing', 'New entries first show the record that will be saved, revised entries show what changed.'),
('llmInterviewConfirmModes', 'never', 'No confirmation', 'The approve button saves at once, when creating and when editing.');

-- Select field types rendered by LlmHooks::outputFieldLlmInterviewSelect*
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES
(NULL, 'select-llm-interview-progress-layout', '15'),
(NULL, 'select-llm-interview-retention', '15'),
(NULL, 'select-llm-interview-chat-position', '15'),
(NULL, 'select-llm-interview-confirm', '15');

-- Interview configuration fields (internal) + participant-facing labels
-- (display=1 so they are translatable per language like other UI labels)
-- + the global language switch (internal, language neutral).
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'llm_interview_context', get_field_type_id('llm_prompt'), '1'),
(NULL, 'llm_interview_include_history', get_field_type_id('checkbox'), '0'),
(NULL, 'llm_interview_progress_layout', get_field_type_id('select-llm-interview-progress-layout'), '0'),
(NULL, 'llm_interview_chat_retention', get_field_type_id('select-llm-interview-retention'), '0'),
(NULL, 'llm_interview_confirm', get_field_type_id('select-llm-interview-confirm'), '0'),
(NULL, 'llm_interview_allow_manual_edit', get_field_type_id('checkbox'), '0'),
(NULL, 'llm_interview_writable_hidden_fields', get_field_type_id('json'), '0'),
(NULL, 'llm_interview_chat_position', get_field_type_id('select-llm-interview-chat-position'), '0'),
(NULL, 'llm_interview_title', get_field_type_id('text'), '1'),
(NULL, 'llm_interview_subtitle', get_field_type_id('text'), '1'),
(NULL, 'llm_interview_input_placeholder', get_field_type_id('text'), '1'),
(NULL, 'llm_interview_send_label', get_field_type_id('text'), '1'),
(NULL, 'llm_interview_approve_label', get_field_type_id('text'), '1'),
(NULL, 'llm_interview_labels', get_field_type_id('json'), '1'),
(NULL, 'llm_use_user_language', get_field_type_id('checkbox'), '0');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmFormLogList'), get_field_id('llm_interview_context'), '', 'Your instructions for the coach: domain knowledge, tone, what to ask and what to leave out. Edited and versioned like every `llm_prompt` field (Prompt Lab).\n\nThe basics need no text here: the coach interviews the participant question by question, offers quick replies for closed questions and, when a saved entry is edited, starts by asking what should change. It never saves anything itself - saving is always the participant clicking the approve button.'),
(get_style_id('llmFormLogList'), get_field_id('llm_interview_include_history'), '0', 'When enabled together with `history_log`, the coach also sees a short summary of the last three saved versions of the entry that is being edited, so it can refer to earlier answers. Off by default.'),
(get_style_id('llmFormLogList'), get_field_id('llm_interview_progress_layout'), 'stepper', 'How the answers panel lists the questions, with the answer collected so far (the panel grows with its content and highlights the question the coach is asking):\n\n* `stepper` (default) - numbered steps connected by a line\n* `cards` - a board: title with the state of the entry, step tracker and one card per question (four questions read as a 2×2). Columns: CSS `--llm-iv-cards-cols` (default 2; one column on narrow screens)\n* `list` - compact rows\n* `none` - no progress and no collected answers. Without hand-editable fields (`llm_interview_allow_manual_edit`) the chat is shown alone and the approve button sits inside it; with them only those fields are listed.\n\nChat width beside the answers: CSS `--llm-iv-chat-width` on the section (default about a slim sidebar).'),
(get_style_id('llmFormLogList'), get_field_id('llm_interview_chat_retention'), 'continue', 'What happens when the participant opens the interview again:\n\n* `continue` (default) - resume the unfinished chat. Opening a saved entry resumes its chat as well.\n* `new_after_save` - resume an unfinished chat, but start a fresh one after the entry was saved.\n* `new_on_open` - always start a fresh chat; an older unfinished draft is discarded. A page reload while the chat is open keeps it.\n\nThe start button shows `label_new` for a fresh chat and the translated `resume` text (default `Continue`) only when an unfinished draft exists.'),
(get_style_id('llmFormLogList'), get_field_id('llm_interview_confirm'), 'edit', 'Confirmation before the entry is saved (the participant pressed the approve button):\n\n* `edit` (default) - only when a saved entry is revised: a dialog lists what changed, before and after\n* `always` - also for new entries: a dialog shows the record that will be saved\n* `never` - no confirmation, the approve button saves at once\n\nThe texts of the dialog come from `confirmation_title`, `confirmation_message`, `confirmation_continue` and `label_cancel`; empty = built-in, translated defaults.'),
(get_style_id('llmFormLogList'), get_field_id('llm_interview_allow_manual_edit'), '0', 'When enabled, participants may also edit the supported native form controls next to the chat. The fields sit in the answers panel under their question; the panel grows with their content. Manual edits share the same draft and approval path; nothing is saved before approval. Off by default.'),
(get_style_id('llmFormLogList'), get_field_id('llm_interview_writable_hidden_fields'), '[]', 'JSON array of ordinary hidden form field names the coach may update during the interview (explicit allowlist). Reserved/system keys, fields excluded by a condition and unknown names are always rejected.\n\nExample:\n\n```json\n[\n  "is_done_flag"\n]\n```'),
(get_style_id('llmFormLogList'), get_field_id('llm_interview_chat_position'), 'right', 'Where the chat sits next to the answers: `right` (default), `left`, `top` or `bottom`. Applies to the page and the modal window (see `show_form_in_modal`); narrow screens stack the panels. For `right`/`left` the answers take the free width and the chat stays a sidebar; override width with CSS `--llm-iv-chat-width` on the section (e.g. `18rem` slim, `36rem` or `40%` wider).'),
(get_style_id('llmFormLogList'), get_field_id('llm_interview_title'), '', 'Optional title of the chat header, for example the coach name. Supports `{{field}}` interpolation.'),
(get_style_id('llmFormLogList'), get_field_id('llm_interview_subtitle'), '', 'Optional line under the title, for example `Your personal plan for {{module_title}}`. Supports `{{field}}` interpolation.'),
(get_style_id('llmFormLogList'), get_field_id('llm_interview_input_placeholder'), 'Type your answer...', 'Placeholder of the interview message input.'),
(get_style_id('llmFormLogList'), get_field_id('llm_interview_send_label'), 'Send', 'Label of the interview send button (tooltip and screen readers).'),
(get_style_id('llmFormLogList'), get_field_id('llm_interview_approve_label'), 'Approve & Save', 'Label of the button that saves the collected answers as a form record. Saving always requires this explicit click; the coach refers to this exact label.'),
(get_style_id('llmFormLogList'), get_field_id('llm_interview_labels'), '', 'Optional JSON object that overrides the built-in participant-facing texts. English, German and French are built in; other languages use English. Translatable per language. The start button uses the `label_new` field.\n\nKeys:\n\n* launcher: `launcherIcon` (FontAwesome class), `launcherTitle`, `launcherText`, `resumeText` (`{done}` `{total}`), `resume`, `restart`, `restartConfirm`\n* workspace: `minimize`, `close`, `back`, `coachTitle`, `coachStatus`, `thinking`, `choose`, `readyToSave`, `editTitle` (modal title while a record is edited; default the `label_edit` field)\n* answers: `collectedEyebrow`, `collectedTitle`, `progressLabel`, `statusDraft`, `statusReady`, `statusSaved`, `collectedHint`, `editingHint`, `editedBadge`, `valueBefore`, `valueAfter`, `valueEmpty`, `notAnswered`, `required`, `stepDone`, `stepActive`, `stepPending`, `approveHint`, `noChanges`, `unsupportedFiles` (`{fields}`)\n* save dialog: `reviewTitleNew`, `reviewTitleEdit`, `reviewValues`, `reviewChanges`, `reviewUnchanged` (`{count}`), `savedTitle`, `savedText`\n* composer, voice and errors: `clearInput`, `mic`, `micStop`, `micBusy`, `retry`, `errorEmpty`, `errorGeneric`, `errorBusy`, `errorRate`, `errorConflict`, `errorInvalid`, `errorSave`\n\nExample:\n\n```json\n{\n  "launcherTitle": "Your next step",\n  "launcherIcon": "fa-flag",\n  "reviewTitleEdit": "Save your changes?"\n}\n```'),
(get_style_id('llmFormLogList'), get_field_id('llm_chat_appearance'),
 '{\n  "user": {\n    "bg": "#007bff",\n    "text": "#ffffff",\n    "border": "#007bff",\n    "icon": "fa-user",\n    "iconMobile": "person-circle",\n    "iconImage": ""\n  },\n  "ai": {\n    "bg": "#f1f3f5",\n    "text": "#212529",\n    "border": "#dee2e6",\n    "icon": "fa-robot",\n    "iconMobile": "chatbubble-ellipses",\n    "iconImage": ""\n  }\n}',
 'Visual appearance of the interview chat bubbles - the same JSON contract as the llmChat field of the same name. Each side (`user`, `ai`) accepts `bg`, `text`, `border` (accent rail), `icon` (FontAwesome class, for example `fa-user`, `fa-seedling`), `iconMobile` (Ionic name) and `iconImage` (custom avatar URL; wins over the icon classes; paths starting with `/` get the BASE_PATH prefix). Colours are CSS values, so `var(--llm-iv-accent, #0d5c63)` follows the section accent. Partial overrides are merged over the defaults.\n\nExample (the defaults):\n\n```json\n{\n  "user": {\n    "bg": "#007bff",\n    "text": "#ffffff",\n    "border": "#007bff",\n    "icon": "fa-user",\n    "iconMobile": "person-circle",\n    "iconImage": ""\n  },\n  "ai": {\n    "bg": "#f1f3f5",\n    "text": "#212529",\n    "border": "#dee2e6",\n    "icon": "fa-robot",\n    "iconMobile": "chatbubble-ellipses",\n    "iconImage": ""\n  }\n}\n```'),
(get_style_id('llmFormLogList'), get_field_id('enable_speech_to_text'), '0', 'Enable speech-to-text input in the interview composer. When enabled and an audio model is selected, a microphone button appears next to the send button. The recording is transcribed with the selected Whisper model and put into the message box so the participant can review it before sending. Audio is deleted right after transcription; the participant must grant microphone access.'),
(get_style_id('llmFormLogList'), get_field_id('speech_to_text_model'), 'faster-whisper-large-v3', 'Whisper model used for the speech recognition. The microphone only appears when this model is set and speech-to-text is enabled.');

-- Language rule switch on every LLM style that talks to the model.
-- Default ON = pre-1.6.0 always-on participant language. Uncheck to let
-- the author context alone decide.
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmChat'), get_field_id('llm_use_user_language'), '1', 'Language rule. Checked (default): a rule is appended so the model always answers in the language the participant selected in the system (for example `de`, `en`, `fr`). Unchecked: the model answers in the language your context (prompt) is written in.'),
(get_style_id('llmFormRecord'), get_field_id('llm_use_user_language'), '1', 'Language rule. Checked (default): a rule is appended so the model always answers in the language the participant selected in the system (for example `de`, `en`, `fr`). Unchecked: the model answers in the language your context (prompt) is written in.'),
(get_style_id('llmFormLog'), get_field_id('llm_use_user_language'), '1', 'Language rule. Checked (default): a rule is appended so the model always answers in the language the participant selected in the system (for example `de`, `en`, `fr`). Unchecked: the model answers in the language your context (prompt) is written in.'),
(get_style_id('llmFormLogList'), get_field_id('llm_use_user_language'), '1', 'Language rule. Checked (default): a rule is appended so the coach always answers in the language the participant selected in the system (for example `de`, `en`, `fr`). Unchecked: the coach answers in the language your `llm_interview_context` is written in.');

-- CMS selects for the four interview lookup fields (single hook pair; the
-- exec methods dispatch on the concrete field name and field type).
INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES
(
    (SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'),
    'field-llm-interview-selects-edit',
    'Output interview select fields (progress layout, retention, chat position, confirmation) - edit mode',
    'CmsView',
    'create_field_form_item',
    'LlmHooks',
    'outputFieldLlmInterviewSelectEdit',
    5
),
(
    (SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'),
    'field-llm-interview-selects-view',
    'Output interview select fields (progress layout, retention, chat position, confirmation) - view mode',
    'CmsView',
    'create_field_item',
    'LlmHooks',
    'outputFieldLlmInterviewSelectView',
    5
);

-- =====================================================
-- 4. GENERIC CONVERSATION STATE COLUMNS + INDEXES
-- =====================================================
-- Producer-neutral contract shared by every backend producer that writes
-- llmConversations (interviews first; chat/scripts/playground/memory may
-- opt in later):
--   id_dataRows  generic link to a core data row; NULL = unbound/new draft.
--                Indexed without an FK, consistent with the message-level
--                reference: deleting a row must neither delete audit
--                threads nor SET NULL them into resumable new drafts.
--   completed_at when the producer's unit of work completed successfully.
--   context_key  opaque producer-defined scenario identity inside the
--                actor + section + source scope; the storage layer never
--                interprets it.
--   state        JSON envelope {"format_version": 1, "revision": <int>,
--                "payload": <object>}; the source identifies the producer
--                and each producer validates its own payload. Writes go
--                through revision-checked updates
--                (LlmConversationStateService).
CALL add_table_column('llmConversations', 'id_dataRows',
    "INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'Generic link to a core dataRows entry when the producer works with one. NULL = unbound/new draft. No FK: deleting a row must not delete or resurface audit threads.' AFTER `id_llm_conversation_sources`");

CALL add_table_column('llmConversations', 'completed_at',
    "TIMESTAMP NULL DEFAULT NULL COMMENT 'When the producing unit of work completed successfully (e.g. interview approval). Other producers leave it NULL.' AFTER `id_dataRows`");

CALL add_table_column('llmConversations', 'context_key',
    "VARCHAR(191) DEFAULT NULL COMMENT 'Opaque producer-defined scenario identity inside the actor+section+source scope (e.g. canonical interview context fingerprint).' AFTER `completed_at`");

CALL add_table_column('llmConversations', 'state',
    "JSON DEFAULT NULL COMMENT 'Shared envelope {format_version: 1, revision: <int>, payload: <object>}; producer-neutral storage, each producer validates its own payload.' AFTER `context_key`");

-- Scoped lookups: actor + section + source + exact record (edit threads)
-- and actor + section + source + context key (new-draft threads), each
-- covering the completed_at filter used by the active-thread access path.
CALL add_index('llmConversations', 'idx_llm_conv_scope_record',
    'id_users, id_sections, id_llm_conversation_sources, id_dataRows, completed_at');
CALL add_index('llmConversations', 'idx_llm_conv_scope_context',
    'id_users, id_sections, id_llm_conversation_sources, context_key, completed_at');

-- =====================================================
-- 5. floating_shortcuts_hide_delay on llmChat
-- =====================================================

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`)
VALUES (NULL, 'floating_shortcuts_hide_delay', get_field_type_id('number'), '0');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`)
VALUES (
    get_style_id('llmChat'),
    get_field_id('floating_shortcuts_hide_delay'),
    '2',
    'Seconds the floating shortcut tray stays visible after mouse leave / blur (0-60). Default 2. 0 hides immediately.'
);

-- =====================================================
-- 6. always_start_new_conversation on llmChat + shortcut
--    help refresh (prefills composer, does not auto-send)
-- =====================================================

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`)
VALUES (NULL, 'always_start_new_conversation', get_field_type_id('checkbox'), '0');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`)
VALUES (
    get_style_id('llmChat'),
    get_field_id('always_start_new_conversation'),
    '0',
    'When enabled, every page load starts a fresh chat and previous sessions are hidden (no sidebar / session switcher).\n\nWhen disabled, existing sessions are shown if `enable_conversations_list` is enabled, so the participant can continue a previous conversation or start a new one.'
);

-- `llm_chat_shortcuts` exists since v1.4.0: refresh its help (the only UPDATE
-- of this script besides the version bump). Clicks now prefill the composer.
UPDATE styles_fields
SET help = 'Quick-start message shortcuts that appear as pills around the floating chat button. Each shortcut has a `label` (shown on the pill) and a `message` (prefilled into the chat input when clicked). If `message` is empty, the `label` is used as the message. Shortcuts are only shown when `enable_floating_button` is enabled.\n\nFormat (JSON array):\n```json\n[\n  {\n    "label": "Where was that exercise with XY again?",\n    "message": "Where was that exercise with XY again?"\n  }\n]\n```\n\n**Web behaviour:** The tray appears on hover/focus over the floating button. Clicking a shortcut opens the chat and inserts the message into the input so the participant can edit it before sending.\n\n**Mobile behaviour:** First tap on the floating button shows the shortcut tray; tapping a shortcut opens the chat and prefills the input. Tapping the floating button again opens the chat normally.'
WHERE id_styles = get_style_id('llmChat')
  AND id_fields = get_field_id('llm_chat_shortcuts');

-- =====================================================
-- 7. Danger / crisis detection on llmFormLogList
--    (same fields as llmChat; linked here for interviews)
-- =====================================================

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`)
VALUES (
    get_style_id('llmFormLogList'),
    get_field_id('enable_danger_detection'),
    '0',
    'Enable danger word detection for this interview. When enabled, participant messages are scanned for dangerous keywords before the coach answers. If detected:\n\n- The message is not sent to the LLM\n- A safety message is shown\n- The interview conversation is blocked\n- Email notifications are sent to configured addresses\n- The detection is logged for audit\n- The coach context includes critical safety instructions\n\nLLM responses can also mark critical safety concerns and block the conversation after a turn.'
);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`)
VALUES (
    get_style_id('llmFormLogList'),
    get_field_id('danger_keywords'),
    'suicide,selbstmord,kill myself,mich umbringen,self-harm,selbstverletzung,harm myself,mir schaden,end my life,mein leben beenden,overdose,überdosis,kill someone,jemanden töten,harm others,anderen schaden',
    'Comma-separated list of danger keywords. Case-insensitive. Supports multi-language.\n\n**Example:**\nsuicide,selbstmord,kill myself,mich umbringen,self-harm,harm others\n\n**Tips:**\n- Include variations in all languages your study uses\n- Prefer phrases for accuracy (e.g. `kill myself` vs just `kill`)\n- These keywords are also injected into the coach context as critical safety instructions\n- Word-boundary matching reduces false positives (e.g. `skill` will not match `kill`)'
);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`)
VALUES (
    get_style_id('llmFormLogList'),
    get_field_id('danger_notification_emails'),
    '',
    'Email addresses to notify when danger keywords (or critical LLM safety) are detected in this interview.\nOne email per line or separated by semicolons.\n\n**Example:**\nadmin@example.com\nresearcher@example.com\n\n**Note:** If empty, only audit logging occurs (no email notifications). Emails are sent via the SelfHelp job scheduler.'
);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`)
VALUES (
    get_style_id('llmFormLogList'),
    get_field_id('danger_blocked_message'),
    'I noticed some concerning content in your message. While I want to help, I''m not equipped to handle sensitive topics like this.\n\n**Please consider reaching out to:**\n- A trusted friend or family member\n- A mental health professional\n- Crisis hotlines in your area\n\nIf you''re in immediate danger, please contact emergency services.\n\n*Your well-being is important. Take care of yourself.*',
    'Message shown when danger keywords are detected (or when the interview conversation is blocked for safety). Supports markdown. The conversation is blocked and further messages cannot be sent.'
);

-- =====================================================
-- 8. Memory rule picker UX (llmChat + core LogList)
-- =====================================================
-- NOTE: styles, fields and hooks are cached - clear the SelfHelp cache after
-- running this migration.

-- llmChat fallback field: keep the legacy name `memory_rule_ids` (and its
-- stored comma-separated ids) but switch the CMS widget to the shared
-- multi-select. The select-llm-memory-rules hook filters by field name so
-- this lists llm_chat_form_submit rules, not form_action_submit.
UPDATE `fields` f
INNER JOIN `fieldType` ft ON ft.name = 'select-llm-memory-rules'
SET f.`id_type` = ft.id
WHERE f.`name` = 'memory_rule_ids'
  AND (f.`id_type` IS NULL OR f.`id_type` = 0 OR f.`id_type` <> ft.id);

UPDATE `styles_fields`
SET `help` = 'Memory rules to run when a chat form is submitted and data saving is OFF on this section (fallback when the UserInput / form-action pipeline is unavailable). Only rules with source `llm_chat_form_submit` are listed. Leave empty to auto-match all enabled rules of that source. When data saving is ON, use a form action with job type `llm_memory_update` on the chat data table instead — this field is ignored.'
WHERE `id_styles` = get_style_id('llmChat')
  AND `id_fields` = get_field_id('memory_rule_ids');

-- Core formUserInputLogList (SelfHelp 7.12+): same picker as the other form
-- styles. Skipped when the core style is not installed. No new hooks: LogList
-- saves through FormUserInputModel::save_user_input / update_user_input.
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`)
SELECT
    s.`id`,
    get_field_id('llm_memory_rule_ids'),
    '',
    'Memory rules to run in the background after this form is finished. Leave empty to disable. Form actions with job type llm_memory_update still run in addition.'
FROM `styles` s
WHERE s.`name` = 'formUserInputLogList';

-- =====================================================
-- 9. Token usage split on llmMessages (new writes only)
-- =====================================================
-- prompt_tokens / completion_tokens are filled by
-- LlmService::addAssistantMessageFromApiResponse from the normalized
-- provider usage object. Old rows stay NULL; admin UI falls back to
-- tokens_used alone. No backfill from raw_response.

CALL add_table_column(
    'llmMessages',
    'prompt_tokens',
    'INT DEFAULT NULL COMMENT ''Prompt / input tokens from provider usage (new messages only)'''
);

CALL add_table_column(
    'llmMessages',
    'completion_tokens',
    'INT DEFAULT NULL COMMENT ''Completion / output tokens from provider usage (new messages only)'''
);

-- Version bump last: a failed DDL statement above must never mark v1.6.0
-- as installed (DDL commits implicitly, so ordering is the only guard).
UPDATE plugins SET version = 'v1.6.0' WHERE `name` = 'llm';

COMMIT;
