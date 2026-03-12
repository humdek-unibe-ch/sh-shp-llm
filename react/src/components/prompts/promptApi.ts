import type {
  PromptBootstrapData,
  PromptBuilderResponse,
  PromptDescriptor,
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

export function createPromptLabApi(endpoint: string, csrfToken?: string) {
  const resolvedEndpoint = normalizePromptEndpoint(endpoint);

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

    async getVersion(versionId: number) {
      const formData = new FormData();
      formData.append('action', 'get_version');
      formData.append('version_id', String(versionId));
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
      formData.append('csrf_token', csrfToken || '');
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
      formData.append('csrf_token', csrfToken || '');
      if (selectedModel) {
        formData.append('selected_model', selectedModel);
      }
      return post<PromptBuilderResponse>(formData);
    },
  };
}
