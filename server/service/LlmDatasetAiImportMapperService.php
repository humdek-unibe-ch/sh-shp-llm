<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * LLM Dataset AI Import Mapper Service
 *
 * Normalizes and maps the output of the AI import parser into the standard
 * dataset case schema. Handles column mapping, field normalization,
 * input/output payload construction, and expected label extraction.
 *
 * This is a pure-logic service (no DB access) that transforms parsed data
 * into structures compatible with LlmDatasetService::addCase().
 *
 * @package LLM Plugin
 * @see LlmDatasetAiImportParserService For the LLM-powered parsing step
 * @see LlmDatasetBatchImportService For bulk import orchestration
 */
class LlmDatasetAiImportMapperService
{
    /** @var LlmDatasetService For metadata lookups and normalization helpers */
    private $dataset_service;

    /** @param LlmDatasetService $dataset_service Dataset service for metadata lookups. */
    public function __construct($dataset_service)
    {
        $this->dataset_service = $dataset_service;
    }

    /**
     * Normalize a full AI-parsed import payload into standard dataset case structures.
     *
     * @param array  $payload            Parsed output with 'cases' and optional 'mapping'.
     * @param array  $descriptor         Owner descriptor for the target prompt.
     * @param string $execution_profile  Target execution profile code.
     * @param array  $runtime_overrides  Runtime parameter overrides.
     * @return array{mapping: array, cases: array, warnings: string[]}
     */
    public function normalizeParsedPayload($payload, $descriptor, $execution_profile, $runtime_overrides = array())
    {
        $payload = is_array($payload) ? $payload : array();
        $rows = is_array($payload['cases'] ?? null) ? $payload['cases'] : array();
        $mapping = is_array($payload['mapping'] ?? null)
            ? $payload['mapping']
            : (is_array($payload['column_mapping'] ?? null) ? $payload['column_mapping'] : array());
        $warnings = array();

        $normalized_rows = array();
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $warnings[] = 'Skipped non-object row at index ' . $index;
                continue;
            }

            $normalized = $this->normalizeSingleRow($row, $descriptor, $execution_profile, $runtime_overrides);
            if (!$normalized) {
                $warnings[] = 'Skipped empty row at index ' . $index;
                continue;
            }

