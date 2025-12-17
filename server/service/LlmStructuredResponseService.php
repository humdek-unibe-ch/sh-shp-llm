<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Service class for enforcing structured JSON responses from LLM.
 * 
 * This service adds context instructions that force the LLM to always return
 * responses in a specific JSON schema format. This enables:
 * - Consistent parsing of all responses
 * - Flexible user interaction (forms are optional)
 * - Progress tracking integration
 * - Rich content with text blocks, forms, and media
 */
class LlmStructuredResponseService
{
    /**
     * The structured response schema instruction that gets prepended to context
     */
    private const SCHEMA_INSTRUCTION = <<<'SCHEMA'
## CRITICAL OUTPUT RULE - READ CAREFULLY

You MUST ALWAYS return a SINGLE valid JSON object following this exact RESPONSE_SCHEMA.
No plain text. No markdown. No explanations outside JSON.

### RESPONSE_SCHEMA

```json
{
  "content": {
    "text_blocks": [
      {
        "type": "paragraph|heading|list|quote|info|warning|success|tip",
        "content": "Markdown text content",
        "level": 2  // Only for headings (1-6)
      }
    ],
    "forms": [  // OPTIONAL - only when collecting structured input
      {
        "id": "unique_form_id",
        "title": "Form Title",
        "description": "Optional description",
        "optional": true,
        "fields": [
          {
            "id": "field_id",
            "type": "radio|checkbox|select|text|textarea|number",
            "label": "Question text",
            "required": false,
            "options": [{"value": "val", "label": "Label"}],
            "placeholder": "Optional placeholder",
            "help_text": "Optional help"
          }
        ],
        "submit_label": "Submit"
      }
    ],
    "media": [  // OPTIONAL
      {"type": "image|video", "src": "url", "alt": "description", "caption": "optional"}
    ],
    "next_step": {  // OPTIONAL but recommended
      "prompt": "What would you like to do next?",
      "suggestions": ["Option 1", "Option 2", "Option 3"],
      "can_skip": true
    }
  },
  "meta": {
    "response_type": "educational|conversational|assessment|summary|error",
    "progress": {
      "percentage": 0-100,
      "covered_topics": ["topic1", "topic2"],
      "newly_covered": ["topic2"],
      "remaining_topics": 20,
      "milestone": null|"25%"|"50%"|"75%"|"100%"
    },
    "module_state": {
      "current_phase": "Phase Name",
      "current_section": "Section Name",
      "sections_completed": 1,
      "total_sections": 6
    },
    "emotion": "neutral|encouraging|celebratory|supportive|informative"
  }
}
```

### INTERACTION MODEL

- Users may ALWAYS enter free text - respect their autonomy
- Forms are OPTIONAL guidance tools, not mandatory gates
- NEVER block user progress behind a form
- ALWAYS allow topic changes and free exploration
- If user types free text instead of using a form, acknowledge and respond appropriately

### TEXT BLOCK TYPES

- `paragraph`: Regular text content
- `heading`: Section titles (use level 2-4)
- `list`: Bulleted or numbered lists (use markdown: "- item" or "1. item")
- `quote`: Quotations or important callouts
- `info`: Blue informational boxes
- `warning`: Yellow warning boxes
- `success`: Green success/achievement boxes
- `tip`: Helpful tips

### FORM RULES

- Forms must have `optional: true` unless absolutely required
- Use descriptive snake_case `id` values
- Selection fields (radio, checkbox, select) MUST have `options` array
- Text fields (text, textarea, number) must NOT have `options`
- Always provide text_blocks explaining the purpose before forms

### PROGRESS TRACKING

- Track topic coverage from both free text AND form responses
- Update `covered_topics` when users demonstrate understanding
- Set `newly_covered` to topics covered in THIS response
- Celebrate milestones (25%, 50%, 75%, 100%) with `emotion: "celebratory"`

### FAILURE CONDITIONS (NEVER DO)

❌ Do not output raw text without JSON wrapper
❌ Do not wrap JSON in markdown code blocks
❌ Do not ask user to "switch modes"
❌ Do not require forms for continuation
❌ Do not break the schema structure
❌ Do not include text outside the JSON

### MINIMAL RESPONSE EXAMPLE

```json
{
  "content": {
    "text_blocks": [
      {"type": "paragraph", "content": "Your response text here."}
    ]
  },
  "meta": {
    "response_type": "conversational",
    "emotion": "neutral"
  }
}
```

---
SCHEMA;

