# LLM Request Traces Implementation Plan (revision 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every LLM call gets a small, hash-verified trace (row refs + deduplicated text chunks + params) so the large `sent_context` / `request_payload` blobs can stop being written, without an outage or a destructive migration.

**Architecture:** `LlmService::callLlmApi` builds the trace from the neutral `$messages` it receives: messages identical to a stored history row become `{"m": id}`, everything else becomes text chunks, shared (declared by code) or per-user. `LlmRequestTraceService` saves the trace in the same `LlmTransaction` as the message, rebuilds it immediately and compares the hash; on any problem the message keeps its blobs. A setting switches from dual-write to trace-only.

**Tech Stack:** PHP 8.2+ (plugin README requirement; `array_is_list` needs 8.1+), MySQL migrations, React + TypeScript (Vite), standalone PHP test scripts (`php tests/<Name>.php`).

**Spec:** `docs/superpowers/specs/2026-10-30-llm-request-traces-design.md`

## Global Constraints

- Release **v1.7.0**, after v1.6.0: all request-trace SQL goes into a new `server/db/v1.7.0.sql` (run after `v1.6.0.sql`). No column drop, no legacy table. Idempotent; version bump stays last.
- New FK columns are `INT(10) UNSIGNED ZEROFILL`.
- No change to how prompts are built or interpolated. The trace observes the `$messages` passed to `callLlmApi`; it never re-renders templates.
- An LLM request is never refused because of tracing. Any trace failure or self-check mismatch → the message keeps `sent_context` + `request_payload`, and `LlmErrorReporter::log('trace_fallback: <reason>', ...)`.
- Text is per-user unless the code declared it shared with `LlmTraceHints::share()`. Shared text comes only from code and global config.
- `request_hash` = `sha256(canonical_json({provider, params, messages}))`: `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, object keys sorted recursively, list order kept, `serialize_precision = -1` set and restored inside the canonicalizer, every `data:` URL replaced by `sha256:<hex of the data URL>`.
- `params` = the adapted payload without `messages` and `system`.
- Message + trace writes run in one `LlmTransaction` (`tests/LlmConventionsTest.php` must pass).
- Setting `llm_trace_store_blobs` defaults to `1` (dual-write).
- Tests are standalone scripts in the style of `tests/LlmDangerDetectionServiceTest.php`: stand-ins, no DB.

## Decided during implementation

These replace the matching task text below.

- Task 4: no `getLlmDerivative()` and no `.llm.txt`. The image derivative is
  written on first send (`encodeImageForApi`); documents are already stable. A
  `Login::delete_user` overwrite hook (`onDeleteUserLlmFiles`) also unlinks
  files on a core user delete.
- Task 5: the hints are not reset per call (a retry reuses the built context);
  `LlmTraceHints::image()` declares each sent image so `{"x"}` chunks hold
  `{"img", "sha256"}` instead of base64. Mode, floating, media and suggestion
  texts are covered by the asset loader; the language rules call `share()`;
  strict-mode text stays per-user (interpolated author context).
- Task 6: message + trace in one `LlmTransaction`, `record()` in its own
  savepoint. Both interview calls pass `trace_rows`; the chat auto-start sends
  no history.
- Task 7: no Prompt Lab UI change. System entries are unreachable through its
  access checks; archived entries are never opened again.
- Task 7: section prompts are resolve-only (never created by the linker); the
  interview reports `llm_interview_context`.
- Task 10: `untraced` counts only model replies (`raw_response` set) from the
  first trace on; canned assistant texts have no trace by design. Readers
  rebuild only rows without a blob.

## Review Focus

1. A retry where the assistant echo is truncated (`RETRY_ECHO_MAX_CHARS`) must be stored as text, not as `{"m"}`, and rebuild must still verify. Test in Task 5.
2. A section prompt containing participant data (`{{name}}` filled with `O'Brien`) must end up in a per-user chunk, never in a shared one, even though its surrounding text is shared. Test in Task 5.
3. On Anthropic (top-level `system`), `params_json` must not contain the system prompt. Test in Task 6.
4. Deleting a user must leave no row in `llmTraceChunks` with that user's `owner`, and no file of theirs on disk. Test in Task 8.
5. An assets file checked out with CRLF must produce the same hash as with LF. Test in Task 2.

---

### Task 0: Release as v1.7.0

