/**
 * LLM Chat Type Definitions
 * =========================
 * 
 * TypeScript type definitions for the LLM Chat React component.
 * These types match the data structures used by the SelfHelp backend
 * controller (LlmChatController.php) and the vanilla JS implementation.
 * 
 * @module types
 */

// ============================================================================
// FILE CONFIGURATION TYPES
// ============================================================================

/**
 * File upload configuration matching the vanilla JS FILE_CONFIG
 * These values are typically passed from the PHP backend via data attributes
 */
export interface FileConfig {
  /** Maximum file size in bytes (default: 10MB) */
  maxFileSize: number;
  /** Maximum number of files per message (default: 5) */
  maxFilesPerMessage: number;
  /** Allowed image file extensions */
  allowedImageExtensions: string[];
  /** Allowed document file extensions */
  allowedDocumentExtensions: string[];
  /** Allowed code file extensions */
  allowedCodeExtensions: string[];
  /** All allowed extensions combined */
  allowedExtensions: string[];
  /** Models that support vision/image processing */
  visionModels: string[];
}

/**
 * Default file configuration values
 * Matches DEFAULT_FILE_CONFIG from vanilla JS
 */
export const DEFAULT_FILE_CONFIG: FileConfig = {
  maxFileSize: 10 * 1024 * 1024, // 10MB
  maxFilesPerMessage: 5,
  allowedImageExtensions: ['jpg', 'jpeg', 'png', 'gif', 'webp'],
  allowedDocumentExtensions: ['pdf', 'txt', 'md', 'csv', 'json', 'xml'],
  allowedCodeExtensions: ['py', 'js', 'php', 'html', 'css', 'sql', 'sh', 'yaml', 'yml'],
  allowedExtensions: [
    'jpg', 'jpeg', 'png', 'gif', 'webp',
    'pdf', 'txt', 'md', 'csv', 'json', 'xml',
    'py', 'js', 'php', 'html', 'css', 'sql', 'sh', 'yaml', 'yml'
  ],
  visionModels: ['internvl3-8b-instruct', 'qwen3-vl-8b-instruct']
};

// ============================================================================
// CORE DATA TYPES
// ============================================================================

/**
 * Message object as returned by the controller
 * Matches the database structure and API response format
 */
export interface Message {
  /** Unique message identifier */
  id: string;
  /** Parent conversation ID */
  conversation_id?: string;
  /** Message role: 'user' or 'assistant' */
  role: 'user' | 'assistant' | 'system';
  /** Raw message content (may contain markdown) */
  content: string;
  /** Pre-formatted HTML content from backend */
  formatted_content?: string;
  /** Message creation timestamp (ISO format) */
  timestamp: string;
  /** Number of tokens used (for assistant messages) */
  tokens_used?: number;
  /** JSON string of file attachments */
  attachments?: string;
  /** Model used for this message */
  model?: string;
  /** JSON snapshot of context sent with this message for debugging/audit */
  sent_context?: string;
}

/**
 * Conversation object as returned by the controller
 * Matches the llmConversations table structure
 */
export interface Conversation {
  /** Unique conversation identifier */
  id: string;
  /** Owner user ID */
  user_id?: number;
  /** Conversation title (auto-generated or user-defined) */
  title: string;
  /** LLM model used for this conversation */
  model: string;
  /** Temperature setting (0.0-1.0) */
  temperature?: number;
  /** Maximum tokens per response */
  max_tokens?: number;
  /** Creation timestamp (ISO format) */
  created_at: string;
  /** Last update timestamp (ISO format) */
  updated_at: string;
  /** Whether conversation is blocked by admin */
  blocked?: boolean | number;
  /** Reason for blocking */
  blocked_reason?: string;
}

// ============================================================================
// FILE ATTACHMENT TYPES
// ============================================================================

/**
 * Selected file with tracking information
 * Used for managing files before upload
 */
export interface SelectedFile {
  /** Unique identifier for this attachment */
  id: string;
  /** The actual File object */
  file: File;
  /** Hash for duplicate detection */
  hash: string;
  /** Preview data URL for images */
  previewUrl?: string;
}

/**
 * File validation result
 */
export interface FileValidationResult {
  /** Whether the file is valid */
  valid: boolean;
  /** File hash if valid */
  hash?: string;
  /** Error message if invalid */
  error?: string;
}

// ============================================================================
// COMPONENT CONFIGURATION
// ============================================================================

/**
 * LLM Chat component configuration
 * Passed from PHP via data attributes on the container element
 * Matches the data attributes set in llm_chat_main.php
 */
/**
 * Floating button position options
 */
export type FloatingButtonPosition = 
  | 'bottom-right' 
  | 'bottom-left' 
  | 'top-right' 
  | 'top-left'
  | 'bottom-center'
  | 'top-center';

export interface LlmChatConfig {
  /** Current user ID */
  userId: number;
  /** Section ID (for multi-section support) */
  sectionId?: number;
  /** Current conversation ID (if any) */
  currentConversationId?: string;
  /** Configured LLM model */
  configuredModel: string;
  /** Whether conversations list is enabled */
  enableConversationsList: boolean;
  /** Whether file uploads are enabled */
  enableFileUploads: boolean;
  /** Whether streaming is enabled */
  streamingEnabled: boolean;
  /** Whether to do full page reload after streaming (default: false - React refresh only) */
  enableFullPageReload: boolean;
  /** Accepted file types string */
  acceptedFileTypes: string;
  /** Whether the current model supports vision */
  isVisionModel: boolean;
  /** Whether conversation context is configured */
  hasConversationContext: boolean;
  /** Whether auto-start conversation is enabled */
  autoStartConversation: boolean;
  /** Auto-start message content */
  autoStartMessage: string;
  /** Whether form mode is enabled (LLM returns only forms, text input disabled) */
  enableFormMode: boolean;
  /** Form mode active title (shown when text input is disabled) */
  formModeActiveTitle: string;
  /** Form mode active description (shown when text input is disabled) */
  formModeActiveDescription: string;
  /** Whether structured response mode is enabled (LLM always returns JSON schema) */
  enableStructuredResponse: boolean;
  /** Continue button label for form mode when no form is pending */
  continueButtonLabel: string;
  /** File configuration */
  fileConfig: FileConfig;
  
  // ===== Progress Tracking Configuration =====
  /** Whether progress tracking is enabled */
  enableProgressTracking: boolean;
  /** Label for the progress bar */
  progressBarLabel: string;
  /** Message shown when progress is complete */
  progressCompleteMessage: string;
  /** Whether to show the topic list */
  progressShowTopics: boolean;
  
  // ===== Floating Button Configuration =====
  /** Whether floating button mode is enabled */
  enableFloatingButton: boolean;
  /** Position of the floating button */
  floatingButtonPosition: FloatingButtonPosition;
  /** Font Awesome icon class for the floating button */
  floatingButtonIcon: string;
  /** Label text for the floating button */
  floatingButtonLabel: string;
  /** Title for the floating chat modal */
  floatingChatTitle: string;
  /** Whether the chat is currently in floating mode */
  isFloatingMode: boolean;
  /** Force scroll to bottom (used by floating chat when panel opens) */
  forceScrollToBottom?: boolean;
  
