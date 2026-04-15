<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';

/**
 * LLM Dataset Ingestion Service
 *
 * Handles the creation of dataset test cases from various sources:
 * - Playground runs (manual prompt testing results)
 * - Form submissions (user-facing form data)
 * - Conversation messages (chat history excerpts)
 * - Script executions (automated LLM script outputs)
 * - Manual entry and bulk CSV/JSON imports
 * - AI-assisted imports (LLM-parsed unstructured data)
 *
 * Each method normalizes source data into the standard case schema
 * (input_payload, expected_output, source_ref, provenance) before
 * delegating persistence to LlmDatasetService.
 *
 * @package LLM Plugin
 * @see LlmDatasetService For case persistence and dataset CRUD
 */
class LlmDatasetIngestionService extends BaseLlmService
{
    /** @var LlmDatasetService Parent dataset service for persistence */
    private $dataset_service;

    /**
     * @param object $services SelfHelp services container
     * @param LlmDatasetService $dataset_service Parent dataset service
     */
    public function __construct($services, $dataset_service)
    {
        parent::__construct($services);
        $this->dataset_service = $dataset_service;
    }

    /**
     * Create a dataset case from a playground run result, normalizing the run metadata into the standard case schema.
     *
     * @param int   $dataset_id Target dataset ID.
     * @param array $payload {
     *     @type array       $descriptor           Prompt owner descriptor.
     *     @type array       $message_history       Conversation messages.
     *     @type array       $variables             Template variables used.
     *     @type array       $runtime_overrides     Model parameter overrides.
     *     @type int|null    $id_llm_prompt_playground_runs  Source run ID.
     *     @type int|null    $id_llmConversations   Conversation ID.
     *     @type int|null    $id_llmMessages_request Request message ID.
     *     @type int|null    $id_llmMessages_response Response message ID.
     *     @type string|null $title                 Case title.
     *     @type array|null  $expected_output       Expected output for evaluation.
     *     @type array|null  $expected_labels       Expected labels for evaluation.
     *     @type array|null  $tags                  Tag strings.
     *     @type string|null $notes                 Freetext notes.
     * }
     * @return array Created case row.
     */
    public function addCaseFromPlaygroundRun($dataset_id, $payload)
    {
        $descriptor = is_array($payload['descriptor'] ?? null) ? $payload['descriptor'] : array();
        $message_history = $this->dataset_service->normalizeMessages($payload['message_history'] ?? array());
        $input_payload = array(
            'execution_profile' => (string)($payload['execution_profile'] ?? 'text_only'),
            'owner_descriptor' => $this->dataset_service->buildOwnerDescriptor($descriptor),
            'message_history' => $message_history,
            'trigger_message' => $this->dataset_service->extractLastUserMessage($message_history),
            'variables' => is_array($payload['variables'] ?? null) ? $payload['variables'] : array(),
            'runtime_overrides' => is_array($payload['runtime_overrides'] ?? null) ? $payload['runtime_overrides'] : array(),
            'source_context' => array(
                'id_llm_prompt_playground_runs' => !empty($payload['id_llm_prompt_playground_runs']) ? (int)$payload['id_llm_prompt_playground_runs'] : null,
                'id_llmConversations' => !empty($payload['id_llmConversations']) ? (int)$payload['id_llmConversations'] : null,
                'id_llmMessages_request' => !empty($payload['id_llmMessages_request']) ? (int)$payload['id_llmMessages_request'] : null,
                'id_llmMessages_response' => !empty($payload['id_llmMessages_response']) ? (int)$payload['id_llmMessages_response'] : null
            )
        );

        return $this->dataset_service->createCase($dataset_id, array(
            'title' => $payload['title'] ?? '',
            'case_type' => $this->dataset_service->toCaseType($input_payload['execution_profile']),
            'source_type' => 'playground_run',
            'input_payload' => $input_payload,
            'expected_output' => $payload['expected_output'] ?? null,
            'expected_labels' => $payload['expected_labels'] ?? null,
            'source_ref' => $input_payload['source_context'],
            'tags' => is_array($payload['tags'] ?? null) ? $payload['tags'] : array(),
            'notes' => $payload['notes'] ?? ''
        ));
    }

