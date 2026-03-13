<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmEvaluationDefinitionService.php';
require_once __DIR__ . '/LlmEvaluationRunnerService.php';
require_once __DIR__ . '/LlmEvaluationReviewService.php';

class LlmEvaluationService extends BaseLlmService
{
    private $definition_service;
    private $runner_service;
    private $review_service;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->definition_service = new LlmEvaluationDefinitionService($services);
        $this->runner_service = new LlmEvaluationRunnerService($services);
        $this->review_service = new LlmEvaluationReviewService($services);
    }

    public function listDefinitions() { return $this->definition_service->listDefinitions(); }
    public function runDatasetEval($payload) { return $this->runner_service->runDatasetEval($payload); }
    public function getEvalRun($run_id) { return $this->runner_service->getEvalRun($run_id); }
    public function listEvalRunCases($run_id) { return $this->runner_service->listEvalRunCases($run_id); }

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