    /**
     * Build structured response context to enforce JSON Schema responses
     * 
     * @param array $existing_context Existing conversation context
     * @param bool $include_progress_tracking Whether to include progress tracking in schema
     * @return array Context with structured response instructions prepended
     */
    public function buildStructuredResponseContext($existing_context = [], $include_progress_tracking = true)
    {
        $instruction_content = self::SCHEMA_INSTRUCTION;
        
        // If progress tracking is disabled, simplify the schema instruction
        if (!$include_progress_tracking) {
            $instruction_content = $this->getSimplifiedSchemaInstruction();
        }
        
        $structured_instruction = [
            'role' => 'system',
            'content' => $instruction_content
        ];

        // Prepend structured response instruction to existing context
        return array_merge([$structured_instruction], $existing_context);
    }

    /**
     * Get simplified schema instruction without progress tracking
     * 
     * @return string Simplified schema instruction
     */
    private function getSimplifiedSchemaInstruction()
    {
        return <<<'SCHEMA'
## CRITICAL OUTPUT RULE

You MUST ALWAYS return a SINGLE valid JSON object following this RESPONSE_SCHEMA.
No plain text. No markdown code blocks. No explanations outside JSON.

### RESPONSE_SCHEMA

```json
{
  "content": {
    "text_blocks": [
      {"type": "paragraph|heading|list|quote|info|warning|success|tip", "content": "Markdown text", "level": 2}
    ],
    "forms": [  // OPTIONAL
      {"id": "form_id", "title": "Title", "optional": true, "fields": [...], "submit_label": "Submit"}
    ],
    "next_step": {"prompt": "What next?", "suggestions": ["Option 1"], "can_skip": true}
  },
  "meta": {
    "response_type": "educational|conversational|assessment|summary|error",
    "emotion": "neutral|encouraging|celebratory|supportive|informative"
  }
}
```

### RULES

- Users may ALWAYS enter free text
- Forms are OPTIONAL guidance, not mandatory
- NEVER block progress behind a form
- Text blocks use markdown formatting
- Selection fields need `options` array
- Text/number fields must NOT have `options`

### MINIMAL RESPONSE

```json
{"content":{"text_blocks":[{"type":"paragraph","content":"Response here."}]},"meta":{"response_type":"conversational"}}
```
SCHEMA;
    }

