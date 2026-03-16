<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * LLM Response Service - Unified Response Handling
 * 
 * This service is the single source of truth for LLM response processing.
 * It replaces both LlmStructuredResponseService and the legacy response handling.
 * 
 * Key responsibilities:
 * - Build context instructions for structured JSON output
 * - Parse and validate LLM responses against the unified schema
 * - Handle safety detection from LLM responses
 * - Convert structured responses to markdown fallback
 * - Manage retry logic for invalid responses
 * 
 * @see doc/response-schema.md for complete schema documentation
 * @see server/constants/LlmResponseSchema.php for schema definition
 * @version 1.0.0
 */

require_once __DIR__ . '/LlmLanguageUtility.php';
require_once __DIR__ . '/../constants/LlmResponseSchema.php';
require_once __DIR__ . '/prompt/LlmPromptAssetLoader.php';

class LlmResponseService
{
    /**
     * Maximum retry attempts for invalid responses
     */
    const MAX_RETRY_ATTEMPTS = 3;

    /**
     * @var object Model instance for configuration
     */
    private $model;

    /**
     * @var object Services container
     */
    private $services;
    /** @var LlmPromptAssetLoader */
    private $prompt_assets;

    /**
     * Constructor
     * 
     * @param object $model Model instance for configuration access
     * @param object $services Services container (optional)
     */
    public function __construct($model, $services = null)
    {
        $this->model = $model;
        $this->services = $services;
        $this->prompt_assets = new LlmPromptAssetLoader();
    }

    /* Context Building *******************************************************/

    /**
     * Build structured response context for LLM
     * 
     * This adds the schema instructions and safety detection instructions
     * to the context messages. The LLM will be instructed to:
     * - Always return valid JSON following the schema
     * - Assess safety of user messages
     * - Include all required fields
     * 
     * @param array $existing_context Existing context messages
     * @param bool $include_progress Whether to include progress tracking
     * @param array $progress_data Progress tracking data (topics, current_progress, etc)
     * @param array $danger_config Danger detection config (keywords, enabled)
     * @return array Context with schema instructions prepended
     */
    public function buildResponseContext(
        $existing_context = [], 
        $include_progress = false, 
        $progress_data = [],
        $danger_config = []
    ) {
        // Start with the base schema instructions
        $schema_instruction = $this->buildSchemaInstruction($include_progress, $progress_data);
        
        // Add danger detection instructions if enabled
        if (!empty($danger_config['enabled']) && !empty($danger_config['keywords'])) {
            $schema_instruction = $this->addSafetyInstructions($schema_instruction, $danger_config['keywords']);
        }

        $structured_message = [
            'role' => 'system',
            'content' => $schema_instruction
        ];

        // Prepend schema instruction to existing context
        return array_merge([$structured_message], $existing_context);
    }

    /**
     * Build the main schema instruction
     *
     * @param bool $include_progress Whether to include progress tracking
     * @param array $progress_data Progress tracking data
     * @return string Schema instruction text
     */
    private function buildSchemaInstruction($include_progress = false, $progress_data = [])
    {
        try {
            $schema = LlmResponseSchema::getSchema();
            $schema_json = json_encode($schema, JSON_PRETTY_PRINT);
        } catch (Exception $e) {
            error_log('Failed to load JSON schema file: ' . $e->getMessage());
            throw $e;
        }

        $template = $this->prompt_assets->load('core.response.schema.instruction');
        $instruction = strtr($template, array(
            '{{schema_json}}' => $schema_json,
        ));

        if ($include_progress && !empty($progress_data)) {
            $instruction .= "\n\n" . $this->buildProgressInstruction($progress_data);
        }

        return $instruction;
    }

    /**
     * Add safety detection instructions to schema
     * 
     * @param string $instruction Base instruction
     * @param array $keywords Danger keywords to detect
     * @return string Modified instruction with safety instructions
     */
    private function addSafetyInstructions($instruction, $keywords)
    {
        $keywords_list = implode(', ', array_slice($keywords, 0, 50));
        $template = $this->prompt_assets->load('core.response.safety_detection');
        $safety_instruction = strtr($template, array('{{keywords_list}}' => $keywords_list));

        return $instruction . "\n\n" . $safety_instruction;
    }

