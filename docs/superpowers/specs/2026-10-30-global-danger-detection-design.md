# Global Danger Detection - Design

Date: 2026-10-30
Branch: feature/woop
Release: v1.6.0 (unreleased - no backward-compatibility migration needed)

## Goal

Danger detection is always on for every LLM call that carries participant text.
Keywords, notification emails and the blocked message are configured once in the
global LLM settings (`sh_module_llm`). A style section can override each value; an
empty style value uses the global one.

## Decisions

| Topic | Decision |
|---|---|
| Enable checkbox | Removed. Detection always runs. |
| Global keywords | `danger_keywords` on `sh_module_llm`, seeded by `v1.6.0.sql` with the existing bilingual default list. |
| Global emails | `danger_notification_emails` on `sh_module_llm`, empty by default, configured by an admin. |
| Global blocked message | `danger_blocked_message` (already `display = 1`, translatable) on `sh_module_llm`, seeded for every system language. |
| Style values | Override (replace) the global value when non-empty after trim. Not merged. |
| Existing section values | Kept as-is; they act as overrides. No cleanup. |
| Style defaults | `styles_fields.default_value` for the three fields becomes `''` on llmChat and llmFormLogList so unset sections fall back to global. |
| Danger categories (topics) | Stay hard-coded in `assets/prompts/core/response/safety-detection.md`. |
| Emails | Sent through the SelfHelp job scheduler (`add_and_execute_job`), as today. |
| Other global fields | Out of scope (`llm_use_user_language`, speech-to-text model, base instructions). |

## Coverage

| Entry point | Keyword pre-check | Safety prompt | LLM safety assessment |
|---|---|---|---|
| llmChat send / form-mode submit | yes (existing, now always on) | yes | yes |
| llmChat form-mode auto-start (fixed text) | n/a | yes | yes |
| llmFormLogList interview turn | yes (existing, now always on) | yes | yes |
| llmFormRecord / llmFormLog `callLlmWithData()` | **new** | **new** (`critical-safety.md`) | no (plain-text response) |
| Prompt playground / interview `previewTurn` | **new**, result only (no email, no audit log) | yes | as live |

Out of scope (no participant message): memory updates, scripts, evaluation judge,
dataset AI import, prompt builder. `llmResponse` only renders stored output.

## Components

### `LlmSafetyConfigService` (new, `server/service/LlmSafetyConfigService.php`)

Constructed with `$services` and the style model. Public API:

- `getKeywords(): string[]` - style `getDangerKeywords()` if non-empty after trim, else
  global `danger_keywords`. Parsed: comma split, trim, `mb_strtolower`, unique, non-empty.
- `getNotificationEmails(): string[]` - style `getDangerNotificationEmails()` if non-empty,
  else global `danger_notification_emails` (split on newline / semicolon, trimmed).
- `getBlockedMessage(): string` - style `getDangerBlockedMessage()` if non-empty, else the
  global message in the participant's language with default-language fallback
  (`CALL get_page_fields(page, user_lang, default_lang, '', '')`).

Global values are read from the `sh_module_llm` page (`PAGE_LLM_CONFIG`), cached per request.

### Style models

- `LlmChatModel`, `LlmFormLogListModel`: delete `isDangerDetectionEnabled()`; the three
  getters return the raw field value (no hard-coded fallback message in
  `LlmFormLogListModel::getDangerBlockedMessage()`).
- `LlmFormModel` (llmFormRecord / llmFormLog): add raw getters for the three fields.
- `LlmPromptPlaygroundService`: delete `isDangerDetectionEnabled()`; keep raw runtime getters.

### `LlmDangerDetectionService`

- Reads keywords, emails and blocked message from `LlmSafetyConfigService`.
- `isEnabled()` removed. `detectKeywords()` returns `[]` only when no keywords exist.
- `getCriticalSafetyContext()` returns the block whenever keywords exist.
- `processSafetyDetection()`: no enabled gate on logging the unavailable assessment or on
  sending notifications.

### Context builders

`LlmContextService::buildDangerConfig()` and `LlmFormInterviewContextBuilder::buildDangerConfig()`
return `['enabled' => true, 'keywords' => LlmSafetyConfigService::getKeywords()]` - the
safety instruction is always added.

