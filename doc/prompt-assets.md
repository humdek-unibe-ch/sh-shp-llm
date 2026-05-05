# Prompt Assets

All static LLM-facing prompt text in `sh-shp-llm` is stored under
`assets/prompts/`. This document describes the storage layout, the loader
contract, and the **purpose, placeholders, and reviewer guidance** for every
template currently shipped with the plugin (as of v1.3.0).

> **Why this matters:** prompts are the *first* token the model sees. A
> stale or misleading template silently degrades every conversation. Always
> read the relevant section here before editing a prompt asset.

---

## Storage Layout

```
assets/prompts/
└── core/
    ├── chat/
    │   └── media-rendering-instructions.md
    ├── danger-detection/
    │   └── critical-safety.md
    ├── dataset-import/
    │   ├── repair-json.md
    │   └── system.md
    ├── evaluation/
    │   └── judge-system.md
    ├── floating-mode/
    │   └── system.md
    ├── form-mode/
    │   └── system.md
    ├── memory/
    │   ├── default-instructions.md
    │   ├── language-suffix.md
    │   └── system.md
    ├── playground/
    │   ├── default-chat-prompt.md
    │   └── language-suffix.md
    ├── prompt-builder/
    │   └── system.md
    ├── prompt-scaffold/
    │   └── standard.md
    ├── response/
    │   ├── progress-tracking.md
    │   ├── retry-prompt.md
    │   ├── safety-detection.md
    │   ├── schema-instruction.md
    │   ├── schema-system-instructions.md
    │   └── suppress-suggestions.md         # (v1.3.0)
    └── strict-conversation/
        └── enforcement.md
```

## Loader Contract

- One file per prompt string (`.md` for multiline, `.txt` for short text).
- Runtime services resolve prompt text by key through:
  - `server/service/prompt/LlmPromptAssetRegistry.php` — central key→path map.
  - `server/service/prompt/LlmPromptAssetLoader.php` — disk loader with
    `{{placeholder}}` substitution.
- **Adding a new prompt**:
  1. Drop the file under `assets/prompts/...`.
  2. Register the key in `LlmPromptAssetRegistry::getMap()`.
  3. Load by key in services/components: `LlmPromptAssetLoader::load($key, $vars)`.
- Missing key/file is **fail-closed** (runtime exception). This is intentional
  — a missing prompt is never silently swallowed.

## Placeholder Convention

Placeholders use double curly braces, e.g. `{{schema_json}}`. They are
substituted at load time by `LlmPromptAssetLoader`. Unknown placeholders are
left in the output verbatim, which is usually a bug — verify the calling
service supplies every placeholder the template references.

---

## Template Reference

The tables below list every template registered in `LlmPromptAssetRegistry`,
its purpose, the placeholders it expects, where it is consumed, and the most
common reasons to edit it. Use this as a checklist when reviewing a prompt
change.

### Chat & Conversation

#### `core.chat.media_rendering_instructions`
**Path:** `core/chat/media-rendering-instructions.md`

Tells the model **how to embed images and videos** in chat messages so the
React renderer (`MarkdownRenderer`) can display them inline. Required
because the chat panel does not interpret raw HTML — it only renders
GitHub-flavoured Markdown plus a custom video-on-its-own-line rule.

| Placeholder | Source |
|---|---|
| _(none)_ | Static. |

**Used by:** `LlmContextService` for chat conversations that allow media.

**When to edit:** when introducing new media types, changing the URL
detection rule, or expanding the alt-text policy.

---

#### `core.strict_conversation.enforcement`
**Path:** `core/strict-conversation/enforcement.md`

Wraps a chat conversation in a "stay-on-topic" guard. The model is told to
refuse off-topic questions and gently redirect the user.

| Placeholder | Source |
|---|---|
| `{{context}}` | Author-supplied conversation context (`conversation_context` field). |
| `{{topic_list}}` | Comma-separated list of allowed topic labels. |

