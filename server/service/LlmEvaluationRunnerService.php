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

/**
 * LLM Evaluation Runner Service
 *
 * Orchestrates evaluation runs against datasets. For each test case in a
 * dataset, the runner:
 * 1. Replays the prompt through the LLM using LlmDatasetReplayService
 * 2. Scores the response using the configured evaluation definitions
 * 3. Aggregates per-case scores into run-level summary statistics
 * 4. Persists all results for comparison and regression tracking
 *
 * Supports baseline comparisons by linking runs and computing delta metrics.
 *
 * @package LLM Plugin
 * @see LlmEvaluationService Facade that exposes these methods
 * @see LlmEvaluationScoringService For per-case scoring logic
 * @see LlmEvaluationAggregationService For summary computation
 */
class LlmEvaluationRunnerService extends BaseLlmService
{
    /** @var LlmDatasetService Dataset CRUD and case retrieval */
    private $dataset_service;

    /** @var LlmDatasetReplayService Replays prompts through the LLM */
    private $replay_service;

    /** @var LlmEvaluationDefinitionService Scoring criteria management */
    private $definition_service;

    /** @var LlmEvaluationScoringService Per-case scoring engine */
    private $scoring_service;

    /** @var LlmEvaluationAggregationService Run-level summary computation */
    private $aggregation_service;

    /** @var LlmPromptRegistryService Prompt registry for context resolution */
    private $registry_service;

    /** @param object $services SelfHelp services container. */
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

    /**
     * Execute a full evaluation run against a dataset: replays all cases, scores each, and aggregates results.
     *
     * @param array $payload {
     *     @type int    $dataset_id          Required. Target dataset ID.
     *     @type array  $descriptor          Prompt owner descriptor.
     *     @type array  $selected_models     Model names for multi-model comparison.
     *     @type array  $eval_definition_ids Evaluation definition IDs (falls back to defaults).
     *     @type string $target_type         'draft', 'version', or 'active_version'.
     *     @type string $draft_prompt        Prompt text (for 'draft' target).
     *     @type int    $target_version_id   Prompt version ID (for 'version' target).
     *     @type array  $runtime_overrides   Runtime parameter overrides.
     * }
     * @return array{run: array, cases: array} Completed run record and per-case results.
     * @throws Exception On missing dataset, empty cases, or LLM failure.
     */
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
        $definitions = $this->appendHumanReviewDefinition($definitions);

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
                        'id_llm_eval_cases' => (int)$case['id'],
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
                        'case_id' => (int)$case['id'],
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

    /**
     * Retrieve a single evaluation run by ID, with decoded summary and target_ref.
     *
     * @param int $run_id Run primary key.
     * @return array|null Run row or null if not found.
     */
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

