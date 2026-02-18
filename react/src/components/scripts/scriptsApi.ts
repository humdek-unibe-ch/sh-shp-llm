/**
 * LLM Scripts API Layer
 * =====================
 * Communicates with ModuleLlmScriptController via the current page URL.
 * Uses window.location (same pattern as AdminConsole).
 * GET requests append ?action=xxx, POST requests send action in FormData.
 */

export interface LlmScript {
  id: number;
  generated_id: string;
  name: string;
  script: string;
  data_config: string;
  test_variables: string;
  async: number | boolean;
  model: string | null;
  temperature: string | null;
  max_tokens: number | null;
  refresh_sections: string | null;
  created_at: string;
  updated_at: string;
}

export interface LlmModel {
  id: string;
  [key: string]: unknown;
}

export interface SectionInfo {
  id: number;
  name: string;
}

export interface LlmDefaults {
  default_model: string;
  default_temperature: number;
  default_max_tokens: number;
  acl: {
    select: boolean;
    insert: boolean;
    update: boolean;
    delete: boolean;
  };
}

function buildUrl(action: string, extraParams: Record<string, string> = {}): string {
  const url = new URL(window.location.href);
  url.searchParams.set('action', action);

  Object.entries(extraParams).forEach(([key, value]) => {
    url.searchParams.set(key, value);
  });

  return url.toString();
}

async function apiGet<T>(action: string, params: Record<string, string> = {}): Promise<T> {
  const url = buildUrl(action, params);

  const response = await fetch(url, {
    method: 'GET',
    headers: {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
    credentials: 'same-origin',
  });

  if (!response.ok) {
    const data = await response.json().catch(() => ({}));
    throw new Error(data.error || `HTTP ${response.status}`);
  }

  return response.json();
}

async function apiPost<T>(formData: FormData): Promise<T> {
  const response = await fetch(window.location.pathname, {
    method: 'POST',
    body: formData,
    headers: {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
    credentials: 'same-origin',
  });

  if (!response.ok) {
    const data = await response.json().catch(() => ({}));
    throw new Error(data.error || `HTTP ${response.status}`);
  }

  return response.json();
}

export function createScriptsApi() {
  return {
    async list(): Promise<LlmScript[]> {
      const res = await apiGet<{ scripts: LlmScript[] }>('list');
      return res.scripts || [];
    },

    async get(sid: number): Promise<LlmScript> {
      const res = await apiGet<{ script: LlmScript; error?: string }>('get', { sid: String(sid) });
      if (res.error) throw new Error(res.error);
      return res.script;
    },

    async create(): Promise<LlmScript> {
      const formData = new FormData();
      formData.append('action', 'create');
      const res = await apiPost<{ script: LlmScript; error?: string }>(formData);
      if (res.error) throw new Error(res.error);
      return res.script;
    },

    async update(script: Partial<LlmScript> & { sid: number }): Promise<LlmScript> {
      const formData = new FormData();
      formData.append('action', 'update');
      Object.entries(script).forEach(([key, value]) => {
        if (value !== null && value !== undefined) {
          formData.append(key, String(value));
        }
      });
      const res = await apiPost<{ script: LlmScript; error?: string }>(formData);
      if (res.error) throw new Error(res.error);
      return res.script;
    },

    async remove(sid: number): Promise<void> {
      const formData = new FormData();
      formData.append('action', 'delete');
      formData.append('sid', String(sid));
      const res = await apiPost<{ success?: boolean; error?: string }>(formData);
      if (res.error) throw new Error(res.error);
    },

    async test(data: {
      script: string;
      script_name?: string;
      sid?: string;
      test_variables?: string;
      data_config?: string;
      model?: string;
      temperature?: string;
      max_tokens?: string;
    }): Promise<Record<string, unknown>> {
      const formData = new FormData();
      formData.append('action', 'test');
      Object.entries(data).forEach(([key, value]) => {
        if (value !== null && value !== undefined) {
          formData.append(key, String(value));
        }
      });
      return apiPost<Record<string, unknown>>(formData);
    },

    async getConfig(): Promise<LlmDefaults> {
      return apiGet<LlmDefaults>('config');
    },

    async getModels(): Promise<LlmModel[]> {
      const res = await apiGet<{ models: LlmModel[] }>('models');
      return res.models || [];
    },

    async getSections(): Promise<SectionInfo[]> {
      const res = await apiGet<{ sections: SectionInfo[] }>('sections');
      return res.sections || [];
    },
  };
}