    /**
     * List candidate records from a given source type that can be imported as dataset cases.
     *
     * @param string $source_type One of: 'playground_run', 'form_submission', 'conversation_message', 'script_run'.
     * @param int    $limit       Max candidates (clamped to 1–100).
     * @param array  $context     Filter context with 'descriptor', 'allowed_page_id', 'allow_script_source'.
     * @return array List of candidate rows with preview_text and assistant_preview fields.
     * @throws Exception If source_type is unsupported.
     */
    public function getImportCandidates($source_type, $limit = 50, $context = array())
    {
        $limit = max(1, min((int)$limit, 100));
        $descriptor = is_array($context['descriptor'] ?? null) ? $context['descriptor'] : array();
        $allowed_page_id = isset($context['allowed_page_id']) ? (int)$context['allowed_page_id'] : 0;
        $allow_script_source = !empty($context['allow_script_source']);

        if ($source_type === 'playground_run') {
            return $this->decorateImportCandidates('playground_run', $this->listPlaygroundRunCandidates($limit, $descriptor, $allowed_page_id));
        }
        if ($source_type === 'form_submission') {
            return $this->isStyleOwner($descriptor) ? $this->decorateImportCandidates('form_submission', $this->listFormSubmissionCandidates($limit, $descriptor, $allowed_page_id)) : array();
        }
        if ($source_type === 'conversation_message') {
            return ($this->isStyleOwner($descriptor) || $this->isScriptOwner($descriptor))
                ? $this->decorateImportCandidates('conversation_message', $this->listConversationCandidates($limit, $descriptor, $allowed_page_id))
                : array();
        }
        if ($source_type === 'script_run') {
            return $allow_script_source ? $this->decorateImportCandidates('script_run', $this->listScriptCandidates($limit, $descriptor)) : array();
        }

        throw new Exception('Unsupported source type for import candidates');
    }

    /**
     * Bulk-import cases from a specific source type by their IDs.
     *
     * @param int    $dataset_id  Target dataset ID.
     * @param string $source_type One of: 'playground_run', 'form_submission', 'conversation_message', 'script_run'.
     * @param array  $source_ids  Source record IDs to import.
     * @param array  $context     Filter context with 'descriptor', 'execution_profile', 'runtime_overrides', etc.
     * @return array List of created case rows.
     * @throws Exception If no cases could be imported or source_type is unsupported.
     */
    public function addCasesFromSource($dataset_id, $source_type, $source_ids, $context = array())
    {
        $created = array();
        $attempted = 0;
        $descriptor = is_array($context['descriptor'] ?? null) ? $context['descriptor'] : array();
        $execution_profile = (string)($context['execution_profile'] ?? 'text_only');
        $runtime_overrides = is_array($context['runtime_overrides'] ?? null) ? $context['runtime_overrides'] : array();
        $allowed_page_id = isset($context['allowed_page_id']) ? (int)$context['allowed_page_id'] : 0;
        $allow_script_source = !empty($context['allow_script_source']);

        foreach ((array)$source_ids as $source_id) {
            $source_id = (int)$source_id;
            if ($source_id <= 0) {
                continue;
            }
            $attempted++;

            if ($source_type === 'playground_run') {
                $case = $this->importPlaygroundRunCase($dataset_id, $source_id, $descriptor, $execution_profile, $runtime_overrides, $allowed_page_id);
            } elseif ($source_type === 'form_submission') {
                $case = $this->importFormSubmissionCase($dataset_id, $source_id, $descriptor, $runtime_overrides, $allowed_page_id);
            } elseif ($source_type === 'conversation_message') {
                $case = $this->importConversationCase($dataset_id, $source_id, $descriptor, $execution_profile, $runtime_overrides, $allowed_page_id);
            } elseif ($source_type === 'script_run') {
                $case = $allow_script_source ? $this->importScriptFixtureCase($dataset_id, $source_id, $descriptor, $runtime_overrides) : null;
            } else {
                throw new Exception('Unsupported dataset import source type');
            }

            if ($case) {
                $created[] = $case;
            }
        }

        if ($attempted > 0 && empty($created)) {
            throw new Exception(
                'No cases were imported for source "' . $source_type . '". ' .
                'Selected records may be outside scope, missing owner metadata, or unavailable.'
            );
        }

        return $created;
    }

