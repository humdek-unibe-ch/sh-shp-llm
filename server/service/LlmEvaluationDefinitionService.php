<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';

/**
 * LLM Evaluation Definition Service
 *
 * Manages evaluation scoring definitions stored in `llm_eval_definitions`.
 * Each definition specifies a scoring strategy (programmatic, LLM judge,
 * or human review) and its configuration (thresholds, prompts, rubrics).
 *
 * @package LLM Plugin
 */
class LlmEvaluationDefinitionService extends BaseLlmService
{
    public function listDefinitions()
    {
        return $this->db->query_db(
            "SELECT d.*, et.lookup_code AS eval_type_code
             FROM llm_eval_definitions d
             LEFT JOIN lookups et ON et.id = d.id_lookups_eval_type
             WHERE d.is_active = 1
             ORDER BY d.name ASC"
        );
    }

    /**
     * Load evaluation definitions by their IDs.
     *
     * @param array $ids Evaluation definition IDs.
     * @return array Definition rows with eval_type_code.
     */
    public function loadDefinitionsByIds($ids)
    {
        $ids = array_values(array_filter(array_map('intval', (array)$ids)));
        if (empty($ids)) {
            return array();
        }

        return $this->db->query_db(
            "SELECT d.*, et.lookup_code AS eval_type_code
             FROM llm_eval_definitions d
             LEFT JOIN lookups et ON et.id = d.id_lookups_eval_type
             WHERE d.id IN (" . implode(',', $ids) . ")
               AND d.is_active = 1
             ORDER BY d.name ASC"
        );
    }

    /** @return array All default (non-deleted) evaluation definitions ordered by sort_order. */
    public function loadDefaultDefinitions()
    {
        return $this->db->query_db(
            "SELECT d.*, et.lookup_code AS eval_type_code
             FROM llm_eval_definitions d
             LEFT JOIN lookups et ON et.id = d.id_lookups_eval_type
             WHERE d.is_active = 1
               AND et.lookup_code = :eval_type
             ORDER BY d.name ASC",
            array(':eval_type' => LLM_EVAL_TYPE_PROGRAMMATIC)
        );
    }

    /**
     * @param int $definition_id Definition ID.
     * @return array|null Single evaluation definition row, or null.
     */
    public function getDefinition($definition_id)
    {
        return $this->db->query_db_first(
            "SELECT d.*, et.lookup_code AS eval_type_code
             FROM llm_eval_definitions d
             LEFT JOIN lookups et ON et.id = d.id_lookups_eval_type
             WHERE d.id = :id
             LIMIT 1",
            array(':id' => (int)$definition_id)
        );
    }
}
?>
