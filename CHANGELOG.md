# Changelog

All notable changes to the **sh-shp-llm** plugin are documented in this file.

## [1.6.0]

The main features of this release are **LLM form interviews**, where a coach
fills in a form in a chat and the record is saved only after the participant
approves it, and a **security overhaul** of the whole plugin. It also brings
chat improvements and token reporting.

### Upgrade notes

- Requires **SelfHelp core v7.12.0**. Apply the core migration
  `44_update_v7.11.0_v7.12.0.sql` first.
- Run `server/db/v1.6.0.sql` once, then clear the SelfHelp cache
  (**Admin > Cache > Clear all**). The migration can be run again safely.
- **API key encryption (recommended):** set `LLM_SECRET_KEY` (at least 16
  characters) as an environment variable or in `globals_untracked.php`. Then
  click **Encrypt stored keys now** on the LLM settings page. Existing plain
  text keys keep working until then. Changing the secret later makes the
  encrypted keys unreadable. See
  [README > API Key Protection](README.md#api-key-protection).
- **Admin console permissions:** the migration grants `update` and `delete`
  on `moduleLlmAdminConsole` to the admin group. Grant them to other
  moderator groups in the CMS.
- **Cross-origin clients:** the chat and form endpoints trust only the exact
  origins listed in core's `CORS_ALLOWED_ORIGINS` (new in core v7.12.0). An
  origin that used to match only partly, such as another port or a
  subdomain, must now be added to the list. See
  [README > CSRF and cross-origin clients](README.md#csrf-and-cross-origin-clients).
- The `llm*` tables must use InnoDB so that a failed interview approval is
  rolled back completely. See [README > Database engine](README.md#database-engine).

### Added

- **LLM form interviews (`llmFormLogList`).** A new style that extends the
  core `formUserInputLogList`: it keeps the same list, quick actions, history,
  ownership and permissions. A coach asks the form's questions in a chat. The
  answers are kept as a draft and saved as a form record only after the
  participant approves them. Guide:
  [doc/llm-form-interview.md](doc/llm-form-interview.md).
  - **Workspace:** the answers are shown next to the chat, either in the page
    or in a modal window (`show_form_in_modal`). Four answer layouts are
    available (`llm_interview_progress_layout`): `stepper`, `cards` (a
    question board with a status chip and step tracker), `list` and `none`.
    The chat position is set with `llm_interview_chat_position`. The look is
    themed through `--llm-iv-*` CSS variables.
  - **Conversation:** quick replies for closed questions, a marker on the
    question being asked, and edited answers shown next to their previous
    value. An unfinished draft survives a page reload. The workspace is
    linked by URL (`?record=new|<id>&sid=<section>`). Speech-to-text is
    optional; recordings are deleted right after transcription.
  - **Saving:** the save dialog (`llm_interview_confirm`: `edit`, `always`,
    `never`) shows a before/after comparison when a record is edited.
    `llm_interview_chat_retention` controls whether a returning participant
    continues the chat or starts over.
  - **Control over what is written:** hidden, scope-bound and unsupported
    fields can only be written when allowlisted
    (`llm_interview_writable_hidden_fields`). Manual edits require
    `llm_interview_allow_manual_edit`.
  - **Crisis detection:** the same settings as `llmChat`
    (`enable_danger_detection`, keywords, notification emails, blocked
    message). A blocked interview shows the blocked message and locks the
    composer. The transcript is kept for review.
  - **Reuse and URL parameters:** one interview form can be reused on many
    pages through a reference section in a `loop`. URL parameters declared in
    `extra_params` (for example a module code) work as placeholders in every
    field, keep separate drafts per value, and can pin a form field to the
    URL value.
  - The list keeps the core `formUserInputLogList` look and can be themed
    with `--llm-iv-*` variables. Adding `formUserInputLogList-bare` to the
    section's CSS removes the look.
  - Labels in English, German and French (`llm_interview_labels`). The chat
    uses the same appearance settings as `llmChat` (`llm_chat_appearance`).
  - Interview conversations are kept out of the chat sidebar. The admin
    console can filter them by source and record.
- **Prompt Lab: interview profile.** `llm_interview_context` can be tested
  against a read-only preview of the real interview. No participant data,
  approval or memory is written.
- **`llm_use_user_language`** on `llmChat`, `llmFormRecord`, `llmFormLog` and
  `llmFormLogList`. On by default, which keeps the 1.5.0 behavior of
  answering in the participant's language. Turn it off to let the author's
  context decide the language.
- **`llmChat`: `always_start_new_conversation`.** Every page load starts a
  new chat and earlier sessions are hidden.
- **`llmChat`: `floating_shortcuts_hide_delay`** (default 2 seconds, 0-60):
  how long the floating shortcuts stay visible after the mouse leaves them.
- **Memory rules on the core `formUserInputLogList`** (`llm_memory_rule_ids`),
  as on the other form styles since 1.5.0.
- **Memory rules editor:** the "llmChat form submit" source can now be
  chosen, so chat fallback rules no longer have to be created in SQL.
- **Token split:** new messages store prompt and completion tokens separately.
  The admin conversation monitor shows `in · out · total`. Older messages keep
  only the total.
- **Script audit:** each script run records which script and revision it used
  (`script_id`, `script_name`, `script_updated_at`).

### Changed

- **API keys are never sent to a browser.** The settings page and the CMS
  editor show only whether a server has a key, its last four characters and
  where it comes from. Leaving the key field empty keeps the stored key, but
  only while the server's scheme, host and port stay the same, so a stored
  key can never be sent to another host. Keys can be stored encrypted
  (AES-256-GCM) or read from an environment variable (`env:LLM_NAME`).
- **Saving a server takes one click.** **Save** and **Delete** on a server card
  (settings page and CMS editor) store the change immediately, and errors
  show inside the card. The other settings still use **Save Changes**.
- **Settings are validated before they are saved.** One invalid value
  rejects the whole save, with a message for each field. Base URLs must use
  `https` (`http` only for localhost) and must not contain credentials, a
  query or a fragment. An optional host allow-list can be set with
  `LLM_ALLOWED_API_HOSTS`. Temperature, max tokens and timeout are checked
  against their ranges.
- **Floating shortcuts fill in the message** instead of sending it, so the
  participant can edit it before sending
  ([doc/llm-chat-shortcuts.md](doc/llm-chat-shortcuts.md)).
- **The inline `llmChat` looks like the floating chat** (same header and
  scrollbar).
- **`llmChat` `memory_rule_ids` is a multi-select** that lists only the
  "llmChat form submit" rules. Stored values are unchanged.
- **Memory updates are listed under Scheduled Jobs.** The memory rules picked
  on a form, survey or `llmFormLogList` section (`llm_memory_rule_ids`, also
  after an interview approval) and the rules of an `llmChat` form submit
  now run as a core `llm_memory_update` task job that is executed
  immediately, like a form action set to "immediately". Each run therefore
  shows up in Scheduled Jobs and the calendar. Core marks the job done when
  the background worker starts; the worker adds the real result ("LLM
  memory update finished / failed: rule #N, memory key ...") to the job's
  log. Login and profile-name triggers still run directly.
- **Memory rules can also run when an entry is deleted.** New checkbox
  "Also run when an entry is deleted" in the memory rule editor (adds
  `deleted` to the rule's trigger types; off for existing rules). When a
  participant deletes an entry of a form that selects such a rule, the rule
  runs as a scheduled job with the entry's last values, and the memory
  prompt says the entry was deleted. The rule's instructions decide whether
  the memory forgets the entry or keeps it as history; the plugin removes
  nothing on its own. Hook `LlmHooks::onFormDeletedMemoryRules` on
  `FormUserInputModel::delete_user_input` (clear the SelfHelp cache after the
  migration).
- **Context layers combine.** The form, floating and strict-conversation
  modes are applied together, with the strict guardrail first, instead of
  only the first one that is enabled.
- **Response retries are limited.** At most `LLM_RESPONSE_MAX_ATTEMPTS`
  attempts (default 3). Failures that a retry cannot fix (rate limit, bad
  request, rejected credentials) are not retried.
- **Clear errors for participants.** Provider, database and runtime errors
  are logged on the server, and participants see a generic notice. After a
  failed turn the chat shows "no answer" in its error banner instead of the
  turn silently disappearing.
- `globals_untracked.php` is now optional. A fresh checkout, CI and the
  workers start without it. `globals_untracked.default.php` is the template.

### Fixed

- Form actions with `llm_memory_update` on a plain form (run immediately or
  later from the job queue) were dropped without running: core executes jobs
  inside its own database transaction, which the new after-commit queue
  treated as uncommitted. They run again; only updates queued inside an
  interview approval still wait for its commit.
- An `llmChat` section could delete the participant's other conversations by
  id, including a form interview transcript. Deleting is now limited to the
  conversations of that chat section.
- A memory update for a deleted form entry passed `trigger_type=deleted` into
  `UserInput::save_data()`, which marked the memory row itself deleted so it
  looked empty afterwards. Memory rows are always written as live.
- `llmChat` crisis keyword detection now checks messages before they are
  stored or sent to the model, and blocks the conversation on a match.
- Messages sent within the same second could appear out of order.
- `llmChat` treated other POST requests on the same page, such as a CMS save
  or another form, as an empty chat message.
- Stored messages were cut off at the first `{"id":` they contained, which
  truncated pasted JSON and structured answers.
- Prompt Lab versions of scripts and memory rules stayed on "v1 Draft" after
  saving. Versions saved earlier reappear.
- Inline code in chat answers was shown as a code block with a copy button.
- The chat markup contained `node="[object Object]"` attributes.

### Security

- **Admin console:** each action checks its own permission (`select` to
  read, `update` to block or unblock and see sent context, `delete` to
  delete). Changes require POST and a CSRF token, and the audit log names
  the admin who made them. Interview draft data is no longer included in
  conversation reads.
- **CSRF protection everywhere.** Admin pages, settings, Prompt Lab,
  scripts, memory and interview changes always require the session token.
  The chat and form endpoints also accept requests from the same site and
  from the native app, but reject cross-site requests (403).
- **Guests are isolated.** Guests share one account, so a guest could see
  and continue the previous guest's chats. Guest conversations now belong to
  the visitor's browser session, with per-session and site-wide rate limits.
  Guests cannot upload files, use speech input or save form records or
  memories.
- **No unsafe HTML.** Markdown from the model, participants and stored
  context is sanitized before it is shown. Scripts, iframes, forms, event
  handlers and unsafe links are removed. Images written in Markdown are
  still loaded from their host.
- **File access:** participant input can no longer be read as a file path.
  Only real attachments inside the plugin's upload folder are sent to the
  model.
- **Safety assessment:** a response without a safety assessment from the
  model is reported as "not assessed" instead of "safe". Blocked
  conversations stay blocked for every chat action.
- **Prompt Lab access** is decided by the pages that really show a section,
  not by the page id the browser sends. Dataset imports accept only records
  that belong to the prompt's owner.
- **Form endpoints** (`llmFormRecord`, `llmFormLog`) answer only their own
  section, are rate limited, and load exactly the requested record.
- **Speech-to-text** is limited to 6 recordings per minute per user, and the
  audio type is checked from the file content.
- **Input limits** on interview messages and form values.
- **Crisis alert emails** escape the user input they quote. Keyword matching
  handles accents and tolerates typos in phrases ("kill myslef"), while
  single words still match exactly ("skill" never matches "kill").
- **Memory updates run only after the record is saved.** If an interview
  approval or form save fails, no memory is updated, and a failure no longer
  clears the whole SelfHelp cache.
- **Interview locking** no longer blocks core login and profile updates.

### For developers

- New services: `LlmSecretService`, `LlmApiKeysConfigService`,
  `LlmSettingsValidator`, `LlmCsrfService`, `LlmErrorReporter`,
  `LlmTransaction` (transactions and after-commit tasks),
  `LlmFormInterviewService`, `LlmFormSchemaService`,
  `LlmConversationStateService`.
- `tests/LlmConventionsTest.php` fails on direct transaction calls and on
  exception messages written to `error_log()` outside these services.
- The async and memory workers share one bootstrap
  (`llm_worker_bootstrap.php`).
- The shared `MessageInput` has a new `variant="field"` composer.

### Removed

- `LlmAutoStartService` and
  `LlmChatModel::generateContextAwareAutoStartMessage()` (unused since 1.4.1).
- `CallbackLlm` (unused).
- `LlmFileNamingService::buildFileUrl()` and the `url` entry of upload
  payloads (it pointed to an endpoint that does not exist).
- Deprecated wrappers in `globals.php`: `llm_is_vision_model()`,
  `llm_validate_mime_type()`, `llm_get_file_type_category()` and
  `llm_is_non_chat_model()`. Use the `LlmModelCapabilities` methods instead.

## [1.5.0]

### Added

- **Anthropic provider.** Native Messages API support for
  `https://api.anthropic.com/v1` (`AnthropicProvider`): Anthropic auth headers,
  OpenAI-style payload conversion (including images), normalized responses
  (text + thinking), and model listing. Model fetches now use each provider’s
  auth helpers (fixes Anthropic 401 when only Bearer was sent).
- **OpenAI Chat Completions adapter.** `OpenAIProvider` (OpenAI / Azure hosts)
  remaps `max_tokens` → `max_completion_tokens` and applies OpenAI
  `reasoning_effort`. GPUStack and other OpenAI-compatible hosts keep
  `max_tokens`.
- **Reasoning effort.** New CMS / module field `llm_reasoning_effort`
  (`default`, `none`, `minimal`, `low`, `medium`, `high`, `xhigh`, `max`) on
  `llmChat`, `llmFormRecord`, `llmFormLog`, and module defaults. Mapped per
  provider (OpenAI Chat Completions, Anthropic `output_config.effort`; stripped
  for GPUStack).
- **Memory rules directly on forms and surveys.** New optional multi-select CMS
  field `llm_memory_rule_ids` on `formUserInput`, `formUserInputRecord`,
  `formUserInputLog`, `llmFormRecord`, `llmFormLog` and `surveyJS`: pick the
  memory rules that run when the form / survey is finished, without a form
  action. Hooks on `FormUserInputModel::save_user_input` / `update_user_input`
  and `SurveyJSModel::save_survey` (`LlmHooks::onFormSavedMemoryRules` /
  `onSurveySavedMemoryRules`) queue the rules in the existing async memory
  worker after a successful `finished` save of a logged-in user; missing or
  disabled rules are skipped, an empty selection changes nothing, and form
  actions with `llm_memory_update` keep running in addition.
- **Labeled submission context for memory.** New
  `LlmMemorySubmissionContextService` snapshots the submission when it is saved
  (form field labels and readable choice texts; SurveyJS question titles,
  choices, matrices, dynamic panels, comments) and `LlmMemoryUpdateService` adds
  it to the memory prompt as `## Submission Context` (only for
  `form_action_submit` payloads that carry it, so form actions are unchanged).
- **SurveyJS installed later.** The migration wires SurveyJS only when its style
  exists. `server/db/optional/enable_surveyjs_llm_memory_rules.sql` is the
  separate, idempotent snippet to run once when SurveyJS is installed after this
  plugin (clear the SelfHelp cache afterwards, as for the rest of `v1.5.0.sql`).
- **Memory activity feed.** The Memory → Activity tab is now a paginated,
  filterable event log (`memory_activity` action → `LlmMemoryAdminService::
  getActivity`, `LlmMemoryStorageService::getHistoryActivity`). Each event shows
  the participant (name, email, code, id), the rule label and memory key, what
  triggered it (form / survey submit with form name and record, llmChat form
  submit, login, profile name change, or an admin action with the acting
  admin) and the result. Filters: participant (name, email, code or id), rule
  (including manual admin actions without a rule), memory key, trigger, status
  and event date range.
- **Form / survey sections as memory sources.** The Memory → Sources tab and the
  per-rule source counts now include sections that select rules through
  `llm_memory_rule_ids` (page, style and an "Open section" CMS link), not only
  form actions and llmChat fallbacks. Source cards are labelled by category.
- **Vision image support in LLM scripts.** Scripts can now send uploaded form and SurveyJS images (including base64 data URLs and file paths) to vision-capable models, automatically extracting EXIF metadata (capture time, camera, GPS) into the prompt context.
- **Labeled image collage mode for scripts (`combine_images`).** Optional setting in Scripts Manager to stitch multiple images (up to 12) into a single high-resolution, labeled `Image N` grid with a matching prompt and EXIF legend.
- **Anthropic & OpenAI provider support.** Added native Anthropic Messages API support (`api.anthropic.com`) alongside OpenAI Chat Completions compatibility (`max_completion_tokens` and reasoning effort mapping).
- **Reasoning effort configuration (`llm_reasoning_effort`).** Configurable reasoning effort level (`default`, `none`, `minimal`, `low`, `medium`, `high`, `xhigh`, `max`) in module defaults and on `llmChat`, `llmFormRecord`, and `llmFormLog` styles.
- **Direct memory rules on forms and surveys (`llm_memory_rule_ids`).** Select memory rules directly on `formUserInput`, `formUserInputRecord`, `formUserInputLog`, `llmFormRecord`, `llmFormLog`, and `surveyJS` sections to run automatically on submission with full field/question label context, without needing a separate form action.
- **Memory Activity log & source tracking.** Filterable, paginated activity feed in the Memory admin console (by participant, rule, memory key, trigger, status, and date range), plus visibility for form and survey sections bound via `llm_memory_rule_ids`.

### Changed

- **Default model updated to `gpt-oss-120b`.** Module-level defaults for model, temperature, max tokens, and reasoning effort now propagate to `llmChat`, `llmFormRecord`, and `llmFormLog` style defaults.
- **Strict section model binding in chat.** Interactive `llmChat` sections always use the model configured on the section and start a fresh conversation when the section model changes.
- **Image attachment limit per message (`LLM_MAX_FILES_PER_MESSAGE`).** Set to 4 to match vision provider limits when collage mode is disabled.

## [1.4.1]

### Added

- **Audio embeds in chat responses.** Markdown media rendering now plays
  audio (`.mp3`, `.wav`, `.ogg`, `.oga`, `.m4a`, `.aac`, `.flac`) the same way
  images and videos already work:
  - Bare audio URL on its own line → native `<audio controls>`
  - `![audio Caption](/assets/file.mp3)` image syntax with an `audio` alt marker
  - Structured `media[]` items with `type: "audio"` in JSON responses
  - Updated `core.chat.media_rendering_instructions` prompt asset and docs
  - Bare `.ogg` is treated as audio; use `.ogv` for Ogg video
- **Reliable local media playback.** Chat media paths now honour `BASE_PATH`
  (`/selfhelp` or `/`), bare `/assets/...` video/audio lines are promoted to
  markdown players, and the media system prompt requires
  `![video](...)` / `![audio](...)` so the model returns renderable markup.

### Fixed

- **Auto-start on new chat.** With `auto_start_conversation` enabled, creating a
  new conversation from the sidebar now seeds the prepared opening assistant
  message (or form-mode first form). Previously auto-start only ran when the
  user had *zero* conversations, so "New Chat" left an empty thread.
- **Auto-start reliability.** `start_auto_conversation` now returns the created
  conversation and messages directly, rethrows backend failures instead of
  reporting false success, and accepts an optional `conversation_id` to seed an
  existing empty conversation.
- **Auto-start message stays as authored.** Opening messages now use the CMS
  `auto_start_message` field only. Conversation context is still sent as system
  context for replies, but it no longer rewrites/mixes the greeting text.
  `LlmAutoStartService` is retained (documented, unused by the live path) so
  context-mixed greetings can be re-enabled later without rewriting topic
  extraction.

## [1.4.0] - 2026-06-03

### Added

- **Floating chat shortcuts.** A new translatable `llm_chat_shortcuts` JSON field
  on the `llmChat` style allows authors to configure quick-start message shortcuts
  that appear as pills around the floating chat button.
  - When floating chat is enabled and shortcuts are configured:
    - **Web:** Shortcut pills appear on hover/focus over the floating action button.
      Clicking a shortcut opens the chat panel and sends the configured message
      automatically through the existing chat send flow.
    - **Mobile:** First tap on the floating button shows the shortcut tray (if
      shortcuts are configured). Tapping a shortcut opens the chat and sends the
      message. Tapping the floating button again when the tray is open opens the
      chat normally.
  - The field accepts an array of shortcut objects with `label` (shown on the pill)
    and `message` (sent when clicked). If `message` is empty or missing, the
    `label` is used as the message. Entries without usable label text are ignored.
  - The field is translatable (`display=1`) like `floating_button_label`, so
    authors can configure different shortcuts per language in the CMS.
  - Default value is an empty array, meaning no shortcuts are shown.
  - The React layer normalises the JSON in `LlmChatModel::getFloatingShortcuts()`
    and exposes it under `config.floatingShortcuts`. `FloatingChat` renders the
    tray on hover/focus, and `LlmChat` accepts an optional `initialMessage` prop
    to send the shortcut message exactly once on mount.
  - **No new backend logging type, endpoint, or conversation source is needed.**
    Shortcut sends use the existing message endpoint and are indistinguishable
    from manual user messages in the conversation history.
  - Existing `enable_hint_suggestions` behavior is unchanged — model-generated
    suggestions remain separate from author-configured shortcuts.

## [1.3.2] - 2026-05-20

### Added

- **Source record tracking for generated LLM script rows.** When an
  `llm_script` job is triggered from a record-backed form action, the
  generated output row now stores `linked_record_id` with the source
  record id. The link is propagated through both synchronous and async
  worker execution paths, so later processing can trace which record
  produced a generated row. Non-record/manual runs keep the previous
  behavior and do not write the field.

## [1.3.1] - 2026-05-20

### Fixed

- **Prompt Lab editor + playground stability.** The LLM Script Prompt and
  Test Variables Monaco editors no longer remount on every keystroke
  (parent callback identity was forcing `monaco.editor.create` to fire on
  every render, blurring the field and dropping the caret). The shared
  `PromptEditor` now keeps the latest `onChange` in a ref and only
  rebuilds the editor when true structural inputs change (`editorMode`,
  `language`, fallback availability).
- **Playground result panel no longer disappears after a run completes.**
  `PromptPlaygroundModal` previously re-initialized its entire local
  state whenever the parent rerendered (e.g. after `onRunComplete`
  captured the run reference), which immediately cleared the freshly
  set `result`. Reset now only fires on a real open transition of the
  modal; the previous run stays visible while a new one is in flight
  and is replaced atomically.
- **Stable parent callbacks for the playground modal.** `ScriptsManager`,
  `PromptFieldApp`, and `MemoryRulesEditorApp` now pass memoized
  `resolveRuntimeOverrides` / `resolveInitialVariables` callbacks, so
  unstable arrow identities never trigger spurious modal
  reinitialization or downstream Monaco recreation.
- **Result panel renders gracefully on empty/error responses.**
  `PromptResultPanel` now coerces non-string `raw_content` into a
  JSON-formatted markdown block, prefers `display_content` when
  populated, falls back to `raw_content` when not, and shows a clear
  "No content returned from the model" hint instead of a blank panel
  when both are empty.

## [1.3.0] - 2026-05-05

### Added

- **Unified `llm_chat_appearance` JSON field on the `llmChat` style.**
  Replaces the legacy `llm_chat_colors` field (and the short-lived
  `llm_chat_icons` field that was prototyped earlier in this release).
  One field now controls the bubble's full visual identity per side
  (`user` / `ai`):
  - `bg`, `text`, `border` — bubble palette (unchanged keys from the
    previous `llm_chat_colors` field).
  - `icon` — FontAwesome class for the **web** avatar (e.g. `fa-user`,
    `fa-robot`). Both `fa-user` shorthand and full `fas fa-user` syntax
    are accepted.
  - `iconMobile` — Ionic icon name for the **mobile** avatar (e.g.
    `person-circle`, `chatbubble-ellipses`).
  - `iconImage` — custom avatar URL/path. **Wins over `icon` and
    `iconMobile` on every platform when set.** Absolute URLs / `data:`
    / `blob:` pass through; paths starting with `/` are normalised
    against `BASE_PATH` server-side; `{{interpolation}}` resolves
    before normalisation so per-user dynamic avatars work without any
    conditional logic.
  - The default value stored on the `styles_fields` row is a complete
    tree, so the chat looks polished even when authors never open the
    field. The PHP side merges author overrides on top of this floor,
    so partial JSON like `{"user":{"bg":"#fff"}}` still produces a
    fully-resolved config for the React + mobile layers.
  - Help text on the field includes a paste-ready JSON example with
    every key populated; authors can copy-paste and tweak rather than
    typing the schema from scratch.
  - **Clever FontAwesome fallback.** The React layer probes for
    FontAwesome at first mount (hidden `<i className="fas fa-check">`,
    `getComputedStyle().fontFamily` check, cached on `window`); if the
    font is missing AND no `iconImage` is configured, the renderer
    falls back to a coloured letter avatar (`U` / `AI`) so the bubble
    layout never breaks. Pages that load FontAwesome behave exactly
    as before.
  - Mobile parity is preserved by `LlmChatView::output_content_mobile()`
    serialising the merged tree under `style.llm_chat_appearance.content`;
    the SelfHelp mobile app v4.0.4+ reads it via
    `LlmChatStyleComponent.loadChatAppearance()` and applies the same
    image > Ionic-icon > letter-fallback resolution priority.
  - **No backward-compat shim, per the v1.3.0 spec.** The legacy
    `llm_chat_colors` field is dropped from `fields` and
    `styles_fields`; authors who customised colours on a pre-v1.3.0
    install need to re-paste the JSON into `llm_chat_appearance`. The
    `bg` / `text` / `border` keys are unchanged.
  - See `doc/chat-appearance.md` for the full reference, mobile
    rendering rules, interpolation examples and migration notes.
- **`enable_hint_suggestions` toggle on the `llmChat` style** (defaults to
  enabled). When disabled, two things happen in lockstep:
  1. The React `StructuredResponseRenderer` skips the entire `next_step`
     block, so the AI's quick-reply buttons never render — even on
     historical/cached responses.
  2. The backend appends the new
     `core.response.suppress_suggestions` prompt asset to the system
     context, instructing the model to leave `content.suggestions` empty
     so we do not pay for unused output tokens.

  Use this for guided / linear flows where free-form replies feel more
  natural than "tap one of the buttons below" prompts. See
  `doc/prompt-assets.md` for the full template reference.
- **`llmConversationSourceType` lookup family + new
  `llmConversations.id_llm_conversation_sources` foreign-key column.** Every
  back-end producer that writes into `llmConversations` now tags its rows
  with one of: `chat`, `playground`, `builder`, `memory`, `dataset_eval`,
  `form`, `script`, `dataset_import`. The chat sidebar
  (`LlmService::getUserConversations()`) now filters to `chat` or `NULL`
  (legacy rows), so Prompt Lab / Builder / Memory conversations no longer
  bleed into the user-facing chat list. Existing rows are intentionally
  not backfilled — they keep `NULL` and remain visible to chat (preserving
  legacy behaviour). New rows written by v1.3.0 code paths populate the
  column explicitly via the producer call sites
  (`LlmPromptPlaygroundService`, `LlmPromptBuilderService`,
  `LlmMemoryUpdateService`, `LlmDatasetAiImportParserService`).
- **`assets/prompts/core/response/suppress-suggestions.md`** prompt asset,
  registered under `core.response.suppress_suggestions`. Loaded only when
  hint suggestions are disabled on a chat.
- **Comprehensive `doc/prompt-assets.md` audit.** Every one of the 21
  registered prompt assets is documented with its purpose, expected
  placeholders, call sites, and reviewer guidance — including notes on
  which fields the schema enforces vs. which the React layer renames.

### Changed

- **Default style fields backfilled on every LLM-owned style.** SelfHelp
  styles are expected to expose `data_config`, `condition`, `debug`, `css`,
  and `css_mobile` for a uniform authoring experience. The audit found:
  - `llmChat` (v1.0.0) had `css` + `css_mobile`. The v1.3.0 migration adds
    `data_config`, `condition`, `debug`.
  - `llmFormRecord` (v1.1.0) had `css`, `css_mobile`, `data_config`,
    `condition`. The v1.3.0 migration adds `debug`.
  - `llmFormLog` (v1.1.0) had `css`, `css_mobile`, `data_config`,
    `condition`. The v1.3.0 migration adds `debug`.
  - `llmResponse` (v1.0.0) already had all five — no change.
- **Admin conversation viewer (`module_llm/conversations`) prefers the
  rich structured renderer.** Until v1.2.x, the admin viewer would
  detect any JSON envelope in a message and fall back to the labeled
  JSON tree (the screenshot the issue report referenced). The renderer
  now evaluates renderability **first**: when a message can be rendered
  as text blocks / forms / media / suggestions, the structured view
  wins. The JSON tree is now reserved exclusively for messages that are
  raw JSON without a renderable schema (debug payloads, malformed
  envelopes, user-side context dumps).
- **`LlmHooks::outputSelectLlmModelField()` and
  `outputSelectAudioModelField()` now defensively include the saved value
  in the dropdown options.** Live deployments occasionally observed the
  saved default model not pre-selecting on reload because
  `LlmService::getAvailableModels()` returned a partial list (timeout,
  upstream API blip, scoping mismatch between `qwen3-vl-8b-instruct` and
  `bfh/qwen3-vl-8b-instruct`). The CMS edit form now appends both the
  normalized and the raw saved identifier as fallback items so the field
  always re-mounts with the previously configured value selected. The
  bug is impossible to reproduce locally; this is a belt-and-braces fix.

### Fixed

- **Prompt Lab conversations leaking into the chat sidebar.** When the
  same user opened the playground (`/admin/module_llm/prompts/...`) for
  a section that also had an `llmChat` style, the playground's
  conversation row would later appear in the chat sidebar tagged with
  the playground section keyword (e.g. `Section 78 conversation_context`).
  With the new `id_llm_conversation_sources` column, the chat query
  filters out everything that is not `chat` or legacy (`NULL`).
- **Schema-instruction template drift.** The
  `core.response.schema.instruction` template's "FIELD SPECIFICATIONS"
  section was double-checked against
  `LlmResponseService::getResponseSchema()`. Field paths agree
  (`content.suggestions` is the canonical location; the React layer
  remaps to `next_step.suggestions` at render time).

### Migration

Apply `server/db/v1.3.0.sql`. The migration is idempotent (uses
`INSERT IGNORE`, `add_table_column`, `add_index`, `add_foreign_key`)
and performs no destructive operations on existing rows.

After applying, **rebuild the React UMD bundles** (`cd react && npm run
build`). The new `enableHintSuggestions` config field flows from
`LlmChatModel::getChatConfig()` through `MessageList` into
`StructuredResponseRenderer`; without the rebuild the React layer simply
defaults to "show suggestions", which is the legacy behaviour and
therefore safe.

## [1.2.1] - 2026-04-28

### Fixed

- **CMS edit mode children placeholder for `llmFormRecord` and `llmFormLog`.**
  In the new CMS UI, the children area of the LLM form styles was hidden even
  though the styles register the `children` field. The previous CMS edit
  branch in `LlmFormView::output_content()` deferred to
  `FormUserInputView::output_content()`, which returns early when the form
  `name` field is empty and therefore never emits the
  `.section-children-ui-cms` wrapper that `cms_ui.js` relies on to attach the
  "Add new section" placeholder. CMS edit mode now bypasses the form render
  and calls `output_children()` directly, so child sections can be added to
  LLM form styles in the same way as the `div` style.

## [1.2.0] - 2026-04-23

### Added

- **Mobile parity for `llmFormRecord` and `llmFormLog`.** LLM form styles
  render natively in the SelfHelp mobile app (v4.1.0+). All four result
  panel modes (`default`, `card`, `collapse`, `modal`), all four placements
  (`top`, `bottom`, `left`, `right`), regenerate / retry / manual-feedback
  actions, and the "show previous result" behaviour work on mobile exactly
  like they do on the web.
- **Record id round-trip for LLM forms.** Every LLM response (submit,
  regenerate, retry, generate-feedback) now carries `record_id` inside
  `llm_meta`, and the current record id is exposed on both the web config
  payload (`currentRecordId`) and the mobile style output
  (`current_record_id`). This makes regenerate / retry reliable even on a
  fresh page load where no form has been submitted yet in the current
  session.
- **Previous result survives page reloads.** `LlmFormModel::getPreviousLlmResult()`
  and `getPreviousLlmMeta()` now fall back to a direct `dataTables` lookup
  when the cached entry record does not yet have the LLM columns populated.
  Users now see the last generated LLM response whenever they reopen the
  form, on both web and mobile.
- **Mobile output for LLM form styles.** `LlmFormView::output_content_mobile()`
  extends the core `FormUserInputView` mobile payload with the dynamic
  values the native mobile app needs to render the LLM result panel:
  `llm_previous_result`, `llm_previous_meta`, `current_record_id`,
  `section_id`, and `user_language`. All static config fields (`llm_enabled`,
  `llm_model`, `llm_result_placement`, `llm_result_panel`, etc.) are still
  included via the parent's `get_db_fields()` call, so no changes are
  required on the web side.
- **Global User Memory.** Centralized, per-user memory that stores stable
  facts across all LLM interactions.
  - Enable memory and choose storage mode (`record`, `log`, or `both`) from
    the LLM Settings page.
  - Create memory rules that trigger from form submissions, login, or
    profile changes. Rules use LLM summarization to extract and merge key
    facts from submitted data.
  - Admins write only the intent (e.g. "Extract hobbies from the form");
    the system auto-injects current memory, submitted data, user language,
    and Data Config context.
  - Memory content is written in the user's language automatically.
  - Infinite-loop protection blocks memory-update jobs whose source is the
    memory table itself.
  - Dedicated Memory admin page with a rules editor, source visibility,
    per-user memory browser, and history diff.
  - Full Prompt Lab integration for memory rules: version history,
    playground, datasets, and evaluations.
  - Async memory workers execute multiple rules in parallel on Windows and
    Linux.
- **Admin UI redesign.** Unified admin layout with persistent sidebar
  navigation (Settings, Conversations, Scripts, Memory). The Settings page
  is now a React interface for API keys, model defaults, and memory
  configuration.
- **Memory updates are importable as evaluation cases.** Real memory-update
  conversations now appear as candidates in the dataset import modal's
  "From conversations" source and are imported as `memory_runtime` cases
  tagged `memory_conversation`.
- **Structured memory replay.** When a memory case is imported, the memory
  worker's full prompt context is captured (system prompt, `## Current
  Memory`, `## Submitted Data`, `## Instructions`, `## Reminder`, plus
  parsed variables such as `memory_key`, `memory_text`, `memory_json`, and
  `event_payload_json`). Replay reassembles this context around the draft
  prompt, so evaluations measure the prompt change instead of a reduced,
  context-less rerun.
- **Per-case evaluation delete.** The row-level Delete button in the
  Evaluation Summary now removes only the selected evaluation case and its
  scores — sibling cases in the same run are preserved. The parent run is
  cleaned up automatically once its last case is removed. The bulk
  "Delete All Evaluations" button is unchanged.
- **Schema-aware LLM judge.** The judge receives an
  `output_format_contract` when the runtime enforces a fixed output
  envelope (for example, the memory JSON schema). The judge-system prompt
  instructs the judge to respect this contract and score the *content*
  inside the enforced fields rather than the envelope.

### Changed

- **Dataset import is owner-aware.** The import modal only offers sources
  that make sense for the owner. Memory rules default to "From memory
  executions (conversations)" and hide `form_submission` / `script_run`.
  Scripts hide `form_submission`. Contextual banners explain where the
  entries come from.
- **Playground import is limited to real playground tests.** Candidate
  runs from `llm_prompt_playground_runs` are filtered to `playground` and
  `compare` run modes only — builder ("Build With AI") and
  dataset-evaluation runs are excluded.
- **Memory conversations list correctly.** Dataset import queries
  assistant rows (not user rows) for memory rules, because the memory
  worker logs only a single assistant row per update and keeps the prompt
  in `sent_context`. That `sent_context` payload is replayed as the
  message history on import.
- **Evaluation calls inherit model parameters from the global LLM config.**
  `judge_temperature` and `judge_max_tokens` can be set per evaluator;
  otherwise the scorer inherits `llm_temperature` / `llm_max_tokens` from
  the LLM Settings page and finally from built-in defaults. All other
  evaluation paths already resolve through `LlmService::callLlmApi`, which
  honours the same hierarchy.
- **Lean LLM judge input.** The judge receives a focused summary of each
  case: for memory, `memory_key`, `current_memory`, `submitted_data`, and
  `instructions`; for other profiles, a trimmed `trigger_message` plus
  pruned variables. Internal form metadata (`_json`, `_meta_*`,
  `response_id`, `pageNo`, `trigger_type`, and similar) is stripped,
  oversized strings are truncated with a marker, and `model_output` is
  reduced to `display_content` only.

### Fixed

- **LLM form regenerate button now works.** The regenerate / retry actions
  previously needed a `record_id` that the backend never returned in the
  response, so the client stayed disabled after save. The server now always
  responds with `llm_meta.record_id`, and both the React `LlmFormPanel` and
  the Angular `LlmFormStyleComponent` seed their `record_id` from the
  initial config so the button works immediately on page load.
- **Previous LLM response displays after navigation.** On both web and
  mobile the last generated response was missing when the user navigated
  away and came back to an LLM form. The model now falls back to a direct
  `dataTables` lookup when the cached entry record does not yet have the
  LLM columns, so the previous result and its metadata always reappear.
- **Playground works for memory rules.** Running a memory-rule prompt
  through the playground no longer fails with
  `SQLSTATE[23000] ... fk_llmConversations_llm_scripts` /
  "Failed to create conversation for strict LLM logging".
- **Memory evaluations no longer fail with "information is incomplete".**
  Imported memory cases replay with the full original context (system +
  structured user message) rather than the admin instructions alone.
- **LLM judge no longer fails with "Control character error".** Judge
  verdicts are no longer truncated (response budget inherits from the
  global config instead of a fixed small value), and JSON parsing
  tolerates unescaped control characters that many LLMs emit inside
  string values.
- **Memory responses are no longer scored "unhelpful" for being JSON.**
  Admin instructions that ask for a narrative paragraph no longer cause
  the judge to penalise the mandatory JSON envelope — the judge scores the
  prose inside `memory_text` instead.
- **Form-triggered memory updates record the correct record id and table.**
  `source_ref` written to `llm_memory_history` now contains the real
  submission identifiers, and the memory-table infinite-loop guard works
  correctly.

### Internal

- Memory rules identified by integer `rule_id` only; simplified database
  schema.
- Memory prompt templates externalized to `assets/prompts/core/memory/`.
- Evaluation scoring consolidated behind a single `buildLeanJudgeInput`
  path with shared truncation and pruning utilities.
- New endpoints: `AjaxLlmPromptLab` action `delete_eval_run_case`; service
  methods `LlmEvaluationRunnerService::deleteEvalRunCase()` and facade
  `LlmEvaluationService::deleteEvalRunCase()`; React clients
  `promptApi.deleteEvalRunCase` and `evaluationApi.deleteEvalRunCase`.

## [1.1.0] - 2026-03-19

### Floating mode conversation switcher

- **Fixed**: Floating chat panel now shows a conversation switcher dropdown when `enable_conversations_list` is enabled. Previously the dropdown was rendered inside the `.llm-chat-header` which is hidden by CSS in floating mode (`display: none !important`), making it invisible. The conversation switcher is now rendered as a separate `llm-floating-conv-switcher` bar outside the Card component, above the messages area but below the panel header.
- **Fixed**: `FloatingChat.tsx` no longer adds the `llm-float-has-conversations` class (approach abandoned in favor of React conditional rendering).
- Users can now create new conversations and switch between existing ones directly from the floating chat panel via a compact dropdown.

### Model normalization for conversation retrieval

- **Fixed**: `LlmService::getUserConversations()` now normalizes the `$model` parameter at the start of the function before constructing the cache key and SQL query. This ensures conversations stored with scoped model names (`ServerName :: model-id`) are correctly retrieved when the CMS provides a raw model name.
- **Fixed**: `LlmService::resolveConversation()` now checks both scoped and raw model formats when determining if an existing conversation matches, preventing unnecessary new conversation creation after multi-server migration.

### Code quality and architecture refactoring

- **LlmChatModel** (1482 → 489 lines):
  - Removed 80+ private property declarations; getters now call `get_db_field()` directly, leveraging `StyleModel`'s internal cache.
  - Lazy-loaded `conversation_id` — resolved on first access instead of in the constructor, eliminating unnecessary DB queries on every page load.
  - Exposed `getLlmService()` so the controller reuses the model's service instance instead of creating a duplicate.
  - Inlined 36 UI-label getters into `getChatConfig()` via a local closure, cutting boilerplate while keeping the config array explicit.
  - Delegated auto-start message generation and topic extraction to new `LlmAutoStartService`.
  - Removed dead `formatMessageContent()` method (referenced uninitialized `$this->parsedown`; React handles markdown).

- **LlmChatController** (1690 → 1424 lines):
  - Extracted safety detection + transaction logging into `LlmDangerDetectionService::processSafetyDetection()`.
  - Extracted progress calculation + persistence into `LlmProgressTrackingService::calculateAndUpdateProgress()`.
  - Extracted topic confirmation detection and topic inference into `LlmProgressTrackingService::processTopicConfirmation()`.
  - Extracted repeated rate-limit + conversation resolution into `resolveConversationWithRateLimit()`.
  - Centralized multilingual topic-confirmation vocabulary into `LlmLanguageUtility` static methods.
  - Removed redundant `buildChatConfig()` one-line wrapper.
  - Gated `handleDebugProgress()` behind `DEBUG` constant to prevent system-prompt exposure in production.

- **LlmFormController**:
  - Adopted `LlmJsonResponseTrait` for consistent JSON response handling; removed duplicate private `sendJsonResponse()`.
  - Fixed potential SQL injection in `loadRecordData()` — switched to parameterized query for `record_id`.
  - Removed duplicate `extractInterpolationKeys()`; now delegates to `LlmFormModel::extractContextFieldKeys()`.

- **New services and utilities**:
  - `LlmAutoStartService` — static service for context-aware auto-start message generation and topic extraction from context.
  - `LlmLanguageUtility` additions — `getUserLanguageCode()`, `getTopicIdFieldPatterns()`, `getUnderstandingFieldPatterns()`, `getStrongConfirmationValues()` for centralized multilingual vocabulary.
  - `LlmDangerDetectionService` additions — `processSafetyDetection()` and `logSafetyDetectionToTransaction()` for complete safety lifecycle handling.
  - `LlmProgressTrackingService` additions — `calculateAndUpdateProgress()`, `processTopicConfirmation()`, `detectTopicConfirmation()`, `inferCurrentTopic()`.

- **React frontend**:
  - Removed `formatted_content` from `Message` interface (backend no longer sends it; React renders markdown directly).
  - Removed utility function re-exports from `types/index.ts`; consumers now import directly from `utils/formUtils` and `utils/llmResponseUtils`.
  - Changed `DEFAULT_FILE_CONFIG` to use empty defaults — backend is authoritative source for allowed extensions and vision models.

- **LlmFormModel**: Language detection now delegates to `LlmLanguageUtility::getUserLanguageCode()`.

### Previous 1.1.0 changes

### Prompt asset externalization

- Moved hardcoded LLM-facing runtime prompts/instructions into file assets under `assets/prompts/`.
- Added key-based prompt loading via:
  - `server/service/prompt/LlmPromptAssetRegistry.php`
  - `server/service/prompt/LlmPromptAssetLoader.php`
- Updated core runtime services to load prompts from assets (fail-closed on missing key/file).
- Added `doc/prompt-assets.md` to document prompt ownership, naming, and troubleshooting.

### Multi-server model configuration

- Replaced the single `llm_base_url` / `llm_api_key` setup with the new `llm_api_keys` JSON field.
- Added a dedicated CMS manager UI for named LLM server entries.
- Unified model discovery so chat, forms, scripts, prompt lab, and speech-to-text all use the same scoped model catalog.
- Added migration from legacy API settings into a default multi-server entry.

### Prompt registry and prompt lab

- Added the shared prompt registry schema:
  - `llm_prompt_entries`
  - `llm_prompt_locales`
  - `llm_prompt_versions`
  - `llm_prompt_playground_runs`
- Added the `llm_prompt` field type for version-aware CMS prompt editing.
- Added immutable prompt versions, version comments, version restore, diffing, runtime-aware playground runs, and build-with-AI suggestions.
- Connected `llm_scripts.script` to the same prompt registry through `llm_scripts.id_llm_prompt_entries`.
- Added `AjaxLlmPromptLab` as the shared endpoint for prompt bootstrap, versions, compare, playground, builder, datasets, and evaluations.
- Unified JSON UX in plugin UIs:
  - standardized non-editable JSON rendering on shared `JsonInspector` tree/raw viewer
  - standardized editable JSON fields on shared Monaco-based JSON editor wrapper
  - removed remaining ad-hoc JSON `<pre>`/textarea surfaces in prompt-lab/admin JSON views

### Datasets and evaluations

- Added first-class dataset storage with:
  - `llm_eval_datasets`
  - `llm_eval_cases`
  - `llm_eval_dataset_case_links`
- Added first-class evaluation storage with:
  - `llm_eval_definitions`
  - `llm_eval_runs`
  - `llm_eval_run_cases`
  - `llm_eval_scores`
- Refactored prompt-lab case storage to use canonical reusable cases plus explicit dataset membership links, so cases can be promoted or moved between datasets without losing their identity/history.
- Implemented dataset ingestion from:
  - latest playground runs
  - saved form submissions
  - conversation history
  - script runs
- Implemented shared dataset replay through the existing runtime-aware prompt execution path.
- Added dataset metadata editing for rename, description, type changes, and guarded execution-profile changes.
- Updated the dataset browser to surface both dataset `Type` and runtime `Profile`.
- Added bulk case selection plus move/promotion flows between compatible datasets.
- Added case-level evaluation history so promoted cases keep their prior run and review trail.
- Added programmatic evaluators:
  - `json_validity`
  - `required_fields_present`
  - `no_empty_output`
  - `safety_label_match`
- Added `llm_judge` scoring support and made manual review part of every evaluation run by default.
- Added a shared prompt standard layer in the parent plugin for:
  - reusable authored prompt scaffold order (`task/role`, `style`, `domain safety`, `examples`, `output behavior`)
  - centralized dataset safety expectation defaults (`expected_labels_json.safety.danger_level`)
  - scaffold-aware Build With AI guidance across prompt fields and scripts
- Added prompt-lab UI flows for dataset browsing, metadata editing, case preview, source import, case promotion, evaluation runs, result inspection, pending-review filtering, and manual review.
- Exposed the same datasets/evaluations workflow in both CMS prompt fields and the scripts manager.
- Added consistent CMS-style delete confirmation for dataset deletion (`$.confirm` with safe browser fallback).
- Added AI-assisted dataset case import (`Import With AI`) for bulk paste of tabular/free-text examples.
- Added new Prompt Lab actions:
  - `parse_cases_from_text`
  - `import_parsed_cases`
  - `move_dataset_cases`
  - `list_compatible_datasets`
  - `list_case_evaluation_history`
  - `list_evaluation_example_candidates`
- Added parser/import backend services:
  - `LlmDatasetAiImportParserService`
  - `LlmDatasetAiImportMapperService`
  - `LlmDatasetBatchImportService`
- Extended Build With AI so curated, manually approved evaluation examples can be imported directly into prompt-building context.
- Added editable playground-local drafts with explicit `Apply To Draft`, `Reset From Draft`, and shared Build With AI reuse inside playground.
- Added evaluation run history cleanup controls in Prompt Lab datasets:
  - single-run delete (`delete_eval_run`)
  - bulk delete per dataset (`delete_eval_runs_bulk`)
- Documented and surfaced cascade behavior:
  - deleting a dataset case removes related eval run-case/score rows by FK cascade
  - deleting eval runs removes dependent run-cases/scores by FK cascade
- Added parser prompt assets under `assets/prompts/core/dataset-import/` and registry keys:
  - `core.dataset_import.system`
  - `core.dataset_import.repair_json`
- Added dataset source provenance type `ai_text_import` in `llm_eval_source_types`.
- Fixed form-runtime replay reliability for AI-imported cases:
  - mapper now auto-aligns imported variable keys to active prompt placeholders (`{{...}}`) using alias/similarity matching
  - replay path now falls back to normalized variable payload when placeholder filtering would otherwise produce empty user input (`Form submission`)
- Kept full `input_payload_json` case shape for replay compatibility and debugging.
- Improved parser robustness for large/malformed model output:
  - parser token budget floor increased for multi-row imports
  - tolerant JSON candidate extraction (fences + embedded fragments)
  - automatic JSON repair pass when first parse fails
- Improved admin JSON inspection for nested/embedded JSON-like content and parser payload diagnostics.
### LLM forms

- Added the new `llmFormRecord` and `llmFormLog` styles.
- Added configurable result placement, panel type, result metadata storage, retry/regenerate behavior, and inline result rendering.
- Added manual feedback mode for `llmFormRecord`, including a separate feedback button and no-save generation flow.
- Kept all LLM form requests on the shared logging path through `llmConversations` and `llmMessages`.

### Migration and packaging

- Expanded `server/db/v1.1.0.sql` to include prompt lab, datasets, and evaluations.
- Kept the migration rerunnable with `INSERT IGNORE`, `CREATE TABLE IF NOT EXISTS`, and helper-based key/index convergence.
- Added prompt-lab frontend bundles and refreshed the shipped prompt-field and scripts assets.
- Added prompt-lab documentation for editors and developers, including dataset, replay-import, payload-shape, evaluator-authoring, and migration guides.

## [1.0.0] - 2026-02-26

### Initial release

- Added the core chat system with structured JSON responses, conversation history, safety handling, file uploads, and speech-to-text.
- Added reusable LLM scripts with sync/async execution, testing, and scheduler integration.
- Added the admin console for conversation inspection, payload debugging, and conversation blocking.
- Added the `llmResponse` rendering component and the initial React build pipeline.
