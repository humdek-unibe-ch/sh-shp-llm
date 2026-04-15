/**
 * TypeScript types for LLM Form styles (llmFormRecord / llmFormLog).
 *
 * Covers the configuration passed from the PHP view via `data-config`,
 * the result metadata returned after LLM generation, and the full
 * response envelope used by the form panel.
 *
 * @module types/form
 */

/** Configuration object parsed from the `data-config` attribute on the form root. */
export interface LlmFormConfig {
  llmEnabled: boolean;
  llmModel: string;
  llmTemperature: number;
  llmMaxTokens: number;
  llmResultPlacement: 'top' | 'bottom' | 'left' | 'right';
  llmResultPanel: 'default' | 'card' | 'modal' | 'collapse';
  llmResultTitle: string;
  llmResultClosable: boolean;
  llmResultCss: string;
  llmResultCssMobile: string;
  llmShowErrors: boolean;
  llmRetryEnabled: boolean;
  llmRetryLabel: string;
  llmRegenerateEnabled: boolean;
  llmRegenerateLabel: string;
  llmGeneratingText: string;
  useSmallButtons: boolean;
  manualFeedbackEnabled: boolean;
  feedbackButtonLabel: string;
  feedbackButtonColor: string;
  contextFieldKeys: string[];
  debug: boolean;
  llmShowPreviousResult: boolean;
  llmResultFieldName: string;
  previousResult: string | null;
  previousMeta: LlmResultMeta | null;
  userLanguage: string;
  sectionId: number;
}

/** Metadata attached to each LLM generation result for audit and display. */
export interface LlmResultMeta {
  model: string;
  temperature: number;
  max_tokens: number;
  tokens_used: number;
  timestamp: string;
  status: 'success' | 'error';
  language: string;
  error?: string;
}

/** Full response envelope returned by the PHP controller after LLM form submission. */
export interface LlmFormResult {
  success: boolean;
  llm_result: string;
  llm_meta: LlmResultMeta;
  error?: string;
  manual_feedback_mode?: boolean;
  form_errors?: Record<string, string> | null;
}