  // ===== UI Labels =====
  /** Message input placeholder text */
  messagePlaceholder: string;
  /** Message when no conversations exist */
  noConversationsMessage: string;
  /** New conversation modal title */
  newConversationTitleLabel: string;
  /** Conversation title input label */
  conversationTitleLabel: string;
  /** Cancel button label */
  cancelButtonLabel: string;
  /** Create conversation button label */
  createButtonLabel: string;
  /** Delete confirmation title */
  deleteConfirmationTitle: string;
  /** Delete confirmation message */
  deleteConfirmationMessage: string;
  /** Confirm delete button label */
  confirmDeleteButtonLabel: string;
  /** Cancel delete button label */
  cancelDeleteButtonLabel: string;
  /** Tokens used suffix (e.g., ' tokens') */
  tokensSuffix: string;
  /** AI thinking text */
  aiThinkingText: string;
  /** AI streaming text */
  aiStreamingText: string;
  /** Conversations sidebar heading */
  conversationsHeading: string;
  /** New chat button label */
  newChatButtonLabel: string;
  /** Select conversation heading */
  selectConversationHeading: string;
  /** Select conversation description */
  selectConversationDescription: string;
  /** Model label prefix */
  modelLabelPrefix: string;
  /** Message when no messages exist */
  noMessagesMessage: string;
  /** Loading text */
  loadingText: string;
  /** Upload image label */
  uploadImageLabel: string;
  /** Upload help text */
  uploadHelpText: string;
  /** Clear button label */
  clearButtonLabel: string;
  /** Submit button label */
  submitButtonLabel: string;
  /** Error message for empty message */
  emptyMessageError: string;
  /** Error message for streaming active */
  streamingActiveError: string;
  /** Error message for streaming interruption */
  streamingInterruptionError: string;
  /** Default chat title */
  defaultChatTitle: string;
  /** Delete button title/tooltip */
  deleteButtonTitle: string;
  /** Conversation title input placeholder */
  conversationTitlePlaceholder: string;
  /** Text for single file attached indicator */
  singleFileAttachedText: string;
  /** Text for multiple files attached indicator */
  multipleFilesAttachedText: string;
  /** Empty state title */
  emptyStateTitle: string;
  /** Empty state description */
  emptyStateDescription: string;
  /** Loading messages text */
  loadingMessagesText: string;
  /** Placeholder text when streaming is in progress */
  streamingInProgressPlaceholder: string;
  /** Attach files button title */
  attachFilesTitle: string;
  /** No vision support title */
  noVisionSupportTitle: string;
  /** No vision support text */
  noVisionSupportText: string;
  /** Send message button title */
  sendMessageTitle: string;
  /** Remove file button title */
  removeFileTitle: string;
  /** Message shown when conversation is blocked */
  conversationBlockedMessage: string;
}

/**
 * Default configuration values
 */
export const DEFAULT_CONFIG: Partial<LlmChatConfig> = {
  configuredModel: 'qwen3-vl-8b-instruct',
  enableConversationsList: true,
  enableFileUploads: true,
  streamingEnabled: true,
  enableFullPageReload: false,
  acceptedFileTypes: '',
  isVisionModel: false,
  hasConversationContext: false,
  autoStartConversation: false,
  autoStartMessage: 'Hello! I\'m here to help you. What would you like to talk about?',
  enableFormMode: false,
  formModeActiveTitle: 'Form Mode Active',
  formModeActiveDescription: 'Please use the form above to respond.',
  enableStructuredResponse: false,
  continueButtonLabel: 'Continue',
  fileConfig: DEFAULT_FILE_CONFIG,
  // Progress tracking defaults
  enableProgressTracking: false,
  progressBarLabel: 'Progress',
  progressCompleteMessage: 'Great job! You have covered all topics.',
  progressShowTopics: false,
  // Floating button defaults
  enableFloatingButton: false,
  floatingButtonPosition: 'bottom-right',
  floatingButtonIcon: 'fa-comments',
  floatingButtonLabel: 'Chat',
  floatingChatTitle: 'AI Assistant',
  isFloatingMode: false,
  forceScrollToBottom: false,
  messagePlaceholder: 'Type your message...',
  noConversationsMessage: 'No conversations yet',
  newConversationTitleLabel: 'New Conversation',
  conversationTitleLabel: 'Conversation Title (optional)',
  cancelButtonLabel: 'Cancel',
  createButtonLabel: 'Create Conversation',
  deleteConfirmationTitle: 'Delete Conversation',
  deleteConfirmationMessage: 'Are you sure you want to delete this conversation? This action cannot be undone.',
  confirmDeleteButtonLabel: 'Delete',
  cancelDeleteButtonLabel: 'Cancel',
  tokensSuffix: ' tokens',
  aiThinkingText: 'AI is thinking...',
  aiStreamingText: 'AI is streaming...',
  conversationsHeading: 'Conversations',
  newChatButtonLabel: 'New',
  selectConversationHeading: 'Select a conversation',
  selectConversationDescription: 'Choose a conversation from the list below or start a new one.',
  modelLabelPrefix: 'Model:',
  noMessagesMessage: 'No messages yet. Start a conversation!',
  loadingText: 'Loading...',
  uploadImageLabel: 'Upload Image',
  uploadHelpText: 'Select an image file to attach to your message.',
  clearButtonLabel: 'Clear',
  submitButtonLabel: 'Send',
  emptyMessageError: 'Please enter a message',
  streamingActiveError: 'Please wait for the current response to complete',
  streamingInterruptionError: 'The AI response was interrupted. Please try again.',
  defaultChatTitle: 'AI Chat',
  deleteButtonTitle: 'Delete conversation',
  conversationTitlePlaceholder: 'Enter conversation title (optional)',
  singleFileAttachedText: '1 file attached',
  multipleFilesAttachedText: '{count} files attached',
  emptyStateTitle: 'Start a conversation',
  emptyStateDescription: 'Send a message to start chatting with the AI assistant.',
  loadingMessagesText: 'Loading messages...',
  streamingInProgressPlaceholder: 'Streaming in progress...',
  attachFilesTitle: 'Attach files',
  noVisionSupportTitle: 'Current model does not support image uploads',
  noVisionSupportText: 'No vision',
  sendMessageTitle: 'Send message',
  removeFileTitle: 'Remove file',
  conversationBlockedMessage: 'This conversation has been blocked. You cannot send any more messages.'
};

// ============================================================================
// API RESPONSE TYPES
// ============================================================================

/**
 * Response from get_conversations action
 */
export interface GetConversationsResponse {
  conversations?: Conversation[];
  error?: string;
}

/**
 * Response from get_conversation action
 */
