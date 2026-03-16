# Prompt Lab Payload Shapes

## Purpose

Dataset cases store normalized replay payloads so the replay engine can call the same runtime-aware execution path used by production.

v1.1.0 keeps the full `input_payload_json` object for compatibility and debugging. Do not trim this down to minimal fields in storage.

## Shared shape

Each case payload stores:

- `execution_profile`
- `owner_descriptor`
- runtime-specific input fields (`variables`, `message_history`, `trigger_message`, `form_data`, etc. depending on profile)
- `runtime_overrides` (can be empty object)
- optional metadata fields such as `source_context`

Replay-critical fields:

- `execution_profile`
- `owner_descriptor`
- at least one usable runtime input source (`variables`, `message_history`, `trigger_message`, or profile-specific equivalent)

Optional/debug fields:

- `runtime_overrides` when not needed
- `source_context`
- extra canonical/import helper keys retained for traceability

## `chat_runtime`

```json
{
  "execution_profile": "chat_runtime",
  "owner_descriptor": {
    "owner_type": "style_field",
    "owner_id": 123,
    "prompt_slot": "conversation_context",
    "id_languages": 1
  },
  "message_history": [
    { "role": "user", "content": "I feel overwhelmed." },
    { "role": "assistant", "content": "Tell me more about that." }
  ],
  "trigger_message": "I also cannot sleep.",
  "runtime_overrides": {},
  "source_context": {
    "id_llmConversations": 45,
    "message_window": "last_12"
  }
}
```

## `form_runtime`

```json
{
  "execution_profile": "form_runtime",
  "owner_descriptor": {
    "owner_type": "style_field",
    "owner_id": 456,
    "prompt_slot": "llm_context",
    "id_languages": 1
  },
  "variables": {
    "reflection": "I struggled this week."
  },
  "form_data": {
    "reflection": "I struggled this week.",
    "stress_level": "8"
  },
  "runtime_overrides": {}
}
```

## `script_runtime`

```json
{
  "execution_profile": "script_runtime",
  "owner_descriptor": {
    "owner_type": "llm_script",
    "owner_id": 45,
    "prompt_slot": "script",
    "id_languages": 1
  },
  "variables": {
    "name": "Stefan"
  },
  "runtime_overrides": {
    "data_config": {}
  }
}
```

## Expectations

Cases can also store:

- `expected_output_json`
- `expected_labels_json`

Use `expected_labels_json` when you want strict checks without requiring one exact natural-language answer.

Example:

```json
{
  "safety": {
    "danger_level": "warning"
  }
}
```

This matches the built-in `safety_label_match` evaluator.

## Notes for developers

- Keep payloads normalized and owner-aware.
- Keep full normalized payload shape in `input_payload_json`; do not reduce to minimal replay-only snapshots.
- Preserve source references separately in `source_ref_json`.
- For `form_runtime`, ensure variables align with actual prompt placeholders (`{{...}}`) so replay does not collapse to fallback user text.

## Related guides

- [prompt-lab-developer-guide.md](prompt-lab-developer-guide.md)
- [prompt-evaluator-authoring-guide.md](prompt-evaluator-authoring-guide.md)
