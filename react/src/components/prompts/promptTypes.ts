export type {
  PromptDataset,
  PromptDatasetCase,
  PromptImportCandidate,
  PromptImportSourceType,
} from '../datasets/datasetTypes';
export type {
  PromptEvalDefinition,
  PromptEvalRunCase,
  PromptEvalRunResult,
  PromptEvalScore,
} from '../evaluations/evaluationTypes';

export type PromptOwnerType = 'style_field' | 'llm_script';

export type KnownPromptExecutionProfile =
  | 'chat_runtime'
  | 'form_runtime'
  | 'script_runtime'
  | 'text_only';

export type PromptExecutionProfile = KnownPromptExecutionProfile | (string & {});

export type PromptPlaygroundRuntimeType = 'chat' | 'form' | 'script' | 'none';

export interface PromptDescriptor {
  ownerType: PromptOwnerType;
  ownerId: number;
  promptSlot: string;
  languageId?: number | null;
  pageId?: number | null;
  title?: string | null;
}

export interface PromptVariableDefinition {
  name: string;
  type?: string;
  required?: boolean;
  description?: string;
}

export interface PromptVersion {
  id: number;
  id_llm_prompt_locales: number;
  version_no: number;
  template_raw: string;
  template_hash?: string;
  config_json?: string | null;
  metadata_json?: string | null;
  variables_schema_json?: string | null;
  tags_json?: string | null;
  change_note?: string | null;
  based_on_version_id?: number | null;
  created_at: string;
  created_user_name?: string | null;
  active_version_id?: number | null;
}

export interface PromptMetaState {
  prompt?: {
    entryId?: number | null;
    localeId?: number | null;
    activeVersionId?: number | null;
    activeVersionNo?: number | null;
    lastComparedVersionId?: number | null;
    pendingChangeNote?: string;
    variablesSchema?: PromptVariableDefinition[];
  };
  [key: string]: unknown;
}

export interface PromptModel {
  id: string;
  [key: string]: unknown;
}

export interface PromptBootstrapData {
  entry?: { id: number } | null;
  locale?: { id: number } | null;
  active_version?: PromptVersion | null;
  versions: PromptVersion[];
  execution_profile: PromptExecutionProfile;
  playground_runtime_type?: PromptPlaygroundRuntimeType;
  companion_field_names: string[];
  variables_schema: PromptVariableDefinition[];
  models: PromptModel[];
  meta?: PromptMetaState;
}

export interface PromptMessage {
  role: 'system' | 'user' | 'assistant';
  content: string;
}

export interface PromptPlaygroundRun {
  model: string;
  execution_profile: PromptExecutionProfile;
  raw_content: string;
  display_content: string;
  parsed_response?: Record<string, unknown> | null;
  safety?: Record<string, unknown> | null;
  request_payload?: unknown;
  effective_context?: PromptMessage[] | Record<string, unknown> | null;
  id_llmConversations?: number | null;
  id_llmMessages_request?: number | null;
  id_llmMessages_response?: number | null;
  tokens_used?: number | null;
  duration_ms?: number | null;
  logged_message_id?: number | null;
  id_llm_prompt_playground_runs?: number | null;
  parse_errors?: string[];
  is_fallback?: boolean;
}

export interface PromptPlaygroundResponse {
  execution_profile: PromptExecutionProfile;
  comparison_group_id?: string | null;
  runs: PromptPlaygroundRun[];
}

export interface PromptBuilderSuggestion {
  prompt_template: string;
  variables: PromptVariableDefinition[];
  notes: string[];
  change_summary: string;
}

export interface PromptBuilderResponse {
  suggestion: PromptBuilderSuggestion;
  model: string;
  request_payload?: unknown;
  logged_message_id?: number | null;
  id_llmConversations?: number | null;
  id_llmMessages_request?: number | null;
  id_llmMessages_response?: number | null;
}

export function parsePromptMeta(value: string | null | undefined): PromptMetaState {
  if (!value) {
    return {};
  }

  try {
    const parsed = JSON.parse(value) as PromptMetaState;
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
}

export function stringifyPromptMeta(meta: PromptMetaState): string {
  return JSON.stringify(meta ?? {});
}