    /**
     * Query playground run candidates scoped by descriptor or page access.
     *
     * @param int   $limit           Max rows.
     * @param array $descriptor      Owner descriptor for scope filtering.
     * @param int   $allowed_page_id Fallback page scope from ACL.
     * @return array Raw DB rows with request/response content.
     */
    private function listPlaygroundRunCandidates($limit, $descriptor, $allowed_page_id)
    {
        $params = array();
        $where = array('1=1');
        $owner_type_id = $this->resolveOwnerTypeLookupId($descriptor);
        $owner_id = (int)($descriptor['owner_id'] ?? 0);

        if ($owner_type_id > 0 && $owner_id > 0) {
            $where[] = 'pe.id_llm_prompt_owner_types = :owner_type_id';
            $where[] = 'pe.owner_id = :owner_id';
            $params[':owner_type_id'] = $owner_type_id;
            $params[':owner_id'] = $owner_id;
            if (!empty($descriptor['prompt_slot'])) {
                $where[] = 'pe.prompt_slot = :prompt_slot';
                $params[':prompt_slot'] = (string)$descriptor['prompt_slot'];
            }
        } elseif ($allowed_page_id > 0) {
            $where[] = 'ps.id_pages = :allowed_page_id';
            $params[':allowed_page_id'] = $allowed_page_id;
        }

        return $this->db->query_db(
            "SELECT pr.id, pr.created_at, pr.id_llmConversations, pr.id_llmMessages_request, pr.id_llmMessages_response,
                    req.content AS request_content, res.content AS response_content
             FROM llm_prompt_playground_runs pr
             LEFT JOIN llm_prompt_entries pe ON pe.id = pr.id_llm_prompt_entries
             LEFT JOIN llmMessages req ON req.id = pr.id_llmMessages_request
             LEFT JOIN llmMessages res ON res.id = pr.id_llmMessages_response
             LEFT JOIN llmConversations lc ON lc.id = pr.id_llmConversations
             LEFT JOIN sections sec ON sec.id = lc.id_sections
             LEFT JOIN pages_sections ps ON ps.id_sections = sec.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY pr.created_at DESC LIMIT {$limit}",
            $params
        );
    }

    /**
     * Query form-submission (user message) candidates, filtering to messages that have dataRow links or parseable field lines.
     *
     * @param int   $limit           Max results.
     * @param array $descriptor      Owner descriptor for section scope.
     * @param int   $allowed_page_id Fallback page scope.
     * @return array Filtered rows with parseable form data.
     */
    private function listFormSubmissionCandidates($limit, $descriptor, $allowed_page_id)
    {
        $params = array();
        $where = array("m.role = 'user'");
        if (!empty($descriptor['owner_id'])) {
            $where[] = 'lc.id_sections = :section_id';
            $params[':section_id'] = (int)$descriptor['owner_id'];
        } elseif ($allowed_page_id > 0) {
            $where[] = 'ps.id_pages = :allowed_page_id';
            $params[':allowed_page_id'] = $allowed_page_id;
        }

        $rows = $this->db->query_db(
            "SELECT m.id, m.id_llmConversations, m.id_dataRows, m.timestamp AS created_at, m.content
             FROM llmMessages m
             LEFT JOIN llmConversations lc ON lc.id = m.id_llmConversations
             LEFT JOIN sections sec ON sec.id = lc.id_sections
             LEFT JOIN pages_sections ps ON ps.id_sections = sec.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY m.timestamp DESC LIMIT " . (int)($limit * 4),
            $params
        );

        $filtered = array();
        foreach ($rows as $row) {
            if (!empty($row['id_dataRows'])) {
                $filtered[] = $row;
                continue;
            }
            $parsed = $this->dataset_service->parseFieldLines((string)($row['content'] ?? ''));
            if (!empty($parsed)) {
                $filtered[] = $row;
            }
            if (count($filtered) >= $limit) {
                break;
            }
        }

        return $filtered;
    }

    /**
     * Query conversation user-message candidates scoped by section, script, or page.
     *
     * @param int   $limit           Max results.
     * @param array $descriptor      Owner descriptor.
     * @param int   $allowed_page_id Fallback page scope.
     * @return array Raw message rows.
     */
    private function listConversationCandidates($limit, $descriptor, $allowed_page_id)
    {
        $params = array();
        $where = array("m.role = 'user'");
        if (!empty($descriptor['owner_id']) && $this->isStyleOwner($descriptor)) {
            $where[] = 'lc.id_sections = :section_id';
            $params[':section_id'] = (int)$descriptor['owner_id'];
        } elseif (!empty($descriptor['owner_id']) && $this->isScriptOwner($descriptor)) {
            $where[] = 'lc.id_llm_scripts = :script_id';
            $params[':script_id'] = (int)$descriptor['owner_id'];
        } elseif ($allowed_page_id > 0) {
            $where[] = 'ps.id_pages = :allowed_page_id';
            $params[':allowed_page_id'] = $allowed_page_id;
        }

        return $this->db->query_db(
            "SELECT m.id, m.id_llmConversations, m.timestamp AS created_at, m.role, m.content, lc.id_llm_scripts
             FROM llmMessages m
             LEFT JOIN llmConversations lc ON lc.id = m.id_llmConversations
             LEFT JOIN sections sec ON sec.id = lc.id_sections
             LEFT JOIN pages_sections ps ON ps.id_sections = sec.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY m.timestamp DESC LIMIT {$limit}",
            $params
        );
    }

