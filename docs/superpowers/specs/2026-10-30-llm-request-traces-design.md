# LLM Request Traces - Design (revision 2)

Status: draft for review
Date: 2026-10-30
Replaces: revision 1 (full rebuild from versions + variables, v1.7.0). That revision
was reviewed; the findings below were checked against the code and this design
follows from them.

## Goal

For every LLM call, an auditor can see exactly what was sent and verify it with a
hash, without storing the same large text again on every message. Rollout is safe:
no outage, no destructive migration, rollback by redeploy.

## Verified findings that shape this design

| Finding | Where | Consequence |
|---|---|---|
| The biggest constant block is the schema instruction: `json_encode(LlmResponseSchema::getSchema(), JSON_PRETTY_PRINT)` (≈17.7 KB) inside `schema-instruction.md`, identical for all users, stored twice per assistant row. | `LlmResponseService.php:113-126` | Storing that block **once, shared** is the main win. |
| The send path caps history at 50 messages. | `LlmChatController.php:576`, `LlmFormInterviewContextBuilder.php:95` | The quadratic part saturates; the constant part matters as much. |
| The registry is a lazy mirror. Entries appear on CMS save or Prompt Lab bootstrap; never-saved sections have no version. No PHP upgrade hook exists. | `LlmPromptRegistryService.php:55-90` | The runtime must not depend on the registry. Version links are resolve-or-create and informational. |
| Core interpolation (`replace_calced_values`) uses `addslashes`, `trim`, turns newlines into `\n`, and fills cascades inside one call; `@user*` uses substring `str_replace`. The form path runs `strip_tags`; JSON-array contexts become N messages. | core `PageDb.php:817-880`, `StyleModel.php:378-436`, `LlmFormController.php:454`, `LlmChatModel.php:345` | **No re-implementation of interpolation.** Store rendered text instead. |
| `request_payload` is the wire body after `convertMessagesForModel`, `getAdditionalRequestParams` and `adaptChatCompletionPayload`; Anthropic lifts system messages into a top-level `system` field and reshapes roles. | `LlmService.php:1609-1634`, `AnthropicProvider.php:140,385` | The trace is taken at the **neutral boundary**: the `$messages` array `callLlmApi` receives. The provider id and adapted params (without `messages`/`system`) are stored next to it. |
| The retry echo is truncated to 8,000 chars. | `LlmResponseService.php:33,733` | A message is stored as a row reference only when the stored row formats to **exactly** the sent message; otherwise its text is stored. |
| User deletion: `onCleanUserDataLlmCleanup` deletes `llmConversations`, messages cascade; core `delete_user` cascades `users → llmConversations → llmMessages`. Uploaded files are never unlinked. | `LlmHooks.php:2281-2308`, `v1.0.0.sql:265,293` | Trace tables cascade with the message; per-user text cascades with the user. Shared text never contains user data. Files get unlinked in the cleanup hook. |
| Callers embed participant data in large strings (interview machine/history context, judge transcripts, memory prompt, auto-start turn). | `LlmFormInterviewContextBuilder.php:230,314`, `LlmChatController.php:1339` | Text is **per-user by default**; only text explicitly declared shared by the code is shared. |
| `getConversationMessages` returns `sent_context` to participants (chat JSON and mobile). | `LlmService.php:982`, `LlmChatController.php:877-881`, `LlmChatView.php:120` | Existing leak: author prompt, keyword list and schema reach the browser. Fixed first, independently. |
| No `.gitattributes`, `core.autocrlf=true`; assets are `i/lf w/crlf` on Windows. | repo | Sent bytes and hashes depend on the checkout. Force LF and normalize in the loader. |
| Only `ensureVersionSnapshot` writes `active_version_id`, together with the new version row. | `LlmPromptRegistryService.php:659-667` | No activation log table; the version row already records who/when/based-on. |
| Transactions must go through `LlmTransaction`. | `tests/LlmConventionsTest.php` | Message + trace in one `LlmTransaction`. |

