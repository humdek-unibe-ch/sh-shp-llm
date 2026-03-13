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
  case_key: string;
  title?: string | null;
  case_type_code?: string | null;
  source_type_code?: string | null;
  input_payload_json?: string;
  expected_output_json?: string | null;
  expected_labels_json?: string | null;
  source_ref_json?: string | null;
  tags_json?: string | null;
  notes?: string | null;
  created_at?: string;
  updated_at?: string;
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
  id_llmMessages_request?: number | null;
  id_llmMessages_response?: number | null;
  id_dataRows?: number | null;
  request_content?: string | null;
  response_content?: string | null;
  content?: string | null;
  role?: string | null;
  name?: string | null;
  model?: string | null;
}