    /**
     * Query script fixture candidates (llm_scripts table), optionally scoped to a specific script ID.
     *
     * @param int   $limit      Max results.
     * @param array $descriptor Owner descriptor.
     * @return array Script rows.
     */
    private function listScriptCandidates($limit, $descriptor)
    {
        $params = array();
        $where = array('1=1');
        if (!empty($descriptor['owner_id'])) {
            $where[] = 's.id = :script_id';
            $params[':script_id'] = (int)$descriptor['owner_id'];
        }

        return $this->db->query_db(
            "SELECT s.id, s.name, s.model, s.updated_at
             FROM llm_scripts s
             WHERE " . implode(' AND ', $where) . "
             ORDER BY s.updated_at DESC LIMIT {$limit}",
            $params
        );
    }

    /** @return int Numeric lookup ID for the descriptor's owner_type, or 0 if unresolved. */
    private function resolveOwnerTypeLookupId($descriptor)
    {
        if (empty($descriptor['owner_type'])) {
            return 0;
        }
        $id = $this->db->get_lookup_id_by_code('llm_prompt_owner_types', (string)$descriptor['owner_type']);
        return $id ? (int)$id : 0;
    }

    /** @return bool Whether the descriptor's owner_type is a CMS style field. */
    private function isStyleOwner($descriptor)
    {
        return ($descriptor['owner_type'] ?? '') === LLM_PROMPT_OWNER_STYLE_FIELD;
    }

    /** @return bool Whether the descriptor's owner_type is an LLM script. */
    private function isScriptOwner($descriptor)
    {
        return ($descriptor['owner_type'] ?? '') === LLM_PROMPT_OWNER_SCRIPT;
    }

    /**
     * Import a single playground run as a dataset case after scope validation.
     *
     * @return array|null Created case row, or null if out of scope.
     */
    private function importPlaygroundRunCase($dataset_id, $source_id, $descriptor, $execution_profile, $runtime_overrides, $allowed_page_id)
    {
        $row = $this->db->query_db_first(
            "SELECT pr.*, pe.owner_id AS prompt_owner_id, pe.id_llm_prompt_owner_types AS prompt_owner_type_id, lc.id_sections,
                    ps.id_pages AS source_page_id, req.content AS request_content, res.content AS response_content
             FROM llm_prompt_playground_runs pr
             LEFT JOIN llm_prompt_entries pe ON pe.id = pr.id_llm_prompt_entries
             LEFT JOIN llmConversations lc ON lc.id = pr.id_llmConversations
             LEFT JOIN sections sec ON sec.id = lc.id_sections
             LEFT JOIN pages_sections ps ON ps.id_sections = sec.id
             LEFT JOIN llmMessages req ON req.id = pr.id_llmMessages_request
             LEFT JOIN llmMessages res ON res.id = pr.id_llmMessages_response
             WHERE pr.id = :id LIMIT 1",
            array(':id' => $source_id)
        );
        if (!$row || !$this->matchesDescriptorScope($row, $descriptor, $allowed_page_id)) {
            return null;
        }

        $variables = $this->dataset_service->decodeJsonColumn($row['variables_json'] ?? '{}', array());
        if (!is_array($variables)) {
            $variables = array();
        }
        $run_snapshot = $this->dataset_service->decodeJsonColumn($row['config_snapshot_json'] ?? '{}', array());
        $message_history = array();
        $request_content = trim((string)($row['request_content'] ?? ''));
        if ($request_content !== '') {
            $message_history[] = array('role' => 'user', 'content' => $request_content);
        }

        return $this->dataset_service->createCase($dataset_id, array(
            'title' => $this->buildImportedCaseTitle('Playground run', (string)($row['request_content'] ?? ''), (int)$row['id']),
            'case_type' => $this->dataset_service->toCaseType($execution_profile),
            'source_type' => 'playground_run',
            'input_payload' => array(
                'execution_profile' => $execution_profile,
                'owner_descriptor' => $this->dataset_service->buildOwnerDescriptor($descriptor),
                'message_history' => $message_history,
                'trigger_message' => $this->dataset_service->extractLastUserMessage($message_history),
                'variables' => $variables,
                'runtime_overrides' => $this->mergeRuntimeOverrides($run_snapshot, $runtime_overrides),
                'source_context' => array(
                    'id_llm_prompt_playground_runs' => (int)$row['id'],
                    'id_llmConversations' => !empty($row['id_llmConversations']) ? (int)$row['id_llmConversations'] : null,
                    'id_llmMessages_request' => !empty($row['id_llmMessages_request']) ? (int)$row['id_llmMessages_request'] : null,
                    'id_llmMessages_response' => !empty($row['id_llmMessages_response']) ? (int)$row['id_llmMessages_response'] : null
                )
            ),
            'expected_output' => trim((string)($row['response_content'] ?? '')) !== '' ? array('assistant_text' => (string)$row['response_content']) : null,
            'source_ref' => array(
                'id_llm_prompt_playground_runs' => (int)$row['id'],
                'id_llmConversations' => !empty($row['id_llmConversations']) ? (int)$row['id_llmConversations'] : null,
                'id_llmMessages_request' => !empty($row['id_llmMessages_request']) ? (int)$row['id_llmMessages_request'] : null,
                'id_llmMessages_response' => !empty($row['id_llmMessages_response']) ? (int)$row['id_llmMessages_response'] : null
            ),
            'tags' => array('imported', 'playground')
        ));
    }

