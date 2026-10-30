# Global Danger Detection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Danger detection always runs on every LLM call carrying participant text, configured globally in LLM Settings with per-section overrides.

**Architecture:** A new `LlmSafetyConfigService` resolves keywords, notification emails and the blocked message (style value if non-empty, else global `sh_module_llm` value, else a built-in constant). `LlmDangerDetectionService`, the two context builders, the form controller and the playground read from it. The settings page gets a Safety group with a per-language blocked message.

**Tech Stack:** PHP 7.4+/8 (SelfHelp core), MySQL migrations, React + TypeScript (Vite), standalone PHP test scripts (`php tests/<Name>.php`).

**Spec:** `docs/superpowers/specs/2026-10-30-global-danger-detection-design.md`

## Global Constraints

- All SQL goes into `server/db/v1.6.0.sql` (unreleased). No new migration file, no cleanup of saved section values.
- Field names stay `danger_keywords`, `danger_notification_emails`, `danger_blocked_message`. `enable_danger_detection` is removed from every style and from all PHP code.
- A style value overrides (replaces) the global value when non-empty after trim. Never merge.
- Danger categories stay hard-coded in `assets/prompts/core/response/safety-detection.md`.
- Emails go through `add_and_execute_job` exactly as today.
- Style help text (all styles, all three fields): "Leave empty to use the global value from LLM Settings → Safety. A value here replaces the global value for this section."
- Settings languages come from the core `fetch_languages()` (`languages where id > 1`).
- Background LLM work (memory, scripts, evaluation, dataset import, prompt builder) is out of scope.
- Tests are standalone scripts in the style of `tests/LlmDangerDetectionServiceTest.php`: sandbox tree, stand-ins, no bootstrap, no DB.

## Review Focus

1. A style keyword field containing only separators (`" , ,"`) must count as empty and use the global keywords - otherwise one stray comma silently disables detection for a section. Test in Task 2.
2. In forms, a danger word inside a form field that is interpolated into the **system prompt** (not only the user prompt) must be detected - every text that reaches the LLM is scanned. Test in Task 5.
3. A participant language with no blocked-message translation, and a default language with none either, must still show a non-empty message (`LLM_DEFAULT_DANGER_BLOCKED_MESSAGE`). Test in Task 2.
4. Global keywords typed with capitals or umlauts (`Selbstmord`, `TÖTEN`) must match lowercase messages. Test in Task 2.
5. Saving `danger_notification_emails` with one bad address among good ones (`a@x.ch;nope`) must reject the whole save with 422 naming the bad entry, and save nothing. Test in Task 7.

---

### Task 1: Database and default constants

**Files:**
- Modify: `server/db/v1.6.0.sql` (section 7, lines ~340-375, plus a new section)
- Modify: `server/service/globals.php`
- Test: `tests/LlmGlobalsLoadTest.php`

**Interfaces:**
- Produces: constants `LLM_DEFAULT_DANGER_KEYWORDS` (string, the current llmFormLogList default list from v1.6.0 section 7) and `LLM_DEFAULT_DANGER_BLOCKED_MESSAGE` (string, the current default message text).
- Produces (DB): the three fields linked to pageType `sh_module_llm`, to the `sh_module_llm` page, and to styles `llmChat`, `llmFormLogList`, `llmFormRecord`, `llmFormLog` with `default_value = ''`.

- [ ] **Step 1: Write the failing test** - in `tests/LlmGlobalsLoadTest.php` assert both constants are defined, non-empty, and `LLM_DEFAULT_DANGER_KEYWORDS` contains `kill myself` and `selbstmord`.
- [ ] **Step 2: Run** `php tests/LlmGlobalsLoadTest.php` - expected FAIL (constant not defined).
- [ ] **Step 3: Add the two `define()`s to `globals.php`** next to the other `LLM_DEFAULT_*` constants.
- [ ] **Step 4: Run** `php tests/LlmGlobalsLoadTest.php` - expected PASS.
- [ ] **Step 5: Edit `v1.6.0.sql`**
  - Section 7: delete the `enable_danger_detection` INSERT; set `default_value` of the three INSERTs to `''`; replace help with the Global Constraints help text.
  - New section "Global danger detection":
    - `DELETE FROM styles_fields WHERE id_fields = get_field_id('enable_danger_detection') AND id_styles IN (get_style_id('llmChat'), get_style_id('llmFormLogList'));`
    - `UPDATE styles_fields SET default_value = '', help = <override help>` for the three fields on `llmChat`.
    - `INSERT IGNORE INTO styles_fields` the three fields for `llmFormRecord` and `llmFormLog`, default `''`, override help.
    - `INSERT IGNORE INTO pageType_fields` the three fields for `sh_module_llm` with global help ("Applies to every LLM section. A section can override it in its own field.").
    - `INSERT IGNORE INTO pages_fields_translation` for page `sh_module_llm`: `danger_keywords` (language 1, the default list), `danger_notification_emails` (language 1, `''`), `danger_blocked_message` via `INSERT ... SELECT` from `languages WHERE id > 1` with the default message.
