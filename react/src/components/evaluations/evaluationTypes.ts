export interface PromptEvalDefinition {
  id: number;
  name: string;
  eval_type_code?: string | null;
  description?: string | null;
  config_json?: string | null;
}

export interface PromptEvalScore {
  id?: number;
  id_llm_eval_definitions?: number;
  eval_name?: string;
  score_type: string;
  score_value_numeric?: number | null;
  score_value_label?: string | null;
  passed?: number | null;
  details_json?: string | null;
  details?: Record<string, unknown> | null;
}

export interface PromptEvalRunCase {
  run_case_id?: number;
  id_llm_eval_runs?: number;
  dataset_case_id?: number;
  case_id?: number;
  title?: string;
  comparison_label?: 'Target' | 'Baseline' | string;
  passed?: boolean;
  status?: 'passed' | 'failed' | 'pending_review';
  model?: string;
  run_created_at?: string;
  dataset_name?: string;
  input_preview?: string;
  input_fields?: Array<{ key: string; value: string }>;
  input_payload_json?: string | null;
  input_payload?: Record<string, unknown> | null;
  display_content?: string;
  id?: number;
  id_llm_eval_dataset_cases?: number;
  id_llm_eval_cases?: number;
  dataset_case_title?: string;
  normalized_output?: Record<string, unknown> | null;
  normalized_output_json?: string | null;
  scores: PromptEvalScore[];
}

export interface PromptEvalRunResult {
  run: {
    id: number;
    summary?: {
      dataset_case_count?: number;
      execution_count?: number;
      total_cases?: number;
      pass_count?: number;
      fail_count?: number;
      pending_review_count?: number;
      pass_rate?: number;
      avg_score?: number | null;
      models?: string[];
      failure_buckets?: Array<{ label: string; count: number }>;
      definition_summaries?: Array<{
        name: string;
        total: number;
        pass_count: number;
        fail_count: number;
        pending_count: number;
        avg_score?: number | null;
      }>;
    };
    [key: string]: unknown;
  };
  cases: PromptEvalRunCase[];
}
