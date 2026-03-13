<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';

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