export interface GetConversationResponse {
  conversation?: Conversation;
  messages?: Message[];
  error?: string;
}

/**
 * Safety assessment from LLM response
 * Used for danger detection and intervention
 */
export interface SafetyAssessment {
  /** True if content is safe */
  is_safe: boolean;
  /** Danger level: null (safe), warning, critical, emergency */
  danger_level: null | 'warning' | 'critical' | 'emergency';
  /** Detected concern categories */
  detected_concerns: string[];
  /** True if administrators should be notified */
  requires_intervention: boolean;
  /** Supportive safety message from LLM */
  safety_message?: string | null;
}

/**
 * Response from send_message action
 */
export interface SendMessageResponse {
  conversation_id?: string;
  message?: string;
  is_new_conversation?: boolean;
  streaming?: boolean;
  progress?: ProgressData;
  error?: string;
  // Structured response data
  structured?: LlmStructuredResponse;
  // Safety assessment from LLM
  safety?: SafetyAssessment;
  // Legacy danger detection fields (for backwards compatibility)
  blocked?: boolean;
  type?: 'danger_detected' | 'conversation_blocked';
  detected_keywords?: string[];
}

/**
 * Unified LLM Structured Response Schema
 * All LLM responses follow this format for predictable parsing
 */
export interface LlmStructuredResponse {
  type: 'response';
  safety: SafetyAssessment;
  content: {
    text_blocks: Array<{
      type: 'text' | 'heading' | 'info' | 'warning' | 'error' | 'success' | 'code';
      content: string;
      style?: 'default' | 'bold' | 'italic' | 'code' | 'quote';
    }>;
    form?: {
      title?: string;
      description?: string;
      fields: FormField[];
      submit_label?: string;
    } | null;
    media?: Array<{
      type: 'image' | 'video' | 'audio';
      url: string;
      alt?: string;
      caption?: string;
    }>;
    /** Quick reply suggestions - STRICT FORMAT: Each item must have only "text" property */
    suggestions?: Array<{
      /** REQUIRED: The button label text. This is the ONLY accepted property. */
      text: string;
    }>;
  };
  progress?: {
    percentage: number;
    current_topic?: string | null;
    topics_covered?: string[];
    topics_remaining?: string[];
    milestones_reached?: string[];
  } | null;
  metadata: {
    model: string;
    tokens_used?: number | null;
    confidence?: number | null;
    language?: string | null;
  };
}

/**
 * Response from new_conversation action
 */
export interface NewConversationResponse {
  conversation_id?: string;
  error?: string;
}

/**
 * Response from delete_conversation action
 */
export interface DeleteConversationResponse {
  success?: boolean;
  error?: string;
}

/**
 * Response from prepare_streaming action
 */
export interface PrepareStreamingResponse {
  status?: 'prepared';
  conversation_id?: string;
  is_new_conversation?: boolean;
  user_message?: Message;
  error?: string;
  // Safety/danger detection fields
  blocked?: boolean;
  type?: 'danger_detected' | 'conversation_blocked';
  message?: string;
  detected_keywords?: string[];
  safety?: SafetyAssessment;
}

// ============================================================================
// STREAMING EVENT TYPES
// ============================================================================

/**
 * Server-Sent Event data structure
 * Matches the SSE events sent by handleStreamingRequest()
 */
export interface StreamingEvent {
  /** Event type */
  type: 'connected' | 'start' | 'chunk' | 'done' | 'error' | 'close';
  /** Text content for chunk events */
  content?: string;
  /** Conversation ID for connected events */
  conversation_id?: string;
  /** Tokens used for done events */
  tokens_used?: number;
  /** Progress data for done events (when progress tracking is enabled) */
  progress?: ProgressData;
  /** Safety assessment for done events (when danger detected) */
  safety?: SafetyAssessment;
  /** Error message for error events */
  message?: string;
  /** Model used for start events */
  model?: string;
  /** Message count for start events */
  message_count?: number;
}

// ============================================================================
// ADMIN TYPES
// ============================================================================

export interface AdminConfig {
  pageSize: number;
  labels: {
    heading: string;
    filtersTitle: string;
    userFilterLabel: string;
    sectionFilterLabel: string;
    searchPlaceholder: string;
    conversationsEmpty: string;
    messagesEmpty: string;
    refreshLabel: string;
    loadingLabel: string;
    dateFilterLabel: string;
    dateFromLabel: string;
    dateToLabel: string;
  };
  csrfToken?: string;
}

export interface AdminFilterOption {
  id: number;
  name: string;
  email?: string;
  user_validation_code?: string | null;
}

export interface AdminFiltersResponse {
  filters: {
    users: AdminFilterOption[];
    sections: { id: number; name: string }[];
  };
  error?: string;
}

export interface AdminConversation extends Conversation {
  id_users?: number;
  id_sections?: number;
  user_name?: string;
  user_email?: string;
  section_name?: string;
  message_count?: number;
  /** Whether conversation is blocked */
  blocked?: boolean | number;
  /** Reason for blocking */
  blocked_reason?: string;
  /** When the conversation was blocked */
  blocked_at?: string;
  /** Who blocked the conversation (user ID) */
  blocked_by?: number;
  /** Whether conversation is deleted (soft delete) */
  deleted?: boolean | number;
}

export interface AdminConversationsResponse {
  items: AdminConversation[];
  page: number;
  per_page: number;
  total: number;
  error?: string;
}

export interface AdminMessagesResponse {
  conversation: AdminConversation | null;
  messages: Message[];
  error?: string;
}

// ============================================================================
// UI STATE TYPES
// ============================================================================

/**
 * Chat UI state
 */
export interface ChatState {
  /** List of user's conversations */
  conversations: Conversation[];
  /** Currently selected conversation */
  currentConversation: Conversation | null;
  /** Messages in current conversation */
  messages: Message[];
  /** Whether data is loading */
  isLoading: boolean;
  /** Whether currently streaming */
  isStreaming: boolean;
  /** Accumulated streaming message content */
  streamingContent: string;
  /** Error message (if any) */
  error: string | null;
}

/**
 * Initial chat state
 */
export const INITIAL_CHAT_STATE: ChatState = {
  conversations: [],
  currentConversation: null,
  messages: [],
  isLoading: false,
  isStreaming: false,
  streamingContent: '',
  error: null
};

// ============================================================================
// FORM MODE TYPES
// ============================================================================

/**
 * Form field option (for radio, checkbox, select)
 */
export interface FormFieldOption {
  /** Unique value for this option */
  value: string;
  /** Display label for this option */
  label: string;
}

/**
 * Form field definition
 * Supports radio buttons, checkboxes, dropdowns, text inputs, number inputs, and hidden fields
 */
