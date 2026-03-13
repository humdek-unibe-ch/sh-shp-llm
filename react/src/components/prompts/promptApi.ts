import type {
  PromptBootstrapData,
  PromptBuilderResponse,
  PromptDataset,
  PromptDatasetCase,
  PromptDescriptor,
  PromptEvalDefinition,
  PromptEvalRunCase,
  PromptEvalRunResult,
  PromptImportCandidate,
  PromptImportSourceType,
  PromptMessage,
  PromptPlaygroundResponse,
} from './promptTypes';

declare const BASE_PATH: string;

interface AjaxEnvelope<T> {
  success: boolean;
  data: T | string | null;
}

interface PlaygroundRunRequest {
  descriptor: PromptDescriptor;
  draftPrompt: string;
  runtimeOverrides?: Record<string, unknown>;
  variables?: Record<string, unknown>;
  messageHistory?: PromptMessage[];
  selectedModels?: string[];
}

interface BuilderRunRequest {
  descriptor: PromptDescriptor;
  currentPrompt: string;
  instructions: string;
  selectedModel?: string | null;
}

interface AddCaseFromPlaygroundRequest {
  descriptor: PromptDescriptor;
  datasetId: number;
  executionProfile: string;
  title?: string;
  runtimeOverrides?: Record<string, unknown>;
  variables?: Record<string, unknown>;
  messageHistory?: PromptMessage[];
  runRef?: {
    id_llm_prompt_playground_runs?: number | null;
    id_llmConversations?: number | null;
    id_llmMessages_request?: number | null;
    id_llmMessages_response?: number | null;
  };
}

interface RunDatasetEvalRequest {
  descriptor: PromptDescriptor;
  datasetId: number;
  targetType: 'draft' | 'version' | 'active_version';
  draftPrompt: string;
  targetVersionId?: number;
  runtimeOverrides?: Record<string, unknown>;
  selectedModels?: string[];
  evalDefinitionIds?: number[];
}

interface AddCasesFromSourceRequest {
  descriptor: PromptDescriptor;
  datasetId: number;
  sourceType: PromptImportSourceType;
  sourceIds: number[];
  executionProfile: string;
  runtimeOverrides?: Record<string, unknown>;
}

interface SaveHumanScoreRequest {
  descriptor: PromptDescriptor;
  runCaseId: number;
  definitionId: number;
  scoreValueNumeric?: number | null;
  scoreValueLabel?: string | null;
  passed?: number | null;
  details?: Record<string, unknown>;
}

interface LinkEvalRunBaselineRequest {
  descriptor: PromptDescriptor;
  runId: number;
  baselineRunId: number;
  baselineSummary?: Record<string, unknown>;
}

function appendDescriptor(formData: FormData, descriptor: PromptDescriptor): void {
  formData.append('owner_type', descriptor.ownerType);
  formData.append('owner_id', String(descriptor.ownerId));
  formData.append('prompt_slot', descriptor.promptSlot);

  if (descriptor.languageId != null) {
    formData.append('id_languages', String(descriptor.languageId));
  }
  if (descriptor.pageId != null) {
    formData.append('page_id', String(descriptor.pageId));
  }
  if (descriptor.title) {
    formData.append('title', descriptor.title);
  }
}

function extractErrorMessage(data: unknown): string {
  if (typeof data === 'string' && data.trim() !== '') {
    return data;
  }
  if (data && typeof data === 'object') {
    const maybeError = (data as Record<string, unknown>).error;
    if (typeof maybeError === 'string' && maybeError.trim() !== '') {
      return maybeError;
    }
  }
  return 'Prompt request failed';
}

