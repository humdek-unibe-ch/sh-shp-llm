/**
 * LLM Chat Type Definitions
 * =========================
 * 
 * TypeScript type definitions for the LLM Chat React component.
 * These types match the data structures used by the SelfHelp backend
 * controller (LlmchatController.php) and the vanilla JS implementation.
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
  removeFileTitle: 'Remove file'
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
 * Response from send_message action
 */
export interface SendMessageResponse {
  conversation_id?: string;
  message?: string;
  is_new_conversation?: boolean;
  streaming?: boolean;
  error?: string;
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
 * Supports radio buttons, checkboxes, dropdowns, text inputs, and number inputs
 */
export interface FormField {
  /** Unique field identifier */
  id: string;
  /** Field type: radio (single select), checkbox (multi-select), select (dropdown), text (free text), number */
  type: 'radio' | 'checkbox' | 'select' | 'text' | 'textarea' | 'number';
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