- [ ] **Step 6: Verify** - apply `v1.6.0.sql` on the dev DB (re-run is safe: `INSERT IGNORE`), clear the SelfHelp cache, then:
  `SELECT COUNT(*) FROM styles_fields WHERE id_fields = get_field_id('enable_danger_detection');` → `0`;
  `SELECT id_languages, LEFT(content,30) FROM pages_fields_translation WHERE id_pages=(SELECT id FROM pages WHERE keyword='sh_module_llm') AND id_fields=get_field_id('danger_blocked_message');` → one row per system language.
- [ ] **Step 7: Commit** - `feat: global danger detection fields and defaults`

---

### Task 2: LlmSafetyConfigService

**Files:**
- Create: `server/service/LlmSafetyConfigService.php`
- Test: `tests/LlmSafetyConfigServiceTest.php` (new)

**Interfaces:**
- Consumes: constants from Task 1; style model raw getters `getDangerKeywords(): string`, `getDangerNotificationEmails(): string[]`, `getDangerBlockedMessage(): string` (any may be missing on a model → treat as empty, use `method_exists`).
- Produces:
  - `__construct($services, $model)`
  - `getKeywords(): string[]` - lowercased (`mb_strtolower`, UTF-8), trimmed, unique, non-empty, re-indexed.
  - `getNotificationEmails(): string[]` - split on `/[\n;]+/`, trimmed, non-empty.
  - `getBlockedMessage(): string` - never empty.
  - `protected loadGlobalFields(): array` - `name => value` from `CALL get_page_fields(page_id, user_lang, default_lang, '', '')` for `PAGE_LLM_CONFIG`; user language from `$_SESSION['user_language']`, default from the core default language. Returns `[]` and logs on any exception. Cached per instance.
  - Resolution rule: a style value counts as set when its **parsed** form is non-empty (keywords: list after parsing; emails: list; message: `trim() !== ''`). Global empty → `LLM_DEFAULT_DANGER_KEYWORDS` (keywords) / `LLM_DEFAULT_DANGER_BLOCKED_MESSAGE` (message) / `[]` (emails).

- [ ] **Step 1: Write the failing tests** (stand-in model + subclass overriding `loadGlobalFields()`):
  - `style_keywords_override_global`: style `"foo, bar"`, global `"suicide"` → `['foo','bar']`.
  - `separator_only_style_uses_global`: style `" , ,"`, global `"suicide"` → `['suicide']`.
  - `global_keywords_lowercased_utf8`: global `"Selbstmord, TÖTEN"` → `['selbstmord','töten']`.
  - `missing_global_uses_constant`: `loadGlobalFields()` returns `[]` → contains `'kill myself'`.
  - `style_emails_override_global`: style `['a@x.ch']`, global `"b@x.ch;c@x.ch"` → `['a@x.ch']`.
  - `empty_style_emails_use_global`: → `['b@x.ch','c@x.ch']`.
  - `blocked_message_style_wins`, `blocked_message_global_used`, `blocked_message_all_empty_uses_constant` (=== `LLM_DEFAULT_DANGER_BLOCKED_MESSAGE`).
- [ ] **Step 2: Run** `php tests/LlmSafetyConfigServiceTest.php` - expected FAIL (class missing).
- [ ] **Step 3: Implement** `LlmSafetyConfigService` extending `BaseLlmService` (for `$this->db`, logging).
- [ ] **Step 4: Run** `php tests/LlmSafetyConfigServiceTest.php` - expected all PASS.
- [ ] **Step 5: Commit** - `feat: resolve danger detection settings from style or global`

