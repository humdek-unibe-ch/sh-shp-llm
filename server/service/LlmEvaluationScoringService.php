<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmService.php';
require_once __DIR__ . '/prompt/LlmPromptAssetLoader.php';

class LlmEvaluationScoringService extends BaseLlmService
{
    private $llm_service;
    private $prompt_assets;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->llm_service = new LlmService($services);
        $this->prompt_assets = new LlmPromptAssetLoader();
    }

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

    private function scoreWithJudge($definition, $run_output, $dataset_case, $config)
    {
        $scale_min = isset($config['scale_min']) ? (int)$config['scale_min'] : 1;
        $scale_max = isset($config['scale_max']) ? (int)$config['scale_max'] : 5;
        if ($scale_max < $scale_min) {
            $scale_max = $scale_min;
        }
        $pass_threshold = isset($config['pass_threshold']) ? (float)$config['pass_threshold'] : (float)(($scale_min + $scale_max) / 2);
        $judge_model = trim((string)($config['judge_model'] ?? ($run_output['model'] ?? '')));
        if ($judge_model === '') {
            $llm_config = $this->getLlmConfig();
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
            'input_payload' => $input_payload,
            'model_output' => array('display_content' => trim((string)($run_output['display_content'] ?? '')), 'parsed_response' => $run_output['parsed_response'] ?? null)
        ));
        if (!$judge_prompt) {
            return array('score_type' => 'llm_judge', 'score_value_numeric' => null, 'score_value_label' => 'judge_input_error', 'passed' => null, 'details' => array('reason' => 'Failed to prepare judge input payload'));
        }

        try {
            $conversation_id = $this->llm_service->getOrCreateConversationForModel((int)($this->getCurrentUserId() ?: 0), $judge_model, 0.0, 400, null);
            $messages = array(
                array('role' => 'system', 'content' => $this->prompt_assets->load('core.evaluation.judge.system')),
                array('role' => 'user', 'content' => $judge_prompt)
            );
            $response = $this->llm_service->callLlmApi($messages, $judge_model, 0.0, 400, array('conversation_id' => $conversation_id, 'sent_context' => $messages, 'is_validated' => true));
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

    private function scoreResult($score_type, $passed, $label, $numeric, $details)
    {
        return array('score_type' => $score_type, 'score_value_numeric' => $numeric, 'score_value_label' => $label, 'passed' => $passed ? 1 : 0, 'details' => $details);
    }

    private function decodeJsonValue($value, $fallback)
    {
        if (!is_string($value) || trim($value) === '') {
            return $fallback;
        }
        $decoded = $this->jsonDecode($value);
        return $decoded !== null ? $decoded : $fallback;
    }

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

    private function extractJsonObject($text)
    {
        if ($text === '') {
            return null;
        }
        $decoded = $this->jsonDecode($text);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/i', $text, $match)) {
            $decoded = $this->jsonDecode($match[1]);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = $this->jsonDecode(substr($text, $start, $end - $start + 1));
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }
}
?>