    /**
     * List all per-case results for an evaluation run, with scores, previews, and status.
     *
     * @param int $run_id Run primary key.
     * @return array List of run-case records with decoded payloads and computed status.
     */
    public function listEvalRunCases($run_id)
    {
        $cases = $this->db->query_db(
            "SELECT rc.*, dc.title AS dataset_case_title, dc.input_payload_json
             FROM llm_eval_run_cases rc
             LEFT JOIN llm_eval_cases dc ON dc.id = rc.id_llm_eval_cases
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

    /**
     * Re-aggregate and persist the summary for an existing run (e.g. after human review scores change).
     *
     * @param int $run_id Run primary key.
     * @return array Updated run record.
     * @throws Exception If run not found.
     */
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

    /**
     * List evaluation runs for a dataset, ordered by newest first.
     *
     * @param int $dataset_id Dataset primary key.
     * @param int $limit      Max results (clamped to 1–100).
     * @return array Run rows with decoded summary and target_ref.
     * @throws Exception If dataset_id invalid.
     */
    public function listEvalRuns($dataset_id, $limit = 20)
    {
        $dataset_id = (int)$dataset_id;
        $limit = max(1, min((int)$limit, 100));
        if ($dataset_id <= 0) {
            throw new Exception('dataset_id is required');
        }

        $rows = $this->db->query_db(
            "SELECT r.*, s.lookup_code AS status_code, rm.lookup_code AS run_mode_code
             FROM llm_eval_runs r
             LEFT JOIN lookups s ON s.id = r.id_lookups_status
             LEFT JOIN lookups rm ON rm.id = r.id_lookups_run_mode
             WHERE r.id_llm_eval_datasets = :dataset_id
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT {$limit}",
            array(':dataset_id' => $dataset_id)
        );

        foreach ($rows as &$row) {
            $row['summary'] = $this->decodeJsonValue($row['summary_json'] ?? '{}', array());
            $row['target_ref'] = $this->decodeJsonValue($row['target_ref_json'] ?? '{}', array());
        }
        unset($row);

        return $rows;
    }

    /**
     * Delete a single evaluation run and its cascading data.
     *
     * @param int $run_id     Run primary key.
     * @param int $dataset_id Optional dataset scope check (0 = skip).
     * @return array{deleted: bool, deleted_count: int, dataset_id?: int}
     * @throws Exception If run doesn't belong to the specified dataset.
     */
    public function deleteEvalRun($run_id, $dataset_id = 0)
    {
        $run_id = (int)$run_id;
        $dataset_id = (int)$dataset_id;
        if ($run_id <= 0) {
            throw new Exception('run_id is required');
        }

        $run = $this->getEvalRun($run_id);
        if (!$run) {
            return array('deleted' => true, 'deleted_count' => 0);
        }

        if ($dataset_id > 0 && (int)($run['id_llm_eval_datasets'] ?? 0) !== $dataset_id) {
            throw new Exception('Evaluation run does not belong to selected dataset');
        }

        $this->db->remove_by_ids('llm_eval_runs', array('id' => $run_id));
        $this->addPluginTransaction('delete', 'llm_eval_runs', $run_id, 'LLM evaluation run deleted');

        return array('deleted' => true, 'deleted_count' => 1, 'dataset_id' => (int)($run['id_llm_eval_datasets'] ?? 0));
    }

    /**
     * Delete all evaluation runs associated with a dataset (bulk cleanup).
     *
     * @param int $dataset_id Dataset primary key.
     * @return array{deleted: bool, deleted_count: int, dataset_id: int}
     * @throws Exception If dataset_id invalid.
     */
    public function deleteEvalRunsForDataset($dataset_id)
    {
        $dataset_id = (int)$dataset_id;
        if ($dataset_id <= 0) {
            throw new Exception('dataset_id is required');
        }

        $rows = $this->db->query_db(
            "SELECT id FROM llm_eval_runs WHERE id_llm_eval_datasets = :dataset_id",
            array(':dataset_id' => $dataset_id)
        );

        $deleted_count = 0;
        foreach ((array)$rows as $row) {
            $run_id = (int)($row['id'] ?? 0);
            if ($run_id <= 0) {
                continue;
            }
            $this->db->remove_by_ids('llm_eval_runs', array('id' => $run_id));
            $this->addPluginTransaction('delete', 'llm_eval_runs', $run_id, 'LLM evaluation run deleted via dataset bulk cleanup');
            $deleted_count++;
        }

        return array(
            'deleted' => true,
            'deleted_count' => $deleted_count,
            'dataset_id' => $dataset_id
        );
    }

    /**
     * Link a baseline run to a target run for comparison, merging baseline metrics into the run summary.
     *
     * @param int   $run_id           Target run to annotate.
     * @param int   $baseline_run_id  Baseline run for comparison.
     * @param array $baseline_summary Additional baseline metrics to merge into summary.
     * @return array Updated run record.
     * @throws Exception If either run not found.
     */
    public function linkBaselineRun($run_id, $baseline_run_id, $baseline_summary = array())
    {
        $run = $this->getEvalRun($run_id);
        if (!$run) {
            throw new Exception('Evaluation run not found');
        }
        $baseline_run = $this->getEvalRun($baseline_run_id);
        if (!$baseline_run) {
            throw new Exception('Baseline evaluation run not found');
        }

        $target_ref = is_array($run['target_ref'] ?? null) ? $run['target_ref'] : array();
        $target_ref['baseline_run_id'] = (int)$baseline_run_id;
        $target_ref['comparison_mode'] = 'target_vs_baseline';

        $summary = is_array($run['summary'] ?? null) ? $run['summary'] : array();
        $summary['baseline_run_id'] = (int)$baseline_run_id;
        if (is_array($baseline_summary)) {
            foreach ($baseline_summary as $key => $value) {
                $summary[$key] = $value;
            }
        }

        $this->db->update_by_ids('llm_eval_runs', array(
            'target_ref_json' => $this->jsonEncode($target_ref),
            'summary_json' => $this->jsonEncode($summary)
        ), array('id' => (int)$run_id));

        return $this->getEvalRun($run_id);
    }

    /**
     * Batch-load scores for run cases, grouped by run_case ID.
     *
     * @param int[] $run_case_ids Run case primary keys.
     * @return array<int, array> Map of run_case_id => score rows.
     */
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

    /**
     * Ensure the human_review evaluation definition is included in the definition set.
     *
     * @param array $definitions Current definition rows.
     * @return array Definitions with human_review appended if missing.
     */
    private function appendHumanReviewDefinition($definitions)
    {
        $definitions = is_array($definitions) ? array_values($definitions) : array();
        foreach ($definitions as $definition) {
            if (($definition['eval_type_code'] ?? '') === LLM_EVAL_TYPE_HUMAN_REVIEW) {
                return $definitions;
            }
        }

        foreach ($this->definition_service->listDefinitions() as $definition) {
            if (($definition['eval_type_code'] ?? '') === LLM_EVAL_TYPE_HUMAN_REVIEW) {
                $definitions[] = $definition;
                break;
            }
        }

        return $definitions;
    }

    /** @return mixed Decoded JSON value, or fallback on failure. */
    private function decodeJsonValue($value, $fallback)
    {
        if (!is_string($value) || trim($value) === '') {
            return $fallback;
        }
        $decoded = $this->jsonDecode($value);
        return $decoded !== null ? $decoded : $fallback;
    }

    /** @return string 'failed', 'pending_review', or 'passed' based on score outcomes. */
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

    /**
     * Resolve the target prompt (draft, versioned, or active) for an evaluation run.
     *
     * @param array $payload    Run payload with target_type, draft_prompt, target_version_id.
     * @param array $descriptor Prompt owner descriptor.
     * @return array{target_type: string, target_ref: array, draft_prompt: string}
     * @throws Exception If resolved prompt is empty.
     */
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

    /**
     * Resolve a lookup code to its numeric ID, throwing on failure.
     *
     * @param string $type_code   Lookup type category.
     * @param string $lookup_code Lookup code.
     * @return int Lookup ID.
     * @throws Exception If lookup not found.
     */
    private function lookupId($type_code, $lookup_code)
    {
        $lookup_id = $this->db->get_lookup_id_by_code($type_code, $lookup_code);
        if (!$lookup_id) {
            throw new Exception('Evaluation lookup setup is incomplete');
        }
        return $lookup_id;
    }

    /** @return string Short human-readable preview from the input payload (max ~220 chars). */
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

    /**
     * Extract a compact list of [{key, value}] pairs from the input payload for UI display (max 4 fields).
     *
     * @param array|null $input_payload Decoded input payload.
     * @return array<int, array{key: string, value: string}>
     */
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

    /** @return string Scalar or JSON-encoded value for preview, or empty string. */
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

    /** @return string Truncated text with '...' suffix if exceeding max_len. */
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