export interface FormField {
  /** Unique field identifier */
  id: string;
  /** Field type: radio (single select), checkbox (multi-select), select (dropdown), text (free text), number, hidden */
  type: 'radio' | 'checkbox' | 'select' | 'text' | 'textarea' | 'number' | 'hidden';
  /** Field label/question text */
  label: string;
  /** Whether the field is required */
  required?: boolean;
  /** Available options for selection (for radio, checkbox, select) */
  options?: FormFieldOption[];
  /** Optional help text */
  helpText?: string;
  /** Placeholder text for text/number inputs */
  placeholder?: string;
  /** Maximum length for text inputs */
  maxLength?: number;
  /** Number of rows for textarea */
  rows?: number;
  /** Minimum value for number inputs */
  min?: number;
  /** Maximum value for number inputs */
  max?: number;
  /** Step value for number inputs */
  step?: number;
  /** Default value (especially for hidden fields) */
  value?: string;
  /** 
   * For "Other" option support: ID of related field to enable when "other" is selected
   * e.g., a radio with "Other" option can have otherFieldId pointing to a text field
   */
  otherFieldId?: string;
  /**
   * Whether this field is an "other" text field that should only show when related option is selected
   */
  showWhenOtherSelected?: string; // ID of the field that triggers this
}

/**
 * Form definition returned by LLM in form mode
 * Uses JSON Schema-inspired format
 */
/**
 * Content section for displaying rich text in forms
 * Allows LLM to return educational content alongside form fields
 */
export interface FormContentSection {
  /** Type of content section */
  type: 'content';
  /** Markdown content to render */
  content: string;
}

/**
 * Union type for form sections (can be either a field or content)
 */
export type FormSection = FormField | FormContentSection;

/**
 * Helper to check if a section is a content section
 */
export function isFormContentSection(section: FormSection): section is FormContentSection {
  return section.type === 'content';
}

/**
 * Helper to check if a section is a form field
 */
export function isFormField(section: FormSection): section is FormField {
  return ['radio', 'checkbox', 'select', 'text', 'textarea', 'number'].includes(section.type);
}

/**
 * Form submission metadata stored in message attachments
 */
export interface FormSubmissionMetadata {
  /** Type identifier */
  type: 'form_submission';
  /** The values submitted by the user */
  values: Record<string, string | string[]>;
}

/**
 * Parse form submission metadata from message attachments
 * @param attachments JSON string from message.attachments
 * @returns FormSubmissionMetadata if valid, null otherwise
 */
export function parseFormSubmissionMetadata(attachments?: string): FormSubmissionMetadata | null {
  if (!attachments) return null;
  
  try {
    const parsed = JSON.parse(attachments);
    
    // Direct form submission format
    if (parsed && parsed.type === 'form_submission' && parsed.values) {
      return parsed as FormSubmissionMetadata;
    }
    
    // Check if it's wrapped in file-like structure (legacy format)
    // e.g., [{"path": "{\"type\":\"form_submission\",...}", "original_name": "..."}]
    if (Array.isArray(parsed) && parsed.length > 0) {
      const firstItem = parsed[0];
      if (firstItem && firstItem.path) {
        try {
          const innerParsed = JSON.parse(firstItem.path);
          if (innerParsed && innerParsed.type === 'form_submission' && innerParsed.values) {
            return innerParsed as FormSubmissionMetadata;
          }
        } catch {
          // Inner content is not form submission JSON
        }
      }
    }
    
    // Check if it's a string that needs double-parsing
    if (typeof parsed === 'string') {
      try {
        const doubleParsed = JSON.parse(parsed);
        if (doubleParsed && doubleParsed.type === 'form_submission' && doubleParsed.values) {
          return doubleParsed as FormSubmissionMetadata;
        }
      } catch {
        // Not double-encoded
      }
    }
  } catch {
    // Not valid JSON or not form submission
  }
  
  return null;
}

export interface FormDefinition {
  /** Must be "form" to identify as form response */
  type: 'form';
  /** Form title */
  title?: string;
  /** Optional form description */
  description?: string;
  /** Array of form fields */
  fields: FormField[];
  /** Submit button label */
  submitLabel?: string;
  /** Optional content sections to display before/after fields */
  contentBefore?: string;
  /** Optional content sections to display after fields */
  contentAfter?: string;
  /** Mixed sections array (fields and content interleaved) - alternative to fields */
  sections?: FormSection[];
}

/**
 * Form submission data
 * Maps field IDs to selected values
 */
export interface FormSubmission {
  /** Field ID to value(s) mapping */
  values: Record<string, string | string[]>;
  /** Original form definition for reference */
  formDefinition: FormDefinition;
  /** Readable text representation of selections */
  readableText: string;
}

/**
 * Form submission response from backend
 */
export interface FormSubmissionResponse {
  /** New conversation ID (if created) */
  conversation_id?: string;
  /** Whether this is a new conversation */
  is_new_conversation?: boolean;
  /** User message that was created */
  user_message?: Message;
  /** Progress data (if progress tracking is enabled) */
  progress?: ProgressData;
  /** Error message if failed */
  error?: string;
}

/**
 * Validate a parsed form definition object
 * @param parsed The parsed JSON object
 * @returns true if valid form definition, false otherwise
 */
function validateFormDefinition(parsed: unknown): parsed is FormDefinition {
  if (!parsed || typeof parsed !== 'object') return false;
  
  const obj = parsed as Record<string, unknown>;
  if (obj.type !== 'form' || !Array.isArray(obj.fields)) return false;
  
  // Validate each field has required properties
  return (obj.fields as FormField[]).every((field: FormField) => {
    // Basic validation
    if (!field.id || !field.type || !field.label) return false;
    
    // Type-specific validation
    if (['radio', 'checkbox', 'select'].includes(field.type)) {
      // Selection fields need options
      return Array.isArray(field.options) &&
        field.options.every((opt: FormFieldOption) => opt.value && opt.label);
    } else if (['text', 'textarea', 'number'].includes(field.type)) {
      // Text and number fields don't need options
      return true;
    }
    
    // For unknown types, skip validation but allow the form to render
    // This provides forward compatibility for new field types
    return true;
  });
}

/**
 * Extract JSON object from mixed content (text + JSON)
 * Handles cases where LLM returns text before/after the JSON form
 * @param content The content to extract JSON from
 * @returns Object with extracted JSON, text before, and text after, or null if no valid JSON found
 */
function extractJsonFromContent(content: string): { 
  json: unknown; 
  textBefore: string; 
  textAfter: string;
} | null {
  // Find the first { that could start a JSON object
  const firstBrace = content.indexOf('{');
  if (firstBrace === -1) return null;
  
  // Find matching closing brace by counting braces
  let braceCount = 0;
  let lastBrace = -1;
  let inString = false;
  let escapeNext = false;
  
  for (let i = firstBrace; i < content.length; i++) {
    const char = content[i];
    
    if (escapeNext) {
      escapeNext = false;
      continue;
    }
    
    if (char === '\\' && inString) {
      escapeNext = true;
      continue;
    }
    
    if (char === '"') {
      inString = !inString;
      continue;
    }
    
    if (!inString) {
      if (char === '{') {
        braceCount++;
      } else if (char === '}') {
        braceCount--;
        if (braceCount === 0) {
          lastBrace = i;
          break;
        }
      }
    }
  }
  
  if (lastBrace === -1) return null;
  
  const jsonStr = content.substring(firstBrace, lastBrace + 1);
  const textBefore = content.substring(0, firstBrace).trim();
  const textAfter = content.substring(lastBrace + 1).trim();
  
  try {
    const json = JSON.parse(jsonStr);
    return { json, textBefore, textAfter };
  } catch {
    return null;
  }
}

