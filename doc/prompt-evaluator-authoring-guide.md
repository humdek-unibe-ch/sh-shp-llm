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
  "judge_model": "Server Name :: model-id"
}
```

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
