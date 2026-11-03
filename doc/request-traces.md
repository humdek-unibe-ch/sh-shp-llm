# Request traces

Every LLM call stores a **request trace** next to the logged assistant reply.
A trace proves what was sent to the model without storing the same large text
again on every message. Design: `docs/superpowers/specs/2026-10-30-llm-request-traces-design.md`.

## What is stored

`LlmService::callLlmApi()` builds the trace from the neutral `$messages` it
receives, before the provider adapts them (for example, Anthropic moves the
system prompt to a top-level field).

| Table | Content |
|---|---|
| `llmRequestTraces` | One row per assistant message: `recipe_json`, `provider`, `params_json` (the adapted payload without `messages` / `system`), `request_hash`, `format_version`, `plugin_version`, `verified` |
| `llmTraceChunks` | Text pieces, stored once per `(owner, sha256)`. `owner = 0`: shared text built from code and global config. `owner = id_users`: anything that may hold participant data |
| `llmTracePromptVersions` | Prompt Lab versions (`llm_prompt_versions`) that were in effect: built-in prompt assets (owner type `system`) and CMS prompt fields (owner type `style_field`) (informational) |

The recipe lists the sent messages in order. Each entry is one of:

- `{"m": 123}`: the message equals stored history row 123, formatted the way it
  is sent (`LlmApiFormatterService::formatStoredMessage()`). The llmChat
  send path and both form interview calls (turn and preview) pass their
  history rows (`log_options['trace_rows']`).
- `{"role": "system", "c": [chunk ids]}`: text content; the content is the
  chunks joined together.
- `{"x": chunk id}`: anything else (multimodal parts, extra keys), stored whole
  as JSON in one per-user chunk. An image data URL is stored as
  `{"img": {path, type, is_collage}, "sha256": <hash of the data URL>}`, not as
  its bytes; the rebuild encodes the upload again
  (`LlmApiFormatterService::imageDataUrl()`) and accepts it only when the hash
  still matches.

Shared text is declared by the code with `LlmTraceHints::share()`. Every loaded
prompt asset is declared automatically (this covers the form, floating, media
and suggestion texts), and so are the rendered schema instruction, the safety
block and the two language rules. Strict-mode text is not shared: it embeds the
interpolated author context. A missing hint only costs deduplication, never
correctness.

Long Global Values are shared too (`LlmPromptVersionLinker::shareGlobalValues()`,
called by `record()` before the trace is built): every string value of at
least `LLM_TRACE_SHARED_GLOBAL_VALUE_MIN_LENGTH` (200) characters of every
language row of `sh_global_values` / `global_values`, in the form core
interpolation inserts it (`PageDb::replace_calced_values('{{key}}', ...)`:
trimmed, newlines and control characters escaped, `addslashes`). Global Values
are configuration, not participant data, so a big text pulled into a prompt
(`{{woop_{{module_id}}_context}}`) is stored once for all users and turns
instead of again in the per-user chunk each time another value of the prompt
changes. A value that reaches the prompt in another form (e.g. a context that
is decoded as JSON before it is sent) is simply not deduplicated.

The hints are not reset per call: a retry sends the same context again
without rebuilding it. They stay small, because they only hold code and
config texts and image hashes with paths.

## The hash

`request_hash = sha256(canonical JSON of {provider, params, messages})`. The
canonical form sorts object keys, keeps list order, uses
`serialize_precision = -1`, and replaces every base64 data URL with
`sha256:<hex>`. Any change to the canonicalizer needs a new
`LLM_TRACE_FORMAT_VERSION`. `tests/LlmTraceGoldenTest.php` pins one hash per
call site.

A `{"m": id}` entry is replayed through today's
`LlmApiFormatterService::formatStoredMessage()` and the upload files (images
via `<upload>.llm.jpg`). A change to that formatter, or a lost upload, makes
older traces stored without their payload fail the hash check.
`tests/LlmApiFormatterServiceTest.php` pins its output (golden cases); change
it only together with a new `LLM_TRACE_FORMAT_VERSION` (only version 1 exists
so far, so no reader branches on it yet).

## Write path and fallback

1. The reply is logged **with** its full `sent_context` / `request_payload`
   (`addAssistantMessageFromApiResponse`).
2. `LlmRequestTraceService::record()` stores the chunks, the trace and the prompt
   links, and locks the referenced history rows (`llmMessages.trace_locked`).
3. Self-check: the request is rebuilt from the database, and the hash must match.
4. Only then, and only when **Store full request payloads** is off, are the
   two blob columns cleared. If anything fails, they simply stay, and
   `trace_fallback` is logged. A call is never refused because of tracing. API
   error rows always keep their blobs.

Steps 1-4 run in one `LlmTransaction`. Inside a caller's transaction it is a
savepoint (core `ExtendedPdo`), so a rollback undoes only this write and the
caller decides about its own. `record()` has its own savepoint too: when it
throws half way, its chunks and trace are rolled back and the reply keeps its
blobs. A self-check mismatch keeps the trace (`verified = 0`) for the verifier.
A message therefore always has the blobs, a verified trace, or both.

When a provider response has no `raw_response`, the whole normalized response
is stored as `raw_response` without its `request_payload`; otherwise the
request would still be stored in full after the blobs are switched off.

The llmChat send path and both form interview calls pass their stored conversation
rows (`log_options['trace_rows']`), so history is referenced (`{"m": id}`). The
other calls (form feedback, memory, scripts, evaluation, Prompt Lab) build their
messages from text; that text is stored once per user as chunks.

