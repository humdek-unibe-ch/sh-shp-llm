<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmEvaluationDefinitionService.php';

class LlmEvaluationReviewService extends BaseLlmService
{
    private $definition_service;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->definition_service = new LlmEvaluationDefinitionService($services);
    }

    public function saveHumanScore($payload)
    {
        $run_case_id = (int)($payload['id_llm_eval_run_cases'] ?? 0);
        $definition_id = (int)($payload['id_llm_eval_definitions'] ?? 0);
        if ($run_case_id <= 0 || $definition_id <= 0) {
            throw new Exception('Missing run case or evaluation definition id');
        }

        $definition = $this->definition_service->getDefinition($definition_id);
        if (!$definition) {
            throw new Exception('Evaluation definition not found');
        }
        if (($definition['eval_type_code'] ?? '') !== LLM_EVAL_TYPE_HUMAN_REVIEW) {
            throw new Exception('save_human_score only supports human_review evaluators');
        }

        $existing = $this->db->query_db_first(
            "SELECT id
             FROM llm_eval_scores
             WHERE id_llm_eval_run_cases = :run_case_id
               AND id_llm_eval_definitions = :definition_id
               AND score_type = 'human_review'
             LIMIT 1",
            array(':run_case_id' => $run_case_id, ':definition_id' => $definition_id)
        );

        $data = array(
            'score_value_numeric' => array_key_exists('score_value_numeric', $payload) ? $payload['score_value_numeric'] : null,
            'score_value_label' => trim((string)($payload['score_value_label'] ?? '')) !== '' ? (string)$payload['score_value_label'] : null,
            'passed' => array_key_exists('passed', $payload) && $payload['passed'] !== null ? (int)$payload['passed'] : null,
            'details_json' => $this->jsonEncode(is_array($payload['details'] ?? null) ? $payload['details'] : array()),
            'id_users_created' => $this->getCurrentUserId()
        );

        if (!empty($existing['id'])) {
            $this->db->update_by_ids('llm_eval_scores', $data, array('id' => (int)$existing['id']));
            $score_id = (int)$existing['id'];
        } else {
            $score_id = $this->db->insert('llm_eval_scores', array_merge($data, array(
                'id_llm_eval_run_cases' => $run_case_id,
                'id_llm_eval_definitions' => $definition_id,
                'score_type' => 'human_review'
            )));
        }

        $run_case = $this->db->query_db_first(
            "SELECT id_llm_eval_runs FROM llm_eval_run_cases WHERE id = :id LIMIT 1",
            array(':id' => $run_case_id)
        );

        $this->addPluginTransaction('update', 'llm_eval_scores', $score_id, 'LLM human score saved');

        return array(
            'score' => $this->db->select_by_uid('llm_eval_scores', $score_id),
            'run_id' => !empty($run_case['id_llm_eval_runs']) ? (int)$run_case['id_llm_eval_runs'] : null
        );
    }
}
?>