---

### Task 3: Detection service always on

**Files:**
- Modify: `server/service/LlmDangerDetectionService.php`
- Modify: `server/component/style/llmChat/LlmChatModel.php:582-598`
- Modify: `server/component/style/llmFormLogList/LlmFormLogListModel.php:845-883`
- Modify: `server/service/LlmPromptPlaygroundService.php:1175-1178`
- Test: `tests/LlmDangerDetectionServiceTest.php`

**Interfaces:**
- Consumes: `LlmSafetyConfigService` (Task 2).
- Produces: `LlmDangerDetectionService::__construct($services, $model, $safety_config = null)` (null → `new LlmSafetyConfigService($services, $model)`); `isEnabled()` **deleted**; `getKeywords()` delegates to the config; `handleDetection()` uses `getBlockedMessage()`; `sendNotifications()` uses `getNotificationEmails()`.
- Models: `isDangerDetectionEnabled()` deleted everywhere; `LlmFormLogListModel::getDangerBlockedMessage()` returns `get_db_field('danger_blocked_message', '')` (no hard-coded text); `LlmChatModel::getDangerBlockedMessage()` likewise.

- [ ] **Step 1: Update the tests** - drop the enable flag from the stand-in model; add `detects_without_enable_flag` (keywords `"kill myself"`, message `"I want to kill myself"` → detected); `no_keywords_anywhere_is_noop` (config stub returns `[]` → `detectKeywords()` === `[]`, no job scheduled); `safety_unavailable_logged_always` (`processSafetyDetection` with unassessed response → one `llm_safety_unavailable` transaction); `critical_llm_safety_notifies_without_flag` (critical level → one email job).
- [ ] **Step 2: Run** `php tests/LlmDangerDetectionServiceTest.php` - expected FAIL on the new cases.
- [ ] **Step 3: Implement** the interface changes above; remove every `isEnabled()` gate.
- [ ] **Step 4: Run** `php tests/LlmDangerDetectionServiceTest.php` and `php tests/LlmChatControllerTest.php` - expected PASS.
- [ ] **Step 5: Verify no leftovers** - `grep -rn "isDangerDetectionEnabled\|enable_danger_detection" server` → only `LlmPromptExecutionProfileService.php` (removed in Task 6).
- [ ] **Step 6: Commit** - `feat: danger detection always runs with global settings`

---

### Task 4: Safety prompt always in chat and interview context

**Files:**
- Modify: `server/service/LlmContextService.php:140-175` (`buildDangerConfig`)
- Modify: `server/service/LlmFormInterviewContextBuilder.php:183-200` (`buildDangerConfig`)
- Test: `tests/LlmFormInterviewServiceTest.php`

**Interfaces:**
- Consumes: `LlmSafetyConfigService::getKeywords()`.
- Produces: both `buildDangerConfig()` return `['enabled' => true, 'keywords' => (new LlmSafetyConfigService($services, $model))->getKeywords()]` - no `method_exists`/flag checks.

- [ ] **Step 1: Write the failing test** `interview_context_contains_safety_block_without_flag` - model stand-in without any enable getter, no style keywords, global keywords `"kill myself"`: the built system messages contain `CRITICAL SAFETY DETECTION` and `kill myself`.
- [ ] **Step 2: Run** `php tests/LlmFormInterviewServiceTest.php` - expected FAIL.
- [ ] **Step 3: Implement** both `buildDangerConfig()` changes.
- [ ] **Step 4: Run** `php tests/LlmFormInterviewServiceTest.php`, `php tests/LlmResponseServiceSafetyTest.php`, `php tests/LlmChatControllerTest.php` - expected PASS.
- [ ] **Step 5: Commit** - `feat: always add safety instructions to chat and interview context`

---

### Task 5: Form feedback (llmFormRecord / llmFormLog)

**Files:**
- Modify: `server/component/style/llmFormRecord/LlmFormModel.php` (add getters near `getLlmContext()`)
- Modify: `server/component/style/llmFormRecord/LlmFormController.php:448-560` (`callLlmWithData`)
- Test: `tests/LlmFormControllerTest.php`