**Files:**
- Create: `server/db/v1.7.0.sql` (request-trace schema; run after `v1.6.0.sql`; version bump to `v1.7.0` last)
- Modify: code, tests and docs that name the release (`CHANGELOG.md`, `README.md`, …)

- [ ] **Step 1:** Add `v1.7.0.sql` and the CHANGELOG `[1.7.0]` section (with upgrade notes).
- [ ] **Step 2: Verify:** migration path and version bump are `v1.7.0`; every `php tests/*Test.php` passes.
- [ ] **Step 3: Commit** `chore: release as v1.7.0`

### Task 1: Stop sending `sent_context` to participants

**Files:**
- Modify: `server/service/LlmService.php:982` (`getConversationMessages` SELECT)
- Test: `tests/LlmServiceGuestTest.php` (or a new `tests/LlmConversationMessagesTest.php`)

**Interfaces:**
- Produces: `getConversationMessages()` rows have keys `id, role, content, attachments, model, tokens_used, timestamp` only.

- [ ] **Step 1: Failing test** `conversation_messages_have_no_sent_context`: the DB stand-in captures the SQL; assert it does not contain `sent_context`.
- [ ] **Step 2: Run** it. Expected: FAIL.
- [ ] **Step 3:** Remove `m.sent_context` from the SELECT. Check `grep -rn "sent_context" react/src` hits only admin components.
- [ ] **Step 4: Run** it plus `LlmChatControllerTest`, `LlmFormInterviewServiceTest`. Expected: PASS.
- [ ] **Step 5: Manual:** open a chat, network tab: `get_conversation` response has no `sent_context`.
- [ ] **Step 6: Commit** `fix: participants no longer receive the sent context`

### Task 2: LF-only prompt assets

**Files:**
- Create: `.gitattributes` (`assets/prompts/** text eol=lf`)
- Modify: `server/service/prompt/LlmPromptAssetLoader.php:63-68` (normalize `\r\n` and `\r` to `\n` before `trim`)
- Test: `tests/LlmPromptAssetLoaderTest.php`

- [ ] **Step 1: Failing test** `crlf_and_lf_assets_load_identically` (sandbox dir with the same asset in CRLF and LF → equal strings).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3:** Implement; then `git add --renormalize assets/prompts`.
- [ ] **Step 4: Run.** Expected: PASS. `git ls-files --eol assets/prompts` shows `w/lf`.
- [ ] **Step 5: Commit** `chore: prompt assets are LF everywhere`

### Task 3: Schema and setting

**Files:**
- Modify: `server/db/v1.6.0.sql` (new section "Request traces", before the version bump)
- Modify: `server/component/sh_module_llm/Sh_module_llmModel.php`, `Sh_module_llmController.php` (`$allowedFields`), `react/src/components/settings/SettingsApp.tsx` (checkbox "Store full request payloads" in the existing advanced/debug group)
- Modify: `server/service/globals.php` (`LLM_TRACE_FORMAT_VERSION = 1`, `LLM_PROMPT_OWNER_SYSTEM = 'system'`)
- Test: `tests/Sh_module_llmControllerTest.php` (extend)

**Interfaces:**
- Produces (DB): the tables and columns listed in the spec, "Tables". `llmTraceChunks` unique `(owner, sha256)`; `llmRequestTraces.id_llmMessages` FK `ON DELETE CASCADE`; `llmTracePromptVersions` FK to versions `RESTRICT`; `fk_llm_prompt_locales_entry` and `fk_llm_prompt_versions_locale` → `RESTRICT`.
- Produces: setting `llm_trace_store_blobs` (checkbox, default `1`), read with `Sh_module_llmModel`'s existing getters.

- [ ] **Step 1: Failing test** `saves_trace_store_blobs_setting`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3:** Write the SQL section and the setting.
- [ ] **Step 4: Verify** on the dev DB, applied twice: tables exist; `SHOW CREATE TABLE llm_prompt_versions` shows `RESTRICT`; the setting shows on `/admin/module_llm` and saves. Run the test. Expected: PASS. `cd react && npm run typecheck && npm run build` → success.
- [ ] **Step 5: Commit** `feat: request trace tables and trace setting`

### Task 4: Stable attachments and file cleanup