            $normalized_rows[] = $normalized;
        }

        return array(
            'mapping' => $mapping,
            'cases' => $normalized_rows,
            'warnings' => $warnings
        );
    }

    /**
     * Normalize one parsed row into a dataset case payload ready for addCase().
     *
     * @param array  $row                Raw parsed case row.
     * @param array  $descriptor         Owner descriptor.
     * @param string $execution_profile  Execution profile code.
     * @param array  $runtime_overrides  Runtime overrides.
     * @return array|null Normalized case payload, or null if row has no material.
     */
    public function normalizeSingleRow($row, $descriptor, $execution_profile, $runtime_overrides = array())
    {
        $row = is_array($row) ? $row : array();

        $title = trim((string)($row['title'] ?? $row['name'] ?? ''));
        if ($title === '') {
            $title = 'Imported Case';
        }

        $notes = trim((string)($row['notes'] ?? ''));
        $tags = $this->normalizeTags($row['tags'] ?? array());
        $variables = $this->normalizeVariables($row);
        $variables = $this->mapVariablesForExecutionProfile($variables, $descriptor, (string)$execution_profile);
        $message_history = $this->normalizeMessages($row['message_history'] ?? array());
        $trigger_message = trim((string)($row['trigger_message'] ?? ''));
        if ($trigger_message === '' && !empty($variables)) {
            $trigger_message = trim((string)($variables['student_answer'] ?? $variables['answer'] ?? $variables['input'] ?? ''));
            if ($trigger_message === '') {
                $trigger_message = $this->firstNonEmptyScalarValue($variables);
            }
        }

        $expected_output = $this->normalizeExpectedOutput($row);
        $input_payload = array(
            'execution_profile' => (string)$execution_profile,
            'owner_descriptor' => $this->dataset_service->buildOwnerDescriptor($descriptor),
            'variables' => $variables,
            'message_history' => $message_history,
            'trigger_message' => $trigger_message,
            'runtime_overrides' => is_array($runtime_overrides) ? $runtime_overrides : array()
        );

        $has_material = !empty($variables) || !empty($message_history) || $trigger_message !== '' || !empty($expected_output) || $notes !== '';
        if (!$has_material) {
            return null;
        }

        return array(
            'title' => $title,
            'case_type' => $this->dataset_service->toCaseType((string)$execution_profile),
            'source_type' => 'ai_text_import',
            'input_payload' => $input_payload,
            'expected_output' => $expected_output,
            'expected_labels' => null,
            'source_ref' => array('import_mode' => 'ai_text_import'),
            'tags' => $tags,
            'notes' => $notes
        );
    }

    /**
     * Extract template variables from a parsed row, checking nested input_payload, variables, inputs, then individual keys.
     *
     * @param array $row Parsed case row.
     * @return array Key-value pairs of template variables.
     */
    private function normalizeVariables($row)
    {
        if (is_array($row['input_payload']['variables'] ?? null)) {
            return $row['input_payload']['variables'];
        }
        if (is_array($row['variables'] ?? null)) {
            return $row['variables'];
        }
        if (is_array($row['inputs'] ?? null)) {
            return $row['inputs'];
        }
        if (is_array($row['input'] ?? null)) {
            return $row['input'];
        }

        $variables = array();
        $variable_keys = array(
            'reflection_question',
            'question',
            'student_answer',
            'answer',
            'additional_info',
            'hint',
            'context',
            'input'
        );

        foreach ($variable_keys as $key) {
            if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
                $variables[$key] = trim((string)$row[$key]);
            }
        }

        return $variables;
    }

    /**
     * Extract expected output from a parsed row, checking structured and text-based keys.
     *
     * @param array $row Parsed case row.
     * @return array{assistant_text: string}|null Structured expected output, or null.
     */
    private function normalizeExpectedOutput($row)
    {
        if (is_array($row['expected_output'] ?? null)) {
            return $row['expected_output'];
        }

        $text = '';
        $candidate_keys = array('feedback', 'reference_feedback', 'expected_output_text', 'expected_output', 'output');
        foreach ($candidate_keys as $key) {
            if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
                $text = trim((string)$row[$key]);
                break;
            }
        }

        if ($text === '') {
            return null;
        }

        return array('assistant_text' => $text);
    }

    /**
     * Deduplicate and trim a list of tag strings.
     *
     * @param array $tags Raw tag values.
     * @return string[] Unique, non-empty trimmed tags.
     */
    private function normalizeTags($tags)
    {
        if (!is_array($tags)) {
            return array();
        }

        $normalized = array();
        foreach ($tags as $tag) {
            $value = trim((string)$tag);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Normalize a message history array via the dataset service's normalizer.
     *
     * @param array $messages Raw message objects.
     * @return array Normalized message array.
     */
    private function normalizeMessages($messages)
    {
        if (!is_array($messages)) {
            return array();
        }

        return $this->dataset_service->normalizeMessages($messages);
    }

    /**
     * Re-map imported variables to match the prompt template's placeholders for form_runtime profiles.
     *
     * Uses alias matching and fuzzy scoring to find the best variable-to-placeholder mapping.
     *
     * @param array  $variables         Raw variable key-value pairs.
     * @param array  $descriptor        Owner descriptor for template resolution.
     * @param string $execution_profile Execution profile code.
     * @return array Mapped variables aligned to template placeholders.
     */
    private function mapVariablesForExecutionProfile($variables, $descriptor, $execution_profile)
    {
        $variables = is_array($variables) ? $variables : array();
        if ($execution_profile !== 'form_runtime' || empty($variables)) {
            return $variables;
        }

        $template = $this->dataset_service->resolvePromptTemplate($descriptor);
        $placeholders = $this->dataset_service->extractPromptPlaceholders($template);
        if (empty($placeholders)) {
            return $variables;
        }

        $mapped = array();
        foreach ($placeholders as $placeholder) {
            if (isset($variables[$placeholder]) && trim((string)$variables[$placeholder]) !== '') {
                $mapped[$placeholder] = trim((string)$variables[$placeholder]);
                continue;
            }

            $candidate = $this->resolveBestVariableMatch($placeholder, $variables);
            if ($candidate !== null && trim((string)$candidate) !== '') {
                $mapped[$placeholder] = trim((string)$candidate);
            }
        }

        // Prefer context-placeholder-shaped payloads for form runtime.
        // If nothing could be mapped, fall back to original variables.
        return !empty($mapped) ? $mapped : $variables;
    }

    /**
     * Find the best matching variable value for a placeholder using aliases then fuzzy scoring.
     *
     * @param string $placeholder Template placeholder name.
     * @param array  $variables   Available variable key-value pairs.
     * @return string|null Best matching value, or null.
     */
    private function resolveBestVariableMatch($placeholder, $variables)
    {
        $placeholder = strtolower(trim((string)$placeholder));
        if ($placeholder === '') {
            return null;
        }

        $alias_candidates = array(
            'student_support' => array('student_support', 'student_answer', 'answer', 'input'),
            'student_answer' => array('student_answer', 'student_support', 'answer', 'input'),
            'answer' => array('answer', 'student_answer', 'student_support', 'input'),
            'input' => array('input', 'student_answer', 'answer', 'student_support'),
            'reflection_question' => array('reflection_question', 'question', 'prompt', 'task'),
            'question' => array('question', 'reflection_question', 'prompt', 'task'),
            'additional_info' => array('additional_info', 'hint', 'context', 'notes'),
            'hint' => array('hint', 'additional_info', 'context', 'notes'),
        );

        if (!empty($alias_candidates[$placeholder])) {
            foreach ($alias_candidates[$placeholder] as $candidate_key) {
                if (isset($variables[$candidate_key]) && trim((string)$variables[$candidate_key]) !== '') {
                    return $variables[$candidate_key];
                }
            }
        }

        $best_key = null;
        $best_score = 0;
        foreach ($variables as $key => $value) {
            $normalized_key = strtolower((string)$key);
            if (trim((string)$value) === '') {
                continue;
            }

            $score = $this->scoreKeySimilarity($placeholder, $normalized_key);
            if ($score > $best_score) {
                $best_score = $score;
                $best_key = $key;
            }
        }

        if ($best_key !== null && $best_score > 0) {
            return $variables[$best_key];
        }

        return null;
    }

    /**
     * Score similarity between a placeholder and a candidate key using token overlap and substring matching.
     *
     * @param string $placeholder   Normalized placeholder name.
     * @param string $candidate_key Normalized candidate variable key.
     * @return int Score: 100 for exact match, token overlap count, 1 for substring match, 0 for no match.
     */
    private function scoreKeySimilarity($placeholder, $candidate_key)
    {
        if ($placeholder === $candidate_key) {
            return 100;
        }

        $placeholder_tokens = array_values(array_filter(explode('_', preg_replace('/[^a-z0-9]+/', '_', $placeholder))));
        $candidate_tokens = array_values(array_filter(explode('_', preg_replace('/[^a-z0-9]+/', '_', $candidate_key))));
        if (empty($placeholder_tokens) || empty($candidate_tokens)) {
            return 0;
        }

        $overlap = count(array_intersect($placeholder_tokens, $candidate_tokens));
        if ($overlap === 0) {
            if (strpos($candidate_key, $placeholder) !== false || strpos($placeholder, $candidate_key) !== false) {
                return 1;
            }
            return 0;
        }

        return $overlap;
    }

    /**
     * Return the first non-empty scalar (or JSON-encoded) value from an associative array.
     *
     * @param array $values Key-value pairs.
     * @return string First non-empty value as string, or empty string.
     */
    private function firstNonEmptyScalarValue($values)
    {
        foreach ((array)$values as $value) {
            if (is_array($value)) {
                $scalar = trim((string)json_encode($value));
            } else {
                $scalar = trim((string)$value);
            }
            if ($scalar !== '') {
                return $scalar;
            }
        }

        return '';
    }
}
?>