/**
 * Parse message content to check if it contains a form definition
 * Handles both pure JSON and mixed content (text + JSON)
 * @param content Message content to parse
 * @returns FormDefinition if valid form, null otherwise
 */
export function parseFormDefinition(content: string): FormDefinition | null {
  if (!content) return null;
  
  // First, try direct JSON parsing (most common case)
  try {
    const parsed = JSON.parse(content.trim());
    if (validateFormDefinition(parsed)) {
      return parsed;
    }
  } catch {
    // Not pure JSON, try to extract from mixed content
  }
  
  // Try to extract JSON from mixed content (text before/after JSON)
  const extracted = extractJsonFromContent(content);
  if (extracted && validateFormDefinition(extracted.json)) {
    const formDef = extracted.json as FormDefinition;
    
    // If there's text before the JSON, add it as contentBefore
    // (only if contentBefore is not already set)
    if (extracted.textBefore && !formDef.contentBefore) {
      formDef.contentBefore = extracted.textBefore;
    }
    
    // If there's text after the JSON, add it as contentAfter
    // (only if contentAfter is not already set)
    if (extracted.textAfter && !formDef.contentAfter) {
      formDef.contentAfter = extracted.textAfter;
    }
    
    return formDef;
  }
  
  return null;
}

/**
 * Format form selections as readable text for display
 * @param formDefinition The form definition
 * @param values Selected values
 * @returns Human-readable text representation
 */
export function formatFormSelectionsAsText(
  formDefinition: FormDefinition,
  values: Record<string, string | string[]>
): string {
  const parts: string[] = [];
  
  // Add form title if available
  if (formDefinition.title) {
    parts.push(`**${formDefinition.title}**`);
    parts.push(''); // Empty line after title
  }
  
  for (const field of formDefinition.fields) {
    const fieldValue = values[field.id];
    if (!fieldValue || (Array.isArray(fieldValue) && fieldValue.length === 0)) {
      continue;
    }
    
    // Handle text/textarea/number fields
    if (field.type === 'text' || field.type === 'textarea' || field.type === 'number') {
      if (typeof fieldValue === 'string' && fieldValue.trim()) {
        parts.push(`${field.label}: ${fieldValue}`);
      }
      continue;
    }
    
    // Get labels for selected values (radio, checkbox, select)
    const selectedValues = Array.isArray(fieldValue) ? fieldValue : [fieldValue];
    const selectedLabels = selectedValues
      .map(val => {
        const option = field.options?.find(opt => opt.value === val);
        return option?.label || val;
      })
      .filter(Boolean);
    
    if (selectedLabels.length > 0) {
      if (selectedLabels.length === 1) {
        parts.push(`${field.label}: ${selectedLabels[0]}`);
      } else {
        parts.push(`${field.label}: ${selectedLabels.join(', ')}`);
      }
    }
  }
  
  // If no selections were made, return a default message
  if (parts.length === 0 || (parts.length === 2 && parts[1] === '')) {
    return 'Form submitted (no selections)';
  }
  
  return parts.join('\n');
}

// ============================================================================
// PROGRESS TRACKING TYPES
// ============================================================================

/**
 * Topic coverage data for a single topic
 */
export interface TopicCoverage {
  /** Unique topic identifier */
  id: string;
  /** Human-readable topic title */
  title: string;
  /** Coverage percentage (0-100) */
  coverage: number;
  /** Topic weight/importance (1-10) */
  weight: number;
  /** Whether the topic has been covered (coverage > 0) */
  is_covered: boolean;
}

/**
 * Progress tracking configuration
 */
export interface ProgressTrackingConfig {
  /** Whether progress tracking is enabled */
  enabled: boolean;
  /** Label for the progress bar */
  barLabel: string;
  /** Message shown when progress is complete */
  completeMessage: string;
  /** Whether to show the topic list */
  showTopics: boolean;
}

/**
 * Debug information for progress tracking
 */
export interface ProgressDebug {
  context_length: number;
  context_preview: string;
  topics_found: number;
  has_trackable_topics_section: boolean;
  has_topic_markers: boolean;
  user_messages_count: number;
  error?: string;
}

/**
 * Progress data returned from the API
 */
export interface ProgressData {
  /** Overall progress percentage (0-100, always monotonically increasing) */
  percentage: number;
  /** Total number of topics extracted from context */
  topics_total: number;
  /** Number of topics that have been covered */
  topics_covered: number;
  /** Detailed coverage for each topic */
  topic_coverage: Record<string, TopicCoverage>;
  /** Whether all topics have been covered */
  is_complete: boolean;
  /** Progress tracking configuration */
  config?: ProgressTrackingConfig;
  /** Debug information (included when no topics found) */
  debug?: ProgressDebug;
}

/**
 * Response from get_progress API action
 */
export interface GetProgressResponse {
  progress?: ProgressData;
  error?: string;
}

// ============================================================================
// STRUCTURED RESPONSE TYPES
// ============================================================================

/**
 * Text block types for structured responses
 */
export type TextBlockType = 
  | 'paragraph' 
  | 'heading' 
  | 'list' 
  | 'quote' 
  | 'info' 
  | 'warning' 
  | 'success' 
  | 'tip';

/**
 * Text block in structured response
 */
export interface TextBlock {
  /** Block type for styling */
  type: TextBlockType;
  /** Markdown-formatted text content */
  content: string;
  /** Heading level (1-6), only for type='heading' */
  level?: number;
}

/**
 * Media item in structured response
 */
export interface MediaItem {
  /** Media type */
  type: 'image' | 'video' | 'audio';
  /** URL or asset path */
  src: string;
  /** Alt text for accessibility */
  alt?: string;
  /** Optional caption */
  caption?: string;
}

/**
 * Form definition in structured response
 * UNIFIED: This extends FormDefinition to ensure consistency between form_mode and structured_mode
 * Both modes use the exact same form structure with FormField[]
 */
export interface StructuredForm {
  /** Unique form identifier */
  id: string;
  /** Form title */
  title?: string;
  /** Form description */
  description?: string;
  /** Whether the form is optional (default: true in structured mode) */
  optional?: boolean;
  /** Form fields - SAME structure as FormDefinition.fields */
  fields: FormField[];
  /** Submit button label */
  submit_label?: string;
  /** Optional content sections to display before fields (for compatibility with FormDefinition) */
  contentBefore?: string;
  /** Optional content sections to display after fields (for compatibility with FormDefinition) */
  contentAfter?: string;
}