**Interfaces:**
- Consumes: `LlmDangerDetectionService::detectKeywords(string): string[]`, `handleDetection(array, string, int, null): array`, `getCriticalSafetyContext(): ?string` (Task 3).
- Produces: `LlmFormModel::getDangerKeywords(): string`, `getDangerNotificationEmails(): string[]`, `getDangerBlockedMessage(): string` (raw `get_db_field`, default `''`). `callLlmWithData()` on detection returns `['success' => true, 'llm_result' => <blocked message>, 'llm_meta' => [... 'status' => 'safety_blocked', 'detected_keywords' => [...]], 'error' => null]`.

- [ ] **Step 1: Write the failing tests**
  - `danger_word_in_user_prompt_skips_llm`: form data `{"feeling":"I want to kill myself"}` → LlmService stand-in records **zero** `callLlmApi` calls; result `llm_meta.status === 'safety_blocked'`; `llm_result` equals the blocked message; one email job scheduled; conversation not blocked (`blocked` update never issued).
  - `danger_word_in_interpolated_context_detected`: context `"Reflect on {{note}}"`, form data `{"note":"kill myself"}` where `note` is only in the context → still `safety_blocked`.
  - `safe_submission_has_safety_block`: safe data → the system message passed to `callLlmApi` contains `CRITICAL SAFETY INSTRUCTION`.
- [ ] **Step 2: Run** `php tests/LlmFormControllerTest.php` - expected FAIL.
- [ ] **Step 3: Implement** - scan text = `$user_prompt . "\n" . implode("\n", <string values of $filtered_form_data>)`; run detection **after** the rate-limit check and conversation resolve, **before** logging the user prompt; on detection call `handleDetection($detected, $scan_text, $user_id, null)`; otherwise append `getCriticalSafetyContext()` (when non-null) to `$system_prompt` before building `$messages` and `$sent_context`.
- [ ] **Step 4: Run** `php tests/LlmFormControllerTest.php` - expected PASS.
- [ ] **Step 5: Commit** - `feat: danger detection for LLM form feedback`

---

### Task 6: Playground and interview preview

**Files:**
- Modify: `server/service/LlmPromptExecutionProfileService.php:204, 415`
- Modify: `server/service/LlmPromptPlaygroundService.php` (chat run entry that builds the user message, ~line 362)
- Modify: `server/service/LlmFormInterviewService.php:1745` (`previewTurn`)
- Test: `tests/AjaxLlmPromptLabTest.php`, `tests/LlmFormInterviewServiceTest.php`

**Interfaces:**
- Consumes: `LlmDangerDetectionService::detectKeywords()` only (never `handleDetection` - no email, no audit log).
- Produces: playground run result and `previewTurn()` result gain `'detected_keywords' => string[]` (empty when safe). The LLM call still runs, so admins see the model's safety answer too.

- [ ] **Step 1: Write the failing tests** - `playground_reports_detected_keywords_without_side_effects` (message with `kill myself` → `detected_keywords === ['kill myself']`, zero scheduled jobs, zero transactions); `preview_turn_reports_detected_keywords` (same for `previewTurn`); `execution_profile_has_no_enable_flag` (snapshot has no `enable_danger_detection` key).
- [ ] **Step 2: Run** both test files - expected FAIL.
- [ ] **Step 3: Implement** - remove `enable_danger_detection` from the profile fields list and snapshot; add the `detectKeywords()` call and result key in both services.
- [ ] **Step 4: Run** both test files - expected PASS; `grep -rn "enable_danger_detection" server react/src` → only `server/db/` hits.
- [ ] **Step 5: Commit** - `feat: show danger keyword hits in playground and preview`

---

### Task 7: Settings backend (Safety group)

**Files:**
- Modify: `server/component/sh_module_llm/Sh_module_llmModel.php` (`getStructuredSettings`, `saveSetting`, `validateSettings`)
- Modify: `server/component/sh_module_llm/Sh_module_llmController.php:142-147` (`$allowedFields`)
- Modify: `server/service/LlmSettingsValidator.php`
- Test: `tests/Sh_module_llmControllerTest.php`, `tests/LlmApiKeysConfigServiceTest.php` (validator section)