### `LlmFormController::callLlmWithData()`

1. Build the user prompt as today.
2. Keyword pre-check via `LlmDangerDetectionService::detectKeywords()` on all participant
   text sent to the LLM: the user prompt plus the form values interpolated into the context.
3. On detection: resolve the conversation (as today), call `handleDetection()` with
   `conversation_id = null` (the form conversation is **not** blocked), skip the LLM call,
   return `success => true`, `llm_result => <blocked message>`,
   `llm_meta.status => 'safety_blocked'`.
4. Otherwise append `getCriticalSafetyContext()` to the system prompt and call the LLM.

### Playground / `previewTurn`

Use the same global fallback. Keyword hits are reported in the result; no email, no
transaction log. Drop `enable_danger_detection` from `LlmPromptExecutionProfileService`
(fields list and snapshot).

### Settings page (`sh_module_llm`)

- New group `safety` in `Sh_module_llmModel::getStructuredSettings()`:
  `danger_keywords` (textarea), `danger_notification_emails` (textarea),
  `danger_blocked_message` (markdown, translatable).
- Languages for the blocked message come from the core `fetch_languages()` (`id > 1`).
  The field value sent to the client is a map `{ id_languages: content }`.
- `Sh_module_llmController::handleSaveConfig()` whitelists the three fields.
  `validateSettings()` rejects any invalid email (422, nothing saved).
- `saveSetting()` gains a language argument; the blocked message is saved per language
  row in `pages_fields_translation`; other fields keep `CONFIG_LANGUAGE_ID = 1`.
- UI (`react/src/components/settings/SettingsApp.tsx`): Safety section with a language
  picker for the blocked message; warning when the email list is empty
  ("alerts are only written to the audit log").

### Database (`server/db/v1.6.0.sql`)

- Link the three fields to `pageType` `sh_module_llm` and to the `sh_module_llm` page,
  with global help text ("Sections can override this value...").
- Seed `pages_fields_translation`: keywords (language 1), blocked message (each
  language from `languages where id > 1`), emails empty.
- `DELETE` `styles_fields` rows for `enable_danger_detection` on llmChat and llmFormLogList
  (remove the llmFormLogList `INSERT` in section 7).
- `UPDATE styles_fields SET default_value = ''` and new help for the three fields on
  llmChat; edit the llmFormLogList `INSERT`s the same way.
- Link the three fields to `llmFormRecord` and `llmFormLog` with `''` defaults and the
  override help.
- Style help text: "Leave empty to use the global value from LLM Settings -> Safety. A
  value here replaces the global value for this section."

## Error handling

- Global page missing or unreadable: keywords fall back to `[]` is NOT acceptable for a
  safety feature - log an error and fall back to the built-in default keyword list
  constant (`LLM_DEFAULT_DANGER_KEYWORDS` in `globals.php`).
- Blocked message empty in the style, the participant language and the default language:
  use the built-in constant `LLM_DEFAULT_DANGER_BLOCKED_MESSAGE` (the current default text).
- No emails anywhere: detection still blocks and logs; `sendNotifications()` returns false.
- Invalid email at send time: skipped with a warning (existing behaviour).

## Testing

- `tests/LlmSafetyConfigServiceTest.php` (new): style override wins; empty / whitespace
  style uses global; blocked-message language fallback; missing global page uses the
  default constant.
- `tests/LlmDangerDetectionServiceTest.php`: detects without any enable flag; no-op with
  no keywords.
- `tests/LlmFormControllerTest.php`: keyword hit skips the LLM, returns the blocked
  message with `safety_blocked`, schedules the email; safe submission's system prompt
  contains the critical-safety block.
- `tests/Sh_module_llmControllerTest.php`: three fields save; invalid email -> 422;
  blocked message saved per language.
- Manual: global values set, chat with no section override and a form submission with a
  danger word; email job visible in scheduled jobs.

## Docs

Update `doc/danger-word-detection.md`, `doc/danger-detection-troubleshooting.md`,
`README.md`, and add a `CHANGELOG.md` v1.6.0 entry.
