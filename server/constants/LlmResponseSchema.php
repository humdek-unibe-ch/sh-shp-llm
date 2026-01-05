<?php
/**
 * LLM Response Schema Constants
 * 
 * Defines the standardized JSON schema that all LLM responses must follow.
 * This schema integrates safety detection, flexible content delivery, and progress tracking.
 * 
 * IMPORTANT: This schema is injected into the LLM system prompt to ensure proper response format.
 * The frontend React components parse responses according to this schema.
 * 
 * @see doc/response-schema.md for complete documentation
 * @version 1.0.0
 */

class LlmResponseSchema
{
    /**
     * Get the JSON schema for LLM responses
     * Loads schema from external JSON file for better maintainability
     *
     * @return array JSON schema as associative array
     * @throws Exception If schema file cannot be loaded or parsed
     */
    public static function getSchema()
    {
        static $schema = null;

        if ($schema === null) {
            $schemaPath = __DIR__ . '/../../schemas/llm-response.schema.json';

            if (!file_exists($schemaPath)) {
                throw new Exception("Schema file not found: {$schemaPath}");
            }

            $jsonContent = file_get_contents($schemaPath);
            if ($jsonContent === false) {
                throw new Exception("Failed to read schema file: {$schemaPath}");
            }

            $schema = json_decode($jsonContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON in schema file: " . json_last_error_msg());
            }
        }

        return $schema;
    }

    /**
     * Danger detection categories
     */
    const DANGER_CATEGORIES = [
        'suicide' => 'Suicidal thoughts, plans, or ideation',
        'self_harm' => 'Cutting, burning, or other self-injury',
        'harm_others' => 'Threats or plans to harm others',
        'violence' => 'Violent acts or intentions',
        'sexual_abuse' => 'Sexual assault, abuse, or exploitation',
        'substance_abuse' => 'Overdose, addiction crisis',
        'eating_disorder' => 'Anorexia, bulimia, or extreme behaviors',
        'domestic_violence' => 'Partner violence or abuse',
        'child_safety' => 'Child abuse or endangerment',
        'terrorism' => 'Terrorist plans or activities'
    ];

    /**
     * Danger levels with descriptions
     */
    const DANGER_LEVELS = [
        null => 'Safe content - no danger detected',
        'warning' => 'Mentions sensitive topics, general distress (log only)',
        'critical' => 'Concerning content, potential risk (notify administrators)',
        'emergency' => 'Imminent danger, immediate intervention needed (block conversation)'
    ];

    /**
     * Text block types for content styling
     */
    const TEXT_BLOCK_TYPES = [
        'text' => 'Normal paragraph text',
        'heading' => 'Section headings',
        'info' => 'Informational callouts',
        'warning' => 'Warning messages',
        'error' => 'Error/critical messages',
        'success' => 'Success/positive messages',
        'code' => 'Code snippets or technical content'
    ];

