<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Server-side facts the Prompt Lab needs to decide who may touch what.
 *
 * The browser only names things: a section, a page, a dataset, a run. Every
 * claim a request makes about where those things live is resolved here from
 * the database, so a user who may edit one page cannot reach the prompts,
 * datasets or evaluation runs of another page by sending that page's id along
 * with somebody else's section.
 *
 * Plain lookups: no ACL decisions and no exceptions. AjaxLlmPromptLab turns
 * these facts into "Access denied".
 */
class LlmPromptLabAccessService
{
    /** Longest section chain walked up from a section to the pages that show it. */
    const MAX_HIERARCHY_DEPTH = 64;

    /** @var object Database (core PageDb) */
    private $db;

    /**
     * @param object $services SelfHelp services container
     */
    public function __construct($services)
    {
        $this->db = $services->get_db();
    }

    /**
     * Pages that show a section, directly or through the sections that contain it.
     *
     * Mirrors how core finds the page of a section: walk sections_hierarchy up to
     * the roots, then read pages_sections (page content) and sections_navigation
     * (children of a navigation section). A section can sit in several places, so
     * every parent is followed; cycles and absurd depths are cut off.
     *
     * @param int $section_id
     * @return int[] Page ids, ascending. Empty when no page shows the section.
     */
    public function hostPagesForSection($section_id)
    {
        $section_id = (int)$section_id;
        if ($section_id <= 0) {
            return array();
        }

        $pages = array();
        $seen = array();
        $level = array($section_id);

        for ($depth = 0; $depth < self::MAX_HIERARCHY_DEPTH && !empty($level); $depth++) {
            $fresh = array();
            foreach ($level as $id) {
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $fresh[] = $id;
                }
            }
            if (empty($fresh)) {
                break;
            }

            list($in, $params) = $this->inList($fresh);

            foreach ($this->rows(
                "SELECT id_pages FROM pages_sections WHERE id_sections IN ({$in})",
                $params
            ) as $row) {
                $pages[(int)$row['id_pages']] = true;
            }
            foreach ($this->rows(
                "SELECT id_pages FROM sections_navigation WHERE child IN ({$in}) AND id_pages IS NOT NULL",
                $params
            ) as $row) {
                $pages[(int)$row['id_pages']] = true;
            }

            $level = array();
            foreach ($this->rows(
                "SELECT parent FROM sections_hierarchy WHERE child IN ({$in})",
                $params
            ) as $row) {
                $level[] = (int)$row['parent'];
            }
        }

        unset($pages[0]);
        $ids = array_keys($pages);
        sort($ids);
        return $ids;
    }

    /**
     * The prompt owner a stored prompt version belongs to.
     *
     * @param int $version_id llm_prompt_versions.id
     * @return array{owner_type: string, owner_id: int}|null Null when the version does not exist
     */
    public function versionOwner($version_id)
    {
        $row = $this->db->query_db_first(
            "SELECT ot.lookup_code AS owner_type, e.owner_id
             FROM llm_prompt_versions v
             INNER JOIN llm_prompt_locales l ON l.id = v.id_llm_prompt_locales
             INNER JOIN llm_prompt_entries e ON e.id = l.id_llm_prompt_entries
             INNER JOIN lookups ot ON ot.id = e.id_llm_prompt_owner_types
             WHERE v.id = :id LIMIT 1",
            array(':id' => (int)$version_id)
        );
        if (!$row) {
            return null;
        }
        return array('owner_type' => (string)$row['owner_type'], 'owner_id' => (int)$row['owner_id']);
    }

    /**
     * The owner a dataset is scoped to. A dataset without a scope is shared by
     * every owner (global).
     *
     * @param int $dataset_id llm_eval_datasets.id
     * @return array{owner_type_scope: string, owner_id_scope: int}|null Null when the dataset does not exist
     */
    public function datasetScope($dataset_id)
    {
        $row = $this->db->query_db_first(
            "SELECT owner_type_scope, owner_id_scope FROM llm_eval_datasets WHERE id = :id LIMIT 1",
            array(':id' => (int)$dataset_id)
        );
        if (!$row) {
            return null;
        }
        return array(
            'owner_type_scope' => (string)($row['owner_type_scope'] ?? ''),
            'owner_id_scope' => (int)($row['owner_id_scope'] ?? 0),
        );
    }

    /**
     * @param int $run_id llm_eval_runs.id
     * @return int The dataset the run belongs to; 0 when the run does not exist
     */
    public function runDatasetId($run_id)
    {
        $row = $this->db->query_db_first(
            "SELECT id_llm_eval_datasets FROM llm_eval_runs WHERE id = :id LIMIT 1",
            array(':id' => (int)$run_id)
        );
        return $row ? (int)$row['id_llm_eval_datasets'] : 0;
    }

    /**
     * @param int $run_case_id llm_eval_run_cases.id
     * @return int The dataset of the run the run case belongs to; 0 when unknown
     */
    public function runCaseDatasetId($run_case_id)
    {
        $row = $this->db->query_db_first(
            "SELECT r.id_llm_eval_datasets
             FROM llm_eval_run_cases rc
             INNER JOIN llm_eval_runs r ON r.id = rc.id_llm_eval_runs
             WHERE rc.id = :id LIMIT 1",
            array(':id' => (int)$run_case_id)
        );
        return $row ? (int)$row['id_llm_eval_datasets'] : 0;
    }

    /**
     * @param int $case_id llm_eval_cases.id
     * @return int[] Datasets the case is linked to (empty: unknown or orphaned case)
     */
    public function caseDatasetIds($case_id)
    {
        $ids = array();
        foreach ($this->rows(
            "SELECT id_llm_eval_datasets FROM llm_eval_dataset_case_links WHERE id_llm_eval_cases = :id",
            array(':id' => (int)$case_id)
        ) as $row) {
            $ids[] = (int)$row['id_llm_eval_datasets'];
        }
        return $ids;
    }

    /**
     * The core query helper answers false when a query fails. Callers here only ever want a
     * list, and a failed lookup must read as "nothing found" (which denies), not as a row.
     *
     * @param string $sql
     * @param array $params
     * @return array[] Rows
     */
    private function rows($sql, array $params)
    {
        $rows = $this->db->query_db($sql, $params);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @param int[] $ids
     * @return array{0: string, 1: array} "IN" placeholder list and its named parameters
     */
    private function inList(array $ids)
    {
        $placeholders = array();
        $params = array();
        foreach (array_values($ids) as $i => $id) {
            $placeholders[] = ':s' . $i;
            $params[':s' . $i] = (int)$id;
        }
        return array(implode(', ', $placeholders), $params);
    }
}