## Design

### 1. Trace at the neutral boundary

`callLlmApi($messages, ...)` is the one choke point (18 call sites). After the
provider call, the trace for the logged message is built from `$messages` exactly as
received, plus:

- `provider` (provider id) and `params_json` = the adapted payload minus `messages`
  and `system` (model, temperature, max tokens, reasoning, provider extras);
- `format_version` (trace format) and `plugin_version`;
- `request_hash` (section 4).

### 2. Messages: row reference or text chunk

Each message in `$messages` becomes one recipe entry:

- `{"m": <llmMessages.id>}` when a candidate history row, formatted with the same
  `LlmApiFormatterService::formatStoredMessage()`, is **identical** to the sent
  message. Candidates are the history rows the caller loaded (passed in
  `log_options['trace_rows']`), matched in order.
- `{"role": "...", "c": [<chunk id>, ...]}` otherwise. Content = concatenation of
  the chunks.

Chunks live in `llmTraceChunks(sha256, content, owner)`:

- **shared** (`owner = 0`, no user): text the code declares shared through
  `LlmTraceHints::share($text)`: the rendered schema instruction, safety block,
  media rendering, strict / form / floating mode texts, language rule. These come
  from code and global config only.
- **per-user** (`owner = id_users`, FK `ON DELETE CASCADE`): everything else,
  including all glue between shared pieces.

Splitting: for each declared shared text that occurs in a message, the content is
cut around its first occurrence; the pieces in between become per-user chunks. The
concatenation is always exact, so a wrong or missing hint only costs dedup, never
correctness. Chunks are deduplicated by `(owner, sha256)`.

Multimodal content (arrays of parts) is stored as one chunk holding the JSON of the
parts, with base64 data URLs replaced by `{"img": {path, type, is_collage}, "sha256": ...}`
(the formatter declares each image it encodes with `LlmTraceHints::image()`);
rebuild encodes the file again and checks the hash (section 3).

### 3. Stable attachments

- Images: the LLM-ready JPEG is written once as `<path>.llm.jpg` on first send
  (new and older uploads alike). Every send base64-encodes that file as is: no GD
  per turn, identical bytes every time.
- Documents: no derivative. A text file is sent from the immutable upload as is,
  any other file only as a fixed note with its name, so the sent text is already
  stable.
- The user cleanup hook and a `Login::delete_user` overwrite hook unlink a user's
  originals and derivatives once the rows are deleted.

### 4. Hash proof and self-check

