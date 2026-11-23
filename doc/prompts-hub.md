# Prompts hub (v1.10.0)

One admin page that lists **every LLM prompt of the site**, lets you search them, jump to
the place that owns them, edit them in place and go back to an older version. It is a
second door to the prompts, never a second truth: a save ends up exactly where the owner's
own editor (CMS, Scripts page, Memory page) would put it.

- Page: **LLM Prompts** (`moduleLlmPrompts`, `/admin/module_llm/prompts`), the last item of the
  LLM admin menu, after "LLM Memory".
- Bundle: `js/ext/llm-prompts.umd.js` + `css/ext/llm-prompts.css` (source `react/src/prompts-hub.tsx`
  and `react/src/components/promptsHub/`).

## Install / upgrade

1. Run `server/db/v1.10.0.sql` (after `v1.9.0.sql`; v1.9.1 has no SQL). It adds the
   page, its title and the ACL of the admin group, and bumps the plugin version last. It is
   idempotent. No tables, fields, styles or hooks are added.
2. Deploy the rebuilt `js/ext` and `css/ext` (`cd gulp && gulp`).
3. Clear the SelfHelp cache (**Admin > Cache > clear all**): pages and their ACL are cached.
4. Other groups get the page through the normal CMS page ACL (select to look, update to edit),
   like the other LLM module pages. See [Who sees and edits what](#who-sees-and-edits-what).

## What is listed

| Source | What | Language | Edit | "Open in ..." |
|--------|------|----------|------|---------------|
| CMS prompt field (`style_field`) | the `llm_prompt` fields of CMS sections (for example `conversation_context` of `llmChat`; also the styles of the other LLM plugins) | one row per language | yes | the section in the CMS (new or old CMS UI, the user's setting) |
| LLM script (`llm_script`) | the text of a script | neutral | yes | the script on the Scripts page |
| Memory rule (`llm_memory_rule`) | the instructions of a memory rule | neutral | yes | the rule on the Memory page |
| Built-in system prompt (`system`) | the templates under `assets/prompts/` | neutral | **read-only** (they are files, see [prompt-assets.md](prompt-assets.md)) | none |
| Agentic chat persona (`agentic_persona`) | the description of a persona of the agentic chat plugin | neutral | **read-only, link only** | the persona library of the agentic chat settings |

A section that never was saved since v1.1.0 is listed too: the hub reads the CMS field, and joins
the prompt registry only for version, author and date. The persona rows only exist while the
agentic chat plugin is installed.

Columns: source, prompt (title and text preview or the search hits), where it lives, language,
active version, last change and author, and a lock for read-only rows (hover for the reason).

**Where "Updated" comes from** (the info icon next to the column explains it too): the CMS keeps no
date for a field text (`sections_fields_translation` has none). Date and author are those of the
prompt's active version in the prompt registry (`llm_prompt_versions.created_at`, `id_users_created`).
A version is written by every save of the prompt: a CMS save of the field
(`LlmHooks::syncStyleFieldPromptOnCmsSave`), the Prompt Lab, the Scripts and Memory pages and this
hub. A script or memory rule without a version shows its own `updated_at`. "—" means the text has no
version yet (never saved since the registry exists, for example imported or written by SQL).

### Search and filters

- The search looks into the **whole text** of every prompt it may show (case-insensitive,
  at most 200 characters). A row shows its first three hits as snippets with the line number
  ("L12") and the match highlighted.
- Filters: source type, plugin, style, page, language and "Show orphaned". Every filter is a
  searchable dropdown (type to narrow a long list of pages). The choices list the values of the
  current search.
- The URL carries search, filters, the page of the list and the open prompt
  (`?q=...&p=3&size=50&key=...`), so a link to a prompt can be shared (the receiver still needs the
  access below).
- The list is paged: 25, 50 or 100 rows per page, numbered pages below the table. A change of the
  search or of a filter goes back to page 1. The server answers at most 500 rows per request.

### Orphans

A prompt whose section is on no page, or whose section, script or rule was deleted, is an
**orphan**. It is hidden by default; "Show orphaned" lists it, read-only (no edit, no restore).
Orphans are visible to every user of the hub because no page is left to check against.

## Editing a prompt

Click a row. At 1700 px viewport width and more the prompt opens in a side panel, below that in
a dialog.

The prompt opens in a dialog like the plugin's other dialogs: a header with the title, a body that
scrolls, and a footer with every button (**Open in CMS**, **Discard changes**, **Cancel**/**Close**,
**Save**). In the side panel of wide screens it is a card with the same three parts.

A CMS prompt field opens with **all its languages** (every site language for a translatable field,
like the CMS form), also those without text yet. Small **language tabs** sit on top of the text; a
tab shows that language's version, history buttons, version comment and the full-width text. A dot
marks a tab with unsaved text. **One Save** saves every changed language of the field together, in
one transaction: either all of them are saved or none (a conflict in one language saves nothing and
opens that language). Saving a language that had no text creates it, exactly like filling it in
the CMS.

1. Change the text in the editor. The badge "Unsaved changes" shows, **Save** is enabled.
2. Optionally write a **Version comment** (at most 255 characters).
3. **Save**: a toast says "Saved as vN - live now". The text is live immediately; the hub clears the
   section cache itself (no extra "clear cache" step), so a running chat uses the new text with the
   next request.

What a save does, per source:

| Source | Saved to |
|--------|----------|
| CMS prompt field | the field text (`sections_fields_translation`), a new registry version, and the CMS field history, exactly like a CMS save (`LlmHooks::syncStyleFieldPromptOnCmsSave`) |
| LLM script | `LlmScriptService::update_script()`; every other column of the script is unchanged. A failed script save is an error, never "saved" |
| Memory rule | `LlmMemoryRuleService::syncPromptForRule()`, the prompt half of the Memory page's save; label, triggers and data config of the rule are unchanged |

A new CMS version is also visible in the CMS prompt field (text, version and the note), a script
in the Scripts page, a rule on the Memory page, and the request traces keep pointing at the
version a request used (`llmTracePromptVersions`).

Leaving a prompt with unsaved text (closing, opening another, reloading the page) asks first
("Discard unsaved changes?").

### Versions, compare and restore

- **Versions** lists the versions of the prompt (number, author, date, note). **Compare with
  active** and the compare action of a version open a side-by-side diff of that version against
  the text in the editor.
- **Restore** saves the old text as a **new version** with the note "Restored from vN". History
  is never rewritten. Only the text is restored (a restore does not touch the model settings).
- Each language of a CMS field has its own version numbers (EN and DE are separate streams).

### Conflicts: nothing is overwritten silently

The editor remembers the active version and the hash of the text it loaded. A save (and a restore)
is only written when **both** still match what is stored at that moment, checked under a row lock
inside the save transaction. If somebody saved in between (in the CMS, on the Scripts page,
in another hub tab):

- nothing is written, the dialog "This prompt was changed by someone else" shows who saved which
  version, with a diff of the live text against yours;
- **Reload theirs (drop my text)** loads what is live now (and clears your note);
  **Keep editing** keeps your text and your next save is checked against what is live now;
  closing the dialog changes nothing.

On top of that, the core's "someone else is editing this page" warning covers the hub page, like
the Scripts and Memory pages (`LlmHooks::get_sensible_pages`).

## Who sees and edits what

The hub is **never a back door**. Every request is checked twice on the server:

1. the user needs ACL on the hub page (`select` to read, `update` to save or restore), and
2. the ACL of the page that owns the prompt:

| Source | Needs |
|--------|-------|
| CMS prompt field | `select` on at least one page that shows the section to see it; `update` on at least one of them to edit |
| LLM script | the Scripts page (`moduleLlmScript`): `select` / `update` |
| Memory rule | the Memory page (`moduleLlmMemory`): `select` / `update` |
| Persona | `select` on the agentic chat settings page (read-only anyway) |
| System prompt | the hub page only |

A prompt the user may not see is **not listed**, and a request for it (by crafted key) is
answered "Prompt not found" like an unknown one: the hub does not tell that it exists. A prompt the
user may see but not edit is listed read-only (lock, "no access"). Writes also need a POST with
the session CSRF token.

## For developers

### Keys

A row is identified by `<sourceType>:<ownerId>:<slot>:<languageId>`:

| Source | Example |
|--------|---------|
| `style_field` | `style_field:9424:conversation_context:3` (section id, field name, language id) |
| `llm_script` | `llm_script:3:script:1` |
| `llm_memory_rule` | `llm_memory_rule:9:memory_rule:1` |
| `agentic_persona` | `agentic_persona:0:<persona key>:0` |
| `system` | `system:0:<asset key>:0` |

The browser sends keys back and nothing else about a row (label, URL, type) is ever believed:
the server parses the key (`LlmPromptCatalogService::parseKey()`, strict pattern) and rebuilds
everything from the database.

### Actions

`ModuleLlmPromptsController`, on the hub page URL. Reads are GET and need `select`; `save`,
`save_all` and `restore` are POST only, need `update` and the CSRF token (`X-CSRF-Token` header or `csrf_token`
field, `LlmCsrfService`).

| Action | Parameters | Answer |
|--------|------------|--------|
| `list` | `q`, `sourceType`, `plugin`, `style`, `pageId`, `languageId`, `includeOrphaned`, `limit`, `offset` | `{rows, total, facets}` |
| `get` | `key` | one row with `content`, `contentHash`, `activeVersionId`, `activeVersionNo`, `versions`, `editable`, `readOnlyReason`, `goToUrl` |
| `get_version` | `key`, `version_id` | the text of one version, only if it belongs to that prompt |
| `save` | `key`, `content`, `change_note`, `expected_active_version_id`, `expected_content_hash` | `{ok, changed, row}` (`row` as in `get`) |
| `save_all` | `items`: JSON list of `{key, content, change_note?, expected_active_version_id, expected_content_hash}`, the languages of one prompt (at most 20) | `{ok, changed, rows}`; all or none, a 409 names the language in `current.key` |
| `restore` | `key`, `version_id`, `expected_active_version_id`, `expected_content_hash` | `{ok, changed, row}` |

Statuses: `400` invalid input or a read-only source (`{error}`), `403` no hub access, bad CSRF
token or `{error: "You may not change this prompt", reason}` (`no_access`, `orphaned`,
`wrong_version`), `404` unknown or not visible (`Prompt not found`, `Version not found`),
`405` a write by GET, `409` conflict `{error: "conflict", message, current}` (`current` is what is
live now: text, version, author, date), `500` a generic text (the details go to the log through
`LlmErrorReporter`, never the prompt text). Content is limited to 1 MB; line endings are stored
as `\n`.

### Code

| File | Role |
|------|------|
| `server/component/moduleLlmPrompts/` | page component, model, view, controller, template (`#llm-prompts-root` with a JSON `data-config`) |
| `server/service/LlmPromptCatalogService.php` | read side: collects the five sources, search, filters, facets, ACL per row, `parseKey()` |
| `server/service/LlmPromptHubSaveService.php` | write side: save and restore for the three editable sources, locking, conflict check, cache |
| `server/service/exception/LlmPromptHubExceptions.php` | conflict, forbidden and not-found exceptions |
| `server/service/LlmCmsLinkHelper.php` | the CMS section URL (new or old UI), shared with the Memory page |
| `react/src/components/promptsHub/` | list, filters, detail panel, editor, conflict dialog; versions and diff dialogs are shared with the Prompt Lab (`components/prompts/`) |

Rules the write side follows (enforced by `tests/LlmConventionsTest.php` where checkable):

- One `LlmTransaction` per save (`save_all` too: all languages of a field in one). It locks every
  source row first, in language order, and only then reads: in MySQL a plain read pins the
  snapshot of the transaction, so a read before the lock could show a text that is already
  old. Then it checks ACL and conflict for every language, and writes only when all of them
  pass. `LlmTransaction::afterCommit()` clears `CACHE_TYPE_SECTIONS` once; a rollback clears
  only the cache types touched, and only after the write phase started.
- Every dynamic SQL value is a bound parameter; the few identifiers that cannot be bound come
  from fixed lists.
- Failures are reported through `LlmErrorReporter`; prompt text is never logged.

Rule the read side follows: the list reads the text of a CMS prompt field only for the rows on the
page it answers (the other rows only need to know whether there is a text), and looks up the pages
of all sections in one walk (`LlmPromptLabAccessService::hostPagesForSections()`), so its
statements follow the depth of the section tree and not the number of prompts.

### Tests

```
php tests/LlmPromptCatalogServiceTest.php
php tests/LlmPromptHubSaveServiceTest.php
php tests/ModuleLlmPromptsControllerTest.php
php tests/LlmPromptLabAccessServiceTest.php
php tests/LlmCmsLinkHelperTest.php
php tests/LlmHooksSensiblePagesTest.php
php tests/LlmConventionsTest.php
cd react && npm run typecheck && npm run test:prompts-hub
```

## Limits

- System prompts are files: to change one, edit the file under `assets/prompts/` (see
  [prompt-assets.md](prompt-assets.md)); personas are edited in the agentic chat settings.
- The hub saves text only. Model settings, schemas and other fields of a section stay in the CMS,
  and the Prompt Lab's execution profiles stay in the Prompt Lab.
- A conflict is detected per prompt and language, not per word: there is no merge, the dialog
  lets you take the live text or keep yours.
