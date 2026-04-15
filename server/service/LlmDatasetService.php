<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmDatasetIngestionService.php';
require_once __DIR__ . '/LlmDatasetAiImportMapperService.php';
require_once __DIR__ . '/LlmDatasetAiImportParserService.php';
require_once __DIR__ . '/LlmDatasetBatchImportService.php';
require_once __DIR__ . '/LlmPromptStandardService.php';
require_once __DIR__ . '/LlmPromptRegistryService.php';

/**
 * LLM Dataset Service
 *
 * Central service for managing evaluation and prompt-building datasets.
 * Datasets are collections of test cases used to evaluate prompt quality,
 * run automated evaluations, and provide examples for the Prompt Builder.
 *
 * Each dataset is stored in the `llm_datasets` table with associated cases
 * in `llm_dataset_cases`. Datasets can be scoped to a specific prompt
 * (owner_type_scope) or shared globally.
 *
 * Delegates to specialized sub-services:
 * - LlmDatasetIngestionService: Parses and ingests raw data (CSV, JSON)
 * - LlmDatasetAiImportMapperService: AI-assisted column mapping
 * - LlmDatasetAiImportParserService: AI-assisted data parsing
 * - LlmDatasetBatchImportService: Bulk case import operations
 *
 * @package LLM Plugin
 * @see LlmEvaluationService For running evaluations against datasets
 * @see LlmPromptBuilderService For using datasets as prompt examples
 */
class LlmDatasetService extends BaseLlmService
{
    /** @var LlmDatasetIngestionService Handles raw data parsing and ingestion */
    private $ingestion_service;

    /** @var LlmDatasetAiImportMapperService AI-assisted column mapping for import */
    private $mapper_service;

    /** @var LlmDatasetAiImportParserService AI-assisted data parsing for import */
    private $parser_service;

    /** @var LlmDatasetBatchImportService Bulk case import operations */
    private $batch_import_service;

    /** @var LlmPromptRegistryService Prompt registry for ownership lookups */
    private $registry_service;

    /** @var LlmPromptStandardService Standard prompt operations */
    private $standard_service;

    /**
     * @param object $services SelfHelp services container
     */
    public function __construct($services)
    {
        parent::__construct($services);
        $this->ingestion_service = new LlmDatasetIngestionService($services, $this);
        $this->mapper_service = new LlmDatasetAiImportMapperService($this);
        $this->parser_service = new LlmDatasetAiImportParserService($services, $this->mapper_service);
        $this->batch_import_service = new LlmDatasetBatchImportService($services, $this, $this->mapper_service);
        $this->registry_service = new LlmPromptRegistryService($services);
        $this->standard_service = new LlmPromptStandardService($services);
    }

    /**
     * List all datasets with optional filtering by search, owner scope, and execution profile.
     *
     * @param array $filters {
     *     @type string $search    Free-text search across name, description, and lookup codes.
     *     @type string $owner_type_scope  Filter by owner type (e.g. 'style', 'script').
     *     @type int    $owner_id_scope    Filter by owner ID.
     *     @type string $execution_profile Filter by execution profile code.
     *     @type int    $exclude_dataset_id Exclude a specific dataset by ID.
     * }
     * @return array List of dataset rows with joined type/profile codes and case counts.
     */
    public function listDatasets($filters = array())
    {
        $where = array('1=1');
        $params = array();

        if (!empty($filters['search'])) {
            $where[] = '(d.name LIKE :search OR d.description LIKE :search OR ds.lookup_code LIKE :search OR ep.lookup_code LIKE :search)';
            $params[':search'] = '%' . trim((string)$filters['search']) . '%';
        }
        if (!empty($filters['owner_type_scope'])) {
            $where[] = 'd.owner_type_scope = :owner_type_scope';
            $params[':owner_type_scope'] = (string)$filters['owner_type_scope'];
        }
        if (!empty($filters['owner_id_scope'])) {
            $where[] = 'd.owner_id_scope = :owner_id_scope';
            $params[':owner_id_scope'] = (int)$filters['owner_id_scope'];
        }
        if (!empty($filters['execution_profile'])) {
            $where[] = 'ep.lookup_code = :execution_profile';
            $params[':execution_profile'] = (string)$filters['execution_profile'];
        }

        if (!empty($filters['exclude_dataset_id'])) {
            $where[] = 'd.id <> :exclude_dataset_id';
            $params[':exclude_dataset_id'] = (int)$filters['exclude_dataset_id'];
        }

        return $this->db->query_db(
            "SELECT d.*, ds.lookup_code AS dataset_type_code, ep.lookup_code AS execution_profile_code,
                    u_created.name AS created_user_name,
                    COUNT(DISTINCT l.id_llm_eval_cases) AS cases_count
             FROM llm_eval_datasets d
             LEFT JOIN lookups ds ON ds.id = d.id_lookups_dataset_type
             LEFT JOIN lookups ep ON ep.id = d.id_lookups_execution_profile
             LEFT JOIN users u_created ON u_created.id = d.id_users_created
             LEFT JOIN llm_eval_dataset_case_links l ON l.id_llm_eval_datasets = d.id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY d.id
             ORDER BY d.updated_at DESC, d.id DESC",
            $params
        );
    }

    /**
     * Retrieve a single dataset by ID with type/profile codes and case count.
     *
     * @param int $dataset_id Dataset primary key.
     * @return array|null Dataset row or null if not found.
     */
    public function getDataset($dataset_id)
    {
        return $this->db->query_db_first(
            "SELECT d.*, ds.lookup_code AS dataset_type_code, ep.lookup_code AS execution_profile_code,
                    (SELECT COUNT(*) FROM llm_eval_dataset_case_links l WHERE l.id_llm_eval_datasets = d.id) AS cases_count
             FROM llm_eval_datasets d
             LEFT JOIN lookups ds ON ds.id = d.id_lookups_dataset_type
             LEFT JOIN lookups ep ON ep.id = d.id_lookups_execution_profile
             WHERE d.id = :id LIMIT 1",
            array(':id' => (int)$dataset_id)
        );
    }