**Used by:** `LlmContextService::buildStrictConversationGuard()`.

**Example output (German):**
> "Ich bin hier, um Ihnen bei *Burnout-Prävention* und *Achtsamkeit* zu
> helfen. Möchten Sie zu einem dieser Themen etwas erfahren?"

---

### Response Schema

#### `core.response.schema.instruction`
**Path:** `core/response/schema-instruction.md`

The **single most important prompt in the plugin.** It tells the model to
return strict JSON matching the configured schema, lists every field
specification (safety / content / progress / metadata), enumerates
text-block types, and closes with a "minimal valid response" example so the
model always has a fall-back.

| Placeholder | Source |
|---|---|
| `{{schema_json}}` | Pretty-printed JSON schema injected by `LlmResponseService`. |

**Used by:** every chat-mode call, every prompt-lab execution.

**Why it matters:** if this prompt drifts away from the actual schema in
`LlmResponseService::getResponseSchema()`, the model will produce JSON the
validator rejects, retries explode, and rate limits get hit. Whenever the
schema changes, this template **must** be updated to match.

**v1.3.0 audit notes:**
- Field path is `content.suggestions` (array of `{text}` objects). The React
  layer maps these into `next_step.suggestions: string[]` at render time
  via `react/src/utils/llmResponseUtils.ts`. Do NOT tell the model to use
  `next_step.suggestions` — the validator will reject that path.
- The "FORM STRUCTURE" example is canonical; mirror it exactly in
  `LlmResponseService` if you change form field types.

---

#### `core.response_schema.system_instructions`
**Path:** `core/response/schema-system-instructions.md`

Two-line preamble appended to the **system message** (not the user message)
before `schema-instruction.md`. Reminds the model of its identity and that
the response must be valid JSON. Kept short on purpose — the long schema
guidance lives in `schema-instruction.md`.

| Placeholder | Source |
|---|---|
| _(none)_ | Static. |

**Used by:** `LlmContextService::buildContextMessages()` (system role).

---

#### `core.response.safety_detection`
**Path:** `core/response/safety-detection.md`

Augments the schema instruction with **danger-keyword monitoring**. Lists
the categories (`suicide`, `self_harm`, etc.) and danger levels
(`warning`/`critical`/`emergency`) the model is expected to fill into the
`safety` envelope.

| Placeholder | Source |
|---|---|
| `{{keywords_list}}` | Bullet-list of danger keywords from `LlmDangerDetectionService::getKeywords()`. |

**Used by:** every chat-mode call where danger detection is enabled (the
default).

**Pairs with:** `core.danger_detection.critical_safety` (a stronger, shorter
restatement appended last so it survives prompt-injection attempts).

---

#### `core.response.progress_tracking`
**Path:** `core/response/progress-tracking.md`

Injected when the chat style enables **progress tracking** (`enableProgressTracking=1`
on the `llmChat` style). Lists current topics with `[x]`/`[o]` markers and
asks the model to populate the `progress` block in the response envelope.

| Placeholder | Source |
|---|---|
| `{{topic_list}}` | Markdown list of topics with covered/remaining flags. |
| `{{current_progress}}` | Integer percentage. |
| `{{remaining_topics}}` | Comma-separated list. |
| `{{context_language}}` | User-facing language for confirm prompts. |
| `{{confirm_question}}` / `{{confirm_yes}}` / `{{confirm_partial}}` / `{{confirm_no}}` | Localised confirmation strings. |

**Used by:** `LlmContextService::buildProgressContext()`.

---

#### `core.response.retry_prompt`
**Path:** `core/response/retry-prompt.md`

Sent as a **user-role retry message** when JSON schema validation fails.
Lists the validator errors and asks the model to try again with a clean
JSON object.

| Placeholder | Source |
|---|---|
| `{{error_list}}` | Bullet-list of validator messages from `LlmResponseService::callLlmWithSchemaValidation()`. |

