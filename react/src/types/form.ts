/**
 * TypeScript types for LLM Form styles.
 */

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
  debug: boolean;
  llmShowPreviousResult: boolean;
  llmResultFieldName: string;
  previousResult: string | null;
  previousMeta: LlmResultMeta | null;
  userLanguage: string;
  sectionId: number;
}

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

export interface LlmFormResult {
  success: boolean;
  llm_result: string;
  llm_meta: LlmResultMeta;
  error?: string;
}
