<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmDatasetIngestionService.php';

class LlmDatasetService extends BaseLlmService
{
    private $ingestion_service;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->ingestion_service = new LlmDatasetIngestionService($services, $this);
    }

    public function listDatasets($filters = array())
    {
        $where = array('1=1');
        $params = array();

        if (!empty($filters['search'])) {
            $where[] = '(d.name LIKE :search OR d.description LIKE :search)';
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

        return $this->db->query_db(
            "SELECT d.*, ds.lookup_code AS dataset_type_code, ep.lookup_code AS execution_profile_code,
                    u_created.name AS created_user_name,
                    (SELECT COUNT(*) FROM llm_eval_dataset_cases c WHERE c.id_llm_eval_datasets = d.id) AS cases_count
             FROM llm_eval_datasets d
             LEFT JOIN lookups ds ON ds.id = d.id_lookups_dataset_type
             LEFT JOIN lookups ep ON ep.id = d.id_lookups_execution_profile
             LEFT JOIN users u_created ON u_created.id = d.id_users_created
             WHERE " . implode(' AND ', $where) . "
             ORDER BY d.updated_at DESC, d.id DESC",
            $params
        );
    }

    public function getDataset($dataset_id)
    {
        return $this->db->query_db_first(
            "SELECT d.*, ds.lookup_code AS dataset_type_code, ep.lookup_code AS execution_profile_code
             FROM llm_eval_datasets d
             LEFT JOIN lookups ds ON ds.id = d.id_lookups_dataset_type
             LEFT JOIN lookups ep ON ep.id = d.id_lookups_execution_profile
             WHERE d.id = :id LIMIT 1",
            array(':id' => (int)$dataset_id)
        );
    }

    public function getDatasetCase($case_id)
    {
        return $this->db->query_db_first(
            "SELECT c.*, ct.lookup_code AS case_type_code, st.lookup_code AS source_type_code
             FROM llm_eval_dataset_cases c
             LEFT JOIN lookups ct ON ct.id = c.id_lookups_case_type
             LEFT JOIN lookups st ON st.id = c.id_lookups_source_type
             WHERE c.id = :id LIMIT 1",
            array(':id' => (int)$case_id)
        );
    }

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

    public function listDatasetCases($dataset_id)
    {
        return $this->db->query_db(
            "SELECT c.*, ct.lookup_code AS case_type_code, st.lookup_code AS source_type_code, u_created.name AS created_user_name
             FROM llm_eval_dataset_cases c
             LEFT JOIN lookups ct ON ct.id = c.id_lookups_case_type
             LEFT JOIN lookups st ON st.id = c.id_lookups_source_type
             LEFT JOIN users u_created ON u_created.id = c.id_users_created
             WHERE c.id_llm_eval_datasets = :dataset_id
             ORDER BY c.updated_at DESC, c.id DESC",
            array(':dataset_id' => (int)$dataset_id)
        );
    }

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

        $case_key = trim((string)($payload['case_key'] ?? ''));
        if ($case_key === '') {
            $case_key = substr(hash('sha256', json_encode($input_payload) . microtime(true)), 0, 24);
        }
        $title = trim((string)($payload['title'] ?? ''));
        if ($title === '') {
            $title = 'Case ' . $case_key;
        }

        $case_id = $this->db->insert('llm_eval_dataset_cases', array(
            'id_llm_eval_datasets' => (int)$dataset_id,
            'case_key' => $case_key,
            'id_lookups_case_type' => $this->lookupId('llm_eval_case_types', (string)($payload['case_type'] ?? 'text_only_case'), 'case type'),
            'title' => $title,
            'input_payload_json' => $this->jsonEncode($input_payload),
            'expected_output_json' => $this->encodeOptionalJson($payload, 'expected_output'),
            'expected_labels_json' => $this->encodeOptionalJson($payload, 'expected_labels'),
            'id_lookups_source_type' => $this->lookupId('llm_eval_source_types', (string)($payload['source_type'] ?? 'manual_entry'), 'source type'),
            'source_ref_json' => $this->encodeOptionalJson($payload, 'source_ref'),
            'tags_json' => $this->jsonEncode(is_array($payload['tags'] ?? null) ? array_values($payload['tags']) : array()),
            'notes' => $this->nullableText($payload['notes'] ?? null),
            'id_users_created' => $this->getCurrentUserId(),
            'id_users_updated' => $this->getCurrentUserId()
        ));

        $this->addPluginTransaction('insert', 'llm_eval_dataset_cases', $case_id, 'LLM dataset case created');
        return $this->getDatasetCase($case_id);
    }

    public function deleteDatasetCase($case_id)
    {
        $case = $this->getDatasetCase($case_id);
        if (!$case) {
            return true;
        }
        $dataset = $this->getDataset((int)$case['id_llm_eval_datasets']);
        if ($dataset && !empty($dataset['is_locked'])) {
            throw new Exception('Dataset is locked');
        }

        $this->db->remove_by_ids('llm_eval_dataset_cases', array('id' => (int)$case_id));
        $this->addPluginTransaction('delete', 'llm_eval_dataset_cases', $case_id, 'LLM dataset case deleted');
        return true;
    }

    public function addCaseFromPlaygroundRun($dataset_id, $payload) { return $this->ingestion_service->addCaseFromPlaygroundRun($dataset_id, $payload); }
    public function getImportCandidates($source_type, $limit = 50, $context = array()) { return $this->ingestion_service->getImportCandidates($source_type, $limit, $context); }
    public function addCasesFromSource($dataset_id, $source_type, $source_ids, $context = array()) { return $this->ingestion_service->addCasesFromSource($dataset_id, $source_type, $source_ids, $context); }
    public function toCaseType($execution_profile) { if ($execution_profile === 'chat_runtime' || $execution_profile === 'therapy_chat_runtime') return 'chat_case'; if ($execution_profile === 'form_runtime') return 'form_case'; if ($execution_profile === 'script_runtime') return 'script_case'; return 'text_only_case'; }
    public function buildOwnerDescriptor($descriptor) { return array('owner_type' => (string)($descriptor['owner_type'] ?? ''), 'owner_id' => (int)($descriptor['owner_id'] ?? 0), 'prompt_slot' => (string)($descriptor['prompt_slot'] ?? ''), 'id_languages' => isset($descriptor['id_languages']) ? (int)$descriptor['id_languages'] : null); }
    public function decodeJsonColumn($value, $fallback) { if (!is_string($value) || trim($value) === '') return $fallback; $decoded = $this->jsonDecode($value); return $decoded !== null ? $decoded : $fallback; }
    public function parseFieldLines($text) { $parsed = array(); foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) { $line = trim((string)$line); if ($line === '' || strpos($line, ':') === false) continue; list($raw_key, $raw_value) = explode(':', $line, 2); $key = trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string)$raw_key))), '_'); $value = trim((string)$raw_value); if ($key !== '' && $value !== '') $parsed[$key] = $value; } return $parsed; }
    public function normalizeMessages($messages) { $normalized = array(); foreach ((array)$messages as $message) { if (!is_array($message)) continue; $role = (string)($message['role'] ?? ''); $content = trim((string)($message['content'] ?? '')); if (!in_array($role, array('system', 'user', 'assistant'), true) || $content === '') continue; $normalized[] = array('role' => $role, 'content' => $content); } return $normalized; }
    public function extractLastUserMessage($messages) { foreach (array_reverse($this->normalizeMessages($messages)) as $message) { if (($message['role'] ?? '') === 'user') return (string)($message['content'] ?? ''); } return ''; }

    private function lookupId($type_code, $lookup_code, $label)
    {
        $lookup_id = $this->db->get_lookup_id_by_code($type_code, $lookup_code);
        if (!$lookup_id) {
            throw new Exception('Invalid ' . $label);
        }
        return $lookup_id;
    }

    private function nullableText($value)
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    private function encodeOptionalJson($payload, $key)
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }
        return $this->jsonEncode($payload[$key]);
    }
}
?>
