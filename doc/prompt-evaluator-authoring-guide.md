# Prompt Evaluator Authoring Guide

## Evaluator families

Prompt Lab supports three evaluator families:

- `programmatic`
- `llm_judge`
- `human_review`

Definitions are stored in `llm_eval_definitions`.

## Built-in programmatic evaluators

Current built-ins are:

- `json_validity`
- `required_fields_present`
- `no_empty_output`
- `safety_label_match`

These are dispatched in `server/service/LlmEvaluationScoringService.php`.

## Definition fields

Each evaluator definition includes:

- `name`
- `eval_type`
- `description`
- `config_json`
- `is_active`

## Config conventions

### `required_fields_present`

```json
{
  "required_fields": ["safety", "content"]
}
```

### `llm_judge`

```json
{
  "scale_min": 1,
  "scale_max": 5,
  "pass_threshold": 3,
  "judge_model": "Server Name :: model-id",
  "judge_temperature": 0.0,
  "judge_max_tokens": 1200
}
```

`judge_temperature` and `judge_max_tokens` are optional. When omitted, the
scorer inherits `llm_temperature` and `llm_max_tokens` from the global LLM
configuration page (`getLlmConfig()`), and finally falls back to the
plugin defaults. This means evaluations honour whatever response budget the
admin has configured globally — no more hard-coded truncation of judge
verdicts.

#### Judge input shape

`LlmEvaluationScoringService::scoreWithJudge` does **not** forward the raw
dataset `input_payload` to the judge LLM. Instead it builds a compact
`case_input` summary via `buildLeanJudgeInput`:

- For `memory_runtime` cases it emits `memory_key`, `current_memory`,
  `submitted_data`, and `instructions` extracted from the captured
  `memory_context` — the noisy pieces (`message_history`, full
  `variables.event_payload_json`, `runtime_overrides`,
  `owner_descriptor`, `source_context`) are dropped.
- For other profiles it emits a trimmed `trigger_message` plus `variables`
  with internal form metadata (`_json`, `_meta_*`, `response_id`,
  `survey_generated_id`, `pageNo`, `trigger_type`, `event_payload_json`,
  `memory_json`, …) stripped.
- Long strings are truncated with a `...[truncated]` marker.
- `model_output` is reduced to `display_content` only (no duplicated
  `parsed_response`).
- For `memory_runtime` cases the payload also includes an
  `output_format_contract` field telling the judge that the memory envelope
  (`memory_text`, `memory_object`, `flat_fields`, `change_summary`) is
  enforced by the system. This prevents the judge from penalising outputs
  for "returning JSON" when the admin's instructions would otherwise request
  a narrative paragraph — the envelope is fixed, and the judge is told to
  score the *content* of `memory_text` (and related fields) against the
  instructions.

#### Judge-system prompt

`assets/prompts/core/evaluation/judge-system.md` reinforces this: when the
user payload contains an `output_format_contract`, the judge must respect it
and score the content within the enforced fields rather than the shape of
the envelope.

Keeping the judge input focused and short means the judge's JSON verdict
comfortably fits within the response-token budget (inherited from
`judge_max_tokens` or `getLlmConfig()['llm_max_tokens']`), so verdicts are
not truncated mid-string.

#### Robust judge-response parsing

`extractJsonObject` tolerates common LLM mistakes:

1. Raw JSON, fenced ```` ```json ```` blocks, and balanced-brace substrings
   are all tried.
2. If none decode, each candidate is sanitized with
   `sanitizeJsonControlChars` — bare control characters (raw `\n`, `\r`,
   `\t`, `\f`, `\b`, or any `< 0x20`) that appear **inside** quoted JSON
   string values are escaped and decoding is retried. This avoids the
   "Control character error, possibly incorrectly encoded" failure that
   PHP's strict `json_decode` raises on prettified or truncated responses.

## Adding a new evaluator

1. Seed or create a row in `llm_eval_definitions`.
2. If it is programmatic, add a handler in `LlmEvaluationScoringService::scoreCase(...)`.
3. If it needs special run-level reporting, extend `LlmEvaluationAggregationService`.
4. If it needs UI review input, reuse the existing human-review score flow.

## Result shape

Evaluator implementations should return:

```json
{
  "score_type": "programmatic",
  "score_value_numeric": 1,
  "score_value_label": "pass",
  "passed": 1,
  "details": {}
}
```

LLM judges should keep `details.reason` short and structured. Human-review definitions should use the shared save path so their scores land in `llm_eval_scores` like machine scores.

## Design rules

- Keep evaluator names stable once datasets and dashboards depend on them.
- Prefer structured `details` over prose blobs.
- Use dataset labels for expectations instead of hard-coding owner-specific behavior.
- Reuse the same evaluator across owners whenever possible.

## Related guides

- [prompt-lab-payload-shapes.md](prompt-lab-payload-shapes.md)
- [prompt-lab-developer-guide.md](prompt-lab-developer-guide.md)
