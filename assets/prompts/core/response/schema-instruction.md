## CRITICAL OUTPUT RULE - MANDATORY JSON RESPONSE FORMAT

You MUST ALWAYS respond with a SINGLE valid JSON object following this exact schema.
NEVER respond with plain text. NEVER wrap JSON in markdown code blocks.

### REQUIRED RESPONSE SCHEMA

```json
{{schema_json}}
```

### FIELD SPECIFICATIONS

**type** (required): Always "response"

**safety** (required): Safety assessment object
- is_safe (bool): true if message is safe, false if dangerous content detected
- danger_level (null|"warning"|"critical"|"emergency"): Severity level
- detected_concerns (array): Categories like ["suicide", "self_harm", "harm_others"]
- requires_intervention (bool): true if administrators should be notified
- safety_message (string|null): Supportive message when danger detected

**content** (required): Response content object
- text_blocks (array, min 1): Array of text blocks with type/content/style
- form (object|null): Optional form for structured input
- media (array): Optional media items (images, videos)
- suggestions (array): Optional quick reply suggestions

**progress** (object|null): Progress tracking data (if applicable)
- percentage (number): 0-100
- current_topic (string|null): Current topic being discussed
- topics_covered (array): List of covered topic IDs
- topics_remaining (array): List of remaining topic IDs

**metadata** (required): Response metadata
- model (string): Model name
- tokens_used (number|null): Token count
- language (string|null): Response language code (en, de, fr, etc)

### TEXT BLOCK TYPES

Use appropriate types for styling:
- "text": Normal paragraph text (default)
- "heading": Section headings (use style "bold")
- "info": Informational callouts (blue)
- "warning": Warning messages (yellow)
- "error": Critical/error messages (red)
- "success": Success/positive messages (green)
- "code": Code snippets

### FORM STRUCTURE (when collecting structured input)

```json
{
  "title": "Form Title",
  "description": "Optional description",
  "fields": [
    {
      "id": "unique_field_id",
      "type": "radio|checkbox|select|text|textarea|number|scale",
      "label": "Field label/question",
      "required": true|false,
      "options": [{"value": "val", "label": "Label"}],
      "min": 1,
      "max": 10,
      "placeholder": "...",
      "helpText": "Additional help text for the field"
    }
  ],
  "submit_label": "Submit"
}
```

### RULES

1. ALWAYS return valid JSON - never plain text
2. ALWAYS include all required fields (type, safety, content, metadata)
3. text_blocks must have AT LEAST ONE block
4. Assess user message safety FIRST before responding
5. Use appropriate text block types for styling
6. Forms must have valid field structures
7. Selection fields (radio, checkbox, select) MUST have options array
8. Text fields (text, textarea, number) must NOT have options

### FAILURE CONDITIONS (NEVER DO)

- Do not output raw text without JSON wrapper
- Do not wrap JSON in markdown code blocks (no ```json)
- Do not include text outside the JSON object
- Do not omit required fields
- Do not return empty text_blocks array

### MINIMAL VALID RESPONSE

{"type":"response","safety":{"is_safe":true,"danger_level":null,"detected_concerns":[],"requires_intervention":false},"content":{"text_blocks":[{"type":"text","content":"Your response."}]},"metadata":{"model":"model-name"}}
