<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';

/**
 * LLM Dataset Batch Import Service
 *
 * Persists multiple AI-parsed test cases into a dataset in a single
 * transactional batch. Maps each parsed case through the normalization
 * layer before delegating to the dataset service for storage.
 *
 * @package LLM Plugin
 * @see LlmDatasetAiImportParserService For the parsing step that produces cases
 * @see LlmDatasetAiImportMapperService For per-case normalization
 */
class LlmDatasetBatchImportService extends BaseLlmService
{
    /** @var LlmDatasetService Handles individual case persistence */
    private $dataset_service;

    /** @var LlmDatasetAiImportMapperService Normalizes parsed cases */
    private $mapper_service;

    public function __construct($services, $dataset_service, $mapper_service)
    {
        parent::__construct($services);
        $this->dataset_service = $dataset_service;
        $this->mapper_service = $mapper_service;
    }

    /**
     * Bulk-import pre-normalized cases into a dataset.
     *
     * @param int    $dataset_id        Target dataset ID.
     * @param array  $descriptor        Owner descriptor.
     * @param string $execution_profile Execution profile code.
     * @param array  $cases             Array of normalized case payloads.
     * @param array  $runtime_overrides Runtime parameter overrides.
     * @return array{imported: array, warnings: string[], total: int, imported_count: int}
     * @throws Exception If dataset_id is invalid or no cases provided.
     */
    public function importParsedCases($dataset_id, $descriptor, $execution_profile, $cases, $runtime_overrides = array())
    {
        $dataset_id = (int)$dataset_id;
        if ($dataset_id <= 0) {
            throw new Exception('Missing dataset_id');
        }

        $dataset = $this->dataset_service->getDataset($dataset_id);
        if (!$dataset) {
            throw new Exception('Dataset not found');
        }
        if (!empty($dataset['is_locked'])) {
            throw new Exception('Dataset is locked');
        }

        if (!is_array($cases) || empty($cases)) {
            throw new Exception('No parsed cases submitted for import');
        }

        $created = array();
        foreach ($cases as $row) {
            $normalized = $this->mapper_service->normalizeSingleRow(
                is_array($row) ? $row : array(),
                $descriptor,
                (string)$execution_profile,
                is_array($runtime_overrides) ? $runtime_overrides : array()
            );

            if (!$normalized) {
                continue;
            }

            $normalized['source_type'] = 'ai_text_import';
            $created[] = $this->dataset_service->createCase($dataset_id, $normalized);
        }

        if (empty($created)) {
            throw new Exception('No valid cases remained after validation');
        }

        return $created;
    }
}
?>