**Used by:** retry loop (max 3 attempts).

---

#### `core.response.suppress_suggestions` *(v1.3.0)*
**Path:** `core/response/suppress-suggestions.md`

Appended to the system context **only when the chat author disables**
`enable_hint_suggestions` on the `llmChat` style. Tells the model not to
emit `content.suggestions` (saves output tokens) and not to scaffold
"tap one of the buttons below" prose into the text blocks.

| Placeholder | Source |
|---|---|
| _(none)_ | Static. |

**Used by:** `LlmContextService::applySuppressSuggestionsContext()` (gated
on `LlmChatModel::isHintSuggestionsEnabled()`).

**Pairs with:** the React `StructuredResponseRenderer` which also hides the
`<NextStepRenderer>` block when `showSuggestions={false}`. Both layers must
agree — backend stops the model from spending tokens on suggestions, frontend
guarantees that legacy/historical messages don't render orphan buttons.

---

### Mode-specific System Prompts

#### `core.form_mode.system`
**Path:** `core/form-mode/system.md`

Used by `formUserInputRecord`/`formUserInputLog` styles when LLM-driven form
generation is enabled (the `llmFormRecord`/`llmFormLog` styles in v1.1.0+).
Constrains the model to output a single `type=form` JSON object with a
limited set of field types (`radio`/`checkbox`/`select`/`text`/`textarea`/
`number`).

**Used by:** `LlmFormModeService`.

**Important:** the field types here must stay aligned with the form fields
that `FormRenderer.tsx` actually knows how to render. If you add a new
field type to the renderer, update this template; if you remove one,
deprecate it here too.

---

#### `core.floating_mode.system`
**Path:** `core/floating-mode/system.md`

Optional system suffix when the chat is rendered in **floating-button
mode** (`floatingButtonEnabled=1`). Tells the model to produce shorter
paragraphs and avoid wide tables, because the floating panel is ~380 px on
desktop.

**Used by:** `LlmContextService` (only when `chatLayout='floating'`).

---

### Memory

#### `core.memory.system`
**Path:** `core/memory/system.md`

System prompt for the **memory update flow**. The "INVIOLABLE RULE" section
is critical — without it, the model frequently drops existing memory rows
when it thinks the new submission supersedes them. Do not weaken this
without thorough evaluation.

| Placeholder | Source |
|---|---|
| `{{memory_key}}` | The memory key being updated (e.g. `user_profile`). |
| `{{output_schema}}` | JSON schema for `memory_text`/`memory_object`/`flat_fields`/`change_summary`. |

**Used by:** `LlmMemoryUpdateService`.

---

#### `core.memory.language_suffix`
**Path:** `core/memory/language-suffix.md`

Appended when the user has a non-default language. Forces the prose
(`memory_text`, values) into the user's language while keeping the keys in
English snake_case (because they are queried by code).

| Placeholder | Source |
|---|---|
| `{{user_language}}` | Localised language name (e.g. "Deutsch"). |

---

#### `core.memory.default_instructions`
**Path:** `core/memory/default-instructions.md`

Default body when the author of a `memory` task does not provide custom
instructions. Encourages the model to keep stable, useful facts and prefer
newer data on conflict.

---

### Prompt Lab (Playground / Builder / Evaluation / Dataset Import)

#### `core.playground.language_suffix` & `core.prompt_execution.default_chat_prompt`

Tiny helpers for the **prompt playground**:

- `language_suffix.md` adds `Please respond in {{language_code}}` to the
  user message when the playground language picker is non-empty.