`request_hash = sha256(canonical_json({provider, params, messages}))`, where
canonical JSON uses `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, recursively
sorted object keys, `serialize_precision = -1` set inside the canonicalizer, and
every base64 data URL replaced by `sha256:<hex>`.

Message and trace are written in one `LlmTransaction` (a savepoint inside a
caller's transaction, core `ExtendedPdo`); `record()` has its own savepoint, so an
exception rolls back only the partial trace. Right after saving, inside the same
request, the service **rebuilds the trace from the DB and compares the hash**. On mismatch, or when trace building fails for any
reason, the message keeps the full blobs and an alert is logged
(`LlmErrorReporter`, tag `trace_fallback`). Nothing is ever refused: a request is
always sent, and every message has either a verified trace or its blobs.

### 5. Rollout without destruction

- Release v1.7.0 (after v1.6.0, own migration). Columns `sent_context` / `request_payload` **stay**.
- Setting `llm_trace_store_blobs` on the LLM settings page (default **on**):
  - on (release 1, dual-write): blobs are written as today and the trace next to
    them. The verifier compares rebuilds with the real blobs.
  - off (after the verifier shows zero mismatches and zero fallbacks): blobs are
    written only on fallback.
- Rollback is a redeploy; old code still finds its columns. Limit: the prompt
  keys stay RESTRICT, so v1.6.0 code can no longer delete a memory rule that has
  a prompt entry.
- No legacy table, no column drop. Old rows keep their blobs.

### 6. Prompt version links (which prompt was used)

The runtime keeps reading prompts exactly as today. Separately, each known template
is linked to an immutable `llm_prompt_versions` row by **resolve-or-create on the
template hash**:

- section prompts: the raw field text (before interpolation) for that section,
  field and language;
- system assets: one registry entry per asset key (owner type `system`), created on
  first use.

Links go to `llmTracePromptVersions`. They are informational: if resolving fails,
the trace is still complete (the proof is the chunks). Versions are never deleted:
prompt FKs become `ON DELETE RESTRICT`, memory-rule deletion archives the entry
(`archived_at`). System entries are not shown in Prompt Lab and cannot be written
through it (its access checks accept only script, memory rule and section field
owners); they change only with the plugin's asset files.

### 7. Immutability

`llmMessages.trace_locked` is set for every row a trace references with `{"m"}`.
`updateMessage` refuses to change `content` or `attachments` on a locked row. The
two existing rewrites (interview coach text, upload) happen before the row is
referenced.

### 8. Deletion

User deletion (core or cleanup hook) cascades: conversations → messages → traces →
links; per-user chunks cascade with the user; files are unlinked. What remains:
shared chunks (code text) and prompt versions. No personal data remains.

### 9. Readers

`LlmAdminService` and `LlmDatasetIngestionService` use the blob when present,
otherwise the rebuild. The admin message view shows a badge: *Trace verified* /
*Trace mismatch* / *Stored payload (no trace)*.

## Tables (in `server/db/v1.7.0.sql`, run after v1.6.0)

All FK columns `INT(10) UNSIGNED ZEROFILL` to match the referenced keys.

- `llmRequestTraces`: `id`, `id_llmMessages` UNIQUE FK CASCADE, `format_version`
  TINYINT, `plugin_version` VARCHAR(16), `provider` VARCHAR(32), `params_json` TEXT,
  `recipe_json` MEDIUMTEXT, `request_hash` CHAR(64), `verified` TINYINT, `created_at`.
- `llmTraceChunks`: `id`, `owner` INT UNSIGNED NOT NULL DEFAULT 0, `id_users` NULL FK
  CASCADE, `sha256` CHAR(64), `content` LONGTEXT, UNIQUE(`owner`, `sha256`).
- `llmTracePromptVersions`: (`id_llmRequestTraces` FK CASCADE, `id_llm_prompt_versions`
  FK RESTRICT), PK both.
- `llmMessages.trace_locked` TINYINT NOT NULL DEFAULT 0.
- `llm_prompt_entries.archived_at` TIMESTAMP NULL; prompt locale/version FKs →
  RESTRICT; owner type lookup `system`.
- Field `llm_trace_store_blobs` (checkbox, default `1`) on page type `sh_module_llm`.
- Hook `llm-delete-user-files` (`hook_overwrite_return` on `Login::delete_user`).

## Performance

- Send path: a few hashes (≈0.2 ms per 100 KB), `INSERT IGNORE` of chunks (shared
  ones already exist), one trace row, one `UPDATE ... trace_locked` on ≤50 ids, and
  the self-check rebuild (≈5-20 ms, rows already in memory).
- Storage per assistant message after the flip: recipe (≈50 ids) + params + new
  per-user chunks, typically 1-5 KB instead of ≈2 × 21 KB + history.
- Rebuild (admin, datasets, verifier): one IN query each for traces, chunks and rows.

## Out of scope

- `raw_response` (the provider's full JSON). Measured separately; it is linear and
  is the model's output, so it stays as is for now.
- The core `Transaction::add_transaction` storing the whole `$_SESSION` (core change).

## Measure first

```sql
SELECT COUNT(*) msgs,
       ROUND(AVG(LENGTH(sent_context))/1024,1)    ctx_kb,
       ROUND(AVG(LENGTH(request_payload))/1024,1) payload_kb,
       ROUND(AVG(LENGTH(raw_response))/1024,1)    raw_kb
FROM llmMessages WHERE role = 'assistant';
```