function normalizePromptEndpoint(endpoint: string): string {
  if (!endpoint) {
    return endpoint;
  }

  if (/^https?:\/\//i.test(endpoint)) {
    return endpoint;
  }

  const basePathFromGlobal = (() => {
    if (typeof BASE_PATH !== 'undefined' && BASE_PATH) {
      return BASE_PATH;
    }
    const maybeWindowBasePath = (window as any).BASE_PATH;
    if (typeof maybeWindowBasePath === 'string' && maybeWindowBasePath) {
      return maybeWindowBasePath;
    }
    return '';
  })();

  if (endpoint.startsWith('/request/') && basePathFromGlobal) {
    return `${basePathFromGlobal}${endpoint}`;
  }

  return endpoint;
}

function resolveCsrfToken(explicitToken?: string): string {
  if (explicitToken && explicitToken.trim() !== '') {
    return explicitToken;
  }

  const hiddenInput = document.querySelector<HTMLInputElement>(
    'input[name="csrf_token"], input[name="token"], input[name="_token"]',
  );
  if (hiddenInput?.value) {
    return hiddenInput.value;
  }

  const win = window as any;
  if (typeof win.csrf_token === 'string' && win.csrf_token) {
    return win.csrf_token;
  }
  if (typeof win.CSRF_TOKEN === 'string' && win.CSRF_TOKEN) {
    return win.CSRF_TOKEN;
  }

  return '';
}