/**
 * Next step guidance in structured response
 */
export interface NextStep {
  /** Suggested next action or question */
  prompt?: string;
  /** Quick reply suggestions */
  suggestions?: string[];
  /** Whether the user can skip this step */
  can_skip?: boolean;
}

/**
 * Content section of structured response
 */
export interface StructuredContent {
  /** Ordered list of text content to display */
  text_blocks: TextBlock[];
  /** Optional forms for structured input */
  forms?: StructuredForm[];
  /** Optional media items */
  media?: MediaItem[];
  /** Guidance on what to do next */
  next_step?: NextStep;
}

/**
 * Response type for context-aware rendering
 */
export type ResponseType = 
  | 'educational' 
  | 'conversational' 
  | 'assessment' 
  | 'summary' 
  | 'error';

/**
 * Emotional tone of response
 */
export type EmotionType = 
  | 'neutral' 
  | 'encouraging' 
  | 'celebratory' 
  | 'supportive' 
  | 'informative';

/**
 * Progress milestone types
 */
export type MilestoneType = '25%' | '50%' | '75%' | '100%' | null;

/**
 * Progress information in structured response meta
 */
export interface StructuredProgress {
  /** Overall progress percentage (0-100) */
  percentage: number;
  /** List of topic IDs/names now covered */
  covered_topics?: string[];
  /** Topics covered in THIS message */
  newly_covered?: string[];
  /** How many topics remain */
  remaining_topics?: number;
  /** Milestone reached (if any) */
  milestone?: MilestoneType;
}

/**
 * Module state in structured response meta
 */
export interface ModuleState {
  /** Current phase name */
  current_phase?: string;
  /** Current section/topic being covered */
  current_section?: string;
  /** Sections completed */
  sections_completed?: number;
  /** Total sections */
  total_sections?: number;
}

/**
 * Metadata section of structured response
 */
export interface StructuredMeta {
  /** Type of response for context-aware rendering */
  response_type: ResponseType;
  /** Progress tracking information */
  progress?: StructuredProgress;
  /** Current position in educational module */
  module_state?: ModuleState;
  /** Emotional tone of this response */
  emotion?: EmotionType;
}

/**
 * Complete structured response from LLM
 * This is the schema that all LLM responses should follow when structured response mode is enabled
 */
export interface StructuredResponse {
  /** All displayable content */
  content: StructuredContent;
  /** Metadata about the response */
  meta: StructuredMeta;
}

/**
 * Check if content is a valid structured response
 * Supports BOTH old schema (with meta) and new unified schema (with safety/metadata)
 * @param content Message content to check
 * @returns true if valid structured response
 */
export function isStructuredResponse(content: unknown): content is StructuredResponse {
  if (!content || typeof content !== 'object') return false;
  
  const obj = content as Record<string, unknown>;
  
  // Must have content object
  if (!obj.content || typeof obj.content !== 'object') return false;
  
  const contentObj = obj.content as Record<string, unknown>;
  
  // Content must have text_blocks array
  if (!Array.isArray(contentObj.text_blocks)) return false;
  
  // Check for NEW unified schema (type: "response" with safety or metadata)
  // The schema requires: type, safety, content, metadata
  // But we're lenient here - if it has type: "response" and valid content structure,
  // and has either safety or metadata, we accept it
  if (obj.type === 'response') {
    // Has metadata object - valid new schema
    if (obj.metadata && typeof obj.metadata === 'object') {
      return true;
    }
    // Has safety object - valid new schema (metadata may be added server-side)
    if (obj.safety && typeof obj.safety === 'object') {
      return true;
    }
  }
  
  // Check for OLD schema (meta.response_type)
  if (obj.meta && typeof obj.meta === 'object') {
    const metaObj = obj.meta as Record<string, unknown>;
    if (typeof metaObj.response_type === 'string') {
      return true; // Valid old schema
    }
  }
  
  return false;
}

/**
 * Check if JSON content appears to be incomplete/truncated
 * This is a conservative check - we only flag as incomplete if clearly truncated
 * @param jsonContent The JSON string to check
 * @returns true if content appears incomplete
 */
function isIncompleteJson(jsonContent: string): boolean {
  // Check for obvious signs of truncation
  const trimmed = jsonContent.trim();
  
  // Empty content is incomplete
  if (!trimmed) {
    return true;
  }

  // Must start with { or [ to be valid JSON
  if (!trimmed.startsWith('{') && !trimmed.startsWith('[')) {
    return true;
  }

  // Check for incomplete objects/arrays (missing closing braces/brackets)
  let openBraces = 0;
  let openBrackets = 0;
  let inString = false;
  let escapeNext = false;

  for (let i = 0; i < trimmed.length; i++) {
    const char = trimmed[i];

    if (escapeNext) {
      escapeNext = false;
      continue;
    }

    if (char === '\\' && inString) {
      escapeNext = true;
      continue;
    }

    if (char === '"' && !escapeNext) {
      inString = !inString;
      continue;
    }

    if (inString) continue;

    switch (char) {
      case '{':
        openBraces++;
        break;
      case '}':
        openBraces--;
        break;
      case '[':
        openBrackets++;
        break;
      case ']':
        openBrackets--;
        break;
    }
  }

  // If we have unclosed braces or brackets, it's incomplete
  if (openBraces !== 0 || openBrackets !== 0) {
    return true;
  }

  // If we're still in a string, it's incomplete
  if (inString) {
    return true;
  }

  // Check if JSON ends with proper closing character
  const lastChar = trimmed[trimmed.length - 1];
  if (lastChar !== '}' && lastChar !== ']') {
    return true;
  }

  return false;
}

/**
 * Parse message content to check if it's a structured response
 * Handles both pure JSON and markdown code block wrapped JSON
 * Supports BOTH old schema (meta) and new unified schema (type, safety, metadata)
 * @param content Message content to parse
 * @returns StructuredResponse if valid, null otherwise
 */