    /**
     * Retrieve a single test case with its dataset link and lookup codes.
     *
     * @param int $case_id   Case primary key.
     * @param int $dataset_id Optional dataset ID for scoped lookup (0 = any dataset).
     * @return array|null Case row with link metadata, or null if not found.
     */
    public function getDatasetCase($case_id, $dataset_id = 0)
    {
        $params = array(':id' => (int)$case_id);
        $join = 'LEFT JOIN llm_eval_dataset_case_links l ON l.id_llm_eval_cases = c.id';
        if ((int)$dataset_id > 0) {
            $join = 'INNER JOIN llm_eval_dataset_case_links l ON l.id_llm_eval_cases = c.id AND l.id_llm_eval_datasets = :dataset_id';
            $params[':dataset_id'] = (int)$dataset_id;
        }

        return $this->db->query_db_first(
            "SELECT c.*, l.id AS link_id, l.id_llm_eval_datasets, l.sort_order, l.promoted_from_dataset_id,
                    l.promoted_by_run_case_id, l.promotion_mode, l.promoted_at,
                    ct.lookup_code AS case_type_code, st.lookup_code AS source_type_code, ep.lookup_code AS execution_profile_code
             FROM llm_eval_cases c
             {$join}
             LEFT JOIN lookups ct ON ct.id = c.id_lookups_case_type
             LEFT JOIN lookups st ON st.id = c.id_lookups_source_type
             LEFT JOIN lookups ep ON ep.id = c.id_lookups_execution_profile
             WHERE c.id = :id
             ORDER BY l.id DESC
             LIMIT 1",
            $params
        );
    }

    /**
     * Create a new evaluation dataset.
     *
     * @param array $payload {
     *     @type string $name               Required dataset name.
     *     @type string $description         Optional description.
     *     @type string $dataset_type        Lookup code (default 'golden_manual').
     *     @type string $execution_profile   Lookup code (default 'text_only').
     *     @type string $owner_type_scope    Optional owner type scope.
     *     @type int    $owner_id_scope      Optional owner ID scope.
     *     @type bool   $is_locked           Whether dataset is locked for editing.
     * }
     * @return array Created dataset record.
     * @throws Exception If name is empty.
     */
    public function createDataset($payload)
    {
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            throw new Exception('Dataset name is required');
        }

        $dataset_id = $this->db->insert('llm_eval_datasets', array(
            'name' => $name,
            'description' => $this->nullableText($payload['description'] ?? null),
            'id_lookups_dataset_type' => $this->lookupId('llm_eval_dataset_types', (string)($payload['dataset_type'] ?? 'golden_manual'), 'dataset type'),
            'id_lookups_execution_profile' => $this->lookupId('llm_eval_execution_profiles', (string)($payload['execution_profile'] ?? 'text_only'), 'execution profile'),
            'owner_type_scope' => $this->nullableText($payload['owner_type_scope'] ?? null),
            'owner_id_scope' => !empty($payload['owner_id_scope']) ? (int)$payload['owner_id_scope'] : null,
            'is_locked' => !empty($payload['is_locked']) ? 1 : 0,
            'id_users_created' => $this->getCurrentUserId(),
            'id_users_updated' => $this->getCurrentUserId()
        ));

