/**
 * Prompt System Type Definitions
 * ==============================
 *
 * TypeScript types for the Prompt Lab, Prompt Builder, Playground,
 * and prompt versioning system. These types correspond to the PHP
 * `llm_prompts`, `llm_prompt_locales`, and `llm_prompt_versions`
 * database tables and are used across all prompt-related React components.
 *
 * Also re-exports dataset and evaluation types used by prompt-adjacent features.
 *
 * @module components/prompts/promptTypes
 */

export type {
  PromptDataset,
  PromptDatasetCase,
  PromptExpectedLabels,
  PromptAiImportCaseDraft,
  PromptAiImportParseResponse,
  PromptImportCandidate,
  PromptImportSourceType,
} from '../datasets/datasetTypes';
export type {
  PromptEvalDefinition,
  PromptEvalRunCase,
  PromptEvalRunResult,
  PromptEvalScore,
} from '../evaluations/evaluationTypes';

/** The entity type that owns a prompt (CMS style field, LLM script, or memory rule). */
export type PromptOwnerType = 'style_field' | 'llm_script' | 'llm_memory_rule';

/** Runtime execution context that determines how a prompt is compiled and run. */
export type KnownPromptExecutionProfile =
  | 'chat_runtime'
  | 'form_runtime'
  | 'script_runtime'
  | 'memory_runtime'
  | 'text_only';

/** Execution profile allowing known values plus arbitrary custom strings. */
export type PromptExecutionProfile = KnownPromptExecutionProfile | (string & {});

/** Which playground runtime UI to use for a given execution profile. */
export type PromptPlaygroundRuntimeType = 'chat' | 'form' | 'script' | 'none';

/** Uniquely identifies a prompt in the registry by its owner and slot. */
export interface PromptDescriptor {
  ownerType: PromptOwnerType;
  ownerId: number;
  promptSlot: string;
  languageId?: number | null;
  pageId?: number | null;
  title?: string | null;
}

/** A template variable placeholder with optional type and description metadata. */
export interface PromptVariableDefinition {
  name: string;
  type?: string;
  required?: boolean;
  description?: string;
}

/** A single versioned snapshot of a prompt template with metadata. */
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

/** Prompt meta-state stored in CMS field JSON, including registry IDs and pending change notes. */
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

/** An available LLM model entry for the model selector. */
export interface PromptModel {
  id: string;
  [key: string]: unknown;
}

/** Initial data loaded when the Prompt Lab opens for a specific prompt. */
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

/** A single message in an LLM conversation with a role (system/user/assistant). */
export interface PromptMessage {
  role: 'system' | 'user' | 'assistant';
  content: string;
}

/** Result of a single playground execution against a model. */
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

/** Response from a playground execution, potentially containing multiple model comparison runs. */
export interface PromptPlaygroundResponse {
  execution_profile: PromptExecutionProfile;
  comparison_group_id?: string | null;
  runs: PromptPlaygroundRun[];
}

/** AI-generated suggestion from the Prompt Builder with a revised template. */
export interface PromptBuilderSuggestion {
  prompt_template: string;
  variables: PromptVariableDefinition[];
  notes: string[];
  change_summary: string;
}

/** Prompt contract describing the expected structure and section ordering for a prompt template. */
export interface PromptContract {
  owner_type?: string;
  execution_profile?: string;
  section_order?: string[];
  guidance?: string;
}

/** A dataset case used as an example in the prompt builder conversation. */
export interface PromptBuilderExample {
  score_id?: number;
  run_case_id?: number;
  run_id?: number;
  case_id: number;
  case_key?: string;
  title?: string | null;
  input_payload_json?: string | null;
  expected_output_json?: string | null;
  expected_labels_json?: string | null;
  notes?: string | null;
  tags_json?: string | null;
  dataset_id?: number | null;
  dataset_name?: string | null;
  execution_profile_code?: string | null;
  score_value_numeric?: number | null;
  score_value_label?: string | null;
  approved_at?: string | null;
  approved_by_name?: string | null;
  normalized_output_json?: string | null;
  output_payload_json?: string | null;
}

/** Full response from the AI prompt builder including the suggestion and conversation context. */
export interface PromptBuilderResponse {
  suggestion: PromptBuilderSuggestion;
  model: string;
  prompt_contract?: PromptContract | null;
  request_payload?: unknown;
  logged_message_id?: number | null;
  id_llmConversations?: number | null;
  id_llmMessages_request?: number | null;
  id_llmMessages_response?: number | null;
}

/**
 * Safely parse a JSON-encoded prompt meta string.
 * Returns an empty object on null, undefined, or invalid JSON.
 */
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

/** Serialize a prompt meta-state object to a JSON string, treating null/undefined as empty. */
export function stringifyPromptMeta(meta: PromptMetaState): string {
  return JSON.stringify(meta ?? {});
}