    /**
     * Import a form submission (user message with field data) as a dataset case.
     *
     * @return array|null Created case row, or null if out of scope.
     */
    private function importFormSubmissionCase($dataset_id, $source_id, $descriptor, $runtime_overrides, $allowed_page_id)
    {
        $message = $this->db->query_db_first(
            "SELECT m.id, m.id_llmConversations, m.id_dataRows, m.content, lc.id_sections, ps.id_pages AS source_page_id
             FROM llmMessages m
             LEFT JOIN llmConversations lc ON lc.id = m.id_llmConversations
             LEFT JOIN sections sec ON sec.id = lc.id_sections
             LEFT JOIN pages_sections ps ON ps.id_sections = sec.id
             WHERE m.id = :id LIMIT 1",
            array(':id' => $source_id)
        );
        if (!$message || !$this->matchesDescriptorScope($message, $descriptor, $allowed_page_id)) {
            return null;
        }

        $assistant_reply = $this->loadNextAssistantMessage((int)$message['id_llmConversations'], (int)$message['id']);
        $parsed_form_data = $this->dataset_service->parseFieldLines((string)$message['content']);
        if (empty($parsed_form_data)) {
            $parsed_form_data = array('submission_text' => trim((string)$message['content']));
        }

        return $this->dataset_service->createCase($dataset_id, array(
            'title' => $this->buildImportedCaseTitle('Form submission', $this->buildFieldPreview($parsed_form_data), (int)$message['id']),
            'case_type' => 'form_case',
            'source_type' => 'form_submission',
            'input_payload' => array(
                'execution_profile' => 'form_runtime',
                'owner_descriptor' => $this->dataset_service->buildOwnerDescriptor($descriptor),
                'variables' => $parsed_form_data,
                'form_data' => $parsed_form_data,
                'runtime_overrides' => $runtime_overrides,
                'source_context' => array(
                    'id_llmConversations' => !empty($message['id_llmConversations']) ? (int)$message['id_llmConversations'] : null,
                    'id_llmMessages_request' => (int)$message['id'],
                    'id_llmMessages_response' => !empty($assistant_reply['id']) ? (int)$assistant_reply['id'] : null,
                    'id_dataRows' => !empty($message['id_dataRows']) ? (int)$message['id_dataRows'] : null
                )
            ),
            'expected_output' => !empty($assistant_reply['content']) ? array('assistant_text' => (string)$assistant_reply['content']) : null,
            'source_ref' => array(
                'id_llmConversations' => !empty($message['id_llmConversations']) ? (int)$message['id_llmConversations'] : null,
                'id_llmMessages_request' => (int)$message['id'],
                'id_llmMessages_response' => !empty($assistant_reply['id']) ? (int)$assistant_reply['id'] : null,
                'id_dataRows' => !empty($message['id_dataRows']) ? (int)$message['id_dataRows'] : null
            ),
            'tags' => array('imported', 'form_submission')
        ));
    }