    /**
     * Parse a structured response from LLM output
     * 
     * @param string $content The raw LLM response content
     * @return array|null Parsed structured response or null if invalid
     */
    public function parseStructuredResponse($content)
    {
        if (empty($content)) {
            return null;
        }

        // Clean the content - remove any markdown code block wrappers
        $cleaned = $this->cleanJsonContent($content);
        
        // Try to parse as JSON
        $parsed = json_decode($cleaned, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        
        // Validate basic structure
        if (!$this->validateStructuredResponse($parsed)) {
            return null;
        }
        
        return $parsed;
    }

    /**
     * Clean JSON content by removing markdown code blocks and extra whitespace
     * 
     * @param string $content Raw content
     * @return string Cleaned JSON string
     */
    private function cleanJsonContent($content)
    {
        $content = trim($content);
        
        // Remove markdown code block wrappers (```json ... ``` or ``` ... ```)
        if (preg_match('/^```(?:json)?\s*\n?(.*?)\n?```$/s', $content, $matches)) {
            $content = trim($matches[1]);
        }
        
        // Also try removing just opening/closing if they're on separate lines
        $content = preg_replace('/^```(?:json)?\s*\n/m', '', $content);
        $content = preg_replace('/\n```\s*$/m', '', $content);
        
        return trim($content);
    }

    /**
     * Validate that parsed JSON matches the structured response schema
     * 
     * @param array $parsed Parsed JSON array
     * @return bool True if valid
     */
    private function validateStructuredResponse($parsed)
    {
        // Must have content object
        if (!isset($parsed['content']) || !is_array($parsed['content'])) {
            return false;
        }
        
        // Must have text_blocks array
        if (!isset($parsed['content']['text_blocks']) || !is_array($parsed['content']['text_blocks'])) {
            return false;
        }
        
        // Must have meta object
        if (!isset($parsed['meta']) || !is_array($parsed['meta'])) {
            return false;
        }
        
        // Validate text_blocks have required properties
        foreach ($parsed['content']['text_blocks'] as $block) {
            if (!isset($block['type']) || !isset($block['content'])) {
                return false;
            }
        }
        
        // Validate forms if present
        if (isset($parsed['content']['forms']) && is_array($parsed['content']['forms'])) {
            foreach ($parsed['content']['forms'] as $form) {
                if (!isset($form['id']) || !isset($form['fields']) || !is_array($form['fields'])) {
                    return false;
                }
                foreach ($form['fields'] as $field) {
                    if (!isset($field['id']) || !isset($field['type']) || !isset($field['label'])) {
                        return false;
                    }
                }
            }
        }
        
        return true;
    }

    /**
     * Convert a structured response to plain text for display fallback
     * 
     * @param array $structured The structured response
     * @return string Plain text/markdown representation
     */
    public function structuredToMarkdown($structured)
    {
        $output = [];
        
        if (isset($structured['content']['text_blocks'])) {
            foreach ($structured['content']['text_blocks'] as $block) {
                $type = $block['type'] ?? 'paragraph';
                $content = $block['content'] ?? '';
                
                switch ($type) {
                    case 'heading':
                        $level = $block['level'] ?? 2;
                        $prefix = str_repeat('#', $level);
                        $output[] = "{$prefix} {$content}";
                        break;
                    case 'quote':
                        $lines = explode("\n", $content);
                        $output[] = implode("\n", array_map(fn($l) => "> {$l}", $lines));
                        break;
                    case 'info':
                        $output[] = "ℹ️ **Info**: {$content}";
                        break;
                    case 'warning':
                        $output[] = "⚠️ **Warning**: {$content}";
                        break;
                    case 'success':
                        $output[] = "✅ **Success**: {$content}";
                        break;
                    case 'tip':
                        $output[] = "💡 **Tip**: {$content}";
                        break;
                    default:
                        $output[] = $content;
                }
            }
        }
        
        return implode("\n\n", $output);
    }

    /**
     * Extract form from structured response (for compatibility with existing form mode)
     * 
     * @param array $structured The structured response
     * @return array|null Form definition in legacy format, or null if no form
     */
    public function extractFormFromStructured($structured)
    {
        if (!isset($structured['content']['forms']) || empty($structured['content']['forms'])) {
            return null;
        }
        
        // Get the first form
        $form = $structured['content']['forms'][0];
        
        // Convert to legacy format for backwards compatibility
        return [
            'type' => 'form',
            'title' => $form['title'] ?? '',
            'description' => $form['description'] ?? '',
            'fields' => $form['fields'] ?? [],
            'submitLabel' => $form['submit_label'] ?? 'Submit',
            // Also include the content before the form as contentBefore
            'contentBefore' => $this->structuredToMarkdown([
                'content' => ['text_blocks' => $structured['content']['text_blocks'] ?? []]
            ])
        ];
    }

    /**
     * Extract progress data from structured response
     * 
     * @param array $structured The structured response
     * @return array|null Progress data or null if not present
     */
    public function extractProgressFromStructured($structured)
    {
        if (!isset($structured['meta']['progress'])) {
            return null;
        }
        
        return $structured['meta']['progress'];
    }

    /**
     * Check if the content is a valid structured response
     * 
     * @param string $content The content to check
     * @return bool True if valid structured response
     */
    public function isStructuredResponse($content)
    {
        return $this->parseStructuredResponse($content) !== null;
    }

    /**
     * Create an error structured response
     * 
     * @param string $message Error message
     * @return array Structured error response
     */
    public function createErrorResponse($message)
    {
        return [
            'content' => [
                'text_blocks' => [
                    [
                        'type' => 'warning',
                        'content' => $message
                    ]
                ]
            ],
            'meta' => [
                'response_type' => 'error',
                'emotion' => 'supportive'
            ]
        ];
    }
}
?>

