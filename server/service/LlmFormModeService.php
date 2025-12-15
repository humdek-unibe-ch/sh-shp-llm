<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Service class for LLM Form Mode functionality.
 * Handles form mode context building and form value processing.
 */
class LlmFormModeService
{
    /**
     * Build form mode context to enforce JSON Schema form responses
     * 
     * @param array $existing_context Existing conversation context
     * @return array Context with form mode instructions prepended
     */
    public function buildFormModeContext($existing_context = [])
    {
        $form_mode_instruction = [
            'role' => 'system',
            'content' => 'IMPORTANT: You are operating in FORM MODE. You MUST respond ONLY with valid JSON form definitions.

Your response must be a valid JSON object with this EXACT structure (no markdown, no code blocks, just pure JSON):
{
  "type": "form",
  "title": "Form Title",
  "description": "Optional description or instructions",
  "fields": [
    {
      "id": "unique_field_id",
      "type": "radio",
      "label": "Question text",
      "required": true,
      "options": [
        {"value": "option_value", "label": "Option Label"}
      ],
      "helpText": "Optional help text"
    }
  ],
  "submitLabel": "Submit"
}

SUPPORTED FIELD TYPES (use ONLY these):
1. "radio" - Single selection, requires "options" array
2. "checkbox" - Multiple selection, requires "options" array  
3. "select" - Dropdown, requires "options" array
4. "text" - Single-line text input, NO options needed
5. "textarea" - Multi-line text input, NO options needed
6. "number" - Numeric input, NO options needed (can have min, max, step)

DO NOT USE: date, time, email, url, file, rating, slider, or any other types.

SELECTION FIELD EXAMPLE:
{
  "id": "preference",
  "type": "radio",
  "label": "Your preference?",
  "required": true,
  "options": [
    {"value": "opt1", "label": "Option 1"},
    {"value": "opt2", "label": "Option 2"}
  ]
}

TEXT FIELD EXAMPLE:
{
  "id": "other_specify",
  "type": "text",
  "label": "Please specify",
  "required": false,
  "placeholder": "Enter your answer..."
}

NUMBER FIELD EXAMPLE:
{
  "id": "weekly_goal",
  "type": "number",
  "label": "Sessions per week?",
  "required": true,
  "min": 1,
  "max": 14
}

CRITICAL RULES:
1. "type" at root MUST be "form"
2. Output ONLY JSON - no markdown, no code blocks, no explanations
3. Each field needs unique "id" (snake_case)
4. Selection fields MUST have "options" array
5. Text/textarea fields must NOT have "options"
6. Include MULTIPLE questions per form when appropriate
7. Use "contentBefore" for educational content before fields

After submission, generate the next form based on responses.
When complete, respond with a summary (not a form).'
        ];

        // Prepend form mode instruction to existing context
        return array_merge([$form_mode_instruction], $existing_context);
    }

    /**
     * Generate readable text from form values when frontend doesn't provide it
     * This is a fallback - the frontend should normally generate this
     * 
     * @param array $form_values The form field values
     * @return string Human-readable text representation
     */
    public function generateReadableTextFromFormValues($form_values)
    {
        if (empty($form_values)) {
            return '';
        }

        $parts = [];
        foreach ($form_values as $field_id => $value) {
            if (empty($value)) {
                continue;
            }

            // Format the field ID to be more readable
            $readable_field = str_replace(['_', '-'], ' ', $field_id);
            $readable_field = ucwords($readable_field);

            if (is_array($value)) {
                $parts[] = $readable_field . ': ' . implode(', ', $value);
            } else {
                $parts[] = $readable_field . ': ' . $value;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Validate form values have at least one selection
     * 
     * @param array $form_values The form field values
     * @return bool True if at least one field has a value
     */
    public function hasSelections($form_values)
    {
        if (empty($form_values) || !is_array($form_values)) {
            return false;
        }

        foreach ($form_values as $key => $value) {
            if (!empty($value) || (is_array($value) && count($value) > 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse and validate form values JSON
     * 
     * @param string $form_values_json JSON string of form values
     * @return array|null Parsed array or null if invalid
     */
    public function parseFormValues($form_values_json)
    {
        if (empty($form_values_json)) {
            return null;
        }

        $form_values = json_decode($form_values_json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($form_values)) {
            return null;
        }

        return $form_values;
    }

    /**
     * Create form metadata for storage
     * 
     * @param array $form_values The form field values
     * @return string JSON encoded metadata
     */
    public function createFormMetadata($form_values)
    {
        return json_encode([
            'type' => 'form_submission',
            'values' => $form_values
        ]);
    }
}
?>