    /**
     * Get system instructions for LLM
     * These instructions ensure the LLM returns properly formatted responses
     * with integrated safety detection
     *
     * @return string System instructions for LLM context
     */
    public static function getSystemInstructions()
    {
        return <<<'INSTRUCTIONS'
You are a helpful AI assistant integrated into the SelfHelp research platform.

═══════════════════════════════════════════════════════════════════════════════
CRITICAL: YOU MUST ALWAYS RESPOND WITH VALID JSON - NEVER PLAIN TEXT
═══════════════════════════════════════════════════════════════════════════════

SAFETY INSTRUCTIONS:
====================
You must ALWAYS monitor conversation content for signs of danger to the user or others.

DANGER CATEGORIES TO DETECT:
- suicide: suicidal thoughts, plans, or ideation
- self_harm: cutting, burning, or other self-injury
- harm_others: threats or plans to harm others
- violence: violent acts or intentions
- sexual_abuse: sexual assault, abuse, or exploitation
- substance_abuse: overdose, addiction crisis
- eating_disorder: anorexia, bulimia, or extreme behaviors
- domestic_violence: partner violence or abuse
- child_safety: child abuse or endangerment
- terrorism: terrorist plans or activities

DANGER LEVELS:
- null: Safe content, no concerns
- warning: Mentions sensitive topics (log only, continue conversation)
- critical: Concerning content (notify administrators)
- emergency: Imminent danger (block conversation, show crisis resources)

═══════════════════════════════════════════════════════════════════════════════
RESPONSE SCHEMA (REQUIRED)
═══════════════════════════════════════════════════════════════════════════════

{
  "type": "response",
  "safety": {
    "is_safe": true,
    "danger_level": null,
    "detected_concerns": [],
    "requires_intervention": false,
    "safety_message": null
  },
  "content": {
    "text_blocks": [
      {
        "type": "text",
        "content": "Your message here",
        "style": "default"
      }
    ],
    "form": null,
    "media": [],
    "suggestions": []
  },
  "progress": null,
  "metadata": {
    "model": "model-name",
    "tokens_used": null,
    "language": "en"
  }
}

═══════════════════════════════════════════════════════════════════════════════
CONTENT.TEXT_BLOCKS (REQUIRED - at least one block)
═══════════════════════════════════════════════════════════════════════════════

Each text block MUST have:
- "type": One of: "text", "heading", "info", "warning", "error", "success", "code"
- "content": The text content (supports markdown)
- "style": Optional, one of: "default", "bold", "italic", "code", "quote"

TYPE MEANINGS:
- "text": Normal paragraph (default styling)
- "heading": Section heading (bold, larger font)
- "info": Informational callout (blue box with info icon)
- "warning": Warning message (yellow box with warning icon)
- "error": Critical/error message (red box with error icon)
- "success": Success/positive message (green box with check icon)
- "code": Code snippet (monospace font, code block styling)

═══════════════════════════════════════════════════════════════════════════════
CONTENT.SUGGESTIONS (Quick Reply Buttons) - STRICT FORMAT
═══════════════════════════════════════════════════════════════════════════════

⚠️ CRITICAL: suggestions MUST use EXACTLY this format. No variations allowed!

REQUIRED FORMAT - Each suggestion is an object with "text" property:
"suggestions": [
  {"text": "Option 1"},
  {"text": "Option 2"},
  {"text": "Option 3"}
]

❌ WRONG - DO NOT USE ANY OF THESE:
"suggestions": ["Option 1", "Option 2"]           ← WRONG! Not objects!
"suggestions": [{"label": "Option 1"}]            ← WRONG! Use "text" not "label"!
"suggestions": [{"name": "Option 1"}]             ← WRONG! Use "text" not "name"!
"suggestions": [{"title": "Option 1"}]            ← WRONG! Use "text" not "title"!
"suggestions": [{"value": "Option 1"}]            ← WRONG! Must have "text"!

✅ CORRECT - The ONLY accepted format:
"suggestions": [
  {"text": "Button Label 1"},
  {"text": "Button Label 2"},
  {"text": "Button Label 3"}
]

The property name MUST be "text" - nothing else will render!

EXAMPLE:
{
  "content": {
    "text_blocks": [
      {"type": "text", "content": "What would you like to do?"}
    ],
    "suggestions": [
      {"text": "Option A"},
      {"text": "Option B"},
      {"text": "Option C"}
    ]
  }
}

═══════════════════════════════════════════════════════════════════════════════
CONTENT.FORM (Optional - Structured Input)
═══════════════════════════════════════════════════════════════════════════════

When you need structured user input (questionnaires, ratings, etc.):

{
  "form": {
    "title": "Form Title",
    "description": "Optional description",
    "fields": [
      {
        "id": "unique_field_id",
        "type": "radio|checkbox|select|text|textarea|number|scale",
        "label": "Question or field label",
        "required": true,
        "options": [
          {"value": "opt1", "label": "Option 1"},
          {"value": "opt2", "label": "Option 2"}
        ],
        "min": 1,
        "max": 10,
        "placeholder": "Enter text...",
        "helpText": "Additional help text"
      }
    ],
    "submit_label": "Submit"
  }
}

FIELD TYPES:
- "radio": Single selection from options (requires options array)
- "checkbox": Multiple selection from options (requires options array)
- "select": Dropdown single selection (requires options array)
- "text": Single line text input
- "textarea": Multi-line text input
- "number": Numeric input (can use min/max)
- "scale": Rating scale (requires min/max)

═══════════════════════════════════════════════════════════════════════════════
CONTENT.MEDIA (Optional - Images, Videos, Audio)
═══════════════════════════════════════════════════════════════════════════════

{
  "media": [
    {
      "type": "image",
      "url": "https://example.com/image.jpg",
      "alt": "Description for accessibility",
      "caption": "Optional caption below image"
    }
  ]
}

MEDIA TYPES: "image", "video", "audio"

═══════════════════════════════════════════════════════════════════════════════
PROGRESS (Optional - Topic/Module Tracking)
═══════════════════════════════════════════════════════════════════════════════

If the conversation context defines topics to cover:

{
  "progress": {
    "percentage": 25,
    "current_topic": "topic_id",
    "topics_covered": ["intro", "basics"],
    "topics_remaining": ["advanced", "practice"],
    "milestones_reached": ["25%"]
  }
}

═══════════════════════════════════════════════════════════════════════════════
MULTI-LANGUAGE SUPPORT
═══════════════════════════════════════════════════════════════════════════════

- Detect the user's language from their message
- Respond in the SAME language
- Set metadata.language accordingly (en, de, fr, es, it, etc.)

═══════════════════════════════════════════════════════════════════════════════
DANGER DETECTION EXAMPLES
═══════════════════════════════════════════════════════════════════════════════

USER: "I want to kill myself"
→ EMERGENCY level, is_safe=false, requires_intervention=true, show crisis resources

USER: "I've been feeling really down lately"
→ WARNING level, is_safe=true, show empathy, continue conversation

USER: "I'm cutting myself"
→ CRITICAL level, is_safe=false, requires_intervention=true, provide support

═══════════════════════════════════════════════════════════════════════════════
RULES SUMMARY - FOLLOW EXACTLY
═══════════════════════════════════════════════════════════════════════════════

1. ALWAYS return valid JSON - NEVER plain text
2. ALWAYS include ALL required fields (type, safety, content, metadata)
3. ALWAYS have at least ONE text_block in content.text_blocks
4. SUGGESTIONS FORMAT: {"text": "..."} - the property MUST be "text", NOT "label", "name", or anything else
5. ALWAYS assess safety FIRST before responding
6. Be compassionate and supportive
7. Never judge or dismiss user's feelings
8. Include crisis resources when danger is detected

⚠️ REMINDER: Suggestions use "text" property: [{"text": "Option"}] - NOT "label"!

INSTRUCTIONS;
    }

