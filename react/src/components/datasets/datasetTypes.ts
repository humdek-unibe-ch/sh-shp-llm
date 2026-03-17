export interface PromptDataset {
  id: number;
  name: string;
  description?: string | null;
  dataset_type_code?: string | null;
  execution_profile_code?: string | null;
  cases_count?: number;
  owner_type_scope?: string | null;
  owner_id_scope?: number | null;
  is_locked?: number;
}

export interface PromptDatasetCase {
  id: number;
  id_llm_eval_datasets: number;
  link_id?: number | null;
  case_key: string;
  title?: string | null;
  case_type_code?: string | null;
  execution_profile_code?: string | null;
  source_type_code?: string | null;
  input_payload_json?: string;
  expected_output_json?: string | null;
  expected_labels_json?: string | null;
  source_ref_json?: string | null;
  provenance_json?: string | null;
  tags_json?: string | null;
  notes?: string | null;
  promoted_from_dataset_id?: number | null;
  promoted_by_run_case_id?: number | null;
  promotion_mode?: string | null;
  promoted_at?: string | null;
  evaluation_runs_count?: number;
  created_at?: string;
  updated_at?: string;
}

export interface PromptExpectedLabels {
  safety?: {
    danger_level?: null | 'warning' | 'critical' | 'emergency';
  };
}

export type PromptImportSourceType =
  | 'playground_run'
  | 'form_submission'
  | 'conversation_message'
  | 'script_run';

export interface PromptImportCandidate {
  id: number;
  created_at?: string;
  updated_at?: string;
  id_llmConversations?: number | null;
  id_llm_scripts?: number | null;
  id_llmMessages_request?: number | null;
  id_llmMessages_response?: number | null;
  id_dataRows?: number | null;
  request_content?: string | null;
  response_content?: string | null;
  content?: string | null;
  role?: string | null;
  name?: string | null;
  model?: string | null;
  preview_text?: string | null;
  assistant_preview?: string | null;
}

export interface PromptAiImportCaseDraft {
  title: string;
  case_type?: string;
  source_type?: string;
  input_payload: Record<string, unknown>;
  expected_output?: Record<string, unknown> | null;
  expected_labels?: Record<string, unknown> | null;
  source_ref?: Record<string, unknown> | null;
  tags?: string[];
  notes?: string;
}

export interface PromptAiImportParseResponse {
  mapping: Record<string, unknown>;
  cases: PromptAiImportCaseDraft[];
  warnings?: string[];
  model?: string | null;
  request_payload?: unknown;
  id_llmConversations?: number | null;
  id_llmMessages_request?: number | null;
  id_llmMessages_response?: number | null;
}