    /**
     * Build progress tracking instruction
     * 
     * @param array $progress_data Progress data
     * @return string Progress instruction
     */
    private function buildProgressInstruction($progress_data)
    {
        $topics = $progress_data['topics'] ?? [];
        $current_progress = $progress_data['current_progress'] ?? 0;
        $context_language = $progress_data['context_language'] ?? 'en';
        $confirmed_topics = $progress_data['confirmed_topics'] ?? [];

        if (empty($topics)) {
            return '';
        }

        $topic_list = [];
        $remaining_topics = [];
        foreach ($topics as $topic) {
            $is_confirmed = in_array($topic['id'], $confirmed_topics);
            $status = $is_confirmed ? 'x' : 'o';
            $topic_list[] = "- [{$status}] {$topic['title']} (id: {$topic['id']})";
            if (!$is_confirmed) {
                $remaining_topics[] = $topic['title'];
            }
        }

        $topic_list_str = implode("\n", $topic_list);
        $remaining_str = !empty($remaining_topics) ? implode(', ', array_slice($remaining_topics, 0, 3)) : 'None';
        $prompts = LlmLanguageUtility::getConfirmationPrompts($context_language);

        $template = $this->prompt_assets->load('core.response.progress_tracking');
        return strtr($template, array(
            '{{topic_list}}' => $topic_list_str,
            '{{current_progress}}' => (string)$current_progress,
            '{{remaining_topics}}' => $remaining_str,
            '{{context_language}}' => $context_language,
            '{{confirm_question}}' => (string)($prompts['question'] ?? ''),
            '{{confirm_yes}}' => (string)($prompts['yes'] ?? ''),
            '{{confirm_partial}}' => (string)($prompts['partial'] ?? ''),
            '{{confirm_no}}' => (string)($prompts['no'] ?? ''),
        ));
    }

    /* Response Parsing *******************************************************/

    /**
     * Parse and validate an LLM response
     * 
     * @param string $content Raw LLM response content
     * @return array Result with 'valid', 'data', 'errors' keys
     */
    public function parseResponse($content)
    {
        if (empty($content)) {
            return [
                'valid' => false,
                'data' => null,
                'errors' => ['Empty response content']
            ];
        }

        // Clean content (remove markdown code blocks, extract JSON object)
        $cleaned = $this->cleanJsonContent($content);

        // Try to parse JSON
        $parsed = json_decode($cleaned, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try fixing common syntax errors from weaker models
            $fixed = $this->fixJsonSyntax($cleaned);
            $parsed = json_decode($fixed, true);
        }
        if ($parsed === null && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'valid' => false,
                'data' => null,
                'errors' => ['Invalid JSON: ' . json_last_error_msg()],
                'raw_content' => $content
            ];
        }

        // Normalize non-conforming responses from weaker models
        $parsed = $this->normalizeResponse($parsed);

        // Validate against schema
        $validation = LlmResponseSchema::validate($parsed);
        if (!$validation['valid']) {
            return [
                'valid' => false,
                'data' => $parsed,
                'errors' => $validation['errors'],
                'raw_content' => $content
            ];
        }

