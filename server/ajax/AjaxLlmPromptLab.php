<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/../../../../ajax/BaseAjax.php';
require_once __DIR__ . '/../service/LlmPromptRegistryService.php';
require_once __DIR__ . '/../service/LlmPromptPlaygroundService.php';
require_once __DIR__ . '/../service/LlmPromptBuilderService.php';
require_once __DIR__ . '/../service/LlmPromptRuntimeValueService.php';
require_once __DIR__ . '/../service/LlmDatasetService.php';
require_once __DIR__ . '/../service/LlmEvaluationService.php';

class AjaxLlmPromptLab extends BaseAjax
{
    /** @var LlmPromptRegistryService */
    private $registry_service;

    /** @var LlmPromptPlaygroundService */
    private $playground_service;

    /** @var LlmPromptBuilderService */
    private $builder_service;

    /** @var LlmDatasetService */
    private $dataset_service;

    /** @var LlmEvaluationService */
    private $evaluation_service;

    /** @var LlmPromptRuntimeValueService */
    private $runtime_value_service;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->registry_service = new LlmPromptRegistryService($services);
        $this->playground_service = new LlmPromptPlaygroundService($services);
        $this->builder_service = new LlmPromptBuilderService($services);
        $this->runtime_value_service = new LlmPromptRuntimeValueService($services);
        $this->dataset_service = new LlmDatasetService($services);
        $this->evaluation_service = new LlmEvaluationService($services);
    }

    public function dispatch($post)
    {
        $action = $post['action'] ?? '';
        $descriptor = $this->readDescriptor($post);

        try {
            switch ($action) {
                case 'bootstrap_owner':
                    $this->assertAccess($descriptor, 'select');
                    return $this->handleBootstrap($post, $descriptor);

                case 'get_version':
                    $this->assertAccess($descriptor, 'select');
                    return $this->handleGetVersion($post);

                case 'list_versions':
                    $this->assertAccess($descriptor, 'select');
                    return $this->handleListVersions($post, $descriptor);

                case 'playground_run':
                    $this->assertAccess($descriptor, 'update');
                    $this->assertCsrf($post);
                    return $this->handlePlaygroundRun($post, $descriptor);

                case 'builder_run':
                    $this->assertAccess($descriptor, 'update');
                    $this->assertCsrf($post);
                    return $this->handleBuilderRun($post, $descriptor);

                case 'list_datasets':
                    $this->assertAccess($descriptor, 'select');
                    return $this->handleListDatasets($post);

                case 'get_dataset':
                    $this->assertAccess($descriptor, 'select');
                    return $this->handleGetDataset($post);

                case 'create_dataset':
                    $this->assertAccess($descriptor, 'update');
                    $this->assertCsrf($post);
                    return $this->handleCreateDataset($post, $descriptor);

                case 'update_dataset':
                    $this->assertAccess($descriptor, 'update');
                    $this->assertCsrf($post);
                    return $this->handleUpdateDataset($post);

                case 'delete_dataset':
                    $this->assertAccess($descriptor, 'update');
                    $this->assertCsrf($post);
                    return $this->handleDeleteDataset($post);

                case 'list_dataset_cases':
                    $this->assertAccess($descriptor, 'select');
                    return $this->handleListDatasetCases($post);

                case 'add_case_from_playground_run':
                    $this->assertAccess($descriptor, 'update');
                    $this->assertCsrf($post);
                    return $this->handleAddCaseFromPlaygroundRun($post, $descriptor);

                case 'get_import_candidates':
                    $this->assertAccess($descriptor, 'select');
                    return $this->handleGetImportCandidates($post);

                case 'add_cases_from_source':
                    $this->assertAccess($descriptor, 'update');
                    $this->assertCsrf($post);
                    return $this->handleAddCasesFromSource($post, $descriptor);

                case 'parse_cases_from_text':
                    $this->assertAccess($descriptor, 'update');
                    $this->assertCsrf($post);
                    return $this->handleParseCasesFromText($post, $descriptor);

                case 'import_parsed_cases':
                    $this->assertAccess($descriptor, 'update');
                    $this->assertCsrf($post);
                    return $this->handleImportParsedCases($post, $descriptor);

                case 'move_dataset_cases':
                    $this->assertAccess($descriptor, 'update');
                    $this->assertCsrf($post);
                    return $this->handleMoveDatasetCases($post, $descriptor);

                case 'list_compatible_datasets':
                    $this->assertAccess($descriptor, 'select');
                    return $this->handleListCompatibleDatasets($post, $descriptor);

                case 'list_case_evaluation_history':
                    $this->assertAccess($descriptor, 'select');
                    return $this->handleListCaseEvaluationHistory($post);

                case 'list_evaluation_example_candidates':
                    $this->assertAccess($descriptor, 'select');
                    return $this->handleListEvaluationExampleCandidates($post, $descriptor);

                case 'delete_dataset_case':
                    $this->assertAccess($descriptor, 'update');
                    $this->assertCsrf($post);
                    return $this->handleDeleteDatasetCase($post);

                case 'update_dataset_case':
                    $this->assertAccess($descriptor, 'update');
                    $this->assertCsrf($post);
                    return $this->handleUpdateDatasetCase($post);

                case 'list_eval_definitions':
                    $this->assertAccess($descriptor, 'select');
                    return $this->evaluation_service->listDefinitions();

                case 'run_dataset_eval':
                    $this->assertAccess($descriptor, 'update');
                    $this->assertCsrf($post);
                    return $this->handleRunDatasetEval($post, $descriptor);

                case 'get_eval_run':
                    $this->assertAccess($descriptor, 'select');
                    return $this->handleGetEvalRun($post);

                case 'list_eval_run_cases':
                    $this->assertAccess($descriptor, 'select');
                    return $this->handleListEvalRunCases($post);

                case 'list_eval_runs':
                    $this->assertAccess($descriptor, 'select');
                    return $this->handleListEvalRuns($post);

                case 'delete_eval_run':
                    $this->assertAccess($descriptor, 'update');
                    $this->assertCsrf($post);
                    return $this->handleDeleteEvalRun($post);

                case 'delete_eval_runs_bulk':
                    $this->assertAccess($descriptor, 'update');
                    $this->assertCsrf($post);
                    return $this->handleDeleteEvalRunsBulk($post);

                case 'link_eval_run_baseline':
                    $this->assertAccess($descriptor, 'update');
                    $this->assertCsrf($post);
                    return $this->handleLinkEvalRunBaseline($post);

                case 'save_human_score':
                    $this->assertAccess($descriptor, 'update');
                    $this->assertCsrf($post);
                    return $this->handleSaveHumanScore($post);
            }

            throw new Exception('Unknown prompt lab action: ' . $action);
        } catch (Exception $e) {
            error_log('AjaxLlmPromptLab error [' . $action . ']: ' . $e->getMessage());
            return array('error' => $e->getMessage());
        }
    }

    private function handleBootstrap($post, $descriptor)
    {
        $runtime_values = $this->resolveRuntimeValues($descriptor, $post);
        return $this->registry_service->bootstrapOwner(
            $descriptor,
            (string)($post['current_content'] ?? ''),
            $post['current_meta'] ?? null,
            $this->canMutate($descriptor),
            $runtime_values
        );
    }

    private function handleGetVersion($post)
    {
        $version_id = isset($post['version_id']) ? (int)$post['version_id'] : 0;
        if ($version_id <= 0) {
            throw new Exception('Missing version_id');
        }

        $version = $this->registry_service->getVersion($version_id);
        if (!$version) {
            throw new Exception('Prompt version not found');
        }

        return $version;
    }

    private function handleListVersions($post, $descriptor)
    {
        $bootstrap = $this->handleBootstrap($post, $descriptor);
        return array(
            'versions' => $bootstrap['versions'] ?? array(),
            'active_version' => $bootstrap['active_version'] ?? null
        );
    }

    private function handlePlaygroundRun($post, $descriptor)
    {
        $runtime_values = $this->resolveRuntimeValues($descriptor, $post);
        $variables = $this->decodeJson($post['variables_json'] ?? '{}');
        $message_history = $this->decodeJson($post['message_history_json'] ?? '[]');
        $selected_models = $this->decodeJson($post['selected_models_json'] ?? '[]');

        return $this->playground_service->run(
            $descriptor,
            (string)($post['draft_prompt'] ?? ''),
            $runtime_values,
            is_array($variables) ? $variables : array(),
            is_array($message_history) ? $message_history : array(),
            is_array($selected_models) ? $selected_models : array()
        );
    }

    private function handleBuilderRun($post, $descriptor)
    {
        $result = $this->builder_service->buildSuggestion(
            $descriptor,
            (string)($post['current_prompt'] ?? ''),
            (string)($post['instructions'] ?? ''),
            !empty($post['selected_model']) ? $post['selected_model'] : null,
            $this->decodeJson($post['examples_json'] ?? '[]')
        );

        $bootstrap = $this->registry_service->bootstrapOwner($descriptor);
        $this->registry_service->logPlaygroundRun(array(
            'id_llm_prompt_entries' => $bootstrap['entry']['id'] ?? null,
            'id_llm_prompt_locales' => $bootstrap['locale']['id'] ?? null,
            'id_llm_prompt_versions' => $bootstrap['active_version']['id'] ?? null,
            'id_llmConversations' => $result['id_llmConversations'] ?? null,
            'id_llmMessages_request' => $result['id_llmMessages_request'] ?? null,
            'id_llmMessages_response' => $result['id_llmMessages_response'] ?? null,
            'run_mode' => LLM_PROMPT_RUN_MODE_BUILDER,
            'variables_json' => array(
                'instructions' => (string)($post['instructions'] ?? ''),
                'examples' => $this->decodeJson($post['examples_json'] ?? '[]')
            ),
            'config_snapshot_json' => array(
                'model' => $result['model'] ?? null
            )
        ));

        return $result;
    }

    private function handleListDatasets($post)
    {
        $filters = array(
            'search' => $post['search'] ?? '',
            'owner_type_scope' => $post['owner_type_scope'] ?? '',
            'owner_id_scope' => $post['owner_id_scope'] ?? '',
            'execution_profile' => $post['execution_profile'] ?? ''
        );
        return $this->dataset_service->listDatasets($filters);
    }

    private function handleGetDataset($post)
    {
        $dataset_id = isset($post['dataset_id']) ? (int)$post['dataset_id'] : 0;
        if ($dataset_id <= 0) {
            throw new Exception('Missing dataset_id');
        }

        $dataset = $this->dataset_service->getDataset($dataset_id);
        if (!$dataset) {
            throw new Exception('Dataset not found');
        }

        return array(
            'dataset' => $dataset,
            'cases' => $this->dataset_service->listDatasetCases($dataset_id)
        );
    }

    private function handleCreateDataset($post, $descriptor)
    {
        $payload = array(
            'name' => (string)($post['name'] ?? ''),
            'description' => (string)($post['description'] ?? ''),
            'dataset_type' => (string)($post['dataset_type'] ?? 'golden_manual'),
            'execution_profile' => (string)($post['execution_profile'] ?? 'text_only'),
            'owner_type_scope' => $descriptor['owner_type'] ?? null,
            'owner_id_scope' => $descriptor['owner_id'] ?? null
        );
        return $this->dataset_service->createDataset($payload);
    }

    private function handleUpdateDataset($post)
    {
        $dataset_id = isset($post['dataset_id']) ? (int)$post['dataset_id'] : 0;
        if ($dataset_id <= 0) {
            throw new Exception('Missing dataset_id');
        }

        $payload = array();
        if (array_key_exists('name', $post)) {
            $payload['name'] = $post['name'];
        }
        if (array_key_exists('description', $post)) {
            $payload['description'] = $post['description'];
        }
        if (array_key_exists('dataset_type', $post)) {
            $payload['dataset_type'] = $post['dataset_type'];
        }
        if (array_key_exists('execution_profile', $post)) {
            $payload['execution_profile'] = $post['execution_profile'];
        }
        if (array_key_exists('is_locked', $post)) {
            $payload['is_locked'] = $post['is_locked'];
        }

        return $this->dataset_service->updateDataset($dataset_id, $payload);
    }

    private function handleListDatasetCases($post)
    {
        $dataset_id = isset($post['dataset_id']) ? (int)$post['dataset_id'] : 0;
        if ($dataset_id <= 0) {
            throw new Exception('Missing dataset_id');
        }
        return $this->dataset_service->listDatasetCases($dataset_id);
    }

    private function handleDeleteDataset($post)
    {
        $dataset_id = isset($post['dataset_id']) ? (int)$post['dataset_id'] : 0;
        if ($dataset_id <= 0) {
            throw new Exception('Missing dataset_id');
        }
        return array('deleted' => $this->dataset_service->deleteDataset($dataset_id));
    }

    private function handleAddCaseFromPlaygroundRun($post, $descriptor)
    {
        $dataset_id = isset($post['dataset_id']) ? (int)$post['dataset_id'] : 0;
        if ($dataset_id <= 0) {
            throw new Exception('Missing dataset_id');
        }

        $payload = array(
            'title' => (string)($post['title'] ?? ''),
            'descriptor' => $descriptor,
            'execution_profile' => (string)($post['execution_profile'] ?? 'text_only'),
            'variables' => $this->decodeJson($post['variables_json'] ?? '{}'),
            'message_history' => $this->decodeJson($post['message_history_json'] ?? '[]'),
            'runtime_overrides' => $this->decodeJson($post['runtime_overrides_json'] ?? '{}'),
            'id_llm_prompt_playground_runs' => isset($post['id_llm_prompt_playground_runs']) ? (int)$post['id_llm_prompt_playground_runs'] : null,
            'id_llmConversations' => isset($post['id_llmConversations']) ? (int)$post['id_llmConversations'] : null,
            'id_llmMessages_request' => isset($post['id_llmMessages_request']) ? (int)$post['id_llmMessages_request'] : null,
            'id_llmMessages_response' => isset($post['id_llmMessages_response']) ? (int)$post['id_llmMessages_response'] : null,
            'expected_labels' => $this->decodeJson($post['expected_labels_json'] ?? '{}'),
            'tags' => $this->decodeJson($post['tags_json'] ?? '[]'),
            'notes' => (string)($post['notes'] ?? '')
        );

        return $this->dataset_service->addCaseFromPlaygroundRun($dataset_id, $payload);
    }

    private function handleGetImportCandidates($post)
    {
        $source_type = (string)($post['source_type'] ?? '');
        if ($source_type === '') {
            throw new Exception('Missing source_type');
        }
        $limit = isset($post['limit']) ? (int)$post['limit'] : 50;
        if ($source_type === 'script_run') {
            $this->assertScriptSourceAccess('select');
        }

        return $this->dataset_service->getImportCandidates(
            $source_type,
            $limit,
            array(
                'descriptor' => $this->readDescriptor($post),
                'allowed_page_id' => $this->resolveDescriptorPageId($this->readDescriptor($post)),
                'allow_script_source' => ($source_type === 'script_run')
            )
        );
    }

    private function handleAddCasesFromSource($post, $descriptor)
    {
        $dataset_id = isset($post['dataset_id']) ? (int)$post['dataset_id'] : 0;
        if ($dataset_id <= 0) {
            throw new Exception('Missing dataset_id');
        }

        $source_type = (string)($post['source_type'] ?? '');
        if ($source_type === '') {
            throw new Exception('Missing source_type');
        }

        if ($source_type === 'script_run') {
            $this->assertScriptSourceAccess('update');
        }

        $source_ids = $this->decodeJson($post['source_ids_json'] ?? '[]');
        if (!is_array($source_ids)) {
            $source_ids = array();
        }

        $context = array(
            'descriptor' => $descriptor,
            'execution_profile' => (string)($post['execution_profile'] ?? 'text_only'),
            'runtime_overrides' => $this->decodeJson($post['runtime_overrides_json'] ?? '{}'),
            'allowed_page_id' => $this->resolveDescriptorPageId($descriptor),
            'allow_script_source' => ($source_type === 'script_run')
        );

        return $this->dataset_service->addCasesFromSource($dataset_id, $source_type, $source_ids, $context);
    }

    private function handleDeleteDatasetCase($post)
    {
        $case_id = isset($post['dataset_case_id']) ? (int)$post['dataset_case_id'] : 0;
        if ($case_id <= 0) {
            throw new Exception('Missing dataset_case_id');
        }
        $dataset_id = isset($post['dataset_id']) ? (int)$post['dataset_id'] : 0;
        return array('deleted' => $this->dataset_service->deleteDatasetCase($case_id, $dataset_id));
    }

    private function handleUpdateDatasetCase($post)
    {
        $case_id = isset($post['dataset_case_id']) ? (int)$post['dataset_case_id'] : 0;
        $dataset_id = isset($post['dataset_id']) ? (int)$post['dataset_id'] : 0;
        if ($case_id <= 0 || $dataset_id <= 0) {
            throw new Exception('Missing dataset_case_id or dataset_id');
        }

        return $this->dataset_service->updateDatasetCase($case_id, $dataset_id, array(
            'title' => $post['title'] ?? null,
            'notes' => $post['notes'] ?? null,
            'tags' => $this->decodeJson($post['tags_json'] ?? '[]'),
            'expected_labels' => $this->decodeJson($post['expected_labels_json'] ?? '{}')
        ));
    }

    private function handleParseCasesFromText($post, $descriptor)
    {
        $raw_text = (string)($post['raw_text'] ?? '');
        $execution_profile = (string)($post['execution_profile'] ?? 'text_only');
        $selected_model = !empty($post['selected_model']) ? (string)$post['selected_model'] : null;
        $runtime_overrides = $this->decodeJson($post['runtime_overrides_json'] ?? '{}');
        if (!is_array($runtime_overrides)) {
            $runtime_overrides = array();
        }

        return $this->dataset_service->parseCasesFromText(
            $descriptor,
            $execution_profile,
            $raw_text,
            $selected_model,
            $runtime_overrides
        );
    }

    private function handleImportParsedCases($post, $descriptor)
    {
        $dataset_id = isset($post['dataset_id']) ? (int)$post['dataset_id'] : 0;
        if ($dataset_id <= 0) {
            throw new Exception('Missing dataset_id');
        }

        $execution_profile = (string)($post['execution_profile'] ?? 'text_only');
        $cases = $this->decodeJson($post['cases_json'] ?? '[]');
        if (!is_array($cases)) {
            $cases = array();
        }
        $runtime_overrides = $this->decodeJson($post['runtime_overrides_json'] ?? '{}');
        if (!is_array($runtime_overrides)) {
            $runtime_overrides = array();
        }

        return $this->dataset_service->importParsedCases(
            $dataset_id,
            $descriptor,
            $execution_profile,
            $cases,
            $runtime_overrides
        );
    }

    private function handleMoveDatasetCases($post, $descriptor)
    {
        $source_dataset_id = isset($post['source_dataset_id']) ? (int)$post['source_dataset_id'] : 0;
        $target_dataset_id = isset($post['target_dataset_id']) ? (int)$post['target_dataset_id'] : 0;
        if ($source_dataset_id <= 0 || $target_dataset_id <= 0) {
            throw new Exception('source_dataset_id and target_dataset_id are required');
        }
        $this->assertDatasetDescriptorScope($source_dataset_id, $descriptor);
        $this->assertDatasetDescriptorScope($target_dataset_id, $descriptor);

        $case_ids = $this->decodeJson($post['case_ids_json'] ?? '[]');
        if (!is_array($case_ids) || empty($case_ids)) {
            throw new Exception('At least one case is required');
        }

        return $this->dataset_service->moveDatasetCases(
            $source_dataset_id,
            $target_dataset_id,
            $case_ids,
            array(
                'remove_source' => !empty($post['remove_source']),
                'promoted_by_run_case_id' => isset($post['promoted_by_run_case_id']) ? (int)$post['promoted_by_run_case_id'] : null,
            )
        );
    }

    private function handleListCompatibleDatasets($post, $descriptor)
    {
        $dataset_id = isset($post['dataset_id']) ? (int)$post['dataset_id'] : 0;
        if ($dataset_id <= 0) {
            throw new Exception('Missing dataset_id');
        }
        $this->assertDatasetDescriptorScope($dataset_id, $descriptor);
        return $this->dataset_service->listCompatibleDatasets($dataset_id, array(
            'owner_type_scope' => $descriptor['owner_type'] ?? null,
            'owner_id_scope' => $descriptor['owner_id'] ?? null,
        ));
    }

    private function handleListCaseEvaluationHistory($post)
    {
        $case_id = isset($post['case_id']) ? (int)$post['case_id'] : 0;
        if ($case_id <= 0) {
            throw new Exception('Missing case_id');
        }
        $limit = isset($post['limit']) ? (int)$post['limit'] : 30;
        return $this->dataset_service->listCaseEvaluationHistory($case_id, $limit);
    }

    private function handleListEvaluationExampleCandidates($post, $descriptor)
    {
        $filters = array(
            'dataset_id' => isset($post['dataset_id']) ? (int)$post['dataset_id'] : 0,
            'owner_type_scope' => $descriptor['owner_type'] ?? null,
            'owner_id_scope' => $descriptor['owner_id'] ?? null,
            'search' => (string)($post['search'] ?? ''),
            'limit' => isset($post['limit']) ? (int)$post['limit'] : 100,
        );
        return $this->dataset_service->listEvaluationExampleCandidates($filters);
    }

    private function handleRunDatasetEval($post, $descriptor)
    {
        $dataset_id = isset($post['dataset_id']) ? (int)$post['dataset_id'] : 0;
        if ($dataset_id <= 0) {
            throw new Exception('Missing dataset_id');
        }
        $this->assertDatasetDescriptorScope($dataset_id, $descriptor);

        $payload = array(
            'dataset_id' => $dataset_id,
            'descriptor' => $descriptor,
            'target_type' => (string)($post['target_type'] ?? 'draft'),
            'target_version_id' => isset($post['target_version_id']) ? (int)$post['target_version_id'] : null,
            'draft_prompt' => (string)($post['draft_prompt'] ?? ''),
            'runtime_overrides' => $this->decodeJson($post['runtime_overrides_json'] ?? '{}'),
            'selected_models' => $this->decodeJson($post['selected_models_json'] ?? '[]'),
            'eval_definition_ids' => $this->decodeJson($post['eval_definition_ids_json'] ?? '[]')
        );
        return $this->evaluation_service->runDatasetEval($payload);
    }

    private function handleGetEvalRun($post)
    {
        $run_id = isset($post['run_id']) ? (int)$post['run_id'] : 0;
        if ($run_id <= 0) {
            throw new Exception('Missing run_id');
        }
        $run = $this->evaluation_service->getEvalRun($run_id);
        if (!$run) {
            throw new Exception('Evaluation run not found');
        }
        return $run;
    }

    private function handleListEvalRunCases($post)
    {
        $run_id = isset($post['run_id']) ? (int)$post['run_id'] : 0;
        if ($run_id <= 0) {
            throw new Exception('Missing run_id');
        }
        return $this->evaluation_service->listEvalRunCases($run_id);
    }

    private function handleListEvalRuns($post)
    {
        $dataset_id = isset($post['dataset_id']) ? (int)$post['dataset_id'] : 0;
        if ($dataset_id <= 0) {
            throw new Exception('Missing dataset_id');
        }
        $this->assertDatasetDescriptorScope($dataset_id, $this->readDescriptor($post));
        $limit = isset($post['limit']) ? (int)$post['limit'] : 20;
        return $this->evaluation_service->listEvalRuns($dataset_id, $limit);
    }

    private function handleDeleteEvalRun($post)
    {
        $run_id = isset($post['run_id']) ? (int)$post['run_id'] : 0;
        if ($run_id <= 0) {
            throw new Exception('Missing run_id');
        }
        $dataset_id = isset($post['dataset_id']) ? (int)$post['dataset_id'] : 0;
        if ($dataset_id <= 0) {
            throw new Exception('Missing dataset_id');
        }
        $this->assertDatasetDescriptorScope($dataset_id, $this->readDescriptor($post));
        return $this->evaluation_service->deleteEvalRun($run_id, $dataset_id);
    }

    private function handleDeleteEvalRunsBulk($post)
    {
        $dataset_id = isset($post['dataset_id']) ? (int)$post['dataset_id'] : 0;
        if ($dataset_id <= 0) {
            throw new Exception('Missing dataset_id');
        }
        $this->assertDatasetDescriptorScope($dataset_id, $this->readDescriptor($post));
        return $this->evaluation_service->deleteEvalRunsForDataset($dataset_id);
    }

    private function assertDatasetDescriptorScope($dataset_id, $descriptor)
    {
        $dataset = $this->dataset_service->getDataset((int)$dataset_id);
        if (!$dataset) {
            throw new Exception('Dataset not found');
        }

        $descriptor_owner_type = (string)($descriptor['owner_type'] ?? '');
        $descriptor_owner_id = (int)($descriptor['owner_id'] ?? 0);
        $dataset_owner_type = (string)($dataset['owner_type_scope'] ?? '');
        $dataset_owner_id = (int)($dataset['owner_id_scope'] ?? 0);

        if ($dataset_owner_type !== '' && $descriptor_owner_type !== '' && $dataset_owner_type !== $descriptor_owner_type) {
            throw new Exception('Dataset is outside current descriptor scope');
        }
        if ($dataset_owner_id > 0 && $descriptor_owner_id > 0 && $dataset_owner_id !== $descriptor_owner_id) {
            throw new Exception('Dataset is outside current descriptor scope');
        }
    }

    private function handleLinkEvalRunBaseline($post)
    {
        $run_id = isset($post['run_id']) ? (int)$post['run_id'] : 0;
        $baseline_run_id = isset($post['baseline_run_id']) ? (int)$post['baseline_run_id'] : 0;
        if ($run_id <= 0 || $baseline_run_id <= 0) {
            throw new Exception('run_id and baseline_run_id are required');
        }

        $baseline_summary = $this->decodeJson($post['baseline_summary_json'] ?? '{}');
        return $this->evaluation_service->linkBaselineRun($run_id, $baseline_run_id, is_array($baseline_summary) ? $baseline_summary : array());
    }

    private function handleSaveHumanScore($post)
    {
        $payload = array(
            'id_llm_eval_run_cases' => isset($post['id_llm_eval_run_cases']) ? (int)$post['id_llm_eval_run_cases'] : 0,
            'id_llm_eval_definitions' => isset($post['id_llm_eval_definitions']) ? (int)$post['id_llm_eval_definitions'] : 0,
            'score_value_numeric' => ($post['score_value_numeric'] ?? '') !== '' ? (float)$post['score_value_numeric'] : null,
            'score_value_label' => (string)($post['score_value_label'] ?? ''),
            'passed' => ($post['passed'] ?? '') !== '' ? (int)$post['passed'] : null,
            'details' => $this->decodeJson($post['details_json'] ?? '{}')
        );
        return $this->evaluation_service->saveHumanScore($payload);
    }

    private function readDescriptor($post)
    {
        $owner_type = (string)($post['owner_type'] ?? ($post['ownerType'] ?? ''));
        $owner_id = isset($post['owner_id']) ? (int)$post['owner_id'] : (isset($post['ownerId']) ? (int)$post['ownerId'] : 0);
        $prompt_slot = (string)($post['prompt_slot'] ?? ($post['promptSlot'] ?? ''));
        $language_id = ($post['id_languages'] ?? ($post['languageId'] ?? ''));
        $page_id = isset($post['page_id']) ? (int)$post['page_id'] : (isset($post['pageId']) ? (int)$post['pageId'] : null);
        $title = $post['title'] ?? null;

        return array(
            'owner_type' => $owner_type,
            'owner_id' => $owner_id,
            'prompt_slot' => $prompt_slot,
            'id_languages' => ($language_id !== '' && $language_id !== null) ? (int)$language_id : null,
            'page_id' => $page_id,
            'title' => $title
        );
    }

    private function resolveRuntimeValues($descriptor, $post)
    {
        $overrides = $this->decodeJson($post['runtime_overrides_json'] ?? '{}');
        if (!is_array($overrides)) {
            $overrides = array();
        }
        return $this->runtime_value_service->resolveRuntimeValues($descriptor, $overrides);
    }

    private function assertAccess($descriptor, $mode)
    {
        $owner_type = (string)($descriptor['owner_type'] ?? '');
        $prompt_slot = (string)($descriptor['prompt_slot'] ?? '');
        if ($owner_type === LLM_PROMPT_OWNER_SCRIPT || ($owner_type === '' && $prompt_slot === 'script')) {
            $page_id = $this->db->fetch_page_id_by_keyword(LLM_SCRIPTS_PAGE_KEYWORD);
            $method = 'has_access_' . $mode;
            if (!$page_id || !$this->acl->$method($_SESSION['id_user'], $page_id)) {
                throw new Exception('Access denied');
            }
            return;
        }

        if ($owner_type === LLM_PROMPT_OWNER_MEMORY_RULE) {
            $page_id = $this->db->fetch_page_id_by_keyword(LLM_MEMORY_PAGE_KEYWORD);
            $method = 'has_access_' . $mode;
            if (!$page_id || !$this->acl->$method($_SESSION['id_user'], $page_id)) {
                throw new Exception('Access denied');
            }
            return;
        }

        $page_id = (int)($descriptor['page_id'] ?? 0);
        if ($page_id <= 0 && (int)($descriptor['owner_id'] ?? 0) > 0) {
            $resolved = $this->db->query_db_first(
                "SELECT id_pages FROM pages_sections WHERE id_sections = :id LIMIT 1",
                array(':id' => (int)$descriptor['owner_id'])
            );
            if (!empty($resolved['id_pages'])) {
                $page_id = (int)$resolved['id_pages'];
            }
        }
        if ($page_id <= 0) {
            throw new Exception('Missing page context');
        }

        $method = 'has_access_' . $mode;
        if (!$this->acl->$method($_SESSION['id_user'], $page_id)) {
            throw new Exception('Access denied');
        }
    }

    private function resolveDescriptorPageId($descriptor)
    {
        $owner_type = (string)($descriptor['owner_type'] ?? '');
        if ($owner_type === LLM_PROMPT_OWNER_MEMORY_RULE) {
            $page_id = $this->db->fetch_page_id_by_keyword(LLM_MEMORY_PAGE_KEYWORD);
            return $page_id ? (int)$page_id : 0;
        }

        $page_id = (int)($descriptor['page_id'] ?? 0);
        if ($page_id > 0) {
            return $page_id;
        }

        $owner_id = (int)($descriptor['owner_id'] ?? 0);
        if ($owner_id <= 0) {
            return 0;
        }

        $resolved = $this->db->query_db_first(
            "SELECT id_pages FROM pages_sections WHERE id_sections = :id LIMIT 1",
            array(':id' => $owner_id)
        );

        return !empty($resolved['id_pages']) ? (int)$resolved['id_pages'] : 0;
    }

    private function assertScriptSourceAccess($mode)
    {
        $this->assertAccess(
            array(
                'owner_type' => LLM_PROMPT_OWNER_SCRIPT,
                'owner_id' => 0,
                'page_id' => null
            ),
            $mode
        );
    }

    private function canMutate($descriptor)
    {
        try {
            $this->assertAccess($descriptor, 'update');
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function assertCsrf($post)
    {
        $token = $post['csrf_token']
            ?? $post['token']
            ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        $session_tokens = array_values(array_filter(array(
            $_SESSION['csrf_token'] ?? '',
            $_SESSION['token'] ?? '',
            $_SESSION['security_token'] ?? ''
        ), function ($value) {
            return is_string($value) && trim($value) !== '';
        }));

        // Some installations do not expose a session CSRF token to plugin AJAX.
        // In that case keep ACL protection and skip token comparison.
        if (empty($session_tokens)) {
            return;
        }

        if (!is_string($token) || trim($token) === '') {
            throw new Exception('Invalid CSRF token');
        }

        foreach ($session_tokens as $session_token) {
            if (hash_equals((string)$session_token, (string)$token)) {
                return;
            }
        }

        throw new Exception('Invalid CSRF token');
    }

    private function decodeJson($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return array();
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array();
        }

        return $decoded;
    }
}
?>