        $this->addPluginTransaction('insert', 'llm_eval_datasets', $dataset_id, 'LLM dataset created');
        return $this->getDataset($dataset_id);
    }

    /**
     * Update dataset metadata fields. Only provided keys are updated.
     *
     * @param int   $dataset_id Dataset primary key.
     * @param array $payload    Associative array of fields to update (name, description, dataset_type, execution_profile, is_locked).
     * @return array Updated dataset record.
     * @throws Exception If dataset not found or name is empty.
     */
    public function updateDataset($dataset_id, $payload)
    {
        $dataset = $this->getDataset($dataset_id);
        if (!$dataset) {
            throw new Exception('Dataset not found');
        }

        $update = array('id_users_updated' => $this->getCurrentUserId());
        if (array_key_exists('name', $payload) && $payload['name'] !== null) {
            $name = trim((string)$payload['name']);
            if ($name === '') {
                throw new Exception('Dataset name cannot be empty');
            }
            $update['name'] = $name;
        }
        if (array_key_exists('description', $payload) && $payload['description'] !== null) {
            $update['description'] = $this->nullableText($payload['description']);
        }
        if (!empty($payload['dataset_type'])) {
            $update['id_lookups_dataset_type'] = $this->lookupId('llm_eval_dataset_types', (string)$payload['dataset_type'], 'dataset type');
        }
        if (!empty($payload['execution_profile'])) {
            if ((int)($dataset['cases_count'] ?? 0) > 0 && (string)($dataset['execution_profile_code'] ?? '') !== (string)$payload['execution_profile']) {
                throw new Exception('Execution profile cannot change after cases exist');
            }
            $update['id_lookups_execution_profile'] = $this->lookupId('llm_eval_execution_profiles', (string)$payload['execution_profile'], 'execution profile');
        }
        if (array_key_exists('is_locked', $payload) && $payload['is_locked'] !== null) {
            $update['is_locked'] = !empty($payload['is_locked']) ? 1 : 0;
        }
        if (array_key_exists('owner_type_scope', $payload) && $payload['owner_type_scope'] !== null) {
            $update['owner_type_scope'] = $this->nullableText($payload['owner_type_scope']);
        }
        if (array_key_exists('owner_id_scope', $payload) && $payload['owner_id_scope'] !== null) {
            $update['owner_id_scope'] = !empty($payload['owner_id_scope']) ? (int)$payload['owner_id_scope'] : null;
        }

        $this->db->update_by_ids('llm_eval_datasets', $update, array('id' => (int)$dataset_id));
        $this->addPluginTransaction('update', 'llm_eval_datasets', $dataset_id, 'LLM dataset updated');
        return $this->getDataset($dataset_id);
    }

    /**
     * Delete a dataset and clean up orphaned cases no longer linked elsewhere.
     *
     * @param int $dataset_id Dataset primary key.
     * @return bool True on success.
     * @throws Exception If dataset is locked.
     */
    public function deleteDataset($dataset_id)
    {
        $dataset = $this->getDataset($dataset_id);
        if (!$dataset) {
            return true;
        }
        if (!empty($dataset['is_locked'])) {
            throw new Exception('Dataset is locked');
        }

        $case_ids = array_map('intval', array_column($this->listDatasetCases($dataset_id), 'id'));
        $this->db->remove_by_ids('llm_eval_datasets', array('id' => (int)$dataset_id));
        $this->cleanupOrphanCases($case_ids);
        $this->addPluginTransaction('delete', 'llm_eval_datasets', $dataset_id, 'LLM dataset deleted');
        return true;
    }

    /**
     * List all test cases linked to a dataset, ordered by sort_order then recency.
     *
     * @param int $dataset_id Dataset primary key.
     * @return array List of case rows with link metadata, type/profile codes, and evaluation run counts.
     */
    public function listDatasetCases($dataset_id)
    {
        return $this->db->query_db(
            "SELECT c.*, l.id AS link_id, l.id_llm_eval_datasets, l.sort_order, l.promoted_from_dataset_id,
                    l.promoted_by_run_case_id, l.promotion_mode, l.promoted_at,
                    ct.lookup_code AS case_type_code, st.lookup_code AS source_type_code, ep.lookup_code AS execution_profile_code,
                    u_created.name AS created_user_name,
                    (SELECT COUNT(*) FROM llm_eval_run_cases rc WHERE rc.id_llm_eval_cases = c.id) AS evaluation_runs_count
             FROM llm_eval_dataset_case_links l
             INNER JOIN llm_eval_cases c ON c.id = l.id_llm_eval_cases
             LEFT JOIN lookups ct ON ct.id = c.id_lookups_case_type
             LEFT JOIN lookups st ON st.id = c.id_lookups_source_type
             LEFT JOIN lookups ep ON ep.id = c.id_lookups_execution_profile
             LEFT JOIN users u_created ON u_created.id = c.id_users_created
             WHERE l.id_llm_eval_datasets = :dataset_id
             ORDER BY COALESCE(l.sort_order, 2147483647) ASC, l.updated_at DESC, l.id DESC",
            array(':dataset_id' => (int)$dataset_id)
        );
    }

    /**
     * Create a new test case and link it to a dataset.
     *
     * @param int   $dataset_id Target dataset ID.
     * @param array $payload {
     *     @type array       $input_payload          Required JSON object representing test input.
     *     @type string      $execution_profile      Execution profile code (falls back to dataset profile).
     *     @type string      $case_type              Case type code (auto-derived if omitted).
     *     @type string      $case_key               Unique key (auto-generated if omitted).
     *     @type string      $title                  Case title (auto-generated if omitted).
     *     @type array|null  $expected_output         Expected LLM output payload.
     *     @type array|null  $expected_labels         Expected quality labels.
     *     @type string      $source_type             Source type code (default 'manual_entry').
     *     @type array|null  $source_ref              Source reference metadata.
     *     @type array|null  $provenance              Provenance tracking data.
     *     @type array       $tags                    String tags array.
     *     @type string      $notes                   Free-text notes.
     *     @type int|null    $sort_order              Explicit sort position within dataset.
     *     @type int|null    $promoted_from_dataset_id Dataset case was promoted from.
     *     @type int|null    $promoted_by_run_case_id  Run case that triggered promotion.
     *     @type string|null $promotion_mode           Promotion mode label.
     * }
     * @return array Created case record.
     * @throws Exception If dataset not found, locked, or input_payload is invalid.
     */
    public function createCase($dataset_id, $payload)
    {
        $dataset = $this->getDataset($dataset_id);
        if (!$dataset) {
            throw new Exception('Dataset not found');
        }
        if (!empty($dataset['is_locked'])) {
            throw new Exception('Dataset is locked');
        }

        $input_payload = is_array($payload['input_payload'] ?? null) ? $payload['input_payload'] : null;
        if ($input_payload === null) {
            throw new Exception('input_payload must be a JSON object');
        }

        $execution_profile = (string)($payload['execution_profile'] ?? ($input_payload['execution_profile'] ?? $this->mapCaseTypeToExecutionProfile($payload['case_type'] ?? null)));
        if ($execution_profile === '') {
            $execution_profile = (string)($dataset['execution_profile_code'] ?? 'text_only');
        }
        if ((string)($dataset['execution_profile_code'] ?? '') !== '' && (string)$dataset['execution_profile_code'] !== $execution_profile) {
            throw new Exception('Case execution profile must match dataset profile');
        }

        $case_key = trim((string)($payload['case_key'] ?? ''));
        if ($case_key === '') {
            $case_key = substr(hash('sha256', json_encode($input_payload) . microtime(true)), 0, 24);
        }
        $title = trim((string)($payload['title'] ?? ''));
        if ($title === '') {
            $title = 'Case ' . $case_key;
        }

        $case_id = $this->db->insert('llm_eval_cases', array(
            'case_key' => $case_key,
            'id_lookups_execution_profile' => $this->lookupId('llm_eval_execution_profiles', $execution_profile, 'execution profile'),
            'id_lookups_case_type' => $this->lookupId('llm_eval_case_types', (string)($payload['case_type'] ?? $this->toCaseType($execution_profile)), 'case type'),
            'title' => $title,
            'input_payload_json' => $this->jsonEncode($input_payload),
            'expected_output_json' => $this->encodeOptionalJson($payload, 'expected_output'),
            'expected_labels_json' => $this->jsonEncode($this->normalizeExpectedLabels($payload['expected_labels'] ?? null)),
            'id_lookups_source_type' => $this->lookupId('llm_eval_source_types', (string)($payload['source_type'] ?? 'manual_entry'), 'source type'),
            'source_ref_json' => $this->encodeOptionalJson($payload, 'source_ref'),
            'provenance_json' => $this->encodeOptionalJson($payload, 'provenance'),
            'tags_json' => $this->jsonEncode(is_array($payload['tags'] ?? null) ? array_values($payload['tags']) : array()),
            'notes' => $this->nullableText($payload['notes'] ?? null),
            'id_users_created' => $this->getCurrentUserId(),
            'id_users_updated' => $this->getCurrentUserId()
        ));

        $this->linkCaseToDataset($dataset_id, $case_id, array(
            'sort_order' => $payload['sort_order'] ?? null,
            'promoted_from_dataset_id' => $payload['promoted_from_dataset_id'] ?? null,
            'promoted_by_run_case_id' => $payload['promoted_by_run_case_id'] ?? null,
            'promotion_mode' => $payload['promotion_mode'] ?? null,
        ));

        $this->addPluginTransaction('insert', 'llm_eval_cases', $case_id, 'LLM dataset case created');
        return $this->getDatasetCase($case_id, $dataset_id);
    }

    /**
     * Update a dataset case's metadata (title, notes, tags, expected labels).
     *
     * @param int   $case_id    Case primary key.
     * @param int   $dataset_id Dataset primary key (for scope check and lock validation).
     * @param array $payload    Associative array of updateable fields.
     * @return array Updated case record.
     * @throws Exception If case not found, dataset is locked, or title is empty.
     */
    public function updateDatasetCase($case_id, $dataset_id, $payload)
    {
        $case = $this->getDatasetCase($case_id, $dataset_id);
        if (!$case) {
            throw new Exception('Dataset case not found');
        }
        $dataset = $this->getDataset((int)$dataset_id);
        if ($dataset && !empty($dataset['is_locked'])) {
            throw new Exception('Dataset is locked');
        }

        $update = array(
            'id_users_updated' => $this->getCurrentUserId()
        );
        if (array_key_exists('title', $payload) && $payload['title'] !== null) {
            $title = trim((string)$payload['title']);
            if ($title === '') {
                throw new Exception('Case title cannot be empty');
            }
            $update['title'] = $title;
        }
        if (array_key_exists('notes', $payload) && $payload['notes'] !== null) {
            $update['notes'] = $this->nullableText($payload['notes']);
        }
        if (array_key_exists('tags', $payload) && $payload['tags'] !== null) {
            $tags = is_array($payload['tags']) ? array_values(array_filter(array_map('strval', $payload['tags']), function ($tag) {
                return trim($tag) !== '';
            })) : array();
            $update['tags_json'] = $this->jsonEncode($tags);
        }
        if (array_key_exists('expected_labels', $payload) && $payload['expected_labels'] !== null) {
            $update['expected_labels_json'] = $this->jsonEncode($this->normalizeExpectedLabels($payload['expected_labels']));
        }

        $this->db->update_by_ids('llm_eval_cases', $update, array('id' => (int)$case_id));
        $this->addPluginTransaction('update', 'llm_eval_cases', $case_id, 'LLM dataset case updated');
        return $this->getDatasetCase($case_id, $dataset_id);
    }

    /** @return array Default expected labels structure from the standard service. */
    public function getDefaultExpectedLabels()
    {
        return $this->standard_service->getDefaultExpectedLabels();
    }

    /**
     * Normalize expected labels, filling missing keys with defaults.
     *
     * @param array|null $expected_labels Raw expected labels or null for defaults.
     * @return array Normalized expected labels structure.
     */
    public function normalizeExpectedLabels($expected_labels = null)
    {
        return $this->standard_service->normalizeExpectedLabels($expected_labels);
    }

    /**
     * Delete a case from a dataset. Cleans up orphaned cases if no other links remain.
     *
     * @param int $case_id    Case primary key.
     * @param int $dataset_id Dataset ID (0 = remove from any dataset).
     * @return bool True on success.
     * @throws Exception If dataset is locked.
     */
    public function deleteDatasetCase($case_id, $dataset_id = 0)
    {
        $link = $this->getCaseLink($case_id, $dataset_id);
        if (!$link) {
            return true;
        }
        $dataset = $this->getDataset((int)$link['id_llm_eval_datasets']);
        if ($dataset && !empty($dataset['is_locked'])) {
            throw new Exception('Dataset is locked');
        }

        $link_count = $this->countCaseLinks($case_id);
        $this->db->remove_by_ids('llm_eval_dataset_case_links', array('id' => (int)$link['id']));
        $this->addPluginTransaction('delete', 'llm_eval_dataset_case_links', (int)$link['id'], 'LLM dataset case link removed');

        if ($link_count <= 1) {
            $this->db->remove_by_ids('llm_eval_cases', array('id' => (int)$case_id));
            $this->addPluginTransaction('delete', 'llm_eval_cases', $case_id, 'LLM dataset case deleted');
        }
        return true;
    }

    /** @see LlmDatasetIngestionService::addCaseFromPlaygroundRun() */
    public function addCaseFromPlaygroundRun($dataset_id, $payload) { return $this->ingestion_service->addCaseFromPlaygroundRun($dataset_id, $payload); }
    /** @see LlmDatasetIngestionService::getImportCandidates() */
    public function getImportCandidates($source_type, $limit = 50, $context = array()) { return $this->ingestion_service->getImportCandidates($source_type, $limit, $context); }
    /** @see LlmDatasetIngestionService::addCasesFromSource() */
    public function addCasesFromSource($dataset_id, $source_type, $source_ids, $context = array()) { return $this->ingestion_service->addCasesFromSource($dataset_id, $source_type, $source_ids, $context); }
    /** @see LlmDatasetAiImportParserService::parseCasesFromText() */
    public function parseCasesFromText($descriptor, $execution_profile, $raw_text, $selected_model = null, $runtime_overrides = array()) { return $this->parser_service->parseCasesFromText($descriptor, $execution_profile, $raw_text, $selected_model, $runtime_overrides); }
    /** @see LlmDatasetBatchImportService::importParsedCases() */
    public function importParsedCases($dataset_id, $descriptor, $execution_profile, $cases, $runtime_overrides = array()) { return $this->batch_import_service->importParsedCases($dataset_id, $descriptor, $execution_profile, $cases, $runtime_overrides); }

    /**
     * Move or promote test cases from one dataset to another.
     *
     * @param int   $source_dataset_id Source dataset ID.
     * @param int   $target_dataset_id Target dataset ID.
     * @param array $case_ids          Array of case IDs to move.
     * @param array $options {
     *     @type bool     $remove_source            If true, removes cases from source (move); otherwise promotes (copies).
     *     @type int|null $promoted_by_run_case_id   Run case that triggered the promotion.
     * }
     * @return array{moved_count: int, linked_count: int, removed_count: int, cases: array}
     * @throws Exception If datasets not found, locked, or have mismatched execution profiles.
     */
    public function moveDatasetCases($source_dataset_id, $target_dataset_id, $case_ids, $options = array())
    {
        $source_dataset = $this->getDataset($source_dataset_id);
        $target_dataset = $this->getDataset($target_dataset_id);
        if (!$source_dataset || !$target_dataset) {
            throw new Exception('Source or target dataset not found');
        }
        if (!empty($target_dataset['is_locked'])) {
            throw new Exception('Target dataset is locked');
        }
        if (!empty($options['remove_source']) && !empty($source_dataset['is_locked'])) {
            throw new Exception('Source dataset is locked');
        }
        if ((string)($source_dataset['execution_profile_code'] ?? '') !== (string)($target_dataset['execution_profile_code'] ?? '')) {
            throw new Exception('Datasets must share the same execution profile');
        }

        $moved = 0;
        $linked = 0;
        $removed = 0;
        $returned = array();
        foreach (array_values(array_unique(array_filter(array_map('intval', (array)$case_ids)))) as $case_id) {
            $source_link = $this->getCaseLink($case_id, (int)$source_dataset_id);
            if (!$source_link) {
                continue;
            }
            $existing_target = $this->getCaseLink($case_id, (int)$target_dataset_id);
            if (!$existing_target) {
                $this->linkCaseToDataset($target_dataset_id, $case_id, array(
                    'promoted_from_dataset_id' => (int)$source_dataset_id,
                    'promoted_by_run_case_id' => !empty($options['promoted_by_run_case_id']) ? (int)$options['promoted_by_run_case_id'] : null,
                    'promotion_mode' => !empty($options['remove_source']) ? 'move' : 'promote'
                ));
                $linked++;
            }
            if (!empty($options['remove_source'])) {
                $this->db->remove_by_ids('llm_eval_dataset_case_links', array('id' => (int)$source_link['id']));
                $this->addPluginTransaction('delete', 'llm_eval_dataset_case_links', (int)$source_link['id'], 'LLM dataset case link moved');
                $removed++;
            }
            $returned[] = $this->getDatasetCase($case_id, (int)$target_dataset_id);
            $moved++;
        }

        return array(
            'moved_count' => $moved,
            'linked_count' => $linked,
            'removed_count' => $removed,
            'cases' => $returned
        );
    }
    /**
     * List datasets compatible with a given dataset for case-move operations (same execution profile and owner scope).
     *
     * @param int   $dataset_id Reference dataset ID.
     * @param array $filters    Optional additional filters (owner_type_scope, owner_id_scope).
     * @return array List of compatible dataset rows.
     * @throws Exception If reference dataset not found.
     */
    public function listCompatibleDatasets($dataset_id, $filters = array())
    {
        $dataset = $this->getDataset($dataset_id);
        if (!$dataset) {
            throw new Exception('Dataset not found');
        }

        $filters['execution_profile'] = (string)($dataset['execution_profile_code'] ?? '');
        $filters['owner_type_scope'] = $filters['owner_type_scope'] ?? ($dataset['owner_type_scope'] ?? null);
        $filters['owner_id_scope'] = $filters['owner_id_scope'] ?? ($dataset['owner_id_scope'] ?? null);
        $filters['exclude_dataset_id'] = (int)$dataset_id;
        return $this->listDatasets($filters);
    }
    /**
     * List evaluation history for a specific test case across all runs.
     *
     * @param int $case_id Case primary key.
     * @param int $limit   Maximum rows (clamped to 1–100).
     * @return array Evaluation records with scores, normalized output, and input preview.
     * @throws Exception If case_id is invalid.
     */
    public function listCaseEvaluationHistory($case_id, $limit = 30)
    {
        $case_id = (int)$case_id;
        $limit = max(1, min((int)$limit, 100));
        if ($case_id <= 0) {
            throw new Exception('case_id is required');
        }

        $rows = $this->db->query_db(
            "SELECT rc.*, r.id AS run_id, r.target_ref_json, r.created_at AS run_created_at,
                    d.id AS dataset_id, d.name AS dataset_name,
                    c.title AS case_title, c.input_payload_json,
                    rc.id_llm_eval_cases AS case_id,
                    rc.id_llm_eval_cases AS id_llm_eval_dataset_cases
             FROM llm_eval_run_cases rc
             INNER JOIN llm_eval_runs r ON r.id = rc.id_llm_eval_runs
             LEFT JOIN llm_eval_datasets d ON d.id = r.id_llm_eval_datasets
             INNER JOIN llm_eval_cases c ON c.id = rc.id_llm_eval_cases
             WHERE rc.id_llm_eval_cases = :case_id
             ORDER BY rc.created_at DESC, rc.id DESC
             LIMIT {$limit}",
            array(':case_id' => $case_id)
        );

        $scores_by_case = $this->loadRunCaseScores(array_map(function ($row) { return (int)$row['id']; }, $rows));
        foreach ($rows as &$row) {
            $row['scores'] = $scores_by_case[(int)$row['id']] ?? array();
            $row['normalized_output'] = $this->decodeJsonColumn($row['normalized_output_json'] ?? '{}', array());
            $row['input_payload'] = $this->decodeJsonColumn($row['input_payload_json'] ?? '{}', array());
            $row['target_ref'] = $this->decodeJsonColumn($row['target_ref_json'] ?? '{}', array());
            $row['model'] = (string)($row['normalized_output']['model'] ?? '');
            $row['display_content'] = (string)($row['normalized_output']['display_content'] ?? '');
            $row['input_preview'] = $this->buildInputPreview($row['input_payload']);
            $row['status'] = $this->deriveCaseStatus($row['scores']);
        }
        unset($row);

        return $rows;
    }
    /**
     * List human-approved evaluation cases that can serve as Prompt Builder examples.
     *
     * @param array $filters {
     *     @type int    $dataset_id       Limit to a specific dataset.
     *     @type string $owner_type_scope Filter by owner type.
     *     @type int    $owner_id_scope   Filter by owner ID.
     *     @type string $search           Free-text search.
     *     @type int    $limit            Max results (clamped to 1–200, default 100).
     * }
     * @return array Approved case records with scores and payloads.
     */
    public function listEvaluationExampleCandidates($filters = array())
    {
        $params = array();
        $where = array("et.lookup_code = 'human_review'", 's.passed = 1');
        if (!empty($filters['dataset_id'])) {
            $where[] = 'r.id_llm_eval_datasets = :dataset_id';
            $params[':dataset_id'] = (int)$filters['dataset_id'];
        }
        if (!empty($filters['owner_type_scope'])) {
            $where[] = 'd.owner_type_scope = :owner_type_scope';
            $params[':owner_type_scope'] = (string)$filters['owner_type_scope'];
        }
        if (!empty($filters['owner_id_scope'])) {
            $where[] = 'd.owner_id_scope = :owner_id_scope';
            $params[':owner_id_scope'] = (int)$filters['owner_id_scope'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(c.title LIKE :search OR d.name LIKE :search OR c.case_key LIKE :search)';
            $params[':search'] = '%' . trim((string)$filters['search']) . '%';
        }
        $limit = max(1, min((int)($filters['limit'] ?? 100), 200));

        return $this->db->query_db(
            "SELECT s.id AS score_id, s.score_value_numeric, s.score_value_label, s.details_json, s.created_at AS approved_at,
                    rc.id AS run_case_id, rc.id_llm_eval_runs AS run_id,
                    rc.normalized_output_json, rc.output_payload_json,
                    c.id AS case_id, c.case_key, c.title, c.input_payload_json, c.expected_output_json, c.expected_labels_json,
                    c.notes, c.tags_json,
                    d.id AS dataset_id, d.name AS dataset_name,
                    ep.lookup_code AS execution_profile_code,
                    u.name AS approved_by_name
             FROM llm_eval_scores s
             INNER JOIN llm_eval_run_cases rc ON rc.id = s.id_llm_eval_run_cases
             INNER JOIN llm_eval_runs r ON r.id = rc.id_llm_eval_runs
             INNER JOIN llm_eval_cases c ON c.id = rc.id_llm_eval_cases
             INNER JOIN llm_eval_datasets d ON d.id = r.id_llm_eval_datasets
             INNER JOIN llm_eval_definitions ed ON ed.id = s.id_llm_eval_definitions
             INNER JOIN lookups et ON et.id = ed.id_lookups_eval_type
             LEFT JOIN lookups ep ON ep.id = c.id_lookups_execution_profile
             LEFT JOIN users u ON u.id = s.id_users_created
             WHERE " . implode(' AND ', $where) . "
               AND s.id = (
                    SELECT MAX(s2.id)
                    FROM llm_eval_scores s2
                    INNER JOIN llm_eval_run_cases rc2 ON rc2.id = s2.id_llm_eval_run_cases
                    INNER JOIN llm_eval_definitions ed2 ON ed2.id = s2.id_llm_eval_definitions
                    INNER JOIN lookups et2 ON et2.id = ed2.id_lookups_eval_type
                    WHERE rc2.id_llm_eval_cases = c.id
                      AND et2.lookup_code = 'human_review'
                      AND s2.passed = 1
               )
             ORDER BY s.created_at DESC, s.id DESC
             LIMIT {$limit}",
            $params
        );
    }
    /**
     * Map an execution profile code to its corresponding case type code.
     *
     * @param string $execution_profile Execution profile code (e.g. 'chat_runtime', 'form_runtime').
     * @return string Case type code (e.g. 'chat_case', 'text_only_case').
     */
    public function toCaseType($execution_profile) { if ($execution_profile === 'chat_runtime') return 'chat_case'; if ($execution_profile === 'form_runtime') return 'form_case'; if ($execution_profile === 'script_runtime') return 'script_case'; $extended = (string)$this->mapExecutionProfileToCaseTypeExtension($execution_profile); return $extended !== '' ? $extended : 'text_only_case'; }

    /**
     * Extension point for mapping custom execution profiles to case types.
     *
     * @param string $execution_profile Execution profile code.
     * @return string Custom case type code, or empty string for default handling.
     */
    public function mapExecutionProfileToCaseTypeExtension($execution_profile) { return ''; }

    /**
     * Build a normalized owner descriptor array from a raw descriptor.
     *
     * @param array $descriptor Raw descriptor with potentially missing keys.
     * @return array{owner_type: string, owner_id: int, prompt_slot: string, id_languages: int|null}
     */
    public function buildOwnerDescriptor($descriptor) { return array('owner_type' => (string)($descriptor['owner_type'] ?? ''), 'owner_id' => (int)($descriptor['owner_id'] ?? 0), 'prompt_slot' => (string)($descriptor['prompt_slot'] ?? ''), 'id_languages' => isset($descriptor['id_languages']) ? (int)$descriptor['id_languages'] : null); }

    /**
     * Resolve the active prompt template text for a given owner descriptor.
     *
     * @param array $descriptor Owner descriptor with owner_type and owner_id.
     * @return string Template text, or empty string if unresolvable.
     */
    public function resolvePromptTemplate($descriptor)
    {
        if (!is_array($descriptor) || empty($descriptor['owner_type']) || empty($descriptor['owner_id'])) {
            return '';
        }
        try {
            $bootstrap = $this->registry_service->bootstrapOwner($descriptor);
            return trim((string)($bootstrap['active_version']['template_raw'] ?? ''));
        } catch (Exception $e) {
            return '';
        }
    }
    /**
     * Extract `{{placeholder}}` variable names from a prompt template.
     *
     * @param string $template Prompt template text.
     * @return string[] Unique placeholder names in order of first appearance.
     */
    public function extractPromptPlaceholders($template)
    {
        $template = (string)$template;
        if ($template === '') {
            return array();
        }
        if (!preg_match_all('/\{\{(\w+)\}\}/', $template, $matches)) {
            return array();
        }
        return array_values(array_unique(array_map(function ($key) { return trim((string)$key); }, $matches[1] ?? array())));
    }
    /**
     * Safely decode a JSON column value with a fallback for empty or invalid data.
     *
     * @param string|null $value    Raw JSON string from DB.
     * @param mixed       $fallback Default returned when decoding fails.
     * @return mixed Decoded value or fallback.
     */
    public function decodeJsonColumn($value, $fallback) { if (!is_string($value) || trim($value) === '') return $fallback; $decoded = $this->jsonDecode($value); return $decoded !== null ? $decoded : $fallback; }

    /**
     * Parse "key: value" line format into an associative array, normalizing keys to snake_case.
     *
     * @param string $text Multi-line text with colon-separated key-value pairs.
     * @return array<string, string> Parsed key-value pairs.
     */
    public function parseFieldLines($text) { $parsed = array(); foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) { $line = trim((string)$line); if ($line === '' || strpos($line, ':') === false) continue; list($raw_key, $raw_value) = explode(':', $line, 2); $key = trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string)$raw_key))), '_'); $value = trim((string)$raw_value); if ($key !== '' && $value !== '') $parsed[$key] = $value; } return $parsed; }

    /**
     * Normalize a raw messages array to contain only valid system/user/assistant messages.
     *
     * @param array $messages Raw message array, each element having 'role' and 'content'.
     * @return array<int, array{role: string, content: string}> Cleaned messages.
     */
    public function normalizeMessages($messages) { $normalized = array(); foreach ((array)$messages as $message) { if (!is_array($message)) continue; $role = (string)($message['role'] ?? ''); $content = trim((string)($message['content'] ?? '')); if (!in_array($role, array('system', 'user', 'assistant'), true) || $content === '') continue; $normalized[] = array('role' => $role, 'content' => $content); } return $normalized; }

    /**
     * Extract the last user message content from a message history.
     *
     * @param array $messages Message history array.
     * @return string Last user message text, or empty string if none found.
     */
    public function extractLastUserMessage($messages) { foreach (array_reverse($this->normalizeMessages($messages)) as $message) { if (($message['role'] ?? '') === 'user') return (string)($message['content'] ?? ''); } return ''; }

    /**
     * Link an existing evaluation case to a dataset (many-to-many via junction table).
     *
     * @param int   $dataset_id Target dataset ID.
     * @param int   $case_id    Case ID to link.
     * @param array $payload    Optional promotion metadata (sort_order, promoted_from_dataset_id, etc.).
     * @return array The link row from llm_eval_dataset_case_links.
     * @throws Exception If dataset/case not found or execution profiles mismatch.
     */
    private function linkCaseToDataset($dataset_id, $case_id, $payload = array())
    {
        $existing = $this->getCaseLink($case_id, $dataset_id);
        if ($existing) {
            return $existing;
        }

        $dataset = $this->getDataset($dataset_id);
        $case = $this->getDatasetCase($case_id);
        if (!$dataset || !$case) {
            throw new Exception('Dataset or case not found');
        }
        if ((string)($dataset['execution_profile_code'] ?? '') !== (string)($case['execution_profile_code'] ?? '')) {
            throw new Exception('Case execution profile must match dataset profile');
        }

        $link_id = $this->db->insert('llm_eval_dataset_case_links', array(
            'id_llm_eval_datasets' => (int)$dataset_id,
            'id_llm_eval_cases' => (int)$case_id,
            'sort_order' => isset($payload['sort_order']) && $payload['sort_order'] !== null ? (int)$payload['sort_order'] : null,
            'promoted_from_dataset_id' => !empty($payload['promoted_from_dataset_id']) ? (int)$payload['promoted_from_dataset_id'] : null,
            'promoted_by_run_case_id' => !empty($payload['promoted_by_run_case_id']) ? (int)$payload['promoted_by_run_case_id'] : null,
            'promotion_mode' => $this->nullableText($payload['promotion_mode'] ?? null),
            'promoted_at' => !empty($payload['promoted_from_dataset_id']) ? date('Y-m-d H:i:s') : null,
            'id_users_created' => $this->getCurrentUserId(),
            'id_users_updated' => $this->getCurrentUserId(),
        ));
        $this->addPluginTransaction('insert', 'llm_eval_dataset_case_links', $link_id, 'LLM dataset case linked');
        return $this->db->select_by_uid('llm_eval_dataset_case_links', $link_id);
    }

    /**
     * Retrieve the junction-table link row for a case, optionally scoped to a dataset.
     *
     * @param int $case_id    Case primary key.
     * @param int $dataset_id Optional dataset scope (0 = any dataset).
     * @return array|null Link row or null if not found.
     */
    private function getCaseLink($case_id, $dataset_id = 0)
    {
        $params = array(':case_id' => (int)$case_id);
        $where = 'id_llm_eval_cases = :case_id';
        if ((int)$dataset_id > 0) {
            $where .= ' AND id_llm_eval_datasets = :dataset_id';
            $params[':dataset_id'] = (int)$dataset_id;
        }
        return $this->db->query_db_first(
            "SELECT * FROM llm_eval_dataset_case_links WHERE {$where} ORDER BY id DESC LIMIT 1",
            $params
        );
    }

    /**
     * Count how many datasets reference a given case.
     *
     * @param int $case_id Case primary key.
     * @return int Number of dataset links.
     */
    private function countCaseLinks($case_id)
    {
        $row = $this->db->query_db_first(
            'SELECT COUNT(*) AS total FROM llm_eval_dataset_case_links WHERE id_llm_eval_cases = :case_id',
            array(':case_id' => (int)$case_id)
        );
        return (int)($row['total'] ?? 0);
    }

    /**
     * Delete cases that are no longer linked to any dataset (orphan cleanup).
     *
     * @param array $case_ids Case IDs to check; only truly orphaned ones are deleted.
     * @return void
     */
    private function cleanupOrphanCases($case_ids)
    {
        foreach (array_values(array_unique(array_filter(array_map('intval', (array)$case_ids)))) as $case_id) {
            if ($this->countCaseLinks($case_id) > 0) {
                continue;
            }
            $this->db->remove_by_ids('llm_eval_cases', array('id' => $case_id));
            $this->addPluginTransaction('delete', 'llm_eval_cases', $case_id, 'LLM orphan evaluation case deleted');
        }
    }

    /**
     * Reverse-map a case type code to its execution profile code.
     *
     * @param string $case_type Case type code (e.g. 'chat_case').
     * @return string Execution profile code (e.g. 'chat_runtime', 'text_only').
     */
    private function mapCaseTypeToExecutionProfile($case_type)
    {
        $case_type = (string)$case_type;
        if ($case_type === 'chat_case') {
            return 'chat_runtime';
        }
        if ($case_type === 'form_case') {
            return 'form_runtime';
        }
        if ($case_type === 'script_case') {
            return 'script_runtime';
        }
        return 'text_only';
    }

    /**
     * Batch-load evaluation scores for multiple run cases, grouped by run_case ID.
     *
     * @param array $run_case_ids Run case primary keys.
     * @return array<int, array> Map of run_case_id => array of score rows (with decoded details).
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
            $row['details'] = $this->decodeJsonColumn($row['details_json'] ?? '{}', array());
            $grouped[(int)$row['id_llm_eval_run_cases']][] = $row;
        }
        return $grouped;
    }

    /**
     * Derive a composite pass/fail/pending status from a set of scores.
     *
     * @param array $scores Score rows with 'passed' field (1, 0, or null).
     * @return string 'failed', 'pending_review', or 'passed'.
     */
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
     * Build a short human-readable preview string from a case's input payload,
     * trying trigger_message, then form_data/variables, then last user message.
     *
     * @param array|null $input_payload Decoded input payload.
     * @return string Truncated preview text (max ~220 chars), or empty string.
     */
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
     * Flatten a single input value to a displayable string for previews.
     *
     * @param mixed $value Scalar, array, or null.
     * @return string Stringified value (JSON-encoded if array), truncated to 140 chars.
     */
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

    /**
     * Truncate text to a maximum length, appending '...' when trimmed.
     *
     * @param string $text    Input text.
     * @param int    $max_len Maximum allowed length.
     * @return string Truncated text.
     */
    private function truncateText($text, $max_len)
    {
        $text = trim((string)$text);
        if ($text === '' || strlen($text) <= (int)$max_len) {
            return $text;
        }
        return substr($text, 0, max(0, (int)$max_len - 3)) . '...';
    }

    /**
     * Resolve a lookup code to its numeric ID, throwing on failure.
     *
     * @param string $type_code   Lookup type category.
     * @param string $lookup_code Lookup code within the category.
     * @param string $label       Human label for error messages.
     * @return int Lookup ID.
     * @throws Exception If lookup code not found.
     */
    private function lookupId($type_code, $lookup_code, $label)
    {
        $lookup_id = $this->db->get_lookup_id_by_code($type_code, $lookup_code);
        if (!$lookup_id) {
            throw new Exception('Invalid ' . $label);
        }
        return $lookup_id;
    }

    /**
     * Coerce a value to a trimmed string, returning null for empty values.
     *
     * @param string|null $value Raw input.
     * @return string|null Trimmed non-empty string, or null.
     */
    private function nullableText($value)
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    /**
     * JSON-encode a specific key from a payload if present and non-null.
     *
     * @param array  $payload Associative array of values.
     * @param string $key     Key to extract and encode.
     * @return string|null JSON string or null if key absent/null.
     */
    private function encodeOptionalJson($payload, $key)
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }
        return $this->jsonEncode($payload[$key]);
    }
}
?>