    /**
     * Import a conversation user message (with history window) as a dataset case.
     *
     * @return array|null Created case row, or null if out of scope.
     */
    private function importConversationCase($dataset_id, $source_id, $descriptor, $execution_profile, $runtime_overrides, $allowed_page_id)
    {
        $message = $this->db->query_db_first(
            "SELECT m.id, m.id_llmConversations, m.content, lc.id_sections, lc.id_llm_scripts AS source_script_id, ps.id_pages AS source_page_id
             FROM llmMessages m
             LEFT JOIN llmConversations lc ON lc.id = m.id_llmConversations
             LEFT JOIN sections sec ON sec.id = lc.id_sections
             LEFT JOIN pages_sections ps ON ps.id_sections = sec.id
             WHERE m.id = :id LIMIT 1",
            array(':id' => $source_id)
        );
        if (!$message || !$this->matchesDescriptorScope($message, $descriptor, $allowed_page_id)) {
            return null;
        }

        $is_script_owner = $this->isScriptOwner($descriptor);
        $runtime_profile = $is_script_owner
            ? 'script_runtime'
            : $this->resolveConversationImportRuntimeProfile($execution_profile, $descriptor, $message);
        $history = $this->loadConversationWindow((int)$message['id_llmConversations'], (int)$message['id'], 12);
        $assistant_reply = $this->loadNextAssistantMessage((int)$message['id_llmConversations'], (int)$message['id']);
        $trigger_message = (string)$message['content'];
        $variables = $is_script_owner ? $this->buildScriptVariablesFromMessage($trigger_message) : array();

        return $this->dataset_service->createCase($dataset_id, array(
            'title' => $this->buildImportedCaseTitle('Conversation message', $trigger_message, (int)$message['id']),
            'case_type' => $this->dataset_service->toCaseType($runtime_profile),
            'source_type' => 'conversation_message',
            'input_payload' => array(
                'execution_profile' => $runtime_profile,
                'owner_descriptor' => $this->dataset_service->buildOwnerDescriptor($descriptor),
                'message_history' => $history,
                'trigger_message' => $trigger_message,
                'variables' => $variables,
                'runtime_overrides' => $runtime_overrides,
                'source_context' => array(
                    'id_llmConversations' => (int)$message['id_llmConversations'],
                    'id_llmMessages_request' => (int)$message['id'],
                    'id_llmMessages_response' => !empty($assistant_reply['id']) ? (int)$assistant_reply['id'] : null,
                    'message_window' => 'last_12'
                )
            ),
            'expected_output' => !empty($assistant_reply['content']) ? array('assistant_text' => (string)$assistant_reply['content']) : null,
            'source_ref' => array(
                'id_llmConversations' => (int)$message['id_llmConversations'],
                'id_llmMessages_request' => (int)$message['id'],
                'id_llmMessages_response' => !empty($assistant_reply['id']) ? (int)$assistant_reply['id'] : null
            ),
            'tags' => $is_script_owner
                ? array('imported', 'conversation', 'script_conversation')
                : array('imported', 'conversation')
        ));
    }

    /**
     * Attempt to parse structured variables from a user message (JSON, field lines, or fallback aliases).
     *
     * @param string $message_content Raw message content.
     * @return array Associative variable map.
     */
    private function buildScriptVariablesFromMessage($message_content)
    {
        $content = trim((string)$message_content);
        if ($content === '') {
            return array();
        }

        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && $this->isAssoc($decoded)) {
            return $decoded;
        }

        $parsed_lines = $this->dataset_service->parseFieldLines($content);
        if (!empty($parsed_lines)) {
            return $parsed_lines;
        }