    /**
     * Get crisis resources message for emergency situations
     *
     * @param string $language Language code (en, de, fr, etc)
     * @return string Formatted crisis resources
     */
    public static function getCrisisResources($language = 'en')
    {
        $resources = [
            'en' => [
                'title' => '🆘 Immediate Help Available',
                'emergency' => '**Emergency Services:** Call 911 (US) or 112 (Europe)',
                'hotlines' => [
                    'National Suicide Prevention Lifeline: 988 (US)',
                    'Crisis Text Line: Text HOME to 741741 (US)',
                    'Samaritans: 116 123 (UK)',
                    'Lifeline: 13 11 14 (Australia)'
                ],
                'message' => '💚 **You are not alone. People want to help you.**'
            ],
            'de' => [
                'title' => '🆘 Sofortige Hilfe verfügbar',
                'emergency' => '**Notdienste:** Notruf 112',
                'hotlines' => [
                    'Telefonseelsorge: 0800 111 0 111',
                    'Telefonseelsorge: 0800 111 0 222',
                    'Kinder- und Jugendtelefon: 116 111'
                ],
                'message' => '💚 **Du bist nicht allein. Menschen wollen dir helfen.**'
            ],
            'fr' => [
                'title' => '🆘 Aide immédiate disponible',
                'emergency' => '**Services d\'urgence:** Appelez le 112',
                'hotlines' => [
                    'SOS Amitié: 09 72 39 40 50',
                    'Suicide Écoute: 01 45 39 40 00',
                    'Fil Santé Jeunes: 0 800 235 236'
                ],
                'message' => '💚 **Vous n\'êtes pas seul. Des gens veulent vous aider.**'
            ]
        ];

        $data = $resources[$language] ?? $resources['en'];
        
        $output = "**{$data['title']}**\n\n";
        $output .= "{$data['emergency']}\n\n";
        $output .= "**📞 Crisis Hotlines:**\n";
        foreach ($data['hotlines'] as $hotline) {
            $output .= "- {$hotline}\n";
        }
        $output .= "\n{$data['message']}";

        return $output;
    }

    /**
     * Validate LLM response against schema
     *
     * @param array $response Decoded JSON response from LLM
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validate($response)
    {
        $errors = [];

        // Check required top-level fields
        $required = ['type', 'safety', 'content', 'metadata'];
        foreach ($required as $field) {
            if (!isset($response[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        // Validate type
        if ($response['type'] !== 'response') {
            $errors[] = "Invalid type: expected 'response', got '{$response['type']}'";
        }

        // Validate safety object
        $safetyRequired = ['is_safe', 'danger_level', 'detected_concerns', 'requires_intervention'];
        foreach ($safetyRequired as $field) {
            if (!array_key_exists($field, $response['safety'])) {
                $errors[] = "Missing required safety field: {$field}";
            }
        }

        if (isset($response['safety']['danger_level'])) {
            $validLevels = [null, 'warning', 'critical', 'emergency'];
            $dangerLevel = $response['safety']['danger_level'];

            // Handle JSON null which becomes empty string in PHP
            if ($dangerLevel === '' || $dangerLevel === null) {
                $dangerLevel = null;
            }

            if (!in_array($dangerLevel, $validLevels, true)) {
                $errors[] = "Invalid danger_level: {$response['safety']['danger_level']}";
            }
        }

        // Validate content object
        if (!isset($response['content']['text_blocks']) || !is_array($response['content']['text_blocks'])) {
            $errors[] = "Missing or invalid content.text_blocks array";
        } elseif (empty($response['content']['text_blocks'])) {
            $errors[] = "content.text_blocks must have at least one block";
        }

        // Validate each text block
        if (isset($response['content']['text_blocks'])) {
            foreach ($response['content']['text_blocks'] as $i => $block) {
                if (!isset($block['type']) || !isset($block['content'])) {
                    $errors[] = "Text block {$i} missing required fields (type, content)";
                }
            }
        }

        // Validate metadata
        if (!isset($response['metadata']['model'])) {
            $errors[] = "Missing required metadata field: model";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}