A referenced history row is immutable: `LlmService::updateMessage()` refuses
to change its `content` or `attachments` (409).

## Setting: Store full request payloads

**LLM Settings → Request Audit → Store full request payloads**
(`llm_trace_store_blobs`, default off).

- **On (exactly `1`, dual write):** blobs are stored as before, and the trace
  is stored next to them.
- **Off (default):** only the trace is stored. Blobs are written only for a
  request whose trace could not be verified.

This is safe by default because a reply loses its blobs only after its trace
was rebuilt from the database and matched the hash of the sent request; any
failure keeps the blobs of that reply. What off gives up: an image whose upload
file is deleted later cannot be shown again (its hash remains), and a rebuild
is the canonical request, not the original bytes. `raw_response` is not
affected. For a pilot, switch it on for a while and run the verifier
(`blob_mismatch`) before you rely on traces alone. The columns stay, so the code can be rolled back by a
redeploy, with one limit: the prompt locale and version keys stay `RESTRICT`,
so the v1.6.0 code can no longer delete a memory rule that has a prompt entry
(the delete fails instead of removing its prompt history).

## Verifier

```bash
php server/plugins/sh-shp-llm/server/scripts/llm_verify_traces.php --since=2026-11-01
```

Options: `--since=YYYY-MM-DD`, `--conversation=ID`, `--host=HOST`. The script
prints counts for:

- `checked` / `verified`: traces rebuilt, and those whose hash matches.
- `hash_mismatch`: the rebuild no longer matches the hash. Stored history,
  a chunk or the formatter changed.
- `blob_mismatch`: in dual-write mode, the rebuild (in the provider's wire
  format) differs from the stored payload.
- `write_unverified`: the self-check already failed at write time.
- `untraced`: replies from a model call (`raw_response` set) without a trace,
  from the first traced message on (older replies predate request traces).
  API error rows and canned assistant texts (auto-start greeting, fallback
  messages) are excluded.

The exit status is 1 when any list is not empty.

## Readers

The admin console (with debug access) and the dataset import use the stored
blob when there is one, otherwise the rebuild. Only rows without a blob are
rebuilt; a row with a blob shows the write-time self-check of its trace (the
verifier re-checks those):

- the rebuilt `sent_context` is everything sent besides stored history rows;
- the rebuilt `request_payload` is in the provider's wire format, like a stored
  one, so it can still be copied into Postman. The stored params are already
  adapted; only the messages are adapted again, as `callLlmApi()` does
  (Anthropic: top-level `system` and alternating roles; other providers:
  `convertMessagesForModel()`). Images show the same placeholder a stored
  payload has.

The admin view shows a badge: **Trace verified**, **Trace mismatch**, or
**Stored payload** (no trace).

## Attachments

An image is resized once into `<upload>.llm.jpg` on its first send. Every
later send base64-encodes that file as it is, so the bytes (and the hash) are
the same every time, with no GD work per turn.

Documents need no derivative: a text file is sent from the (immutable) upload
as it is, and any other file only sends a fixed note with its name.

## Deletion

Deleting a user cascades conversations → messages → traces → links.
Per-user chunks cascade with the user. The `Login::delete_user` hook
(`onDeleteUserLlmFiles`) reads the user's upload paths first and deletes the
files and their `.llm.jpg` once the core delete succeeded. Cleaning a user's
data (the user stays) runs the cleanup hook, which deletes the user's per-user
chunks and their uploads together with their `.llm.jpg` files. Only shared code text and prompt
versions remain. Prompt versions are never deleted: the locale and version
foreign keys are `RESTRICT`, and deleting a memory rule archives its prompt
entry.

## Prompt version links

All prompt versions live in one place, the Prompt Lab registry
(`llm_prompt_entries` → `llm_prompt_locales` → `llm_prompt_versions`), and a
trace links them through `llmTracePromptVersions`. The CMS prompt fields are
not core versioned fields (`fields.versioned` stays 0): their history is the
Prompt Lab one, which every CMS save already writes.

- A built-in asset gets a registry entry with owner type `system` (owner 0,
  slot = asset key), created on first use. It changes only with the plugin's
  asset files: Prompt Lab does not show it and refuses any request for it
  (only script, memory rule and section field owners are accepted).
- An archived entry (deleted memory rule) is history only; its rule id is never
  reused, so Prompt Lab never opens it again.
- A section prompt (llmChat `conversation_context`, llmFormRecord
  `llm_context`, form interview `llm_interview_context`) links to its Prompt
  Lab version (owner type `style_field`, the section, the field name) whose
  hash matches the raw field text in the user's language, else the default
  language: the active version when it holds that text, else the newest one.
  Resolve only: a CMS save creates the version, so content written by SQL and
  never saved in the CMS is not linked until its first save.
- Global Values are not linked. Their long values are shared chunks, so the
  trace still holds the exact text that was sent.
- A template is linked only when its literal text appears in what was sent.

## Measure

Before and after the switch:

```sql
SELECT COUNT(*) msgs,
       ROUND(AVG(LENGTH(sent_context))/1024,1)    ctx_kb,
       ROUND(AVG(LENGTH(request_payload))/1024,1) payload_kb,
       ROUND(AVG(LENGTH(raw_response))/1024,1)    raw_kb
FROM llmMessages WHERE role = 'assistant';

SELECT AVG(LENGTH(recipe_json) + LENGTH(params_json)) FROM llmRequestTraces;
SELECT owner = 0 AS shared, COUNT(*), SUM(LENGTH(content)) FROM llmTraceChunks GROUP BY 1;
```