**Interfaces:**
- Produces:
  - `getStructuredSettings()['safety']` = `['label' => 'Safety', 'fields' => [danger_keywords (textarea), danger_notification_emails (textarea), danger_blocked_message (markdown)]]`. `danger_blocked_message` field carries `'translatable' => true`, `'value' => ['<id_languages>' => content, ...]`, `'languages' => [['id' => int, 'locale' => string, 'language' => string], ...]` from `fetch_languages()`.
  - `saveSetting($fieldName, $value, $languageId = self::CONFIG_LANGUAGE_ID)`.
  - Posted `danger_blocked_message` is an object `{ "<id_languages>": "markdown" }`; each key must be a known language id (else 422) and is saved with that language id.
  - `LlmSettingsValidator::validate('danger_notification_emails', $raw, $ctx)`: normalise to newline-separated; any entry failing `FILTER_VALIDATE_EMAIL` → error `"Not a valid email address: <entry>"`. Empty is valid. `danger_keywords`: trimmed, any string valid.

- [ ] **Step 1: Write the failing tests** - `saves_safety_fields`; `invalid_email_rejects_whole_save` (`a@x.ch;nope` plus a valid `llm_timeout` → 422, error mentions `nope`, nothing saved); `blocked_message_saved_per_language` (`{"2":"de text","3":"en text"}` → two rows with ids 2 and 3); `unknown_language_rejected` (`{"99":"x"}` → 422); `structured_settings_has_safety_group` (group present, message field has `languages`).
- [ ] **Step 2: Run** `php tests/Sh_module_llmControllerTest.php` and `php tests/LlmApiKeysConfigServiceTest.php` - expected FAIL.
- [ ] **Step 3: Implement** the interfaces above.
- [ ] **Step 4: Run** both - expected PASS.
- [ ] **Step 5: Commit** - `feat: safety settings on the LLM settings page`

---

### Task 8: Settings UI (Safety section)

**Files:**
- Create: `react/src/components/settings/SafetySection.tsx`
- Modify: `react/src/components/settings/SettingsApp.tsx` (render after `ModelDefaultsSection`, ~line 260; include safety fields in the save payload)
- Modify: `react/src/components/settings/Settings.css` (only if needed)

**Interfaces:**
- Consumes: the `safety` group from Task 7 (shape above).
- Produces: `SafetySection({ fields, values, onChange, errors })` following `ModelDefaultsSection`'s prop pattern; blocked message edited per language via a language `<select>` over `field.languages`, posting the full `{ id: text }` map.

- [ ] **Step 1: Implement** - keywords textarea (help: comma-separated), emails textarea, language select + markdown textarea for the message; a Bootstrap `alert-warning` "No notification email set - detections are only written to the audit log." when the emails value is empty after trim; field errors from the 422 shown under each field like the other sections.
- [ ] **Step 2: Typecheck** - `cd react && npm run typecheck` → no errors.
- [ ] **Step 3: Build** - `cd react && npm run build` → succeeds, `js/ext/llm-settings.umd.js` updated.
- [ ] **Step 4: Manual check** - open `/admin/module_llm`: Safety group visible; switch language, edit, save, reload → each language keeps its text; enter `nope` in emails → save refused with the message under the field; clear emails → warning shown.
- [ ] **Step 5: Commit** - `feat: safety section in LLM settings UI`

---

### Task 9: Docs and end-to-end check

**Files:**
- Modify: `doc/danger-word-detection.md`, `doc/danger-detection-troubleshooting.md`, `README.md`, `CHANGELOG.md` (v1.6.0 entry)

- [ ] **Step 1: Update docs** - always on; global settings location; style override rule (replace, not merge); coverage table from the spec; forms have no LLM-side assessment; troubleshooting: "no email received" → check global and section email fields and the scheduled jobs list.
- [ ] **Step 2: Run all PHP tests** - `for f in tests/*Test.php; do php "$f" || echo "FAIL $f"; done` → no `FAIL` lines.
- [ ] **Step 3: Manual end-to-end** on the dev site with global emails set and no section overrides:
  - llmChat: send `I want to kill myself` → blocked message shown, conversation blocked, email job in scheduled jobs.
  - llmFormRecord: submit a form with a danger word → blocked message as feedback, email job present, no LLM call in the admin console.
  - Set a section's own email → the next detection emails only that address.
- [ ] **Step 4: Commit** - `docs: global danger detection`