- `default-chat-prompt.md` is the placeholder student input shown when the
  author has not supplied any test variables yet ("Test this prompt in
  playground mode.").

**Used by:** `LlmPromptPlaygroundService`, `LlmPromptExecutionEngine`.

---

#### `core.prompt_builder.system`
**Path:** `core/prompt-builder/system.md`

System prompt for the **prompt-builder assistant**. Tells the LLM to
*minimally edit* an existing prompt rather than rewrite it, preserve
structure, and respect approved examples. The "minimal edit" rule is what
makes side-by-side diffs in the builder usable.

**Used by:** `LlmPromptBuilderService`.

---

#### `core.prompt_scaffold.standard`
**Path:** `core/prompt-scaffold/standard.md`

Hints the prompt builder about the **canonical section ordering** for
authored prompts (`task_role` → `style_requirements` → `domain_safety` →
`examples` → `output_behavior`). Used as a guidance section the builder
appends when scaffolding a new prompt from scratch.

| Placeholder | Source |
|---|---|
| `{{owner_type}}` | The owner kind (e.g. `style`, `task`). |
| `{{execution_profile}}` | The execution profile name (e.g. `chat`, `form`). |
| `{{section_order}}` | Bullet list of expected sections. |

---

#### `core.evaluation.judge.system`
**Path:** `core/evaluation/judge-system.md`

System prompt for the **evaluation judge LLM** (used by the prompt-lab
"score this output against the reference" flow). Tells the judge to return
only a JSON `{score, passed, label, reason}` object and to respect the
`output_format_contract` if the prompt enforces a JSON envelope.

**Used by:** `LlmPromptEvaluationJudgeService`.

---

#### `core.dataset_import.system` & `core.dataset_import.repair_json`
**Paths:** `core/dataset-import/system.md`, `core/dataset-import/repair-json.md`

Used by the **AI dataset import** flow (paste pasted Excel/TSV/CSV into the
prompt-lab dataset wizard). `system.md` instructs the model to extract
evaluation cases, while `repair-json.md` is a follow-up that takes
malformed parser output and coerces it into the canonical schema.

**Used by:** `LlmDatasetAiImportParserService`.

---

### Danger Detection

#### `core.danger_detection.critical_safety`
**Path:** `core/danger-detection/critical-safety.md`

Short, very forceful reminder appended **last** in the system context so it
survives prompt-injection attempts. Pairs with the longer
`core.response.safety_detection` template — same purpose, deliberate
redundancy because models occasionally ignore the first instruction when
the user input is hostile.

| Placeholder | Source |
|---|---|
| `{{keywords_list}}` | Comma-separated keyword list from `LlmDangerDetectionService`. |

**Used by:** `LlmDangerDetectionService` and `LlmContextService` whenever
danger detection is enabled (default).

---

## v1.3.0 Audit Summary

The full prompt-asset library was reviewed for v1.3.0. Findings:

- **All 21 templates remain in active use.** No dead files.
- **`schema-instruction.md`** (the most-used template) was verified against
  the live schema in `LlmResponseService`. Field paths match
  (`content.text_blocks`, `content.form`, `content.suggestions`,
  `content.media`, `safety.*`, `progress.*`, `metadata.*`).
- **New: `core.response.suppress_suggestions`** — added to support the
  `enable_hint_suggestions` toggle on the `llmChat` style. Used only when
  authors explicitly disable the quick-reply UX.
- **No placeholder mismatches** were found between the templates and their
  callers (verified by inspecting each `LlmPromptAssetLoader::load(...)`
  call site).

## Troubleshooting

- If execution fails with a prompt-asset error:
  - Verify key exists in `LlmPromptAssetRegistry::getMap()`.
  - Verify mapped file path exists and is non-empty.
  - Verify deployment includes the entire `assets/prompts/` directory.
  - Check the loader exception message for the actual placeholder that
    failed to substitute (most common cause of partial output is a typo in
    the placeholder name on either side).
- If the model output suddenly fails schema validation in production:
  - First inspect a recent failed message in the admin viewer
    (`module_llm/conversations`). The raw response is stored on
    `llmMessages.raw_response`.
  - Compare against `assets/prompts/core/response/schema-instruction.md`
    and `LlmResponseService::getResponseSchema()`. They must agree.