        // Fallback aliases improve interpolation compatibility across scripts.
        return array(
            'input' => $content,
            'user_input' => $content,
            'message' => $content,
            'query' => $content,
            'topic' => $content,
            'text' => $content
        );
    }

    /** @return bool True if $value is an associative (non-sequential) array. */
    private function isAssoc($value)
    {
        if (!is_array($value)) {
            return false;
        }
        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * Import an LLM script's test fixture as a dataset case.
     *
     * @return array|null Created case row, or null if script not found.
     */
    private function importScriptFixtureCase($dataset_id, $source_id, $descriptor, $runtime_overrides)
    {
        $script = $this->db->query_db_first(
            "SELECT id, name, data_config, test_variables, model, temperature, max_tokens
             FROM llm_scripts WHERE id = :id LIMIT 1",
            array(':id' => $source_id)
        );
        if (!$script) {
            return null;
        }

        $assistant_reply = $this->loadLatestScriptAssistantMessage((int)$script['id']);
        $script_vars = $this->dataset_service->decodeJsonColumn($script['test_variables'] ?? '{}', array());
        $data_config = $this->dataset_service->decodeJsonColumn($script['data_config'] ?? '[]', array());
        $script_descriptor = array(
            'owner_type' => LLM_PROMPT_OWNER_SCRIPT,
            'owner_id' => (int)$script['id'],
            'prompt_slot' => 'script',
            'id_languages' => isset($descriptor['id_languages']) ? (int)$descriptor['id_languages'] : null
        );

        return $this->dataset_service->createCase($dataset_id, array(
            'title' => $this->buildImportedCaseTitle('Script fixture', (string)($script['name'] ?? ''), (int)$script['id']),
            'case_type' => 'script_case',
            'source_type' => 'script_run',
            'input_payload' => array(
                'execution_profile' => 'script_runtime',
                'owner_descriptor' => $this->dataset_service->buildOwnerDescriptor($script_descriptor),
                'variables' => is_array($script_vars) ? $script_vars : array(),
                'message_history' => array(),
                'runtime_overrides' => $this->mergeRuntimeOverrides(array(
                    'data_config' => is_array($data_config) ? $data_config : array(),
                    'model' => $script['model'] ?? null,
                    'temperature' => $script['temperature'] ?? null,
                    'max_tokens' => $script['max_tokens'] ?? null
                ), $runtime_overrides),
                'source_context' => array(
                    'id_llm_scripts' => (int)$script['id'],
                    'id_llmMessages_response' => !empty($assistant_reply['id']) ? (int)$assistant_reply['id'] : null
                )
            ),
            'expected_output' => !empty($assistant_reply['content']) ? array('assistant_text' => (string)$assistant_reply['content']) : null,
            'source_ref' => array(
                'id_llm_scripts' => (int)$script['id'],
                'id_llmMessages_response' => !empty($assistant_reply['id']) ? (int)$assistant_reply['id'] : null
            ),
            'tags' => array('imported', 'script')
        ));
    }

    /**
     * Load a window of recent messages from a conversation up to a specific message ID.
     *
     * @param int $conversation_id   Conversation primary key.
     * @param int $up_to_message_id  Include messages up to (and including) this ID.
     * @param int $window            Number of messages to include (max 40).
     * @return array Normalized messages in chronological order.
     */
    private function loadConversationWindow($conversation_id, $up_to_message_id, $window)
    {
        $rows = $this->db->query_db(
            "SELECT role, content
             FROM llmMessages
             WHERE id_llmConversations = :conversation_id AND id <= :up_to_message_id
             ORDER BY id DESC LIMIT " . max(1, min((int)$window, 40)),
            array(':conversation_id' => (int)$conversation_id, ':up_to_message_id' => (int)$up_to_message_id)
        );
        return $this->dataset_service->normalizeMessages(array_reverse($rows));
    }

    /**
     * Add preview_text and assistant_preview fields to import candidate rows for UI display.
     *
     * @param string $source_type Source type for preview formatting.
     * @param array  $rows        Raw candidate rows.
     * @return array Decorated rows with preview fields added.
     */
    private function decorateImportCandidates($source_type, $rows)
    {
        foreach ($rows as &$row) {
            if ($source_type === 'form_submission') {
                $parsed = $this->dataset_service->parseFieldLines((string)($row['content'] ?? ''));
                $row['preview_text'] = $this->buildFieldPreview(!empty($parsed) ? $parsed : array('text' => (string)($row['content'] ?? '')));
                $row['assistant_preview'] = '';
            } elseif ($source_type === 'conversation_message') {
                $row['preview_text'] = trim((string)($row['content'] ?? ''));
                $row['assistant_preview'] = '';
            } elseif ($source_type === 'playground_run') {
                $row['preview_text'] = trim((string)($row['request_content'] ?? ''));
                $row['assistant_preview'] = trim((string)($row['response_content'] ?? ''));
            } elseif ($source_type === 'script_run') {
                $row['preview_text'] = trim((string)($row['name'] ?? ''));
                $row['assistant_preview'] = '';
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * Build a case title from a prefix and a preview of the content (truncated to 72 chars).
     *
     * @param string $prefix  Source type label (e.g. 'Playground run').
     * @param string $preview Content snippet.
     * @param int    $id      Fallback source ID for the title.
     * @return string Formatted title.
     */
    private function buildImportedCaseTitle($prefix, $preview, $id)
    {
        $preview = trim(preg_replace('/\s+/', ' ', (string)$preview));
        if ($preview === '') {
            return $prefix . ' #' . (int)$id;
        }
        if (mb_strlen($preview) > 72) {
            $preview = mb_substr($preview, 0, 72) . '...';
        }
        return $prefix . ': ' . $preview;
    }

    /** @return string First non-empty scalar value from an array, or empty string. */
    private function buildFieldPreview($values)
    {
        if (!is_array($values)) {
            return '';
        }
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string)$value) !== '') {
                return (string)$value;
            }
        }
        return '';
    }

    /**
     * Load the next assistant reply after a specific message in a conversation.
     *
     * @param int $conversation_id   Conversation ID.
     * @param int $after_message_id  Message ID to look after.
     * @return array|null Assistant message row {id, content} or null.
     */
    private function loadNextAssistantMessage($conversation_id, $after_message_id)
    {
        return $this->db->query_db_first(
            "SELECT id, content
             FROM llmMessages
             WHERE id_llmConversations = :conversation_id AND role = 'assistant' AND id > :message_id
             ORDER BY id ASC LIMIT 1",
            array(':conversation_id' => (int)$conversation_id, ':message_id' => (int)$after_message_id)
        );
    }

    /**
     * Load the most recent assistant message from a script's conversation history.
     *
     * @param int $script_id Script primary key.
     * @return array|null Message row {id, content} or null.
     */
    private function loadLatestScriptAssistantMessage($script_id)
    {
        return $this->db->query_db_first(
            "SELECT m.id, m.content
             FROM llmMessages m
             INNER JOIN llmConversations c ON c.id = m.id_llmConversations
             WHERE c.id_llm_scripts = :script_id AND m.role = 'assistant'
             ORDER BY m.id DESC LIMIT 1",
            array(':script_id' => (int)$script_id)
        );
    }

    /**
     * Check whether a source row falls within the descriptor's ownership scope or page scope.
     * Handles style-field owners, script owners, and page-level fallback matching.
     *
     * @param array $row             Source record with id_sections, prompt_owner_id, source_page_id, source_script_id.
     * @param array $descriptor      Owner descriptor.
     * @param int   $allowed_page_id ACL-validated page ID fallback.
     * @return bool True if the row is in scope for import.
     */
    private function matchesDescriptorScope($row, $descriptor, $allowed_page_id)
    {
        $owner_id = (int)($descriptor['owner_id'] ?? 0);
        if ($owner_id > 0 && $this->isStyleOwner($descriptor)) {
            $source_section_id = (int)($row['id_sections'] ?? 0);
            $prompt_owner_id = (int)($row['prompt_owner_id'] ?? 0);
            $source_page_id = (int)($row['source_page_id'] ?? 0);

            // Prefer strict owner matching whenever source metadata is available.
            if ($source_section_id === $owner_id || $prompt_owner_id === $owner_id) {
                return true;
            }

            // Allow page-level replay imports when ACL already validated page access.
            if ($allowed_page_id > 0 && $source_page_id > 0 && $source_page_id === $allowed_page_id) {
                return true;
            }

            // Fallback: allow page-scoped match when owner-id metadata is missing.
            if ($source_section_id === 0 && $prompt_owner_id === 0 && $allowed_page_id > 0 && $source_page_id > 0) {
                return $source_page_id === $allowed_page_id;
            }

            // Some legacy rows do not carry section/owner references. Do not hard-fail
            // the import in that case; ACL was already enforced at request level.
            if ($source_section_id === 0 && $prompt_owner_id === 0 && $source_page_id === 0) {
                return true;
            }

            return false;
        }
        if ($owner_id > 0 && ($descriptor['owner_type'] ?? '') === LLM_PROMPT_OWNER_SCRIPT) {
            $source_script_id = (int)($row['source_script_id'] ?? 0);
            if ($source_script_id > 0) {
                return $source_script_id === $owner_id;
            }
            $prompt_owner_id = (int)($row['prompt_owner_id'] ?? 0);
            if ($prompt_owner_id > 0) {
                return $prompt_owner_id === $owner_id;
            }

            // Legacy script-linked rows can miss prompt owner linkage.
            return true;
        }
        if ($allowed_page_id > 0) {
            return (int)($row['source_page_id'] ?? 0) === $allowed_page_id;
        }
        return true;
    }

    /**
     * Merge override values into a base config, with overrides taking precedence.
     *
     * @param array $base     Base config array.
     * @param array $override Override values.
     * @return array Merged config.
     */
    private function mergeRuntimeOverrides($base, $override)
    {
        $base = is_array($base) ? $base : array();
        foreach (is_array($override) ? $override : array() as $key => $value) {
            $base[$key] = $value;
        }
        return $base;
    }

    /**
     * Resolve the runtime profile to use when importing a conversation message as a case.
     *
     * @param string $execution_profile Dataset's declared execution profile.
     * @param array  $descriptor        Owner descriptor.
     * @param array  $message           Source message row.
     * @return string Resolved profile code (defaults to 'chat_runtime').
     */
    public function resolveConversationImportRuntimeProfile($execution_profile, $descriptor, $message)
    {
        $extended = $this->resolveConversationImportRuntimeProfileExtension($execution_profile, $descriptor, $message);
        if (is_string($extended) && trim($extended) !== '') {
            return trim($extended);
        }

        return $execution_profile === 'chat_runtime' ? 'chat_runtime' : 'chat_runtime';
    }

    /**
     * Extension point for custom runtime profile resolution during conversation imports. Returns empty string for default behavior.
     *
     * @param string $execution_profile Dataset's execution profile.
     * @param array  $descriptor        Owner descriptor.
     * @param array  $message           Source message row.
     * @return string Custom profile code, or empty string for default handling.
     */
    public function resolveConversationImportRuntimeProfileExtension($execution_profile, $descriptor, $message)
    {
        return '';
    }
}
?>
