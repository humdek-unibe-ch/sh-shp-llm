<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmEvaluationDefinitionService.php';
require_once __DIR__ . '/LlmEvaluationRunnerService.php';
require_once __DIR__ . '/LlmEvaluationReviewService.php';

/**
 * LLM Evaluation Service (Facade)
 *
 * Facade service that coordinates prompt evaluation workflows. Delegates
 * to specialized sub-services for definition management, execution,
 * and human review.
 *
 * Evaluation types (see LLM_EVAL_TYPE_* constants):
 * - Programmatic: Automated scoring via string matching, JSON diff, etc.
 * - LLM Judge: Uses a second LLM call to score the response quality
 * - Human Review: Manual scoring through the admin UI
 *
 * @package LLM Plugin
 * @see LlmEvaluationDefinitionService For managing scoring criteria
 * @see LlmEvaluationRunnerService For executing evaluation runs
 * @see LlmEvaluationReviewService For human review score persistence
 */
class LlmEvaluationService extends BaseLlmService
{
    /** @var LlmEvaluationDefinitionService Scoring criteria management */
    private $definition_service;

    /** @var LlmEvaluationRunnerService Evaluation execution engine */
    private $runner_service;

    /** @var LlmEvaluationReviewService Human review score persistence */
    private $review_service;

    /**
     * @param object $services SelfHelp services container
     */
    public function __construct($services)
    {
        parent::__construct($services);
        $this->definition_service = new LlmEvaluationDefinitionService($services);
        $this->runner_service = new LlmEvaluationRunnerService($services);
        $this->review_service = new LlmEvaluationReviewService($services);
    }

    /** @return array List of all evaluation scoring definitions */
    public function listDefinitions() { return $this->definition_service->listDefinitions(); }

    /**
     * Execute an evaluation run against a dataset.
     * @param array $payload Run configuration (dataset_id, model, prompt, etc.)
     * @return array Run results with case scores
     */
    public function runDatasetEval($payload) { return $this->runner_service->runDatasetEval($payload); }

    /** @param int $run_id Evaluation run ID
     *  @return array|null Run metadata */
    public function getEvalRun($run_id) { return $this->runner_service->getEvalRun($run_id); }

    /** @param int $run_id Evaluation run ID
     *  @return array Per-case results for the run */
    public function listEvalRunCases($run_id) { return $this->runner_service->listEvalRunCases($run_id); }

    /** @param int $dataset_id Dataset to list runs for
     *  @param int $limit Max results
     *  @return array Recent evaluation runs */
    public function listEvalRuns($dataset_id, $limit = 20) { return $this->runner_service->listEvalRuns($dataset_id, $limit); }

    /** @param int $run_id Run to delete
     *  @param int $dataset_id Dataset guard
     *  @return bool Success */
    public function deleteEvalRun($run_id, $dataset_id = 0) { return $this->runner_service->deleteEvalRun($run_id, $dataset_id); }

    /** @param int $dataset_id Delete all runs for this dataset
     *  @return bool Success */
    public function deleteEvalRunsForDataset($dataset_id) { return $this->runner_service->deleteEvalRunsForDataset($dataset_id); }

    /** Link a baseline run for regression comparison.
     *  @param int $run_id Current run
     *  @param int $baseline_run_id Baseline to compare against
     *  @param array $baseline_summary Pre-computed baseline summary */
    public function linkBaselineRun($run_id, $baseline_run_id, $baseline_summary = array()) { return $this->runner_service->linkBaselineRun($run_id, $baseline_run_id, $baseline_summary); }

    /**
     * Save a human review score and refresh the run summary.
     *
     * @param array $payload Score data (run_id, case_id, score, notes)
     * @return array The saved score record
     */
    public function saveHumanScore($payload)
    {
        $result = $this->review_service->saveHumanScore($payload);
        if (!empty($result['run_id'])) {
            $this->runner_service->refreshRunSummary((int)$result['run_id']);
        }
        return $result['score'];
    }
}
?>
