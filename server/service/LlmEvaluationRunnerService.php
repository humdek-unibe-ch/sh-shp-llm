<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmDatasetService.php';
require_once __DIR__ . '/LlmDatasetReplayService.php';
require_once __DIR__ . '/LlmEvaluationDefinitionService.php';
require_once __DIR__ . '/LlmEvaluationScoringService.php';
require_once __DIR__ . '/LlmEvaluationAggregationService.php';
require_once __DIR__ . '/LlmPromptRegistryService.php';

class LlmEvaluationRunnerService extends BaseLlmService
{
    private $dataset_service;
    private $replay_service;
    private $definition_service;
    private $scoring_service;
    private $aggregation_service;
    private $registry_service;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->dataset_service = new LlmDatasetService($services);
        $this->replay_service = new LlmDatasetReplayService($services);
        $this->definition_service = new LlmEvaluationDefinitionService($services);
        $this->scoring_service = new LlmEvaluationScoringService($services);
        $this->aggregation_service = new LlmEvaluationAggregationService($services);
        $this->registry_service = new LlmPromptRegistryService($services);
    }

    public function runDatasetEval($payload)
    {
        $dataset_id = (int)($payload['dataset_id'] ?? 0);
        if ($dataset_id <= 0) {
            throw new Exception('dataset_id is required');
        }

        $dataset = $this->dataset_service->getDataset($dataset_id);
        if (!$dataset) {
            throw new Exception('Dataset not found');
        }
        $cases = $this->dataset_service->listDatasetCases($dataset_id);
        if (empty($cases)) {
            throw new Exception('Dataset has no cases');
        }

        $descriptor = is_array($payload['descriptor'] ?? null) ? $payload['descriptor'] : array();
        $selected_models = is_array($payload['selected_models'] ?? null) ? $payload['selected_models'] : array();
        $definitions = $this->definition_service->loadDefinitionsByIds($payload['eval_definition_ids'] ?? array());
        if (empty($definitions)) {
            $definitions = $this->definition_service->loadDefaultDefinitions();
        }

        $target = $this->resolveTargetPrompt($payload, $descriptor);
        $run_mode = count(array_values(array_filter(array_unique($selected_models)))) > 1 ? 'dataset_eval_compare' : 'dataset_eval_single';
        $run_id = $this->db->insert('llm_eval_runs', array(
            'id_llm_eval_datasets' => $dataset_id,
            'target_type' => (string)$target['target_type'],
            'target_ref_json' => $this->jsonEncode($target['target_ref']),
            'id_lookups_run_mode' => $this->lookupId('llm_eval_run_modes', $run_mode),
            'id_lookups_status' => $this->lookupId('llm_eval_run_statuses', 'running'),
            'summary_json' => null,
            'id_users_created' => $this->getCurrentUserId()
        ));
        $this->addPluginTransaction('insert', 'llm_eval_runs', $run_id, 'LLM dataset evaluation started');

        try {
            $records = array();

            foreach ($cases as $case) {
                $replay = $this->replay_service->replayCase($case, $target, array(
                    'fallback_descriptor' => $descriptor,
                    'runtime_overrides' => is_array($payload['runtime_overrides'] ?? null) ? $payload['runtime_overrides'] : array(),
                    'selected_models' => $selected_models
                ));

                foreach ((array)($replay['runs'] ?? array()) as $run_output) {
                    $run_case_id = $this->db->insert('llm_eval_run_cases', array(
                        'id_llm_eval_runs' => $run_id,
                        'id_llm_eval_dataset_cases' => (int)$case['id'],
                        'id_llmConversations' => $run_output['id_llmConversations'] ?? null,
                        'id_llmMessages_request' => $run_output['id_llmMessages_request'] ?? null,
                        'id_llmMessages_response' => $run_output['id_llmMessages_response'] ?? null,
                        'output_payload_json' => $this->jsonEncode($run_output),
                        'normalized_output_json' => $this->jsonEncode($run_output)
                    ));

                    $scores = array();
                    foreach ($definitions as $definition) {
                        $score = $this->scoring_service->scoreCase($definition, $run_output, $case);
                        $score_id = $this->db->insert('llm_eval_scores', array(
                            'id_llm_eval_run_cases' => $run_case_id,
                            'id_llm_eval_definitions' => (int)$definition['id'],
                            'score_type' => (string)$score['score_type'],
                            'score_value_numeric' => $score['score_value_numeric'],
                            'score_value_label' => $score['score_value_label'],
                            'passed' => $score['passed'],
                            'details_json' => $this->jsonEncode($score['details'] ?? array()),
                            'id_users_created' => $this->getCurrentUserId()
                        ));
                        $scores[] = array_merge($score, array('id' => $score_id, 'id_llm_eval_definitions' => (int)$definition['id'], 'eval_name' => (string)$definition['name']));
                    }

                    $records[] = array(
                        'run_case_id' => $run_case_id,
                        'dataset_case_id' => (int)$case['id'],
                        'title' => (string)($case['title'] ?? ''),
                        'model' => (string)($run_output['model'] ?? ''),
                        'display_content' => (string)($run_output['display_content'] ?? ''),
                        'status' => $this->deriveCaseStatus($scores),
                        'scores' => $scores
                    );
                }
            }

            $summary = $this->aggregation_service->buildSummary(count($cases), $target['target_ref'], $records);
            $this->db->update_by_ids('llm_eval_runs', array(
                'id_lookups_status' => $this->lookupId('llm_eval_run_statuses', 'completed'),
                'summary_json' => $this->jsonEncode($summary),
                'completed_at' => date('Y-m-d H:i:s')
            ), array('id' => $run_id));
            $this->addPluginTransaction('update', 'llm_eval_runs', $run_id, 'LLM dataset evaluation completed');

            return array('run' => $this->getEvalRun($run_id), 'cases' => $records);
        } catch (Exception $e) {
            $this->db->update_by_ids('llm_eval_runs', array(
                'id_lookups_status' => $this->lookupId('llm_eval_run_statuses', 'failed'),
                'summary_json' => $this->jsonEncode(array('error' => $e->getMessage(), 'failed_at' => date('c'))),
                'completed_at' => date('Y-m-d H:i:s')
            ), array('id' => $run_id));
            $this->addPluginTransaction('update', 'llm_eval_runs', $run_id, 'LLM dataset evaluation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getEvalRun($run_id)
    {
        $row = $this->db->query_db_first(
            "SELECT r.*, s.lookup_code AS status_code, rm.lookup_code AS run_mode_code, d.name AS dataset_name
             FROM llm_eval_runs r
             LEFT JOIN lookups s ON s.id = r.id_lookups_status
             LEFT JOIN lookups rm ON rm.id = r.id_lookups_run_mode
             LEFT JOIN llm_eval_datasets d ON d.id = r.id_llm_eval_datasets
             WHERE r.id = :id LIMIT 1",
            array(':id' => (int)$run_id)
        );
        if (!$row) {
            return null;
        }

        $row['summary'] = $this->decodeJsonValue($row['summary_json'] ?? '{}', array());
        $row['target_ref'] = $this->decodeJsonValue($row['target_ref_json'] ?? '{}', array());
        return $row;
    }

    public function listEvalRunCases($run_id)
    {
        $cases = $this->db->query_db(
            "SELECT rc.*, dc.title AS dataset_case_title, dc.input_payload_json
             FROM llm_eval_run_cases rc
             LEFT JOIN llm_eval_dataset_cases dc ON dc.id = rc.id_llm_eval_dataset_cases
             WHERE rc.id_llm_eval_runs = :run_id
             ORDER BY rc.id ASC",
            array(':run_id' => (int)$run_id)
        );
        if (empty($cases)) {
            return array();
        }

        $scores_by_case = $this->loadRunCaseScores(array_map(function ($row) { return (int)$row['id']; }, $cases));
        foreach ($cases as &$case_row) {
            $case_row['scores'] = $scores_by_case[(int)$case_row['id']] ?? array();
            $case_row['normalized_output'] = $this->decodeJsonValue($case_row['normalized_output_json'] ?? '{}', array());
            $case_row['input_payload'] = $this->decodeJsonValue($case_row['input_payload_json'] ?? '{}', array());
            $case_row['model'] = (string)($case_row['normalized_output']['model'] ?? '');
            $case_row['display_content'] = (string)($case_row['normalized_output']['display_content'] ?? '');
            $case_row['input_preview'] = $this->buildInputPreview($case_row['input_payload']);
            $case_row['input_fields'] = $this->buildInputFields($case_row['input_payload']);
            $case_row['status'] = $this->deriveCaseStatus($case_row['scores']);
            $case_row['passed'] = $case_row['status'] === 'passed';
        }
        unset($case_row);

        return $cases;
    }

    public function refreshRunSummary($run_id)
    {
        $run = $this->getEvalRun($run_id);
        if (!$run) {
            throw new Exception('Evaluation run not found');
        }

        $cases = $this->listEvalRunCases($run_id);
        $records = array();
        foreach ($cases as $case) {
            $records[] = array('model' => $case['model'] ?? '', 'status' => $case['status'] ?? 'passed', 'scores' => $case['scores'] ?? array());
        }

        $summary = $this->aggregation_service->buildSummary((int)($run['summary']['dataset_case_count'] ?? count($cases)), $run['target_ref'] ?? array(), $records);
        $this->db->update_by_ids('llm_eval_runs', array('summary_json' => $this->jsonEncode($summary)), array('id' => (int)$run_id));
        return $this->getEvalRun($run_id);
    }

    private function loadRunCaseScores($run_case_ids)
    {
        $run_case_ids = array_values(array_filter(array_map('intval', (array)$run_case_ids)));
        if (empty($run_case_ids)) {
            return array();
        }

        $rows = $this->db->query_db(
            "SELECT s.*, d.name AS eval_name
             FROM llm_eval_scores s
             LEFT JOIN llm_eval_definitions d ON d.id = s.id_llm_eval_definitions
             WHERE s.id_llm_eval_run_cases IN (" . implode(',', $run_case_ids) . ")
             ORDER BY s.id ASC"
        );

        $grouped = array();
        foreach ($rows as $row) {
            $row['details'] = $this->decodeJsonValue($row['details_json'] ?? '{}', array());
            $grouped[(int)$row['id_llm_eval_run_cases']][] = $row;
        }
        return $grouped;
    }

    private function decodeJsonValue($value, $fallback)
    {
        if (!is_string($value) || trim($value) === '') {
            return $fallback;
        }
        $decoded = $this->jsonDecode($value);
        return $decoded !== null ? $decoded : $fallback;
    }

    private function deriveCaseStatus($scores)
    {
        $has_pending = false;
        foreach ((array)$scores as $score) {
            if (($score['passed'] ?? null) === 0 || ($score['passed'] ?? null) === '0') {
                return 'failed';
            }
            if (($score['passed'] ?? null) === null) {
                $has_pending = true;
            }
        }
        return $has_pending ? 'pending_review' : 'passed';
    }

    private function resolveTargetPrompt($payload, $descriptor)
    {
        $target_type = (string)($payload['target_type'] ?? 'draft');
        $draft_prompt = (string)($payload['draft_prompt'] ?? '');
        $target_ref = array('target_type' => $target_type, 'descriptor' => $descriptor);

        if ($target_type === 'version' || $target_type === 'active_version') {
            $version_id = $target_type === 'version'
                ? (int)($payload['target_version_id'] ?? 0)
                : (int)(($this->registry_service->bootstrapOwner($descriptor)['active_version']['id'] ?? 0));
            if ($version_id > 0) {
                $version = $this->registry_service->getVersion($version_id);
                if ($version) {
                    $draft_prompt = (string)($version['template_raw'] ?? '');
                    $target_ref['prompt_version_id'] = $version_id;
                    $target_ref['prompt_version_no'] = (int)($version['version_no'] ?? 0);
                }
            }
        }

        if (trim($draft_prompt) === '') {
            throw new Exception('Target prompt is empty');
        }

        return array('target_type' => $target_type, 'target_ref' => $target_ref, 'draft_prompt' => $draft_prompt);
    }

    private function lookupId($type_code, $lookup_code)
    {
        $lookup_id = $this->db->get_lookup_id_by_code($type_code, $lookup_code);
        if (!$lookup_id) {
            throw new Exception('Evaluation lookup setup is incomplete');
        }
        return $lookup_id;
    }

    private function buildInputPreview($input_payload)
    {
        if (!is_array($input_payload)) {
            return '';
        }

        $trigger = trim((string)($input_payload['trigger_message'] ?? ''));
        if ($trigger !== '') {
            return $this->truncateText($trigger, 220);
        }

        $variables = is_array($input_payload['form_data'] ?? null)
            ? $input_payload['form_data']
            : (is_array($input_payload['variables'] ?? null) ? $input_payload['variables'] : array());
        if (!empty($variables)) {
            $parts = array();
            foreach ($variables as $key => $value) {
                $text = $this->flattenInputValue($value);
                if ($text === '') {
                    continue;
                }
                $parts[] = (string)$key . ': ' . $text;
                if (count($parts) >= 3) {
                    break;
                }
            }
            if (!empty($parts)) {
                return $this->truncateText(implode(' | ', $parts), 220);
            }
        }

        $history = is_array($input_payload['message_history'] ?? null) ? $input_payload['message_history'] : array();
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $row = is_array($history[$i] ?? null) ? $history[$i] : array();
            if (($row['role'] ?? '') !== 'user') {
                continue;
            }
            $content = trim((string)($row['content'] ?? ''));
            if ($content !== '') {
                return $this->truncateText($content, 220);
            }
        }

        return '';
    }

    private function buildInputFields($input_payload)
    {
        if (!is_array($input_payload)) {
            return array();
        }

        $source = is_array($input_payload['form_data'] ?? null)
            ? $input_payload['form_data']
            : (is_array($input_payload['variables'] ?? null) ? $input_payload['variables'] : array());

        $fields = array();
        foreach ($source as $key => $value) {
            $text = $this->flattenInputValue($value);
            if ($text === '') {
                continue;
            }
            $fields[] = array(
                'key' => (string)$key,
                'value' => $this->truncateText($text, 80)
            );
            if (count($fields) >= 4) {
                break;
            }
        }

        return $fields;
    }

    private function flattenInputValue($value)
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return trim((string)$value);
        }
        if (is_array($value)) {
            return $this->truncateText(json_encode($value), 140);
        }
        return '';
    }

    private function truncateText($text, $max_len)
    {
        $text = trim((string)$text);
        if ($text === '' || strlen($text) <= (int)$max_len) {
            return $text;
        }
        return substr($text, 0, max(0, (int)$max_len - 3)) . '...';
    }
}
?>