export function parseStructuredResponse(content: string): StructuredResponse | null {
  if (!content) return null;
  
  let jsonContent = content.trim();
  
  // Remove markdown code block wrappers if present
  if (jsonContent.startsWith('```json')) {
    jsonContent = jsonContent.replace(/^```json\s*\n?/, '').replace(/\n?```\s*$/, '');
  } else if (jsonContent.startsWith('```')) {
    jsonContent = jsonContent.replace(/^```\s*\n?/, '').replace(/\n?```\s*$/, '');
  }
  
  // Check if JSON appears incomplete before parsing
  if (isIncompleteJson(jsonContent)) {
    console.debug('[parseStructuredResponse] JSON appears incomplete:', jsonContent.substring(0, 100));
    return null;
  }

  try {
    const parsed: unknown = JSON.parse(jsonContent);
    
    // Check if it's the new unified schema (type: "response" with content.text_blocks)
    const asAny = parsed as Record<string, unknown>;
    if (asAny && asAny.type === 'response' && asAny.content) {
      const contentObj = asAny.content as Record<string, unknown>;
      if (Array.isArray(contentObj.text_blocks) && contentObj.text_blocks.length > 0) {
        // Valid unified schema - convert to StructuredResponse format
        try {
          return convertUnifiedToStructuredResponse(parsed as LlmStructuredResponse);
        } catch (convError) {
          console.debug('[parseStructuredResponse] Conversion error:', convError);
          // Try to return a basic structure
          return createBasicStructuredResponse(parsed as LlmStructuredResponse);
        }
      }
    }
    
    // Check for old schema (meta.response_type)
    if (isStructuredResponse(parsed)) {
      return parsed;
    }
    
    console.debug('[parseStructuredResponse] Parsed JSON does not match expected schema');
  } catch (parseError) {
    console.debug('[parseStructuredResponse] JSON parse error:', parseError);
  }
  
  return null;
}

/**
 * Extract suggestion text from suggestion object
 * 
 * STRICT FORMAT: Each suggestion MUST be an object with a "text" property.
 * Example: { "text": "Option 1" }
 * 
 * @param suggestions Raw suggestions array from LLM response
 * @returns Array of strings suitable for display
 */
function extractSuggestionTexts(suggestions: unknown[] | undefined): string[] {
  if (!suggestions || !Array.isArray(suggestions) || suggestions.length === 0) {
    return [];
  }
  
  return suggestions
    .map(s => {
      // STRICT: Only accept objects with "text" property
      if (s && typeof s === 'object' && 'text' in s) {
        const obj = s as { text: string };
        return typeof obj.text === 'string' ? obj.text : '';
      }
      // Log invalid format for debugging
      console.warn('[LLM Response] Invalid suggestion format. Expected {text: string}, got:', s);
      return '';
    })
    .filter(Boolean);
}

/**
 * Create a basic structured response from unified schema when full conversion fails
 * This ensures we can at least display the text content
 */
function createBasicStructuredResponse(unified: LlmStructuredResponse): StructuredResponse {
  const textBlocks: TextBlock[] = [];
  
  // Extract text blocks
  if (unified.content?.text_blocks) {
    for (const block of unified.content.text_blocks) {
      textBlocks.push({
        type: mapTextBlockType(block.type || 'text'),
        content: block.content || '',
        level: block.type === 'heading' ? 2 : undefined
      });
    }
  }
  
  // Ensure at least one text block
  if (textBlocks.length === 0) {
    textBlocks.push({ type: 'paragraph', content: 'Response received.' });
  }
  
  // Convert form if present
  const forms: StructuredForm[] = [];
  if (unified.content?.form) {
    forms.push({
      id: 'form_1',
      title: unified.content.form.title,
      description: unified.content.form.description,
      fields: unified.content.form.fields || [],
      submit_label: unified.content.form.submit_label
    });
  }
  
  // Convert suggestions (STRICT: only accepts {text: string} objects)
  const suggestionTexts = extractSuggestionTexts(unified.content?.suggestions as unknown[]);
  const nextStep: NextStep | undefined = suggestionTexts.length > 0
    ? { suggestions: suggestionTexts }
    : undefined;
  
  return {
    content: {
      text_blocks: textBlocks,
      forms: forms.length > 0 ? forms : undefined,
      media: undefined,
      next_step: nextStep
    },
    meta: {
      response_type: 'conversational',
      emotion: 'neutral'
    }
  };
}

/**
 * Convert new unified LLM response schema to old StructuredResponse format
 * for backwards compatibility with existing rendering code
 */
function convertUnifiedToStructuredResponse(unified: LlmStructuredResponse): StructuredResponse {
  // Map text block types from new schema to old schema
  const mappedTextBlocks = unified.content.text_blocks.map(block => ({
    type: mapTextBlockType(block.type),
    content: block.content,
    level: block.type === 'heading' ? 2 : undefined
  }));
  
  // Convert forms if present (new schema uses form, old uses forms array)
  const forms: StructuredForm[] = [];
  if (unified.content.form) {
    forms.push({
      id: 'form_1',
      title: unified.content.form.title,
      description: unified.content.form.description,
      fields: unified.content.form.fields,
      submit_label: unified.content.form.submit_label
    });
  }
  
  // Convert suggestions (STRICT: only accepts {text: string} objects)
  const suggestionTexts = extractSuggestionTexts(unified.content.suggestions as unknown[]);
  
  return {
    content: {
      text_blocks: mappedTextBlocks as TextBlock[],
      forms: forms.length > 0 ? forms : undefined,
      media: unified.content.media?.map(m => ({
        type: m.type,
        src: m.url,
        alt: m.alt,
        caption: m.caption
      })),
      next_step: suggestionTexts.length > 0 
        ? { suggestions: suggestionTexts }
        : undefined
    },
    meta: {
      response_type: 'conversational',
      emotion: 'neutral',
      progress: unified.progress ? {
        percentage: unified.progress.percentage,
        covered_topics: unified.progress.topics_covered,
        remaining_topics: unified.progress.topics_remaining?.length
      } : undefined
    }
  };
}

/**
 * Map new schema text block types to old schema types
 */
function mapTextBlockType(type: string): TextBlockType {
  const typeMap: Record<string, TextBlockType> = {
    'text': 'paragraph',
    'heading': 'heading',
    'info': 'info',
    'warning': 'warning',
    'error': 'warning', // Map error to warning for display
    'success': 'success',
    'code': 'quote' // Map code to quote for now
  };
  return typeMap[type] || 'paragraph';
}

/**
 * Convert structured response to markdown for display
 * Used as fallback when structured rendering is not available
 * Supports both old and new schema text block types
 * @param response The structured response
 * @returns Markdown string
 */
export function structuredResponseToMarkdown(response: StructuredResponse): string {
  const parts: string[] = [];
  
  for (const block of response.content.text_blocks) {
    switch (block.type) {
      case 'heading': {
        const level = block.level || 2;
        const prefix = '#'.repeat(level);
        parts.push(`${prefix} ${block.content}`);
        break;
      }
      case 'quote':
        parts.push(block.content.split('\n').map(l => `> ${l}`).join('\n'));
        break;
      case 'info':
        parts.push(`ℹ️ **Info**: ${block.content}`);
        break;
      case 'warning':
        parts.push(`⚠️ **Warning**: ${block.content}`);
        break;
      case 'success':
        parts.push(`✅ ${block.content}`);
        break;
      case 'tip':
        parts.push(`💡 **Tip**: ${block.content}`);
        break;
      case 'paragraph':
      case 'list':
      default:
        parts.push(block.content);
    }
  }
  
  return parts.join('\n\n');
}

/**
 * Convert structured form to legacy FormDefinition for backwards compatibility
 * @param structuredForm The structured form
 * @returns Legacy FormDefinition
 */
