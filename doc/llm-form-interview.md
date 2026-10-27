# LLM Form Interviews (`llmFormLogList`)

Guided, conversational completion of SelfHelp forms. Participants talk to
the LLM; the interview collects answers against the real CMS child fields
and saves the record through the **inherited `formUserInputLogList` write
path** - only when the participant explicitly approves. Nothing is written
to `dataRows` before that approval.

> **Status:** run `server/db/v1.6.0.sql` and clear the SelfHelp cache
> (**Admin > Cache > Clear all** - styles, fields and the `?v=` asset
> versions are cached; clear it again after every rebuild of the bundles).
> The launcher and workspace (page mode and the real modal window, every
> chat position, all four answers layouts, the table and cards list),
> URL-driven open, drafting, approval with its three confirmation modes,
> resume, editing an existing record (with a before/after view), the
> language rule (default on), quick replies, a shared form reused through a
> reference section, loop interpolation and the archive/restore pattern
> were exercised end-to-end on a development stack (real database, LLM
> endpoint, browser, phone viewport).
> Speech-to-text additionally needs a Whisper-compatible audio model on
> your endpoint - verify it there. Re-check the flows on your target
> environment before relying on them.

## Contents

- [What it is](#what-it-is)
- [CMS recipe](#cms-recipe)
- [Interview fields](#interview-fields)
- [Participant UI](#participant-ui)
- [Saving: confirmation, review and edits](#saving-confirmation-review-and-edits)
- [Layout and mobile](#layout-and-mobile)
- [Theming](#theming)
- [Language, quick replies and speech](#language-quick-replies-and-speech)
- [Shared forms, loops and reference sections](#shared-forms-loops-and-reference-sections)
- [URL parameters (`extra_params`)](#url-parameters-extra_params)
- [Pattern: status, archive, restore and history](#pattern-status-archive-restore-and-history)
- [Conversation scope and state](#conversation-scope-and-state)
- [Drafts, approval and retention](#drafts-approval-and-retention)
- [Conditions, hidden fields and native controls](#conditions-hidden-fields-and-native-controls)
- [Prompt Lab preview](#prompt-lab-preview)
- [Admin console](#admin-console)
- [Memory integration](#memory-integration)
- [Boundaries](#boundaries)
- [HTTP contract](#http-contract)

## What it is

`llmFormLogList` extends the core `formUserInputLogList` style. The list,
the New / Edit CTAs, quick actions, `history_log`, `own_entries_only`,
ownership, GUMP validation, form actions and ACL checks are exactly the
core's - the interview is an additional presentation of the same form:

- The participant's New / Edit links open the **interview workspace**
  instead of the plain form (modifier-clicks and new tabs open the record
  URL, which opens the very same workspace). The workspace is addressed by
  the URL exactly like the core list form: `?record=new&sid=<section id>`
  creates, `?record=<id>&sid=<section id>` revises. The plain page URL
  shows only the launcher card and the list.
- The LLM asks questions and returns validated
  `interview.collected_fields` patches; it can never write a record
  directly.
- Deterministic field progress is computed server-side from the same
  eligibility tree the save would see.
- An explicit **Save** (the answers pane or the bar in the chat) runs the
  inherited controller write (create or update) inside one transaction and
  returns a receipt.
- The workspace looks and behaves like the rest of the plugin: the panel
  header, bubbles, avatars, quick replies and composer are the very same
  components `llmChat` uses (see [Theming](#theming)). It is inline in the
  page or a real modal window - never a floating viewport-corner bubble
  (`llmChat` floating mode is untouched).

## CMS recipe

Requires **SelfHelp core v7.12.0** - the plugin style inherits the core
`formUserInputLogList` implementation, and core 7.12 adds the two seams
it relies on: the protected `output_new_button()` / `get_frontend_config()`
view hooks and the `selfhelp:loglist:before-replace` /
`selfhelp:loglist:after-replace` soft-refresh events. The
`llmFormLogList` style itself is registered by the plugin migration
`v1.6.0.sql`.

1. Create a section with style **`llmFormLogList`**.
2. Add **child fields** exactly like a `formUserInputLogList`: inputs,
   selects, radios, checkboxes, textareas, conditional containers,
   markdown, etc. The record form and the list keep their original
   rendering; the interview collects values against the same field tree
   and shows the renderable controls as per-field fragments (container
   markup is not reproduced inside the panel - see
   [Conditions, hidden fields and native controls](#conditions-hidden-fields-and-native-controls)).
3. Set **`label_new`** (start button and header of the modal window),
   **`label_edit`** and the list columns (`fields_map`, `list_layout`
   `table` or `cards`) as usual; the list below the launcher card keeps
   working unchanged.
4. Write **`llm_interview_context`** - the author prompt with the
   interview's coaching / domain instructions. `{{field}}` placeholders
   resolve against the current draft + base entry at send time; it is a
   `llm_prompt` field, so Prompt Lab versioning applies. Even with an empty
   context the coach opens correctly: it asks the first relevant question
   for a new record and "what would you like to change?" for an existing
   one (built-in start instructions, `assets/prompts/core/form-interview/`);
   the author context tailors that.
5. Optionally set **`extra_params`** - the URL parameters the section
   accepts, e.g. `module_id=&list_status=active` (see
   [URL parameters](#url-parameters-extra_params)).
6. Enable `history_log` if you want the record's edit history (and,
   combined with `llm_interview_include_history`, bounded history context
   in the prompt).

Example (generic WOOP-style fields - the feature is not WOOP-specific,
any field names work):

```
children:
  - conditionalContainer
      - input "wunsch"   (label "Wish",     required)
      - input "ergebnis" (label "Outcome",  required)
      - input "hindernis" (label "Obstacle", required)
      - input "plan"      (label "Plan",    required)

llm_interview_context: |
  Coach the user through {{wunsch}}, {{ergebnis}}, {{hindernis}} and
  {{plan}}. Ask one question at a time.
```

## Interview fields

Every field also appears in the CMS with its own help text.

| Field | Default | Description |
|-------|---------|-------------|
| `llm_interview_context` | - | Author prompt (Prompt Lab `llm_prompt` field). Resolved per turn against draft + base entry + `data_config` + globals. |
| `extra_params` | - | The **URL parameters** the section accepts, `name=default&name2=default2` (core field of `formUserInputLogList`, here with interview semantics). Per name the value is the route parameter, else `?name=` of the page URL, else the default. Available as `{{__name__}}` in every field of the section and its children (`filter`, `data_config`, `css`, texts, button URLs). They also **partition the unsaved drafts** (`context_key`, frozen at open) and a name that is also a form field **pins** that field. Empty = one unbound draft bucket per section (fingerprint of immutable server-bound hidden context). See [URL parameters](#url-parameters-extra_params). |
| `llm_interview_include_history` | `0` | With `history_log` on, include the last <=3 record history snapshots (<=8000 chars, eligible fields only) in the prompt as read-only `RECORD HISTORY`. |
| `llm_interview_progress_layout` | `stepper` | Answers overview, always with the collected values and a highlighted current question: `stepper` (numbered steps on a line) / `cards` (responsive multi-column grid - four questions read as a 2×2 board; more wrap into extra rows) / `list` / `none`. `none` shows no progress and no answers; without native controls (manual editing off) it leaves the **chat only** - the participant approves from the chat bar. |
| `llm_interview_chat_retention` | `continue` | See [Drafts, approval and retention](#drafts-approval-and-retention): `continue`, `new_on_open`, `new_after_save`. |
| `enable_danger_detection` / `danger_keywords` / `danger_notification_emails` / `danger_blocked_message` | off / defaults | Same crisis fields as `llmChat` (see [Danger word detection](danger-word-detection.md)). Keyword pre-scan before the coach runs; critical LLM safety can block after a turn. The UI shows the CMS blocked message (markdown) and locks the composer without treating the entry as saved. |
| `llm_interview_allow_manual_edit` | `0` | Participants may also edit the supported native controls next to the chat. The control sits under its own question, the panel grows with it and the field in focus is highlighted. Edits share the draft and approval path - nothing saves early. |
| `llm_interview_confirm` | `edit` | The **single switch** of the save confirmation: `edit` (only when a saved entry is revised: a before/after dialog), `always` (also for new entries: the record that will be saved) or `never` (the approve button saves at once). See [Saving](#saving-confirmation-and-edits). |
| `confirmation_title` / `confirmation_message` / `confirmation_continue` / `label_cancel` | - | Core fields, here **only the texts** of the confirmation dialog: title, text above the record, save button, back button. Empty = built-in, translated defaults. They never switch the dialog on or off - `llm_interview_confirm` does. |
| `llm_interview_writable_hidden_fields` | `[]` | JSON allowlist of ordinary hidden field names the LLM may patch. Reserved/system keys, scope-bound and condition-excluded fields are always rejected. |
| `llm_interview_chat_position` | `right` | Chat pane position relative to the answers pane: `right`/`left`/`top`/`bottom` - in the page and in the modal window. Small screens stack (the position decides the order there too). |
| `show_form_in_modal` | `0` | Where the interview opens, like the core list form: off = **page mode** (the workspace replaces the list while it is open), on = a **real modal window** with a header (`label_new` / `label_edit`) and a close button; the list stays on the page behind it. See [Layout and mobile](#layout-and-mobile). |
| `show_list` | `1` | The list below the launcher card. Off = no list; the interview opens as soon as the page loads. |
| `llm_interview_title` / `llm_interview_subtitle` | - | Chat header text. Translatable; both support `{{field}}` / loop-variable interpolation (e.g. `Your personal plan for {{module_topic}}`). |
| `llm_interview_input_placeholder` / `llm_interview_send_label` / `llm_interview_approve_label` | defaults | UI labels. The start button uses the core `label_new` field. |
| `llm_interview_labels` | - | Translatable JSON that overrides every remaining participant-facing text - see [Participant UI](#participant-ui). English, German and French are built in. |
| `llm_use_user_language` | `1` | Append the language rule: the coach answers in the language the participant selected in SelfHelp. On by default (same as pre-1.6.0). Off = only the author context decides. Shared by `llmChat`, `llmFormRecord`, `llmFormLog`, `llmFormLogList` and Prompt Lab. |
| `llm_chat_appearance` | blue/grey | Bubble colours and avatars, the same JSON contract as `llmChat` - see [Theming](#theming). |
| `enable_speech_to_text` / `speech_to_text_model` | `0` / `faster-whisper-large-v3` | Microphone button in the composer (Whisper transcription) - see [Language, quick replies and speech](#language-quick-replies-and-speech). |
| `llm_model` / `llm_temperature` / `llm_max_tokens` / `llm_reasoning_effort` | module defaults | Same fallback chain as `llmChat`. |
| `llm_memory_rule_ids` | - | Memory rules queued after the inherited `finished` save - same semantics as core forms (v1.5.0). |

Inherited core fields that behave differently here (their CMS help says so):
`label`, `type` and `url_cancel` are **not used** - the save button is
labelled by `llm_interview_approve_label`, follows the accent colour and
closing the interview returns to the plain page URL. `ajax`,
`alert_success` and `alert_fail` belong to the list actions (delete, quick
actions); a save through the interview is always asynchronous, ends with
the launcher's *Saved* note instead of `alert_success` and adds
`alert_fail` to the error it shows when a save fails.

Native-only fields need no CMS flag: the schema service derives
`native_only` automatically for field styles or input types the
interview cannot safely write (for example file uploads or widgets
without a plain value binding). Those fields always render as their
original control and can only be changed through the native controls.

Texts that come from CMS fields are stored HTML-escaped by the CMS
(`Approve & Save` is stored as `Approve &amp; Save`); the plugin decodes
them for display, so enter plain text.

## Participant UI

Plain Bootstrap 4.6 markup (card, btn, badge, progress, alert) plus the
shared `llmChat` components - it looks like the host site without any
plugin CSS, and every colour/radius/height can be themed afterwards
(see [Theming](#theming)).

**Launcher** - one call-to-action card with icon, title, text and the
start button; it is what the plain page URL shows above the list. The
button label follows the retention
([table](#drafts-approval-and-retention)): with an unfinished draft that
the retention keeps it reads *Continue* (and *Start over* discards the
draft), otherwise it is the section's `label_new`. After a save the card
shows a short success note next to the refreshed list.

**Workspace** - two panes:

- **Answers pane** ("Your answers", progress bar and counter) - the
  overview (`llm_interview_progress_layout`) with every collected answer,
  the optional native controls and the **Save** button with a hint below
  it (`approveHint` while required answers are open, `noChanges` while
  nothing is savable yet). The pane grows with its content - it never gets
  an inner scrollbar - and always shows where the participant is: the
  question the coach is asking carries a *Current question* tag and is
  scrolled into view, an answer the coach has just filled in flashes
  briefly. The cursor stays in the chat box. Opening an existing record
  marks every changed answer with an *Edited* badge (and the previous
  value) and counts them in the header.
- **Chat pane** - the shared panel header (icon, title, subtitle, *Start
  over*, minimize), the transcript (it scrolls inside the pane, the page
  never grows), closed questions as quick-reply buttons, the shared
  composer with character counter, clear button and the optional
  microphone. When everything required is answered and there is something
  to save, a bar above the composer says so (`readyToSave`) and offers
  the **Save** button right there - the only approve path when the answers
  pane is hidden.

Behaviour worth knowing:

- **Nothing to save, nothing to approve.** A new record needs at least one
  answer, an opened record needs at least one change; until then the
  Save button is disabled and the hint below it says why (also enforced
  server-side by `prepareApproval`), and the in-chat bar stays hidden.
- **Page mode** (`show_form_in_modal` off) - **the workspace replaces the
  launcher and the list while it is open**, new and edit alike, exactly
  like the core page form; they return after the save or on minimize.
  **Modal mode** (on) - a real modal window with a header (`label_new` /
  `label_edit`) and a close button opens over the page; the list stays on
  the page behind the backdrop. × and *Esc* close it and keep the
  unfinished draft; clicking the backdrop never closes it, so a
  half-finished answer is not lost by accident. Dialogs opened from it
  (save confirmation, start over) sit above it.
- **Edit starts with a question.** The coach opens an existing record with
  one sentence about which record it is and "what would you like to
  change?", with quick replies when there are only a few editable answers.
  Without any author context this works out of the box (built-in start
  instructions); the author context tailors it.
- After a save the workspace closes, the core list soft-refreshes and the
  launcher returns with the success note.
- **The URL is the state.** Opening the interview puts
  `?record=new&sid=<sectionId>` (or `?record=<id>&sid=<sectionId>`) in the
  address bar; closing returns to the plain page URL, which shows the
  launcher and the list. A reload or back/forward navigation on a scoped
  URL reopens the workspace and keeps the unfinished draft; any other
  visit to such a link follows the retention. A `sid` of another section
  or a malformed `record` is ignored.
- On page load the launcher asks the server for an unfinished draft with a
  quiet `state` read (`peek=1`): "no draft yet" is a normal `200` with
  `state: null`, not a `404` in the console. The start button shows a
  spinner meanwhile.
- A draft that changed in another window is adopted with a short notice;
  the participant is never stuck on a stale revision.
- Long panes use the shared slim scrollbar (`.llm-slim-scroll`).

`llm_interview_labels` (translatable JSON) overrides every other participant
text. English, German and French are built in, other languages fall back to
English. Keys: `launcherIcon` (FontAwesome class), `launcherTitle`,
`launcherText`, `resumeText` (`{done}` `{total}`), `resume`, `restart`,
`restartConfirm`, `minimize`, `close`, `back`, `thinking`, `coachTitle`,
`coachStatus`, `choose`, `mic`, `micStop`, `micBusy`, `retry`, `errorEmpty`,
`clearInput`, `readyToSave`, `collectedEyebrow`, `collectedTitle`,
`progressLabel`, `statusDraft`, `statusReady`, `statusSaved` (the status
chip beside the board title: new draft / all answered / saved entry),
`collectedHint`, `editingHint`, `editedBadge`, `valueBefore`, `valueAfter`, `valueEmpty`,
`notAnswered`, `required`, `stepDone`, `stepActive`, `stepPending`,
`approveHint`, `noChanges`, `reviewTitleNew`, `reviewTitleEdit`,
`reviewValues`, `reviewChanges`, `reviewUnchanged` (`{count}`),
`savedTitle`, `savedText`, `unsupportedFiles` (`{fields}`), `errorGeneric`,
`errorBusy`, `errorRate`, `errorConflict`, `errorInvalid`, `errorSave`.
Optional extra key `editTitle`: the title of the modal window while an
existing record is edited (default: the section's `label_edit`, which is
usually the list's "Open" button text).

## Saving: confirmation and edits

The approve button either saves at once or opens a confirmation dialog
first. **`llm_interview_confirm`** is the single switch:

| `llm_interview_confirm` | New entry | Revised entry |
|-------------------------|-----------|---------------|
| `edit` (default) | one click saves | dialog with a before / after view of every changed answer |
| `always` | dialog with the record that will be saved | the same before / after dialog |
| `never` | one click saves | one click saves |

The core confirmation fields only **word** that dialog - they never turn
it on or off: `confirmation_title` (default `reviewTitleNew` /
`reviewTitleEdit`), `confirmation_message` (text above the record, default
none), `confirmation_continue` (the save button, default the approve
label) and `label_cancel` (the back button, default `back`). Empty =
built-in, translated texts.

- **New record:** the dialog lists the values that will be saved.
- **Existing record:** the dialog is a **before / after** table of the
  changed answers only (changed rows carry the *Edited* badge) plus
  "Unchanged answers: n" - the participant sees exactly what the save will
  change.
- Hidden fields and answers without a value never appear.
- The dialog is at most 90% of the viewport in height and width; header and
  footer stay in place and only the body scrolls.
- The save itself always happens in the `approve` request, in one
  transaction; the dialog only gates it.

## Layout and mobile

- `llm_interview_chat_position` places the chat pane (`right` default;
  `left`, `top`, `bottom`) - in the page and in the modal window alike.
  `right`/`left` put the answers pane and the chat side by side: the
  answers pane takes the free width and grows with its content (no inner
  scrollbar); the chat is a narrower column (`--llm-iv-chat-width`, default
  about a chat sidebar) with a fixed height (in page mode fitted to the
  visible viewport, so the composer is never cut off) and stays pinned at
  `--llm-iv-sticky-top` while the page scrolls past a tall answers pane.
  `top`/`bottom` stack the panes, the chat at `--llm-iv-height`
  (`top` = chat first). With manual editing on, the native controls live in
  the answers pane under their question (and span the full row in the
  `cards` grid). Set `llm_interview_progress_layout` to `cards` for a
  WOOP-style **board**: an eyebrow + title with a status chip, a numbered
  step tracker and one card per question in `--llm-iv-cards-cols` columns
  (default 2, so four questions read as a 2×2). Every answer stays on its
  card - the coach only fills it in, an edit shows the previous value as
  "before" - and a card keeps its size in every state, so nothing jumps
  when an answer arrives. A card shows the question's prompt (a textarea /
  input `placeholder`, or the `alt` of a select) until it is answered.
- **Modal window** (`show_form_in_modal = 1`): up to 1140 px wide (800 px
  for the chat only), the window body scrolls - never the page behind it -
  and the chat height is capped to the window.
- **Chat only:** `llm_interview_progress_layout = none` without native
  controls renders just the chat (centered, max 60rem). The in-chat save bar
  and the quick replies carry the whole interview.
- Below 768 px the panes stack: the answers first, the chat (`min(75vh,
  560px)`, in the modal `min(70vh, 520px)`) below them - only `top` puts the
  chat first. The modal uses nearly the full width, the record and
  before/after rows of the save dialog stack their columns and quick replies
  wrap in rows. The `cards` board shows one card per row (also whenever the
  answers pane itself is narrower than 30rem). On a phone the board is not
  scrolled into view when the coach moves to the next question, so the
  composer stays where it is.
- Mobile JSON output keeps the inherited structure; the interview config is
  included for clients that want it - there is no separate native app
  format.

## Theming

Set any of these custom properties on the section, an ancestor or
`.llm-interview-react` - no plugin CSS needs to be edited. Defaults are
`var()` fallbacks, so an ancestor's value always wins.

| Property | Controls |
|----------|----------|
| `--llm-iv-height` | workspace height (default `clamp(420px, 74vh, 620px)`) |
| `--llm-iv-accent` | chat header, active step, edited marks, progress, quick replies, save buttons, the list's edit button and card hover (default Bootstrap `--primary`) |
| `--llm-iv-done` | completed step colour (default `--success`) |
| `--llm-iv-border`, `--llm-iv-surface`, `--llm-iv-panel-bg`, `--llm-iv-chat-bg` | pane borders and backgrounds (`-border` and `-surface` also the list's table and cards) |
| `--llm-iv-radius`, `--llm-iv-gap` | corner radius (also the list's), space between the panes |
| `--llm-iv-icon-bg` | launcher / header icon background |
| `--llm-iv-sticky-top` | distance of the pinned chat from the viewport top (default `1rem`) |
| `--llm-iv-modal-bg` | background of the modal window body (default `#f4f6f8`) |
| `--llm-iv-chat-width` | width of the chat when it sits beside the answers (default `min(28%, 22rem)`). The answers pane takes the rest. Use e.g. `18rem` for a slim chat, `36rem` or `40%` for a wider one. Ignored when the chat is `top`/`bottom` or the only pane. |
| `--llm-iv-cards-cols` | number of columns of the `cards` board (default `2`: four questions read as a 2×2; an odd first card, e.g. the hub's module question, spans the row). One column when the answers pane is narrower than 30rem / below 576 px. |
| `--llm-iv-ink`, `--llm-iv-muted`, `--llm-iv-soft`, `--llm-iv-track`, `--llm-iv-edited` | board colours: answer/title text, prompts and hints, soft fill of answered cards / tracker / status chip, tracker line and open step circles, edited answers (default: the accent) |

Class hooks for deeper CSS: `.llm-iv-launcher` (`-icon`, `-title`,
`-text`, `-start`), `.llm-iv-workspace` (`.pos-left|right|top|bottom`,
`.is-modal`, `.is-chat-only`), `.llm-iv-collected`, `.llm-iv-pane-head`,
`.llm-iv-pane-body`, `.llm-iv-pane-foot`, `.llm-iv-steps`
(`-stepper|-cards|-list|-plain`), `.llm-iv-step` (`.is-done`, `.is-active`,
`.is-pending`, `.is-asking`, `.is-edited`, `.is-flash`; parts `-label`,
`-answer`, `-empty`, `-before`, `-mark`), `.llm-iv-asking-tag`,
`.llm-iv-edited-badge`, `.llm-iv-chat`, `.llm-iv-transcript`, `.llm-iv-msg`,
`.llm-iv-approvebar`, `.llm-iv-quick-btn`, `.llm-iv-ws-modal` (the modal
window), `.llm-iv-modal` (every dialog), `.llm-iv-record` and
`.llm-iv-changes` (the save dialog). The list gets the root class
`llmFormLogList`. The shared chat parts keep their `llmChat` classes:
`.llm-panel-header`, `.message-bubble`, `.message-input-container`,
`.message-send-btn`.

**The list** (`table` or `cards`) has no styles of its own in this plugin: it
shows the core's default look, the same one a plain `formUserInputLogList`
has (SelfHelp 7.12.0 or newer). The shared `--llm-iv-*` variables above drive
it through the `.llmFormLogList` root class; where they are not set, the core's
`--ful-accent`, `--ful-border`, `--ful-surface`, `--ful-muted` and
`--ful-radius` apply, then Bootstrap's `--primary`. Hooks for custom CSS are
the core's: `.formUserInputLogList-card` (with `-card-cell`, whose
`data-header` is the label), `-table-wrap`, `-col`, `-cell`, `-btn-edit`,
`-btn-quick`, `-btn-delete`, and a language-independent `data-field` on every
column. Add the class `formUserInputLogList-bare` to the section's CSS field
to switch the whole look off and style the list from scratch.

**Bubbles and avatars** come from `llm_chat_appearance` (same JSON as
`llmChat`: `bg`, `text`, `border`, `icon`, `iconMobile`, `iconImage` per
side). Values are used as CSS values, so a bubble can follow the section
accent: `"bg": "var(--llm-iv-accent, #0d5c63)"`.

**The modal window and the dialogs** (save confirmation, Start over)
render on `<body>`, outside the themed section. While one is open the
plugin copies the documented `--llm-iv-*` custom properties and the
section's own CSS classes onto it, so rules written for the section
(`.my-form .llm-iv-step`) apply inside it as well; rules that also need an
ancestor of the section do not reach it. Style it through `.llm-iv-modal`
and `.llm-iv-ws-modal`. The dialogs deliberately do not fade: the core list
refresh removes every `.modal-backdrop` node from `<body>` itself, and a
dialog that is still animating out at that moment could not be torn down
cleanly (the plugin additionally sweeps up leftovers of its own dialogs when
the list is replaced).

## Language, quick replies and speech

- **Language rule (default on).** With `llm_use_user_language = 1` (the
  default) every turn appends a system instruction *after* the author
  context so the participant's current UI language wins - including
  English - even when the author prompt is written in another language or
  tells the coach to speak that language, and even when field labels or
  earlier turns are in another language (unless the participant explicitly
  asks to switch). Switching the site language mid-interview switches the
  coach on the next turn. Untick the flag so only the author context
  decides. Field names and option values in `interview.collected_fields`
  stay exactly as supplied by the server. The built-in UI texts (buttons,
  dialogs, hints) always follow the UI language. Prefer language-neutral
  address rules in the author context when you expect mixed UI languages.
- **Quick replies.** The coach may return `content.suggestions` (up to six
  distinct single-line labels of at most 120 characters). They render as
  buttons under the last coach message of an open draft; choosing one sends
  it as a normal message. Use them for closed questions (module choice,
  "save / change something") - state this in the author context.
- **Speech-to-text.** With `enable_speech_to_text` and a
  `speech_to_text_model` a microphone button appears next to Send. The
  recording is posted to the `transcribe` action (multipart field `audio`;
  type and size are validated, same login/ACL/CSRF gates as every other
  mutation), the text lands in the message box so the participant can read
  it before sending, and the audio is deleted right after transcription.
  The browser needs microphone permission (HTTPS or localhost).

## Shared forms, loops and reference sections

The interview is built once and reused the SelfHelp way: put the
`llmFormLogList` (with its child fields) in one section, show it on any
number of pages through a CMS **reference section** (`refContainer`), and
wrap that reference in a **loop** section per page to hand page-specific
values (a module key, a topic, a colour) to the shared form. Loop
variables are interpolated into **every field of the descendant sections**
- `llm_interview_context`, title/subtitle, input `value`s, `filter`,
  `values_map` - before the list renders, so one form can filter its list,
  prefill an input and brief the coach per page. Loop variables are
  *outgoing* page configuration; for values that arrive **from the URL**
  (a module chosen by a button, a list status) use
  [`extra_params`](#url-parameters-extra_params) instead.

Rules that avoid the usual surprises:

1. **Loop variables must not reuse a data-column name.** `{{wunsch}}` in a
   `values_map` template is a *row* value; if a loop variable has the same
   name it replaces the template before any row exists. Prefix loop
   variables (`module_key`, `module_label`, ...).
2. **A name in `extra_params` that also exists as a form field with a
   value pins that field**: it becomes hidden, read-only context (the coach
   never asks for it and cannot change it) and is seeded from the URL value
   for a new draft. Use a name that is *not* a data column when the
   parameter is only a filter (`list_status`), and the column name itself
   (`module_id`) when the saved record should carry the value. A saved
   record keeps its own value.
3. **Each (participant, section, record, URL parameters) is its own
   thread.** Two different parameter values give two independent unbound
   drafts.
4. **Core list limitation:** the list `filter` is applied to the pivoted
   result, so every column used in `filter` must also be selected for the
   list (reference it in a `values_map` template, `fields_map` or a quick
   action `set`).
5. **Field languages.** Fields flagged language-neutral in the CMS
   (`values_map`, `filter`, `loop`, `quick_actions`, input `value`,
   `extra_params`, `llm_chat_appearance`,
   `llm_interview_writable_hidden_fields`, `llm_use_user_language`) hold one
   value for all languages; translatable ones (`fields_map`, `label*`,
   `items`, `confirmation_*`, `delete_*`, `empty_*`, `quick_actions_labels`,
   `llm_interview_context`, title/subtitle, the `llm_interview_*` texts and
   `llm_interview_labels`) can differ per language - and each language may
   use a *different loop variable* (`{{module_topic}}` in German,
   `{{module_topic_en}}` in English).
6. Deleting a list entry keeps the finished conversation for the admin
   audit; a new interview for the same scope starts a fresh thread.

## URL parameters (`extra_params`)

`extra_params` is a field of the core `formUserInputLogList` (so of
`llmFormLogList` as well) and the **incoming** twin of the Qualtrics
`extra_params`, which are *appended* to a survey link. It declares the URL
parameters the section accepts, in the same `name=default&name2=default2`
notation:

```
module_id=&list_status=active
```

Per name the value is, in this order: a **route parameter** of that name,
`?name=` of the **query string**, the **default**. A default may use
placeholders: the entry or loop row (`{{cat_module}}`) and earlier declared
names (`{{__module_id__}}`). Empty values count as absent; `0` is a value.

- **Available everywhere in the section.** Every declared name is always
  defined (possibly empty) as `{{__name__}}` in all fields of the section
  and its descendants - `filter`, `data_config`, `css`, texts, the `url` of a
  child `button` - and as `#name` in a `data_config`. Sibling sections do not
  see it. One form can therefore be an "active" list on one URL and an
  "archived" list on another, or filter by a module:

  ```
  extra_params: module_id=&list_status=active
  filter:       AND IFNULL(status, 'active') = '{{__list_status__}}'
                AND ('{{__module_id__}}' = '' OR module_id = '{{__module_id__}}')
  ```

  The core drops a `filter` that still contains an unresolved `{{...}}` (the
  list would show everything); declared parameters are always resolved, so
  that cannot happen for them.
- **Strict values, because they reach SQL.** Names match
  `[A-Za-z][A-Za-z0-9_]*` (at most 64 characters), values `[A-Za-z0-9_.-]*`
  (at most 64); anything else counts as absent and the default applies.
  Never accepted: `record`, `sid`, `section_id`, `parent_id`, `relation` and
  the interview endpoint keys `conversation_id`, `peek`, `revision`,
  `record_id`, `action`. A repeated name keeps its first declaration.
- **Filtering is page composition, not style logic.** Put ordinary `button`
  sections on the page whose `url` carries the parameters
  (`|my-page?module_id=module_1`, `|my-page?list_status=archived`) -
  typically rendered by one `loop` over a small catalog data table, so no
  module id is hardcoded anywhere. The list keeps the page query when it
  builds its New / Edit links and the interview sends it with every
  request, so a filtered list stays filtered and the endpoint resolves the
  same parameters as the page.
- **Draft partition.** Unsaved drafts (`context_key`) are partitioned by the
  declared parameters: sorted `name=value&…`, frozen at open - a draft
  opened for `module_id=module_1` is never reused for `module_2`. Every
  declared parameter counts, also a pure filter such as `list_status`.
  Without `extra_params` the key is a fingerprint of the immutable
  server-bound hidden context. A key above the 191 character column limit
  is hashed.
- **Pinned fields.** A declared parameter with a value whose name is also a
  form field **pins** that field (rule 2 above): hidden, read-only for the
  coach and for manual edits, seeded from the URL for a new draft and saved
  with the record. This replaces the old trick of a `d-none` field filled
  by the page.

Dev databases that already ran an earlier 1.6.0 work-in-progress script keep
an orphan `llm_conversation_scope` field. It is unused and can be removed:

```sql
DELETE FROM `sections_fields_translation` WHERE `id_fields` = get_field_id('llm_conversation_scope');
DELETE FROM `styles_fields` WHERE `id_fields` = get_field_id('llm_conversation_scope');
DELETE FROM `fields` WHERE `id` = get_field_id('llm_conversation_scope');
```

Clear the CMS cache after the migrations (field and style registrations).

## Pattern: status, archive, restore and history

Everything below is core `formUserInputLogList` behaviour - the interview
just keeps working with it:

1. Add a **hidden input** `status` with `value = active`. Every record that
   is created through the interview or the plain form starts as `active`;
   the participant never sees the field.
2. Add **quick actions** (JSON) and their labels (translatable JSON, keyed
   by action id):

   ```json
   [
     { "id": "archive",  "when": { "status": "active" },   "set": { "status": "archived" }, "confirm": false, "css": "woop-action-archive" },
     { "id": "activate", "when": { "status": "archived" }, "set": { "status": "active" },   "confirm": false, "css": "woop-action-activate" }
   ]
   ```

   ```json
   { "archive": "Archive", "activate": "Restore" }
   ```

   and `actions_order` (`["edit", "archive", "activate", "delete"]`).
3. Filter the list by status with a URL parameter
   ([`extra_params`](#url-parameters-extra_params) `list_status=active`):
   the same form is the "active" list by default and the "archived" list
   behind a plain button (`|my-page?list_status=archived`):

   ```
   AND IFNULL(status, 'active') = '{{__list_status__}}'
   ```

4. Enable `history_log` (every change keeps the previous values) and
   `llm_interview_include_history`: the coach receives the last snapshots
   as read-only context and can refer to what changed.
5. **Delete** stays the core soft delete: it asks for confirmation
   (`delete_title`, `delete_content`) and cannot be undone from the UI -
   point the participant to *Archive* in the confirmation text.

The Forest WOOP kit (one page whose shared interview form is filtered by
module and by status through plain buttons with URL parameters) is built on
exactly this pattern.

## Conversation scope and state

Each interview is one row in `llmConversations` with
`source_type = form_interview`, scoped by:

```
actor (id_users) + section (id_sections) + source + record (id_dataRows)
```

plus, for unbound create drafts, an immutable `context_key` recomputed
server-side from the declared URL parameters of `extra_params` (sorted
`name=value&…`, hashed above 191 characters) or, when none are declared,
from a fingerprint of immutable server-bound hidden values. Draft-scope identity
is frozen at open: a conversation opened for `module_id=1` can never be
reused for `module_id=2`, and client-supplied `context_key` values are
never trusted.

The interview state lives in the conversation's `state` column as an
envelope `{format_version: 1, revision, payload}`; `payload` is the
producer-owned draft payload:

```
{ phase: draft|approving|abandoned|approved,
  open_token, request: {id, status: pending|succeeded|failed},
  draft, inactive_values, schema_signature, last_coach_signature,
  base_record_fingerprint, opening_context, active_field, approval }
```

Every write is a revision-based compare-and-swap - stale clients get a
`conflict` result with the fresh projection so the UI can recover.

## Drafts, approval and retention

- **Drafts are never persisted.** Patches (chat-driven `manual`, native
  control `native`, LLM `collected_fields`) live only in the envelope
  until approval. `updateDraft` and `turn` merge atomically per revision.
- **Approval is two-phase inside one transaction:** `prepareApproval`
  locks the actor/record/conversation, verifies revision + record
  fingerprint + full requiredness + that there is something to save,
  builds the native `field[id]` / `field[value]` POST and marks the thread
  `approving`; the inherited controller then runs the real core write
  (GUMP, history log, form actions, memory hooks); `completeApproval` binds
  the conversation and its messages to the created/updated row, stores the
  receipt and marks `completed_at`. Any failure rolls the whole thing back -
  including redirect suppression on validation errors.
- **Idempotency:** `request_id` makes turns replayable; `request_token`
  makes approval replayable - a repeat approval returns the stored
  receipt without another row/history/job/memory action.
- **Retention** (`llm_interview_chat_retention`) decides which thread
  `open` resumes vs. replaces and what the launcher button says:

  | Value | Behaviour | Launcher button |
  |-------|-----------|-----------------|
  | `continue` (default) | Opening resumes the latest unfinished thread of the scope. | *Continue* (+ *Start over*) while an unfinished draft exists, else the `label_new`. |
  | `new_on_open` | Every explicit open starts a fresh thread; nothing is resumed or peeked. A reload or back/forward navigation while the workspace is open keeps the thread. | Always `label_new` (after minimizing, the launcher never offers *Continue*). |
  | `new_after_save` | Resumes an unfinished draft, but after an approved save the next open is fresh. | *Continue* while unfinished, else `label_new` (also right after a save). |

  **Blocked (danger/crisis) threads are never resumed.** `open` skips
  conversations with `blocked=1`, for every retention mode. A page reload
  or a new `new_on_open` start may therefore open a **fresh** interview
  for the same scope after a crisis block; the blocked transcript stays
  in `llmConversations` for admin audit. Within the blocked session the
  composer stays locked and the CMS `danger_blocked_message` is shown —
  that is not the same as a normal saved/`finished` completion.

  Clearing a thread is an explicit, confirmed action that abandons the
  draft and starts a fresh one. The old transcript stays in
  `llmConversations`/`llmMessages` for audit.
- **Continue after save:** the approval refreshes
  `base_record_fingerprint` so the same thread can keep editing the now
  existing record (`continue`/`new_after_save`).

## Conditions, hidden fields and native controls

- **Eligibility is rebuilt server-side** from base entry + accepted draft
  on every update/turn/approval - conditional containers and the root
  section condition resolve through the same core pipeline (entry,
  globals, `data_config` external bindings).
- A field that leaves the eligible tree keeps its value in private
  `inactive_values`: it is restored if the field re-enters, never shown
  to the participant or the model, and never saved while inactive.
- **Hidden fields** are excluded from interview questions, patches and
  the participant UI (they also never appear in the save dialog). A
  hidden input with a preset `value` is saved with that value (e.g.
  `status = active`). Eligible hidden facts may still appear in the model
  context as read-only `hidden_facts`;
  `llm_interview_writable_hidden_fields` is the explicit allowlist for
  LLM-writable hidden fields. User/system-private values are never
  exposed.
- **Native controls:** `native_only` fields always render their original
  widget inside the panel; supported (`manual_writable`) fields render
  it when `llm_interview_allow_manual_edit = 1`. The server emits each
  renderable control as a `data-field-name` fragment; the client adopts
  the original DOM (widgets, bindings, escaping), writes draft updates
  through `.value`/`.checked`, and syncs edits as debounced
  `native_patch` values through the same validation path. Typing never
  loses focus: server state is merged around the focused control. The
  fragments carry the field controls themselves in schema order -
  container markup (wrapping divs, card chrome) is not reproduced inside
  the panel; the full container layout stays on the record form/list
  around it.
- **File uploads:** there is no interview upload path. Upload-capable
  widgets keep their own original submit flow through the real record
  form; a plain `input[type=file]` inside the interview shows an
  explicit "unsupported" note rather than accepting fake paths.

## Prompt Lab preview

`llm_interview_context` is a Prompt Lab owner (`style_field` +
`llm_interview_context` slot) resolved to the **`interview_runtime`**
profile. The playground uses the standard chat message editor; a run:

1. instantiates a read-only `LlmFormInterviewPreviewModel` for the
   section (no component, no controller, URL record state pinned to
   "new" so a stray `?record=&sid=` can never preload a row);
2. seeds entry + draft from the playground **variables** - every
   compared model gets the same initial draft independently;
3. runs `LlmFormInterviewService::previewTurn` - the same context
   assembly, schema validation, semantic retry and draft merge as
   production, against a Prompt Lab conversation;
4. returns the coach text plus the merged **draft** and **progress**,
   shown in the result panel.

The edited draft prompt, model/temperature/max_tokens/reasoning_effort,
`llm_interview_include_history`, `llm_interview_writable_hidden_fields`,
`extra_params` (the declaration, not resolved values), `llm_use_user_language`
and `data_config` are the profile's companion fields - overridden for the
run (including the raw `data_config` template the runtime resolves),
snapshotted with the version. A preview has no page URL: `{{__name__}}`
URL parameters resolve to the defaults of the stored section declaration,
page-specific values come from the playground variables. Preview never
touches interview state, participant rows, approval or memory.

## Admin console

`moduleLlmAdminConsole` gained:

- **`source_type` filter** (values of the `llmConversationSourceType`
  lookup) - `form_interview` selects interview threads; **`record_id`
  filter** - a positive record id or the literal `draft` (unbound
  `id_dataRows IS NULL`). Bad values return HTTP 400.
- **Interview badge** from source metadata (not a title prefix):
  `autogenerated = false`, so interview threads are visible under the
  default "real conversations" view.
- **Record # / Unbound draft chips** in the list and the detail header,
  plus `completed_at` for approved threads. Abandoned and completed
  threads stay inspectable regardless of retention mode.

Interview threads never appear in the participant's chat sidebar
(`llmChat`). Calls that failed at the provider are logged as
non-validated `[API ERROR]` assistant messages for the admin; they are
never shown to the participant and never sent to the model again.

## Memory integration

`llm_memory_rule_ids` on `llmFormLogList` behaves exactly like the core
form field (v1.5.0 / v1.6.0 also on plain `formUserInputLogList`): after
the inherited `finished` save succeeds, the selected rules queue through
`LlmMemoryTriggerService`. The interview adds nothing extra - if the
approval/form save fails, nothing is saved and therefore nothing is queued.

## Boundaries

- No automatic persistence: only the explicit approval writes a record.
- The LLM can only submit schema-validated `collected_fields` - it cannot
  write `dataRows`, set ownership, pick the record or bypass GUMP.
- Ordinary form POSTs to the section (a crafted `__form_name` submit
  without the interview marker, or a marker for a foreign section) are
  rejected - quick actions and delete keep working; approval is the only
  write path for the interview draft.
- File inputs have no interview upload path (see above).
- Cross-section/cross-record/wrong-context/blocked/deleted/stale-revision
  references are rejected server-side; guests cannot open interviews.

## HTTP contract

Same-page `formUserInputLogList` request routing:
`__llm_interview_action` marker + `section_id` (must equal the section),
`csrf_token` on every mutation, `conversation_id` + `revision` on
mutations, `request_id` for `turn`, `request_token` for `approve`,
`open_token` for `open`/`reset`. `GET` `state` is read-only.

Because these requests carry no page of their own, SelfHelp does **not**
remember them as "last visited page" - a participant who logs in again is
sent to the real page, never to a JSON URL (core `Router` ignores `action`
and `__*` query parameters for the last-page redirect).

| Action | Method | Params |
|--------|--------|--------|
| `open` | POST | `record_id?` (empty = new draft), `open_token` |
| `state` | GET | `record_id?`, `conversation_id?` (omit to resolve the latest usable thread for the scope), `peek=1?` (answer `{success: true, state: null}` instead of `404 not_found` when there is no thread yet - used by the launcher's quiet resume check) |
| `turn` | POST | `conversation_id`, `revision`, `message` or `start=1`, `request_id`, `patch?`, `native_patch?` |
| `update_draft` | POST | `conversation_id`, `revision`, `patch?`, `native_patch?` |
| `reset` | POST | `record_id?`, `open_token`, `conversation_id` |
| `approve` | POST | `record_id?`, `conversation_id`, `revision`, `request_token`, `patch?`, `native_patch?` |
| `transcribe` | POST (multipart) | `audio` (file; type and size validated). Answers `{success: true, text}`; only available with `enable_speech_to_text`. Needs no conversation - it only turns a recording into text for the message box. |

Wire responses use `{success: true|false, ...}`. On success the body
includes `success: true` plus the service fields (`state`, and for
approve also `approval` / `idempotent`). On failure the body includes
`success: false`, `error: {type, message}`, and optionally `state` /
`field_errors` / `form_errors`. The service layer still returns
`{ok: true|false, ...}` internally; the controller maps that to
`success` for the HTTP client.

Error types: `unauthenticated`, `forbidden`, `not_found`, `conflict`,
`validation`, `rate_limited`, `llm_invalid`, `misconfigured`,
`invalid_state`, `approval_failed`, `csrf` (invalid or missing CSRF on
a mutation), `bad_request` (malformed params / envelopes / unknown
action / a crafted standalone save), `transcription`, `error`. The
participant projection contains `conversation_id`, `record_id`, `mode`
(`new`/`edit`), `revision`, `phase`, `draft`, `changed`, `baseline`,
`fields` (with `native_only`/`manual_writable`/`llm_writable`/`interview`
flags), `native_html`, `progress`, `messages`, `completed_at`,
`can_approve`, `needs_start`, `suggestions`, `asking` (the visible field the
coach is asking about right now - an answered one too while an entry is
edited - which the answers pane highlights) - never raw responses, sent
context, request payloads or private inactive values.