export function createPromptLabApi(endpoint: string, csrfToken?: string) {
  const resolvedEndpoint = normalizePromptEndpoint(endpoint);
  const resolvedCsrfToken = resolveCsrfToken(csrfToken);

  async function post<T>(formData: FormData): Promise<T> {
    const response = await fetch(resolvedEndpoint, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });

    const rawText = await response.text();
    let payload: AjaxEnvelope<T> | null = null;

    try {
      payload = JSON.parse(rawText) as AjaxEnvelope<T>;
    } catch {
      const snippet = rawText.slice(0, 160).replace(/\s+/g, ' ').trim();
      throw new Error(
        `Prompt API returned non-JSON response from "${resolvedEndpoint}". ` +
        `This usually means the endpoint URL is wrong or not accessible. Response starts with: ${snippet}`,
      );
    }

    if (!response.ok || !payload.success) {
      throw new Error(extractErrorMessage(payload.data));
    }

    if (
      payload.data
      && typeof payload.data === 'object'
      && typeof (payload.data as Record<string, unknown>).error === 'string'
      && ((payload.data as Record<string, unknown>).error as string).trim() !== ''
    ) {
      throw new Error((payload.data as Record<string, unknown>).error as string);
    }

    return payload.data as T;
  }

  return {
    async bootstrapOwner(
      descriptor: PromptDescriptor,
      currentContent: string,
      currentMeta: string,
      runtimeOverrides?: Record<string, unknown>,
    ): Promise<PromptBootstrapData> {
      const formData = new FormData();
      formData.append('action', 'bootstrap_owner');
      appendDescriptor(formData, descriptor);
      formData.append('current_content', currentContent);
      formData.append('current_meta', currentMeta || '{}');
      if (runtimeOverrides) {
        formData.append('runtime_overrides_json', JSON.stringify(runtimeOverrides));
      }
      return post<PromptBootstrapData>(formData);
    },

    async listVersions(
      descriptor: PromptDescriptor,
      currentContent: string,
      currentMeta: string,
    ): Promise<PromptBootstrapData['versions']> {
      const formData = new FormData();
      formData.append('action', 'list_versions');
      appendDescriptor(formData, descriptor);
      formData.append('current_content', currentContent);
      formData.append('current_meta', currentMeta || '{}');
      const result = await post<{ versions: PromptBootstrapData['versions'] }>(formData);
      return result.versions || [];
    },

    async getVersion(versionId: number, descriptor?: PromptDescriptor) {
      const formData = new FormData();
      formData.append('action', 'get_version');
      formData.append('version_id', String(versionId));
      if (descriptor) {
        appendDescriptor(formData, descriptor);
      }
      return post(formData);
    },

    async playgroundRun({
      descriptor,
      draftPrompt,
      runtimeOverrides,
      variables,
      messageHistory,
      selectedModels,
    }: PlaygroundRunRequest): Promise<PromptPlaygroundResponse> {
      const formData = new FormData();
      formData.append('action', 'playground_run');
      appendDescriptor(formData, descriptor);
      formData.append('draft_prompt', draftPrompt);
      formData.append('csrf_token', resolvedCsrfToken);
      formData.append('runtime_overrides_json', JSON.stringify(runtimeOverrides || {}));
      formData.append('variables_json', JSON.stringify(variables || {}));
      formData.append('message_history_json', JSON.stringify(messageHistory || []));
      formData.append('selected_models_json', JSON.stringify(selectedModels || []));
      return post<PromptPlaygroundResponse>(formData);
    },

    async builderRun({
      descriptor,
      currentPrompt,
      instructions,
      selectedModel,
    }: BuilderRunRequest): Promise<PromptBuilderResponse> {
      const formData = new FormData();
      formData.append('action', 'builder_run');
      appendDescriptor(formData, descriptor);
      formData.append('current_prompt', currentPrompt);
      formData.append('instructions', instructions);
      formData.append('csrf_token', resolvedCsrfToken);
      if (selectedModel) {
        formData.append('selected_model', selectedModel);
      }
      return post<PromptBuilderResponse>(formData);
    },

    async listDatasets(
      descriptor: PromptDescriptor,
      filters?: {
        search?: string;
        ownerTypeScope?: string;
        ownerIdScope?: number | null;
        executionProfile?: string;
      },
    ): Promise<PromptDataset[]> {
      const formData = new FormData();
      formData.append('action', 'list_datasets');
      appendDescriptor(formData, descriptor);
      if (filters?.search) formData.append('search', filters.search);
      if (filters?.ownerTypeScope) formData.append('owner_type_scope', filters.ownerTypeScope);
      if (filters?.ownerIdScope != null) formData.append('owner_id_scope', String(filters.ownerIdScope));
      if (filters?.executionProfile) formData.append('execution_profile', filters.executionProfile);
      return post<PromptDataset[]>(formData);
    },

    async createDataset(
      descriptor: PromptDescriptor,
      name: string,
      executionProfile: string,
      description = '',
      datasetType = 'golden_manual',
    ): Promise<PromptDataset> {
      const formData = new FormData();
      formData.append('action', 'create_dataset');
      appendDescriptor(formData, descriptor);
      formData.append('name', name);
      formData.append('description', description);
      formData.append('dataset_type', datasetType);
      formData.append('execution_profile', executionProfile);
      formData.append('csrf_token', resolvedCsrfToken);
      return post<PromptDataset>(formData);
    },

    async getDataset(descriptor: PromptDescriptor, datasetId: number): Promise<{ dataset: PromptDataset; cases: PromptDatasetCase[] }> {
      const formData = new FormData();
      formData.append('action', 'get_dataset');
      appendDescriptor(formData, descriptor);
      formData.append('dataset_id', String(datasetId));
      return post<{ dataset: PromptDataset; cases: PromptDatasetCase[] }>(formData);
    },

    async updateDataset(
      descriptor: PromptDescriptor,
      datasetId: number,
      payload: {
        name?: string;
        description?: string;
        datasetType?: string;
        executionProfile?: string;
        isLocked?: boolean;
      },
    ): Promise<PromptDataset> {
      const formData = new FormData();
      formData.append('action', 'update_dataset');
      appendDescriptor(formData, descriptor);
      formData.append('dataset_id', String(datasetId));
      if (payload.name != null) formData.append('name', payload.name);
      if (payload.description != null) formData.append('description', payload.description);
      if (payload.datasetType != null) formData.append('dataset_type', payload.datasetType);
      if (payload.executionProfile != null) formData.append('execution_profile', payload.executionProfile);
      if (payload.isLocked != null) formData.append('is_locked', payload.isLocked ? '1' : '0');
      formData.append('csrf_token', resolvedCsrfToken);
      return post<PromptDataset>(formData);
    },

    async deleteDataset(descriptor: PromptDescriptor, datasetId: number): Promise<{ deleted: boolean }> {
      const formData = new FormData();
      formData.append('action', 'delete_dataset');
      appendDescriptor(formData, descriptor);
      formData.append('dataset_id', String(datasetId));
      formData.append('csrf_token', resolvedCsrfToken);
      return post<{ deleted: boolean }>(formData);
    },

    async listDatasetCases(descriptor: PromptDescriptor, datasetId: number): Promise<PromptDatasetCase[]> {
      const formData = new FormData();
      formData.append('action', 'list_dataset_cases');
      appendDescriptor(formData, descriptor);
      formData.append('dataset_id', String(datasetId));
      return post<PromptDatasetCase[]>(formData);
    },

    async addCaseFromPlaygroundRun({
      descriptor,
      datasetId,
      executionProfile,
      title,
      runtimeOverrides,
      variables,
      messageHistory,
      runRef,
    }: AddCaseFromPlaygroundRequest): Promise<PromptDatasetCase> {
      const formData = new FormData();
      formData.append('action', 'add_case_from_playground_run');
      appendDescriptor(formData, descriptor);
      formData.append('dataset_id', String(datasetId));
      formData.append('execution_profile', executionProfile);
      formData.append('title', title || '');
      formData.append('runtime_overrides_json', JSON.stringify(runtimeOverrides || {}));
      formData.append('variables_json', JSON.stringify(variables || {}));
      formData.append('message_history_json', JSON.stringify(messageHistory || []));
      if (runRef?.id_llm_prompt_playground_runs != null) {
        formData.append('id_llm_prompt_playground_runs', String(runRef.id_llm_prompt_playground_runs));
      }
      if (runRef?.id_llmConversations != null) {
        formData.append('id_llmConversations', String(runRef.id_llmConversations));
      }
      if (runRef?.id_llmMessages_request != null) {
        formData.append('id_llmMessages_request', String(runRef.id_llmMessages_request));
      }
      if (runRef?.id_llmMessages_response != null) {
        formData.append('id_llmMessages_response', String(runRef.id_llmMessages_response));
      }
      formData.append('csrf_token', resolvedCsrfToken);
      return post<PromptDatasetCase>(formData);
    },

    async getImportCandidates(
      descriptor: PromptDescriptor,
      sourceType: PromptImportSourceType,
      limit = 50,
    ): Promise<PromptImportCandidate[]> {
      const formData = new FormData();
      formData.append('action', 'get_import_candidates');
      appendDescriptor(formData, descriptor);
      formData.append('source_type', sourceType);
      formData.append('limit', String(limit));
      return post<PromptImportCandidate[]>(formData);
    },

    async addCasesFromSource({
      descriptor,
      datasetId,
      sourceType,
      sourceIds,
      executionProfile,
      runtimeOverrides,
    }: AddCasesFromSourceRequest): Promise<PromptDatasetCase[]> {
      const formData = new FormData();
      formData.append('action', 'add_cases_from_source');
      appendDescriptor(formData, descriptor);
      formData.append('dataset_id', String(datasetId));
      formData.append('source_type', sourceType);
      formData.append('source_ids_json', JSON.stringify(sourceIds || []));
      formData.append('execution_profile', executionProfile);
      formData.append('runtime_overrides_json', JSON.stringify(runtimeOverrides || {}));
      formData.append('csrf_token', resolvedCsrfToken);
      return post<PromptDatasetCase[]>(formData);
    },

    async deleteDatasetCase(descriptor: PromptDescriptor, datasetCaseId: number): Promise<{ deleted: boolean }> {
      const formData = new FormData();
      formData.append('action', 'delete_dataset_case');
      appendDescriptor(formData, descriptor);
      formData.append('dataset_case_id', String(datasetCaseId));
      formData.append('csrf_token', resolvedCsrfToken);
      return post<{ deleted: boolean }>(formData);
    },

    async listEvalDefinitions(descriptor: PromptDescriptor): Promise<PromptEvalDefinition[]> {
      const formData = new FormData();
      formData.append('action', 'list_eval_definitions');
      appendDescriptor(formData, descriptor);
      return post<PromptEvalDefinition[]>(formData);
    },

    async runDatasetEval({
      descriptor,
      datasetId,
      targetType,
      draftPrompt,
      targetVersionId,
      runtimeOverrides,
      selectedModels,
      evalDefinitionIds,
    }: RunDatasetEvalRequest): Promise<PromptEvalRunResult> {
      const formData = new FormData();
      formData.append('action', 'run_dataset_eval');
      appendDescriptor(formData, descriptor);
      formData.append('dataset_id', String(datasetId));
      formData.append('target_type', targetType);
      formData.append('draft_prompt', draftPrompt);
      if (targetVersionId != null) {
        formData.append('target_version_id', String(targetVersionId));
      }
      formData.append('runtime_overrides_json', JSON.stringify(runtimeOverrides || {}));
      formData.append('selected_models_json', JSON.stringify(selectedModels || []));
      formData.append('eval_definition_ids_json', JSON.stringify(evalDefinitionIds || []));
      formData.append('csrf_token', resolvedCsrfToken);
      return post<PromptEvalRunResult>(formData);
    },

    async getEvalRun(descriptor: PromptDescriptor, runId: number): Promise<PromptEvalRunResult['run']> {
      const formData = new FormData();
      formData.append('action', 'get_eval_run');
      appendDescriptor(formData, descriptor);
      formData.append('run_id', String(runId));
      return post<PromptEvalRunResult['run']>(formData);
    },

    async listEvalRunCases(descriptor: PromptDescriptor, runId: number): Promise<PromptEvalRunCase[]> {
      const formData = new FormData();
      formData.append('action', 'list_eval_run_cases');
      appendDescriptor(formData, descriptor);
      formData.append('run_id', String(runId));
      return post<PromptEvalRunCase[]>(formData);
    },

    async listEvalRuns(descriptor: PromptDescriptor, datasetId: number, limit = 20): Promise<Array<Record<string, unknown>>> {
      const formData = new FormData();
      formData.append('action', 'list_eval_runs');
      appendDescriptor(formData, descriptor);
      formData.append('dataset_id', String(datasetId));
      formData.append('limit', String(limit));
      return post<Array<Record<string, unknown>>>(formData);
    },

    async linkEvalRunBaseline({
      descriptor,
      runId,
      baselineRunId,
      baselineSummary,
    }: LinkEvalRunBaselineRequest): Promise<Record<string, unknown>> {
      const formData = new FormData();
      formData.append('action', 'link_eval_run_baseline');
      appendDescriptor(formData, descriptor);
      formData.append('run_id', String(runId));
      formData.append('baseline_run_id', String(baselineRunId));
      formData.append('baseline_summary_json', JSON.stringify(baselineSummary || {}));
      formData.append('csrf_token', resolvedCsrfToken);
      return post<Record<string, unknown>>(formData);
    },

    async saveHumanScore({
      descriptor,
      runCaseId,
      definitionId,
      scoreValueNumeric,
      scoreValueLabel,
      passed,
      details,
    }: SaveHumanScoreRequest): Promise<unknown> {
      const formData = new FormData();
      formData.append('action', 'save_human_score');
      appendDescriptor(formData, descriptor);
      formData.append('id_llm_eval_run_cases', String(runCaseId));
      formData.append('id_llm_eval_definitions', String(definitionId));
      if (scoreValueNumeric != null) {
        formData.append('score_value_numeric', String(scoreValueNumeric));
      }
      if (scoreValueLabel != null) {
        formData.append('score_value_label', scoreValueLabel);
      }
      if (passed != null) {
        formData.append('passed', String(passed));
      }
      formData.append('details_json', JSON.stringify(details || {}));
      formData.append('csrf_token', resolvedCsrfToken);
      return post<unknown>(formData);
    },
  };
}
