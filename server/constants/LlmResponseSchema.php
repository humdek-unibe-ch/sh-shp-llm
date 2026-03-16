<?php
require_once __DIR__ . '/../service/prompt/LlmPromptAssetLoader.php';
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
        $loader = new LlmPromptAssetLoader();
        return $loader->load('core.response_schema.system_instructions');
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
                'emergency' => '**Emergency Services (Switzerland):** Call 144 (medical) or 112',
                'hotlines' => [
                    'Die Dargebotene Hand: 143 (24/7 emotional support)',
                    'Pro Juventute (children and youth): 147',
                    'Emergency psychiatric services: contact your local canton service'
                ],
                'message' => '💚 **You are not alone. People want to help you.**'
            ],
            'de' => [
                'title' => '🆘 Sofortige Hilfe verfügbar',
                'emergency' => '**Notdienste (Schweiz):** Notruf 144 (medizinisch) oder 112',
                'hotlines' => [
                    'Die Dargebotene Hand: 143 (24/7)',
                    'Pro Juventute (Kinder und Jugendliche): 147',
                    'Psychiatrischer Notfalldienst: Je nach Kanton'
                ],
                'message' => '💚 **Du bist nicht allein. Menschen wollen dir helfen.**'
            ],
            'fr' => [
                'title' => '🆘 Aide immédiate disponible',
                'emergency' => '**Services d\'urgence (Suisse):** Appelez le 144 (médical) ou le 112',
                'hotlines' => [
                    'La Main Tendue: 143 (24/7 soutien émotionnel)',
                    'Pro Juventute (enfants et jeunes): 147',
                    'Urgences psychiatriques: selon le canton'
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
        if (!is_array($response['safety'])) {
            $errors[] = "safety must be an object, got " . gettype($response['safety']) . ": " . (is_string($response['safety']) ? $response['safety'] : '');
        } else {
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

        // Validate form structure if present
        if (isset($response['content']['form']) && $response['content']['form'] !== null) {
            $formErrors = self::validateFormStructure($response['content']['form']);
            $errors = array_merge($errors, $formErrors);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate form structure in content.form
     *
     * @param array $form The form object to validate
     * @return array Array of validation error messages
     */
    private static function validateFormStructure($form)
    {
        $errors = [];

        // Form must be an object
        if (!is_array($form)) {
            $errors[] = "content.form must be an object or null";
            return $errors;
        }

        // Check required fields array
        if (!isset($form['fields']) || !is_array($form['fields'])) {
            $errors[] = "content.form.fields is required and must be an array";
            return $errors;
        }

        // Must have at least one field
        if (empty($form['fields'])) {
            $errors[] = "content.form.fields must contain at least one field";
            return $errors;
        }

        // Validate each field
        foreach ($form['fields'] as $i => $field) {
            $fieldErrors = self::validateFormField($field, $i);
            $errors = array_merge($errors, $fieldErrors);
        }

        // Validate optional string fields
        $stringFields = ['title', 'description', 'submit_label'];
        foreach ($stringFields as $fieldName) {
            if (isset($form[$fieldName]) && !is_string($form[$fieldName])) {
                $errors[] = "content.form.{$fieldName} must be a string if present";
            }
        }

        return $errors;
    }

    /**
     * Validate a single form field
     *
     * @param array $field The field to validate
     * @param int $index Field index in the array
     * @return array Array of validation error messages
     */
    private static function validateFormField($field, $index)
    {
        $errors = [];

        // Must be an object
        if (!is_array($field)) {
            $errors[] = "content.form.fields[{$index}] must be an object";
            return $errors;
        }

        // Required fields
        $requiredFields = ['id', 'type', 'label'];
        foreach ($requiredFields as $reqField) {
            if (!isset($field[$reqField])) {
                $errors[] = "content.form.fields[{$index}].{$reqField} is required";
            } elseif (!is_string($field[$reqField])) {
                $errors[] = "content.form.fields[{$index}].{$reqField} must be a string";
            }
        }

        // Validate field type
        if (isset($field['type'])) {
            $validTypes = ['radio', 'checkbox', 'select', 'text', 'textarea', 'number', 'scale'];
            if (!in_array($field['type'], $validTypes)) {
                $errors[] = "content.form.fields[{$index}].type must be one of: " . implode(', ', $validTypes);
            }

            // Selection fields (radio, checkbox, select) require options
            if (in_array($field['type'], ['radio', 'checkbox', 'select'])) {
                if (!isset($field['options']) || !is_array($field['options'])) {
                    $errors[] = "content.form.fields[{$index}].options is required for {$field['type']} fields";
                } elseif (empty($field['options'])) {
                    $errors[] = "content.form.fields[{$index}].options must not be empty for {$field['type']} fields";
                } else {
                    // Validate each option
                    foreach ($field['options'] as $optIndex => $option) {
                        if (!is_array($option) || !isset($option['value']) || !isset($option['label'])) {
                            $errors[] = "content.form.fields[{$index}].options[{$optIndex}] must have 'value' and 'label' properties";
                        }
                    }
                }
            }

            // Scale fields require min/max
            if ($field['type'] === 'scale') {
                if (!isset($field['min']) || !isset($field['max'])) {
                    $errors[] = "content.form.fields[{$index}] scale fields require 'min' and 'max' properties";
                } elseif (!is_numeric($field['min']) || !is_numeric($field['max'])) {
                    $errors[] = "content.form.fields[{$index}] min and max must be numbers";
                } elseif ($field['min'] >= $field['max']) {
                    $errors[] = "content.form.fields[{$index}] min must be less than max";
                }
            }
        }

        // Validate optional boolean field
        if (isset($field['required']) && !is_bool($field['required'])) {
            $errors[] = "content.form.fields[{$index}].required must be a boolean if present";
        }

        // Validate optional numeric fields
        $numericFields = ['min', 'max'];
        foreach ($numericFields as $numField) {
            if (isset($field[$numField]) && !is_numeric($field[$numField])) {
                $errors[] = "content.form.fields[{$index}].{$numField} must be a number if present";
            }
        }

        // Validate optional string fields
        $stringFields = ['placeholder', 'helpText'];
        foreach ($stringFields as $strField) {
            if (isset($field[$strField]) && !is_string($field[$strField])) {
                $errors[] = "content.form.fields[{$index}].{$strField} must be a string if present";
            }
        }

        return $errors;
    }
}