**Files:**
- Modify: `server/service/LlmFileUploadService.php` (write the derivative at upload)
- Modify: `server/service/LlmApiFormatterService.php:193-242, 303-354, 502-530`
- Modify: `server/component/LlmHooks.php:2281-2308` (`onCleanUserDataLlmCleanup`)
- Test: `tests/LlmApiFormatterServiceTest.php` (extend), `tests/LlmHooksCleanupTest.php` (extend)

**Interfaces:**
- Produces: `LlmApiFormatterService::getLlmDerivative(string $fullPath, array $attachment): ?string`. It returns the path of `<path>.llm.jpg` (image, today's `getOptimizedImageData` rules, collage-aware) or `<path>.llm.txt` (document text), creating it when missing.
- Produces: `LlmApiFormatterService::formatStoredMessage(array $row, bool $isVisionModel): array`. It is today's per-message body of `convertToApiFormat`, extracted so send and rebuild share it.
- Cleanup: before `remove_by_fk('llmConversations', ...)`, the hook unlinks every attachment path and its derivatives for that user's messages (`resolveUploadFile` rules).

- [ ] **Step 1: Failing tests:** `derivative_created_once` (second send doesn't rewrite the file, by mtime); `same_data_url_every_turn`; `old_upload_gets_derivative_on_first_send`; `cleanup_unlinks_user_files`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** them plus `LlmFormInterviewServiceTest`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: LLM-ready attachment files, cleanup unlinks user files`

### Task 5: Trace builder (pure)

**Files:**
- Create: `server/service/trace/LlmTraceHints.php`, `server/service/trace/LlmTraceBuilder.php`, `server/service/trace/LlmTraceCanonicalizer.php`
- Modify: `LlmResponseService::buildResponseContext` / `addSafetyInstructions`, `LlmChatModel::getParsedConversationContext` (media block), `LlmContextService::applyLanguageContext`, `LlmFormModeService`, `LlmFloatingModeService`, `LlmStrictConversationService` (each calls `LlmTraceHints::share($text)` on the text it adds; no change to the text)
- Test: `tests/LlmTraceBuilderTest.php`

**Interfaces:**
- Produces: `LlmTraceHints::share(string $text): void`, `LlmTraceHints::sharedTexts(): string[]`, `LlmTraceHints::reset(): void` (request-scoped static; reset by `callLlmApi` after building).
- Produces: `LlmTraceCanonicalizer::hash(string $provider, array $params, array $messages): string` (rules in Global Constraints).
- Produces: `LlmTraceBuilder::build(array $messages, array $candidate_rows, callable $format_row, array $shared_texts, int $user_id): array{recipe:array, chunks:array<string,array{owner:int, content:string}>, refs:int[]}`.
  - For each message, the next candidate row (in order) whose `$format_row(row)` equals the message → `{"m": id}` (row added to `refs`); else `{"role", "c": [sha...]}`.
  - Content split around the first occurrence of each shared text; shared pieces get `owner 0`, the rest `owner = $user_id`. Array content → one per-user chunk with JSON parts and data URLs replaced by `{"img": path, "sha256": hex}`.
- Produces: `LlmTraceBuilder::rebuildMessages(array $recipe, array $chunks_by_sha, array $rows_by_id, callable $format_row): array`.

- [ ] **Step 1: Failing tests** (Review Focus 1, 2): `identical_history_row_becomes_ref`; `truncated_retry_echo_becomes_text`; `shared_block_split_out_and_rest_per_user` (system message = schema + `"\n\n"` + safety + `"\n\nName: O\\'Brien"` → two shared chunks, two per-user chunks); `unhinted_text_is_per_user`; `rebuild_roundtrip_equal` (`rebuildMessages(build(x)) === x`, including multimodal parts); `hash_stable_under_key_order_and_float_precision`; `hash_ignores_base64_bytes_not_text`.
- [ ] **Step 2: Run** `php tests/LlmTraceBuilderTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.** Add the `share()` calls.
- [ ] **Step 4: Run** it plus `LlmResponseServiceSafetyTest`, `LlmPlaygroundSafetyTest`, `LlmChatControllerTest`. Expected: PASS, rendered text unchanged.
- [ ] **Step 5: Commit** `feat: request trace builder and canonical hash`

### Task 6: Record traces in `callLlmApi`, with self-check and fallback

**Files:**
- Create: `server/service/trace/LlmRequestTraceService.php`
- Modify: `server/service/LlmService.php:786-918` (`addMessage` writes blobs only when the setting is on or a fallback is needed), `LlmService.php:1575-1740` (`callLlmApi`: build, save, self-check, inside `LlmTransaction`; the error-path `addMessage` calls too)
- Modify: every caller that loads history passes it as `log_options['trace_rows']`: `LlmChatController` (2), `LlmFormInterviewService` (2), `LlmFormInterviewContextBuilder`. Callers without history pass nothing (all text chunks).
- Test: `tests/LlmRequestTraceServiceTest.php`

**Interfaces:**
- Produces: `LlmRequestTraceService::record(int $message_id, int $user_id, string $provider, array $params, array $messages, array $candidate_rows): array{trace_id:?int, verified:bool, fallback:?string}`. It writes chunks (`INSERT IGNORE` on `(owner, sha256)`), the trace, prompt links (Task 7), and `UPDATE llmMessages SET trace_locked = 1 WHERE id IN (refs) AND trace_locked = 0`, then rebuilds from the DB and compares hashes.
- Behavior in `callLlmApi`: one `LlmTransaction` around `addAssistantMessageFromApiResponse` + `record`. `record` failing or `verified=false` → the message row is updated with the blobs and `trace_fallback` is logged. The transaction still commits; the participant flow is never interrupted.
- Provider: `$provider->getProviderId()` (add to `LlmProviderInterface` if missing). `params` = adapted payload minus `messages`/`system`.

- [ ] **Step 1: Failing tests** (Review Focus 3): `dual_write_keeps_blobs_and_trace`; `trace_only_writes_null_blobs`; `self_check_mismatch_falls_back_to_blobs`; `record_exception_falls_back_and_request_succeeds`; `anthropic_params_have_no_system`; `refs_get_trace_locked`; `message_and_trace_in_one_transaction` (rollback stand-in leaves neither).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** every `php tests/*.php`, including `LlmConventionsTest`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: every LLM call records a verified trace`

### Task 7: Prompt version links, archived entries, read-only system prompts

**Files:**
- Create: `server/service/trace/LlmPromptVersionLinker.php`
- Modify: `server/service/prompt/LlmPromptAssetLoader.php` (`load()` reports `key + text` to the linker)
- Modify: `LlmChatModel`, `LlmFormModel`, `LlmFormInterviewContextBuilder` (report the raw section field text, read with the same query and language fallback core uses for section fields)
- Modify: `server/service/LlmMemoryRuleService.php:207-212` (hard delete → `archived_at`)
- Modify: `server/service/LlmPromptRegistryService.php` (listings skip archived; system entries flagged `read_only`), `server/ajax/AjaxLlmPromptLab.php` (edit/activate/delete on system entry → 403), the Prompt Lab entry editor (disabled when `read_only`)
- Test: `tests/LlmPromptVersionLinkerTest.php`, `tests/AjaxLlmPromptLabTest.php` (extend), `tests/LlmHooksMemoryDeleteTest.php` (extend)

**Interfaces:**
- Produces: `LlmPromptVersionLinker::note(array $descriptor, string $raw_template): void` (request-scoped) and `LlmPromptVersionLinker::resolveAll(): int[]`. It finds the version with `template_hash = sha256(raw)` in that locale, or creates it through `ensureVersionSnapshot`. Any exception → logged, skipped (links are informational).
- `record()` (Task 6) writes `resolveAll()` into `llmTracePromptVersions`.

- [ ] **Step 1: Failing tests:** `same_template_resolves_existing_version`; `changed_template_creates_new_version`; `linker_failure_does_not_break_trace`; `memory_rule_delete_archives`; `system_entry_edit_refused`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** them. Expected: PASS. `cd react && npm run typecheck && npm run build` → success.
- [ ] **Step 5: Commit** `feat: link traces to prompt versions, prompts are never deleted`

### Task 8: Immutability and deletion

**Files:**
- Modify: `server/service/LlmService.php:960-963` (`updateMessage`)
- Test: `tests/LlmMessageImmutabilityTest.php`, `tests/LlmHooksCleanupTest.php` (extend)

**Interfaces:**
- Produces: `updateMessage` throws `LlmException('Message is referenced by a request trace', 409)` when `content` or `attachments` change and `trace_locked = 1`.

- [ ] **Step 1: Failing tests** (Review Focus 4): `coach_text_rewrite_before_reference_allowed`; `rewrite_after_reference_rejected`; `user_cleanup_leaves_no_user_chunks` (the stand-in DB records the cascade path: conversations delete → messages → traces; chunks `owner = uid` deleted through the user FK; shared chunks kept).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** them plus `LlmFormInterviewServiceTest`. Expected: PASS.
- [ ] **Step 5: Verify on the dev DB:** delete a test user with traced conversations, then `SELECT COUNT(*) FROM llmTraceChunks WHERE owner = <uid>` → `0`.
- [ ] **Step 6: Commit** `feat: traced messages are immutable, deletion leaves no personal trace data`

### Task 9: Readers and admin badge

**Files:**
- Modify: `server/service/trace/LlmRequestTraceService.php` (`rebuildMany`)
- Modify: `server/service/LlmAdminService.php:244-270`, `server/service/LlmDatasetIngestionService.php:652-680`
- Modify: the admin conversation message component in `react/src/` that shows sent context / request payload (badge)
- Test: `tests/ModuleLlmAdminConsoleTest.php`, `tests/LlmDatasetIngestionScopeTest.php` (extend)

**Interfaces:**
- Produces: `LlmRequestTraceService::rebuildMany(int[] $message_ids): array<int, array{status:string, sent_context:?array, request_payload:?array, verified:?bool}>`. `status` is `blob`, `trace` or `none`; it uses one IN query each for traces, chunks and rows. `request_payload` is rebuilt as `params + messages` (the neutral form), labelled as such.
- Readers: a blob present → blob (`status=blob`); else the rebuild. They add `trace_status` and `trace_verified` to each message. The existing keys are unchanged.

- [ ] **Step 1: Failing tests:** `admin_uses_blob_when_present`; `admin_rebuilds_when_blob_null`; `dataset_ingestion_reads_rebuilt_context`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement**, with the badge: "Trace verified" / "Trace mismatch" / "Stored payload".
- [ ] **Step 4: Run.** Expected: PASS. `npm run typecheck && npm run build` → success.
- [ ] **Step 5: Commit** `feat: admin and datasets read rebuilt requests`

### Task 10: Verifier, measurements, docs, flip criteria

**Files:**
- Create: `server/scripts/llm_verify_traces.php` (`--since=YYYY-MM-DD`, `--conversation=ID`. For each trace: rebuild, hash check, and, when the blob exists, compare the rebuilt neutral messages with `sent_context` + the non-system part of `request_payload`. Prints `checked / verified / hash_mismatch / blob_mismatch / fallbacks` from the `trace_fallback` log. Exits 1 on any mismatch or fallback.)
- Create: golden fixtures `tests/fixtures/traces/<call-site>.json` for chat, chat with retry, form record, interview, memory, script, judge, dataset import, prompt builder and playground: the messages array given to `callLlmApi` plus the expected recipe shape.
- Modify: `README.md`, `CHANGELOG.md` (v1.6.0), new `doc/request-traces.md` (what is stored, the hash, the setting, deletion)
- Test: `tests/LlmTraceGoldenTest.php` (each fixture: build → rebuild equal, hash stable)

- [ ] **Step 1: Baseline:** run the "Measure first" SQL from the spec on a prod copy and record the numbers in `doc/request-traces.md`.
- [ ] **Step 2: Golden test:** write the fixtures, run `php tests/LlmTraceGoldenTest.php`. Expected: PASS.
- [ ] **Step 3: Dual-write run on dev:** exercise every call site, then `php server/scripts/llm_verify_traces.php --since=<today>`. Expected: all mismatch and fallback counts `0`.
- [ ] **Step 4: Size check:** `SELECT AVG(LENGTH(recipe_json)+LENGTH(params_json)) FROM llmRequestTraces` and `SELECT owner = 0 AS shared, COUNT(*), SUM(LENGTH(content)) FROM llmTraceChunks GROUP BY 1`. Expected: the shared chunks hold the schema block once.
- [ ] **Step 5: Document the flip:** set `llm_trace_store_blobs` to off only after the verifier reports zero mismatches and fallbacks on production for a period the team picks.
- [ ] **Step 6: Commit** `docs: request traces, verifier and golden fixtures`
