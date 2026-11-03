# sh-shp-llm (LLM plugin) — notes for Claude

SelfHelp plugin for AI chat, LLM forms/interviews, scripts, memory, prompt lab and evaluations.
PHP backend + React 18/TypeScript frontend built with Vite into UMD bundles. Detailed docs live in
`doc/` (start with `doc/architecture.md`, `doc/api-reference.md`, `doc/provider-abstraction.md`);
read the relevant one before changing a subsystem instead of guessing.

## Plugin conventions (enforced by tests)
- **Transactions only through `LlmTransaction`** (`server/service/LlmTransaction.php`). Plugin code never
  calls `begin_transaction()/commit()/rollback()` on the DB handle and never sends
  `START TRANSACTION/COMMIT/ROLLBACK/SAVEPOINT` as SQL — this overrides the generic SelfHelp DB rule.
  Use `LlmTransaction::begin($db)` / `commit($db)` / `rollback($db, $cache_types)`; work that must run after commit goes in `LlmTransaction::afterCommit($db, $task)`.
- **Exceptions are logged through `LlmErrorReporter`**, never `error_log('...' . $e->getMessage())`
  (driver text can contain participant answers).
- Check with `php tests/LlmConventionsTest.php`; other PHP tests in `tests/*Test.php` run the same way.

## Layout
- `server/component/style/`: `llmChat` (component + controller, React), `llmResponse` (view of LLM output
  with `{{field.path}}` interpolation), `llmFormRecord` / `llmFormLog` (formUserInput* + LLM generation),
  `llmFormLogList`. Templates mostly in a `tpl/` subfolder.
- `server/component/LlmHooks.php`: CMS field overrides (`field-llm-model-*`, etc.), job scheduler / task
  hooks. Follow the chaining rule in `../CLAUDE.md`.
- `server/service/`: ~70 services. Core path: `LlmService` (conversations, messages, `callLlmApi()`),
  `LlmContextService` (builds the messages array), `LlmResponseService` (schema-validated calls with
  retries), `LlmDataSavingService` (saves to dataTables via `UserInput::save_data()`),
  `LlmDangerDetectionService`, `LlmCsrfService`; provider abstraction in `service/provider/`,
  request traces in `service/trace/`.
- Constants in `server/service/globals.php` (`LLM_DEFAULT_MODEL`, `LLM_DEFAULT_TEMPERATURE`,
  `LLM_DEFAULT_MAX_TOKENS`, `LLM_DEFAULT_TIMEOUT`, limits, danger keywords).
- Main tables: `llmConversations`, `llmMessages`, `llmConversationProgress`, `llm_scripts`, plus trace
  tables (`llmRequestTraces`, `llmTraceChunks`, `llmTracePromptVersions`) since v1.7.0.

## Frontend
- React sources in `react/src` (entries: `LLMChat.tsx`, `admin.tsx`, `scripts.tsx`, `memory.tsx`,
  `LlmForm.tsx`, `LlmFormInterview.tsx`, `LlmApiKeys.ts`, `LlmPromptField.tsx`, …). PHP views output a
  root `<div data-config="<?php echo htmlspecialchars($this->getReactConfig()); ?>">` (JSON) that React mounts on.
- Build: `cd gulp && gulp` (runs npm install + `npm run build` in `react/`), output to `js/ext/*.umd.js`
  and `css/ext/*.css`. Don't hand-edit those built files. `npm run typecheck`, `npm run test:*` in `react/`.
- Views include assets via `get_css_includes()/get_js_includes()` with a cache-busting `?v=`.

## Migrations
`server/db/vX.Y.Z.sql`, idempotent, plugin version bumped last (`WHERE name = 'llm'`); optional scripts in
`server/db/optional/`. Note in the header that the SelfHelp cache must be cleared. This is its own git repo:
commit here, not in sh-selfhelp.
