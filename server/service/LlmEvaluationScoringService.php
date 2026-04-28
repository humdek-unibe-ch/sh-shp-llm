<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmService.php';
require_once __DIR__ . '/prompt/LlmPromptAssetLoader.php';

/**
 * LLM Evaluation Scoring Service
 *
 * Applies evaluation definitions to individual test case outputs.
 * Supports three scoring strategies:
 * - Programmatic: String matching, JSON diff, regex checks
 * - LLM Judge: Sends the output to a second LLM for quality scoring
 * - Human Review: Marks cases as pending for manual review
 *
 * Scores are persisted in the `llm_eval_scores` table and linked
 * to specific run cases.
 *
 * @package LLM Plugin
 * @see LlmEvaluationRunnerService For orchestration context
 */
class LlmEvaluationScoringService extends BaseLlmService
{
    /** @var LlmService Core LLM service for LLM-judge scoring calls */
    private $llm_service;

    /** @var LlmPromptAssetLoader Loads judge prompt templates */
    private $prompt_assets;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->llm_service = new LlmService($services);
        $this->prompt_assets = new LlmPromptAssetLoader();
    }

    /**
     * Score a single evaluation case using the definition's eval type (programmatic or LLM judge).
     *
     * @param array $definition   Evaluation definition row with eval_type_code, name, config_json.
     * @param array $run_output   LLM run output with display_content, parsed_response, raw_content.
     * @param array $dataset_case Dataset case row with expected_labels_json, input_payload_json.
     * @return array{score_type: string, score_value_numeric: float|null, score_value_label: string, passed: int|null, details: array}
     */
    public function scoreCase($definition, $run_output, $dataset_case)
    {
        $eval_type = (string)($definition['eval_type_code'] ?? '');
        $config = $this->decodeJsonValue($definition['config_json'] ?? '{}', array());

        if ($eval_type === LLM_EVAL_TYPE_HUMAN_REVIEW) {
            return array('score_type' => 'human_review', 'score_value_numeric' => null, 'score_value_label' => 'pending_review', 'passed' => null, 'details' => array('state' => 'pending'));
        }
        if ($eval_type === LLM_EVAL_TYPE_LLM_JUDGE) {
            return $this->scoreWithJudge($definition, $run_output, $dataset_case, $config);
        }

        $name = strtolower((string)($definition['name'] ?? ''));
        $parsed = is_array($run_output['parsed_response'] ?? null) ? $run_output['parsed_response'] : null;
        $display = trim((string)($run_output['display_content'] ?? ''));
        $raw = trim((string)($run_output['raw_content'] ?? ''));
        $expected_labels = $this->decodeJsonValue($dataset_case['expected_labels_json'] ?? '{}', array());

        if ($name === 'json_validity') {
            return $this->scoreResult('programmatic', $parsed !== null, $parsed !== null ? 'valid_json' : 'invalid_json', $parsed !== null ? 1.0 : 0.0, array('has_parsed_response' => $parsed !== null));
        }
        if ($name === 'required_fields_present') {
            $required = is_array($config['required_fields'] ?? null) ? $config['required_fields'] : array();
            $missing = array();
            foreach ($required as $field) {
                if (!is_string($field) || $field === '') {
                    continue;
                }
                if ($parsed === null || !array_key_exists($field, $parsed)) {
                    $missing[] = $field;
                }
            }
            return $this->scoreResult('programmatic', empty($missing), empty($missing) ? 'all_required_present' : 'missing_required_fields', empty($missing) ? 1.0 : 0.0, array('required_fields' => $required, 'missing_fields' => $missing));
        }
        if ($name === 'no_empty_output') {
            $passed = ($display !== '' || $raw !== '');
            return $this->scoreResult('programmatic', $passed, $passed ? 'non_empty' : 'empty_output', $passed ? 1.0 : 0.0, array('display_length' => strlen($display), 'raw_length' => strlen($raw)));
        }
        if ($name === 'safety_label_match') {
            $expected = $this->readPathValue($expected_labels, 'safety.danger_level');
            $has_expected = $this->pathExists($expected_labels, 'safety.danger_level');
            $actual = $this->readPathValue(is_array($run_output['safety'] ?? null) ? array('safety' => $run_output['safety']) : array(), 'safety.danger_level');
            $passed = ($has_expected && $expected === $actual);
            return $this->scoreResult('programmatic', $passed, $passed ? 'safety_label_match' : 'safety_label_mismatch', $passed ? 1.0 : 0.0, array('expected' => $expected, 'actual' => $actual, 'has_expected' => $has_expected));
        }

        return $this->scoreResult('programmatic', true, 'skipped', 1.0, array('reason' => 'No evaluator implementation matched'));
    }

    /**
     * Score a case using an LLM judge: sends case context to a judge model and parses the structured verdict.
     *
     * @param array $definition   Evaluation definition.
     * @param array $run_output   LLM run output.
     * @param array $dataset_case Dataset case data.
     * @param array $config       Judge configuration with scale_min, scale_max, pass_threshold, judge_model.
     * @return array Score result with judge reasoning and model metadata.
     */
    private function scoreWithJudge($definition, $run_output, $dataset_case, $config)
    {
        $scale_min = isset($config['scale_min']) ? (int)$config['scale_min'] : 1;
        $scale_max = isset($config['scale_max']) ? (int)$config['scale_max'] : 5;
        if ($scale_max < $scale_min) {
            $scale_max = $scale_min;
        }
        $pass_threshold = isset($config['pass_threshold']) ? (float)$config['pass_threshold'] : (float)(($scale_min + $scale_max) / 2);
        $llm_config = $this->getLlmConfig();
        $judge_model = trim((string)($config['judge_model'] ?? ($run_output['model'] ?? '')));
        if ($judge_model === '') {
            $judge_model = (string)($llm_config['llm_default_model'] ?? '');
        }

        $input_payload = $this->decodeJsonValue($dataset_case['input_payload_json'] ?? '{}', array());
        $expected_labels = $this->decodeJsonValue($dataset_case['expected_labels_json'] ?? '{}', array());
        $judge_prompt = $this->jsonEncode(array(
            'task' => (string)($definition['name'] ?? 'llm_judge'),
            'criteria' => trim((string)($definition['description'] ?? 'Evaluate quality against instructions and expectations.')),
            'scale_min' => $scale_min,
            'scale_max' => $scale_max,
            'pass_threshold' => $pass_threshold,
            'dataset_case_title' => (string)($dataset_case['title'] ?? ''),
            'expected_labels' => $expected_labels,
            'case_input' => $this->buildLeanJudgeInput($input_payload),
            'model_output' => array(
                'display_content' => $this->truncateForJudge(trim((string)($run_output['display_content'] ?? '')), 6000)
            )
        ));
        if (!$judge_prompt) {
            return array('score_type' => 'llm_judge', 'score_value_numeric' => null, 'score_value_label' => 'judge_input_error', 'passed' => null, 'details' => array('reason' => 'Failed to prepare judge input payload'));
        }

        // Inherit temperature and max_tokens from the evaluator config, then the global LLM
        // configuration, then built-in defaults. Passing null to the LLM service makes it
        // resolve the global `llm_max_tokens` / `llm_temperature` from `getLlmConfig()`.
        $judge_temperature = array_key_exists('judge_temperature', $config)
            ? (float)$config['judge_temperature']
            : (isset($llm_config['llm_temperature']) ? (float)$llm_config['llm_temperature'] : null);
        $judge_max_tokens = array_key_exists('judge_max_tokens', $config)
            ? (int)$config['judge_max_tokens']
            : (isset($llm_config['llm_max_tokens']) ? (int)$llm_config['llm_max_tokens'] : null);
        try {
            $conversation_id = $this->llm_service->getOrCreateConversationForModel((int)($this->getCurrentUserId() ?: 0), $judge_model, $judge_temperature, $judge_max_tokens, null);
            $messages = array(
                array('role' => 'system', 'content' => $this->prompt_assets->load('core.evaluation.judge.system')),
                array('role' => 'user', 'content' => $judge_prompt)
            );
            $response = $this->llm_service->callLlmApi($messages, $judge_model, $judge_temperature, $judge_max_tokens, array('conversation_id' => $conversation_id, 'sent_context' => $messages, 'is_validated' => true));
            $judge_json = $this->extractJsonObject(trim((string)($response['content'] ?? '')));
            if (!is_array($judge_json)) {
                return array('score_type' => 'llm_judge', 'score_value_numeric' => null, 'score_value_label' => 'judge_parse_error', 'passed' => null, 'details' => array('judge_model' => $judge_model, 'raw_response' => (string)($response['content'] ?? '')));
            }

            $score = isset($judge_json['score']) ? (float)$judge_json['score'] : null;
            if ($score !== null) {
                $score = max((float)$scale_min, min((float)$scale_max, $score));
            }
            $passed = array_key_exists('passed', $judge_json) ? ($judge_json['passed'] ? 1 : 0) : (($score !== null && $score >= $pass_threshold) ? 1 : 0);
            $label = trim((string)($judge_json['label'] ?? ''));
            if ($label === '') {
                $label = $passed ? 'pass' : 'fail';
            }

            return array(
                'score_type' => 'llm_judge',
                'score_value_numeric' => $score,
                'score_value_label' => $label,
                'passed' => $passed,
                'details' => array('judge_model' => $judge_model, 'reason' => trim((string)($judge_json['reason'] ?? '')), 'scale_min' => $scale_min, 'scale_max' => $scale_max, 'pass_threshold' => $pass_threshold)
            );
        } catch (Exception $e) {
            return array('score_type' => 'llm_judge', 'score_value_numeric' => null, 'score_value_label' => 'judge_error', 'passed' => null, 'details' => array('judge_model' => $judge_model, 'error' => $e->getMessage()));
        }
    }

    /** Build a standardized score result array. */
    private function scoreResult($score_type, $passed, $label, $numeric, $details)
    {
        return array('score_type' => $score_type, 'score_value_numeric' => $numeric, 'score_value_label' => $label, 'passed' => $passed ? 1 : 0, 'details' => $details);
    }

    /** Decode a JSON string, returning the fallback on failure. */
    private function decodeJsonValue($value, $fallback)
    {
        if (!is_string($value) || trim($value) === '') {
            return $fallback;
        }
        $decoded = $this->jsonDecode($value);
        return $decoded !== null ? $decoded : $fallback;
    }

    /**
     * Read a dot-separated path from a nested array (e.g., 'safety.danger_level').
     *
     * @param array  $data Nested array.
     * @param string $path Dot-separated key path.
     * @return mixed Value at path, or null.
     */
    private function readPathValue($data, $path)
    {
        if (!is_array($data)) {
            return null;
        }
        if (array_key_exists($path, $data)) {
            return $data[$path];
        }
        $current = $data;
        foreach (explode('.', (string)$path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    /** Check if a dot-separated path exists in a nested array. */
    private function pathExists($data, $path)
    {
        if (!is_array($data)) {
            return false;
        }
        if (array_key_exists($path, $data)) {
            return true;
        }
        $current = $data;
        foreach (explode('.', (string)$path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }
            $current = $current[$segment];
        }
        return true;
    }

    /**
     * Extract a JSON object from text, trying raw decode, code fences, and balanced braces.
     * Also tolerates common LLM mistakes such as unescaped control characters (raw \n, \t, \r)
     * inside string values by sanitizing before a second decode attempt.
     */
    private function extractJsonObject($text)
    {
        if ($text === '') {
            return null;
        }
        $candidates = array($text);
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/i', $text, $match)) {
            $candidates[] = $match[1];
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidates[] = substr($text, $start, $end - $start + 1);
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            $sanitized = $this->sanitizeJsonControlChars($candidate);
            if ($sanitized !== $candidate) {
                $decoded = json_decode($sanitized, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        $this->logError('JSON decode failed: ' . json_last_error_msg());
        return null;
    }

    /**
     * Escape bare control characters (raw newlines, tabs, carriage returns, etc.) that appear
     * *inside* quoted JSON string values, which many LLMs emit despite being invalid per RFC.
     * Characters outside strings are left untouched.
     *
     * @param string $text Raw JSON-ish text.
     * @return string Text with string-interior control chars properly escaped.
     */
    private function sanitizeJsonControlChars($text)
    {
        $len = strlen($text);
        $out = '';
        $in_string = false;
        $escape = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if (!$in_string) {
                $out .= $ch;
                if ($ch === '"') {
                    $in_string = true;
                }
                continue;
            }
            if ($escape) {
                $out .= $ch;
                $escape = false;
                continue;
            }
            if ($ch === '\\') {
                $out .= $ch;
                $escape = true;
                continue;
            }
            if ($ch === '"') {
                $out .= $ch;
                $in_string = false;
                continue;
            }
            $code = ord($ch);
            if ($code < 0x20) {
                switch ($ch) {
                    case "\n": $out .= '\\n'; break;
                    case "\r": $out .= '\\r'; break;
                    case "\t": $out .= '\\t'; break;
                    case "\f": $out .= '\\f'; break;
                    case "\b": $out .= '\\b'; break;
                    default: $out .= sprintf('\\u%04x', $code);
                }
                continue;
            }
            $out .= $ch;
        }
        return $out;
    }

    /**
     * Build a lean input summary for the judge, stripping heavy/noisy fields that do not help
     * the judge assess quality: message_history, source_context, runtime_overrides, owner_descriptor,
     * duplicated memory_context sections, and internal form metadata keys (_meta_*, _json, response_id, etc.).
     *
     * @param array $input_payload Full case input payload as stored on the dataset case.
     * @return array Compact structure sent to the judge.
     */
    private function buildLeanJudgeInput($input_payload)
    {
        if (!is_array($input_payload)) {
            return array();
        }
        $lean = array();
        if (!empty($input_payload['execution_profile'])) {
            $lean['execution_profile'] = (string)$input_payload['execution_profile'];
        }
        $profile = (string)($input_payload['execution_profile'] ?? '');
        if ($profile === 'memory_runtime' && is_array($input_payload['memory_context'] ?? null)) {
            $ctx = $input_payload['memory_context'];
            $memory_key = (string)($ctx['memory_key'] ?? '');
            if ($memory_key !== '') {
                $lean['memory_key'] = $memory_key;
            }
            $current_memory = $this->extractSectionFromText((string)($ctx['prefix_before_instructions'] ?? ''), 'Current Memory');
            if ($current_memory !== '') {
                $lean['current_memory'] = $this->truncateForJudge($current_memory, 1500);
            }
            $submitted = $this->extractSectionFromText((string)($ctx['prefix_before_instructions'] ?? ''), 'Submitted Data');
            if ($submitted !== '') {
                $lean['submitted_data'] = $this->truncateForJudge($submitted, 2000);
            }
            $instructions = (string)($ctx['original_instructions'] ?? '');
            if ($instructions !== '') {
                $lean['instructions'] = $this->truncateForJudge($instructions, 1500);
            }
            // Tell the judge that the memory runtime enforces a fixed JSON envelope. Otherwise
            // admin instructions that say "return a paragraph, no JSON" will make the judge
            // penalise outputs for format compliance — when in reality the envelope is
            // non-negotiable and the instructions only guide the *content* of memory_text.
            $lean['output_format_contract'] = 'The memory runtime always returns JSON with exactly these keys: '
                . 'memory_text (human-readable narrative), memory_object (structured data), '
                . 'flat_fields (flat snake_case key/values), change_summary (one-sentence change note). '
                . 'This JSON envelope is MANDATORY and non-negotiable — admin instructions cannot remove it. '
                . 'Judge the output against the instructions by inspecting the CONTENT of memory_text '
                . '(and change_summary / memory_object as relevant). Do NOT penalise the response for '
                . 'being JSON, for including structured fields, or for "not being a paragraph" — the '
                . 'envelope is fixed by the system, not by the admin prompt.';
            return $lean;
        }
        if (!empty($input_payload['trigger_message'])) {
            $lean['trigger_message'] = $this->truncateForJudge((string)$input_payload['trigger_message'], 4000);
        }
        if (is_array($input_payload['variables'] ?? null)) {
            $pruned = $this->pruneVariablesForJudge($input_payload['variables']);
            if (!empty($pruned)) {
                $lean['variables'] = $pruned;
            }
        }
        return $lean;
    }

    /** Internal form-metadata keys that carry no semantic meaning for judging. */
    private static $JUDGE_NOISE_KEYS = array(
        '_json', '_meta_user_agent', '_meta_screen_width', '_meta_screen_height',
        '_meta_pixel_ratio', '_meta_viewport_width', '_meta_viewport_height',
        '_meta_start_time', '_meta_pages', '_meta_duration', '_meta_end_time',
        'response_id', 'survey_generated_id', 'pageNo', 'trigger_type',
        'ajax', 'is_log', 'redirect_at_end', 'record_id',
        'event_payload_json', 'memory_json',
    );

    /** Strip noisy/internal keys and truncate oversized string values. */
    private function pruneVariablesForJudge(array $vars)
    {
        $result = array();
        foreach ($vars as $key => $value) {
            if (in_array($key, self::$JUDGE_NOISE_KEYS, true)) {
                continue;
            }
            if (is_string($value)) {
                $value = $this->truncateForJudge($value, 800);
            } elseif (is_array($value)) {
                $encoded = json_encode($value);
                if (is_string($encoded) && strlen($encoded) > 800) {
                    $value = substr($encoded, 0, 800) . '...[truncated]';
                }
            }
            $result[$key] = $value;
        }
        return $result;
    }

    /** Truncate a string at a max byte length, marking the cut. */
    private function truncateForJudge($text, $max_len)
    {
        if (!is_string($text)) {
            return $text;
        }
        if (strlen($text) <= $max_len) {
            return $text;
        }
        return substr($text, 0, $max_len) . "\n...[truncated]";
    }

    /**
     * Extract the body of a markdown H2 section by heading name (e.g. "Current Memory").
     *
     * @param string $text    Source text containing one or more "## Heading" sections.
     * @param string $heading Heading text to match (without the "## ").
     * @return string Section body (trimmed), or '' if the section is not present.
     */
    private function extractSectionFromText($text, $heading)
    {
        if ($text === '' || $heading === '') {
            return '';
        }
        $pattern = '/^##\s+' . preg_quote($heading, '/') . '\s*\n(.*?)(?=\n##\s|\z)/ms';
        if (preg_match($pattern, $text, $match)) {
            return trim($match[1]);
        }
        return '';
    }
}
?>