        return [
            'valid' => true,
            'data' => $parsed,
            'errors' => []
        ];
    }

    /**
     * Check if response indicates danger
     * 
     * @param array $parsed_response Parsed response data
     * @return array Safety assessment with 'is_safe', 'danger_level', 'requires_intervention', etc
     */
    public function assessSafety($parsed_response)
    {
        if (!isset($parsed_response['safety'])) {
            return [
                'is_safe' => true,
                'danger_level' => null,
                'detected_concerns' => [],
                'requires_intervention' => false,
                'safety_message' => null
            ];
        }

        $safety = $parsed_response['safety'];

        if (!is_array($safety)) {
            $isSafe = is_string($safety) && strtolower($safety) === 'safe';
            return [
                'is_safe' => $isSafe,
                'danger_level' => $isSafe ? null : 'warning',
                'detected_concerns' => [],
                'requires_intervention' => false,
                'safety_message' => null
            ];
        }
        
        return [
            'is_safe' => $safety['is_safe'] ?? true,
            'danger_level' => $safety['danger_level'] ?? null,
            'detected_concerns' => $safety['detected_concerns'] ?? [],
            'requires_intervention' => $safety['requires_intervention'] ?? false,
            'safety_message' => $safety['safety_message'] ?? null
        ];
    }

    /**
     * Check if response requires intervention (notifications)
     * 
     * @param array $parsed_response Parsed response data
     * @return bool True if intervention needed
     */
    public function requiresIntervention($parsed_response)
    {
        $safety = $this->assessSafety($parsed_response);
        return $safety['requires_intervention'] === true;
    }

    /**
     * Check if conversation should be blocked
     * 
     * @param array $parsed_response Parsed response data
     * @return bool True if conversation should be blocked
     */
    public function shouldBlockConversation($parsed_response)
    {
        $safety = $this->assessSafety($parsed_response);
        return $safety['danger_level'] === 'emergency';
    }

    /**
     * Normalize non-conforming LLM responses from weaker models.
     * Attempts to reshape common deviations into the expected schema.
     *
     * @param array $parsed Parsed JSON response
     * @return array Normalized response
     */
    private function normalizeResponse($parsed)
    {
        // Unwrap if response is nested under a "response" key
        if (isset($parsed['response']) && is_array($parsed['response']) && !isset($parsed['type'])) {
            $parsed = $parsed['response'];
        }

        // Add missing "type" field
        if (!isset($parsed['type'])) {
            $parsed['type'] = 'response';
        }

        // Normalize safety field
        if (!isset($parsed['safety'])) {
            $parsed['safety'] = [
                'is_safe' => true,
                'danger_level' => null,
                'detected_concerns' => [],
                'requires_intervention' => false
            ];
        } elseif (!is_array($parsed['safety'])) {
            $safeStr = is_string($parsed['safety']) ? strtolower($parsed['safety']) : '';
            $isSafe = in_array($safeStr, ['safe', 'low', 'none', '']);
            $parsed['safety'] = [
                'is_safe' => $isSafe,
                'danger_level' => $isSafe ? null : 'warning',
                'detected_concerns' => [],
                'requires_intervention' => false
            ];
        } else {
            // Safety is array but might have non-standard keys
            $s = $parsed['safety'];
            if (!isset($s['is_safe'])) {
                $classifier = $s['classifier'] ?? $s['level'] ?? $s['status'] ?? 'safe';
                $isSafe = is_string($classifier) && in_array(strtolower($classifier), ['safe', 'low', 'none']);
                $s['is_safe'] = $isSafe;
            }
            if (!isset($s['danger_level'])) $s['danger_level'] = null;
            if (!isset($s['detected_concerns'])) $s['detected_concerns'] = [];
            if (!isset($s['requires_intervention'])) $s['requires_intervention'] = false;
            $parsed['safety'] = $s;
        }

        // Normalize content / text_blocks
        if (isset($parsed['content']) && is_array($parsed['content'])) {
            if (isset($parsed['content']['text_blocks']) && is_array($parsed['content']['text_blocks'])) {
                $parsed['content']['text_blocks'] = array_map(function ($block) {
                    if (!is_array($block)) {
                        return ['type' => 'text', 'content' => (string) $block];
                    }
                    // Map non-standard "text" key to "content"
                    if (!isset($block['content']) && isset($block['text'])) {
                        $block['content'] = $block['text'];
                        unset($block['text']);
                    }
                    // Map non-standard block types to valid ones
                    $typeMap = [
                        'header' => 'heading', 'section_header' => 'heading', 'title' => 'heading',
                        'item' => 'text', 'detail' => 'text', 'price' => 'text',
                        'line' => 'text', 'paragraph' => 'text', 'note' => 'info',
                        'alert' => 'warning', 'danger' => 'error', 'ok' => 'success',
                    ];
                    if (isset($block['type']) && isset($typeMap[$block['type']])) {
                        $block['type'] = $typeMap[$block['type']];
                    }
                    if (!isset($block['type'])) $block['type'] = 'text';
                    if (!isset($block['content'])) $block['content'] = '';
                    return $block;
                }, $parsed['content']['text_blocks']);
            }
        }

        // Normalize metadata
        if (!isset($parsed['metadata'])) {
            $parsed['metadata'] = ['model' => $this->model ? ($this->model->getConfiguredModel() ?? 'unknown') : 'unknown'];
        } elseif (is_array($parsed['metadata']) && !isset($parsed['metadata']['model'])) {
            $parsed['metadata']['model'] = $this->model ? ($this->model->getConfiguredModel() ?? 'unknown') : 'unknown';
        }

        return $parsed;
    }

    /**
     * Clean JSON content by removing markdown code blocks
     * 
     * @param string $content Raw content
     * @return string Cleaned JSON string
     */
    private function cleanJsonContent($content)
    {
        $content = trim($content);

        // Strip markdown code fences (```json ... ``` or ``` ... ```)
        if (preg_match('/^```(?:json)?\s*\n?(.*?)\n?```$/s', $content, $matches)) {
            $content = trim($matches[1]);
        }
        $content = preg_replace('/^```(?:json)?\s*\n/m', '', $content);
        $content = preg_replace('/\n```\s*$/m', '', $content);
        $content = trim($content);

        // If valid JSON already, return it
        if ($content !== '' && $content[0] === '{') {
            $test = json_decode($content, true);
            if ($test !== null) return $content;
        }

        // Extract the outermost JSON object from anywhere in the response
        $start = strpos($content, '{');
        if ($start !== false) {
            $depth = 0;
            $inStr = false;
            $esc = false;
            $len = strlen($content);
            for ($i = $start; $i < $len; $i++) {
                $ch = $content[$i];
                if ($esc) { $esc = false; continue; }
                if ($ch === '\\' && $inStr) { $esc = true; continue; }
                if ($ch === '"') { $inStr = !$inStr; continue; }
                if ($inStr) continue;
                if ($ch === '{') $depth++;
                elseif ($ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $candidate = substr($content, $start, $i - $start + 1);
                        $test = json_decode($candidate, true);
                        if ($test !== null) return $candidate;
                        break;
                    }
                }
            }
        }

        return $content;
    }

    /**
     * Fix common JSON syntax errors produced by weaker models
     */
    private function fixJsonSyntax($json)
    {
        // Remove trailing commas before } or ]
        $json = preg_replace('/,\s*([\]}])/s', '$1', $json);

        // Remove JavaScript-style comments
        $json = preg_replace('#//[^\n]*#', '', $json);
        $json = preg_replace('#/\*.*?\*/#s', '', $json);

        // Fix unquoted keys: { key: "value" } → { "key": "value" }
        $json = preg_replace('/([{,])\s*([a-zA-Z_]\w*)\s*:/m', '$1"$2":', $json);

        // Replace single-quoted strings with double-quoted (simple cases)
        // Only outside of already double-quoted strings
        $json = preg_replace("/(?<![\"\\\\])'([^'\\\\]*(?:\\\\.[^'\\\\]*)*)'/", '"$1"', $json);

        // Fix Python-style True/False/None
        $json = preg_replace('/\bTrue\b/', 'true', $json);
        $json = preg_replace('/\bFalse\b/', 'false', $json);
        $json = preg_replace('/\bNone\b/', 'null', $json);

        return trim($json);
    }

    /* Response Conversion ****************************************************/

    /**
     * Convert structured response to markdown for display
     * 
     * @param array $structured Parsed structured response
     * @return string Markdown representation
     */
    public function toMarkdown($structured)
    {
        $output = [];

        // Handle safety message if dangerous
        if (isset($structured['safety']) && !$structured['safety']['is_safe']) {
            if (!empty($structured['safety']['safety_message'])) {
                $output[] = "⚠️ **Safety Notice**: " . $structured['safety']['safety_message'];
                $output[] = "";
            }
        }

        // Convert text blocks
        if (isset($structured['content']['text_blocks'])) {
            foreach ($structured['content']['text_blocks'] as $block) {
                $type = $block['type'] ?? 'text';
                $content = $block['content'] ?? '';

                switch ($type) {
                    case 'heading':
                        $output[] = "## " . $content;
                        break;
                    case 'info':
                        $output[] = "ℹ️ **Info**: " . $content;
                        break;
                    case 'warning':
                        $output[] = "⚠️ **Warning**: " . $content;
                        break;
                    case 'error':
                        $output[] = "🚨 **Important**: " . $content;
                        break;
                    case 'success':
                        $output[] = "✅ " . $content;
                        break;
                    case 'code':
                        $output[] = "```\n" . $content . "\n```";
                        break;
                    default:
                        $output[] = $content;
                }
            }
        }

        return implode("\n\n", $output);
    }

    /**
     * Create an error response in the structured format
     * 
     * @param string $message Error message
     * @param string $model Model name
     * @return array Structured error response
     */
    public function createErrorResponse($message, $model = 'unknown')
    {
        return [
            'type' => 'response',
            'safety' => [
                'is_safe' => true,
                'danger_level' => null,
                'detected_concerns' => [],
                'requires_intervention' => false,
                'safety_message' => null
            ],
            'content' => [
                'text_blocks' => [
                    [
                        'type' => 'error',
                        'content' => $message,
                        'style' => 'default'
                    ]
                ],
                'form' => null,
                'media' => [],
                'suggestions' => []
            ],
            'progress' => null,
            'metadata' => [
                'model' => $model,
                'tokens_used' => null
            ]
        ];
    }

    /**
     * Create a retry prompt for invalid responses
     *
     * @param array $errors Validation errors from failed response
     * @return string Retry prompt to send to LLM
     */
    public function createRetryPrompt($errors)
    {
        $error_list = implode("\n- ", $errors);
        $template = $this->prompt_assets->load('core.response.retry_prompt');

        return strtr($template, array('{{error_list}}' => $error_list));
    }

    /**
     * Call LLM API with retry logic for schema validation
     *
     * IMPORTANT: This method now NEVER throws exceptions for validation failures.
     * Instead, it returns a fallback response so the user always sees something.
     * The 'valid' flag indicates whether the response matched the schema.
     *
     * @param callable $callable Function that makes the LLM API call
     * @param array $api_messages Messages to send to LLM
     * @return array [
     *   'response' => parsed_response,
     *   'attempts' => int,
     *   'valid' => bool,
     *   'raw_response' => response,
     *   'request_payload' => array (the full API payload including model, temperature, etc.),
     *   'all_attempts' => array (all attempts with their full payloads and responses for debugging)
     * ]
     */
    public function callLlmWithSchemaValidation($callable, $api_messages)
    {
        $max_attempts = self::MAX_RETRY_ATTEMPTS;
        $last_response = null;
        $last_parsed = null;
        $last_error = null;
        $last_full_payload = null;
        $all_attempts = []; // Track all attempts for debugging/admin view
        $current_messages = $api_messages; // Keep track of current messages (modified with retry instructions)

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $attempt_data = [
                'attempt' => $attempt,
                'request_payload' => null, // Will be set to full payload from API response
                'response' => null,
                'parsed' => null,
                'valid' => false,
                'error' => null
            ];

            try {
                // Make the LLM API call
                $response = $callable($current_messages);

                // Extract the full request payload from the response (includes model, temperature, etc.)
                $full_payload = $response['request_payload'] ?? null;
                $last_full_payload = $full_payload;
                $attempt_data['request_payload'] = $full_payload;

                if (!isset($response['content'])) {
                    throw new Exception('Invalid response from LLM API - missing content');
                }

                $assistant_message = $response['content'];
                $last_response = $response;
                $attempt_data['response'] = $response;

                // Parse and validate the response
                $parsed = $this->parseResponse($assistant_message);
                $last_parsed = $parsed;
                $attempt_data['parsed'] = $parsed;
                $attempt_data['valid'] = $parsed['valid'];

                // Record this attempt
                $all_attempts[] = $attempt_data;

                if ($parsed['valid']) {
                    // Response is valid, return it
                    return [
                        'response' => $parsed,
                        'attempts' => $attempt,
                        'valid' => true,
                        'raw_response' => $response,
                        'request_payload' => $full_payload,
                        'all_attempts' => $all_attempts
                    ];
                }

                // Response validation failed
                $error_msg = 'Schema validation failed on attempt ' . $attempt . ': ' . implode(', ', $parsed['errors']);
                error_log($error_msg);

                // If this is the last attempt, don't retry - return fallback
                if ($attempt >= $max_attempts) {
                    break;
                }

                // Add retry instruction to messages for next attempt
                $retry_instruction = [
                    'role' => 'system',
                    'content' => "⚠️ Your previous response did not match the required JSON schema. " .
                               "Please review the schema carefully and provide a response that strictly follows it. " .
                               "Errors: " . implode(', ', $parsed['errors'])
                ];

                // Prepend retry instruction to messages
                array_unshift($current_messages, $retry_instruction);

                // Small delay before retry to avoid overwhelming the API
                usleep(500000); // 0.5 seconds

            } catch (Exception $e) {
                $error_msg = 'LLM API call failed on attempt ' . $attempt . ': ' . $e->getMessage();
                error_log($error_msg);
                $last_error = $e;
                $attempt_data['error'] = $e->getMessage();
                
                // Try to get the payload from the exception if available (for normalization failures)
                if (method_exists($e, 'getRequestPayload')) {
                    $attempt_data['request_payload'] = $e->getRequestPayload();
                    $last_full_payload = $attempt_data['request_payload'];
                }
                
                // Record this attempt even if it failed
                $all_attempts[] = $attempt_data;

                if ($attempt >= $max_attempts) {
                    break;
                }

                // Small delay before retry
                usleep(500000); // 0.5 seconds
            }
        }

        // All attempts failed - return fallback response instead of throwing
        // This ensures the user ALWAYS sees the response, even if it doesn't match schema
        
        if ($last_response && isset($last_response['content'])) {
            // We have a response but it didn't validate - create fallback
            $fallback = $this->createFallbackResponse($last_response['content'], $last_parsed);
            return [
                'response' => $fallback,
                'attempts' => $max_attempts,
                'valid' => false,
                'raw_response' => $last_response,
                'validation_errors' => $last_parsed['errors'] ?? [],
                'request_payload' => $last_full_payload,
                'all_attempts' => $all_attempts
            ];
        }

        // No response at all - create error fallback
        $error_message = $last_error ? $last_error->getMessage() : 'Failed to get response from LLM';
        $error_fallback = $this->createErrorFallback($error_message);
        return [
            'response' => $error_fallback,
            'attempts' => $max_attempts,
            'valid' => false,
            'raw_response' => null,
            'error' => $error_message,
            'request_payload' => $last_full_payload,
            'all_attempts' => $all_attempts
        ];
    }

    /**
     * Create a fallback structured response from raw content
     * 
     * This method attempts to extract useful content from a response that
     * didn't match the expected JSON schema. It tries multiple strategies:
     * 1. If there's partial JSON data, use what we can
     * 2. If it's plain text, wrap it in a proper structure
     * 3. If we can extract JSON from markdown blocks, try that
     *
     * @param string $rawContent Raw response content from LLM
     * @param array|null $parsedAttempt Previous parse attempt result
     * @return array Fallback response with 'valid' => true (so it renders) and 'data'
     */
    public function createFallbackResponse($rawContent, $parsedAttempt = null)
    {
        $model = 'unknown';
        
        // If we have partially parsed data, try to use it
        if ($parsedAttempt && isset($parsedAttempt['data']) && is_array($parsedAttempt['data'])) {
            $data = $parsedAttempt['data'];
            
            // Try to extract text content from partial data
            $textContent = $this->extractTextFromPartialResponse($data, $rawContent);
            
            return [
                'valid' => true, // Mark as valid so it renders
                'data' => $this->buildFallbackStructure($textContent, $model),
                'errors' => [],
                'is_fallback' => true,
                'original_errors' => $parsedAttempt['errors'] ?? []
            ];
        }
        
        // Try to extract any JSON from the content
        $extractedText = $this->extractTextContent($rawContent);
        
        return [
            'valid' => true, // Mark as valid so it renders
            'data' => $this->buildFallbackStructure($extractedText, $model),
            'errors' => [],
            'is_fallback' => true
        ];
    }

    /**
     * Extract text content from partial/invalid response data
     *
     * @param array $data Partially parsed response data
     * @param string $rawContent Original raw content as fallback
     * @return string Extracted text content
     */
    private function extractTextFromPartialResponse($data, $rawContent)
    {
        $textParts = [];
        
        // Try to get text_blocks content
        if (isset($data['content']['text_blocks']) && is_array($data['content']['text_blocks'])) {
            foreach ($data['content']['text_blocks'] as $block) {
                if (isset($block['content']) && is_string($block['content'])) {
                    $textParts[] = $block['content'];
                }
            }
        }
        
        // Try to get direct content
        if (empty($textParts) && isset($data['content']) && is_string($data['content'])) {
            $textParts[] = $data['content'];
        }
        
        // If we found text in the structure, use it
        if (!empty($textParts)) {
            return implode("\n\n", $textParts);
        }
        
        // Fall back to raw content extraction
        return $this->extractTextContent($rawContent);
    }

    /**
     * Extract readable text from raw content
     * Handles JSON, markdown code blocks, and plain text
     *
     * @param string $rawContent Raw content string
     * @return string Extracted text
     */
    private function extractTextContent($rawContent)
    {
        if (empty($rawContent)) {
            return 'No response content available.';
        }
        
        $content = trim($rawContent);
        
        // Remove markdown code block wrappers
        if (preg_match('/^```(?:json)?\s*\n?(.*?)\n?```\s*$/s', $content, $matches)) {
            $content = trim($matches[1]);
        }
        
        // Try to parse as JSON and extract text
        $decoded = json_decode($content, true);
        if ($decoded !== null && is_array($decoded)) {
            return $this->extractTextFromJson($decoded);
        }
        
        // If it looks like truncated JSON, try to salvage what we can
        if (substr($content, 0, 1) === '{' || substr($content, 0, 1) === '[') {
            $salvaged = $this->salvageTruncatedJson($content);
            if ($salvaged) {
                return $salvaged;
            }
        }
        
        // Return as-is (plain text response)
        return $content;
    }

    /**
     * Extract text from a JSON structure
     *
     * @param array $json Decoded JSON data
     * @return string Extracted text
     */
    private function extractTextFromJson($json)
    {
        $texts = [];
        
        // Look for text_blocks
        if (isset($json['content']['text_blocks'])) {
            foreach ($json['content']['text_blocks'] as $block) {
                if (isset($block['content'])) {
                    $texts[] = $block['content'];
                }
            }
        }
        
        // Look for direct content
        if (isset($json['content']) && is_string($json['content'])) {
            $texts[] = $json['content'];
        }
        
        // Look for message content
        if (isset($json['message'])) {
            $texts[] = $json['message'];
        }
        
        // Look for text field
        if (isset($json['text'])) {
            $texts[] = $json['text'];
        }
        
        if (!empty($texts)) {
            return implode("\n\n", $texts);
        }
        
        // Last resort: pretty print the JSON
        return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Try to salvage text from truncated JSON
     *
     * @param string $content Potentially truncated JSON
     * @return string|null Salvaged text or null
     */
    private function salvageTruncatedJson($content)
    {
        // Look for content patterns in the truncated JSON
        $patterns = [
            '/"content"\s*:\s*"([^"]+)"/s',
            '/"text"\s*:\s*"([^"]+)"/s',
            '/"message"\s*:\s*"([^"]+)"/s',
        ];
        
        $texts = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $match) {
                    // Unescape JSON string escapes
                    $unescaped = json_decode('"' . $match . '"');
                    if ($unescaped) {
                        $texts[] = $unescaped;
                    } else {
                        $texts[] = $match;
                    }
                }
            }
        }
        
        if (!empty($texts)) {
            return implode("\n\n", array_unique($texts));
        }
        
        return null;
    }

    /**
     * Build a proper fallback structure that will render correctly
     *
     * @param string $textContent Text content to wrap
     * @param string $model Model name
     * @return array Structured response data
     */
    private function buildFallbackStructure($textContent, $model = 'unknown')
    {
        return [
            'type' => 'response',
            'safety' => [
                'is_safe' => true,
                'danger_level' => null,
                'detected_concerns' => [],
                'requires_intervention' => false,
                'safety_message' => null
            ],
            'content' => [
                'text_blocks' => [
                    [
                        'type' => 'text',
                        'content' => $textContent,
                        'style' => 'default'
                    ]
                ],
                'form' => null,
                'media' => [],
                'suggestions' => []
            ],
            'progress' => null,
            'metadata' => [
                'model' => $model,
                'tokens_used' => null,
                'is_fallback' => true
            ]
        ];
    }

    /**
     * Create error fallback when no response was received at all
     *
     * @param string $errorMessage Error message to display
     * @return array Fallback response structure
     */
    private function createErrorFallback($errorMessage)
    {
        return [
            'valid' => true, // Mark as valid so it renders
            'data' => [
                'type' => 'response',
                'safety' => [
                    'is_safe' => true,
                    'danger_level' => null,
                    'detected_concerns' => [],
                    'requires_intervention' => false,
                    'safety_message' => null
                ],
                'content' => [
                    'text_blocks' => [
                        [
                            'type' => 'warning',
                            'content' => 'There was an issue getting a response. Please try again.',
                            'style' => 'default'
                        ],
                        [
                            'type' => 'text',
                            'content' => 'Technical details: ' . $errorMessage,
                            'style' => 'default'
                        ]
                    ],
                    'form' => null,
                    'media' => [],
                    'suggestions' => []
                ],
                'progress' => null,
                'metadata' => [
                    'model' => 'unknown',
                    'tokens_used' => null,
                    'is_error_fallback' => true
                ]
            ],
            'errors' => [],
            'is_fallback' => true,
            'is_error' => true
        ];
    }
}
?>