export function structuredFormToFormDefinition(structuredForm: StructuredForm): FormDefinition {
  return {
    type: 'form',
    title: structuredForm.title,
    description: structuredForm.description,
    fields: structuredForm.fields,
    submitLabel: structuredForm.submit_label,
    contentBefore: structuredForm.contentBefore,
    contentAfter: structuredForm.contentAfter
  };
}

/**
 * Check if a message contains any form (either legacy FormDefinition or StructuredForm)
 * Used for Continue button detection - shows Continue only when NO form is present
 * @param content Message content to check
 * @returns true if content contains a form
 */
export function messageHasForm(content: string): boolean {
  // First check for legacy form definition
  const legacyForm = parseFormDefinition(content);
  if (legacyForm) return true;
  
  // Then check for structured response with forms
  const structuredResponse = parseStructuredResponse(content);
  if (structuredResponse && structuredResponse.content.forms && structuredResponse.content.forms.length > 0) {
    return true;
  }
  
  return false;
}

/**
 * Extract form definition from any message format (legacy or structured)
 * Returns the first form found, converted to FormDefinition format
 * @param content Message content to parse
 * @returns FormDefinition if found, null otherwise
 */
export function extractFormFromMessage(content: string): FormDefinition | null {
  // First check for legacy form definition
  const legacyForm = parseFormDefinition(content);
  if (legacyForm) return legacyForm;
  
  // Then check for structured response with forms
  const structuredResponse = parseStructuredResponse(content);
  if (structuredResponse && structuredResponse.content.forms && structuredResponse.content.forms.length > 0) {
    return structuredFormToFormDefinition(structuredResponse.content.forms[0]);
  }
  
  return null;
}

/**
 * Extract forms from structured response as legacy FormDefinitions
 * @param response The structured response
 * @returns Array of FormDefinitions
 */
export function extractFormsFromStructuredResponse(response: StructuredResponse): FormDefinition[] {
  if (!response.content.forms || response.content.forms.length === 0) {
    return [];
  }
  
  return response.content.forms.map(structuredFormToFormDefinition);
}

// ============================================================================
// UNIFIED LLM RESPONSE HANDLING
// ============================================================================

/**
 * Check if parsed object is a valid LlmStructuredResponse
 * @param obj Object to check
 * @returns true if valid unified response
 */
export function isLlmStructuredResponse(obj: unknown): obj is LlmStructuredResponse {
  if (!obj || typeof obj !== 'object') return false;
  
  const response = obj as Record<string, unknown>;
  
  // Check required fields - type must be 'response'
  if (response.type !== 'response') return false;
  
  // Must have safety object (core to the unified schema)
  if (!response.safety || typeof response.safety !== 'object') return false;
  
  // Must have content object
  if (!response.content || typeof response.content !== 'object') return false;
  
  // Metadata is technically required by schema, but be lenient - 
  // accept responses without metadata (can be added server-side)
  // This allows partial responses to still be valid
  
  // Check content.text_blocks
  const content = response.content as Record<string, unknown>;
  if (!Array.isArray(content.text_blocks)) return false;
  if (content.text_blocks.length === 0) return false;
  
  return true;
}

/**
 * Parse message content to unified LLM response
 * @param content Raw message content
 * @returns LlmStructuredResponse if valid, null otherwise
 */
export function parseLlmResponse(content: string): LlmStructuredResponse | null {
  if (!content) return null;
  
  let jsonContent = content.trim();
  
  // Remove markdown code block wrappers if present
  if (jsonContent.startsWith('```json')) {
    jsonContent = jsonContent.replace(/^```json\s*\n?/, '').replace(/\n?```\s*$/, '');
  } else if (jsonContent.startsWith('```')) {
    jsonContent = jsonContent.replace(/^```\s*\n?/, '').replace(/\n?```\s*$/, '');
  }
  
  try {
    const parsed = JSON.parse(jsonContent);
    if (isLlmStructuredResponse(parsed)) {
      return parsed;
    }
  } catch {
    // Not valid JSON
  }
  
  return null;
}

/**
 * Convert unified LLM response to markdown for display
 * @param response Parsed LLM response
 * @returns Markdown string
 */
export function llmResponseToMarkdown(response: LlmStructuredResponse): string {
  const parts: string[] = [];
  
  // Add safety message if danger detected
  if (!response.safety.is_safe && response.safety.safety_message) {
    parts.push(`⚠️ **Safety Notice**: ${response.safety.safety_message}`);
    parts.push('');
  }
  
  // Convert text blocks
  for (const block of response.content.text_blocks) {
    switch (block.type) {
      case 'heading':
        parts.push(`## ${block.content}`);
        break;
      case 'info':
        parts.push(`ℹ️ **Info**: ${block.content}`);
        break;
      case 'warning':
        parts.push(`⚠️ **Warning**: ${block.content}`);
        break;
      case 'error':
        parts.push(`🚨 **Important**: ${block.content}`);
        break;
      case 'success':
        parts.push(`✅ ${block.content}`);
        break;
      case 'code':
        parts.push('```\n' + block.content + '\n```');
        break;
      default:
        parts.push(block.content);
    }
  }
  
  return parts.join('\n\n');
}

/**
 * Check if LLM response indicates danger requiring intervention
 * @param response Parsed LLM response or safety assessment
 * @returns true if intervention is required
 */
export function requiresSafetyIntervention(response: LlmStructuredResponse | SafetyAssessment): boolean {
  const safety = 'safety' in response ? response.safety : response;
  return safety.requires_intervention === true || safety.danger_level === 'emergency';
}

/**
 * Get form from unified LLM response
 * @param response Parsed LLM response
 * @returns FormDefinition if form present, null otherwise
 */
export function getFormFromLlmResponse(response: LlmStructuredResponse): FormDefinition | null {
  if (!response.content.form) return null;
  
  const form = response.content.form;
  return {
    type: 'form',
    title: form.title,
    description: form.description,
    fields: form.fields,
    submitLabel: form.submit_label
  };
}

// ============================================================================
// FILE ERROR MESSAGES
// ============================================================================

/**
 * File error message generators
 * Matches FILE_ERRORS from vanilla JS
 */
export const FILE_ERRORS = {
  fileTooLarge: (fileName: string, maxSize: number): string =>
    `File "${fileName}" exceeds maximum size of ${formatBytes(maxSize)}`,
  invalidType: (fileName: string, extension: string): string =>
    `File type ".${extension}" is not allowed`,
  duplicateFile: (fileName: string): string =>
    `File "${fileName}" is already attached`,
  maxFilesExceeded: (max: number): string =>
    `Maximum ${max} files allowed per message`,
  emptyFile: (fileName: string): string =>
    `File "${fileName}" is empty`,
  uploadFailed: (fileName: string): string =>
    `Failed to upload "${fileName}"`
};

/**
 * Format bytes to human-readable string
 * Matches formatBytes from vanilla JS
 */
export function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}
