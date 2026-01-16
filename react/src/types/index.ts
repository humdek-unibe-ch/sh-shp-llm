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

// Import utility functions that were moved to separate files
export {
  parseFormSubmissionMetadata,
  parseFormDefinition,
  formatFormSelectionsAsText,
  messageHasForm,
  extractFormFromMessage,
  extractFormsFromStructuredResponse,
  structuredFormToFormDefinition
} from '../utils/formUtils';

export {
  parseStructuredResponse,
  isStructuredResponse,
  structuredResponseToMarkdown,
  parseLlmResponse,
  isLlmStructuredResponse,
  llmResponseToMarkdown,
  requiresSafetyIntervention,
  getFormFromLlmResponse
} from '../utils/llmResponseUtils';

export { formatBytes } from '../utils/generalUtils';

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
// TYPE GUARDS
// ============================================================================

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

// ============================================================================
// FILE ERROR MESSAGES
// ============================================================================

/**
 * File error message generators
 * Matches FILE_ERRORS from vanilla JS
 */
export const FILE_ERRORS = {
  fileTooLarge: (fileName: string, maxSize: number): string => {
    const formatBytesLocal = (bytes: number): string => {
      if (bytes === 0) return '0 Bytes';
      const k = 1024;
      const sizes = ['Bytes', 'KB', 'MB', 'GB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    };
    return `File "${fileName}" exceeds maximum size of ${formatBytesLocal(maxSize)}`;
  },
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
  /** Whether the message passed JSON schema validation (1=valid, 0=invalid/retry attempt) */
  is_validated?: number | boolean | string;
  /** JSON payload sent to LLM API for debugging (stored on assistant messages) */
  request_payload?: string;
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
  /** When the conversation was blocked */
  blocked_at?: string;
  /** Who blocked the conversation (user ID) */
  blocked_by?: number;
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
 * Floating button position options
 */
export type FloatingButtonPosition =
  | 'bottom-right'
  | 'bottom-left'
  | 'top-right'
  | 'top-left'
  | 'bottom-center'
  | 'top-center';

/**
 * LLM Chat component configuration
 * Passed from PHP via data attributes on the container element
 * Matches the data attributes set in llm_chat_main.php
 */
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
  /** Whether to do full page reload after request completion (default: false - React refresh only) */
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

  // ===== Speech-to-Text Configuration =====
  /** Whether speech-to-text input is enabled */
  enableSpeechToText: boolean;
  /** The Whisper model used for speech recognition */
  speechToTextModel: string;

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
  /** Error message for form submission failures */
  formSubmissionError?: string;
}

/**
 * Default configuration values
 */
export const DEFAULT_CONFIG: Partial<LlmChatConfig> = {
  configuredModel: 'qwen3-vl-8b-instruct',
  enableConversationsList: true,
  enableFileUploads: true,
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
  // Speech-to-text defaults
  enableSpeechToText: false,
  speechToTextModel: '',
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
  defaultChatTitle: 'AI Chat',
  deleteButtonTitle: 'Delete conversation',
  conversationTitlePlaceholder: 'Enter conversation title (optional)',
  singleFileAttachedText: '1 file attached',
  multipleFilesAttachedText: '{count} files attached',
  emptyStateTitle: 'Start a conversation',
  emptyStateDescription: 'Send a message to start chatting with the AI assistant.',
  loadingMessagesText: 'Loading messages...',
  attachFilesTitle: 'Attach files',
  noVisionSupportTitle: 'Current model does not support image uploads',
  noVisionSupportText: 'No vision',
  sendMessageTitle: 'Send message',
  removeFileTitle: 'Remove file',
  conversationBlockedMessage: 'This conversation has been blocked. You cannot send any more messages.',
  formSubmissionError: 'Form submission failed'
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
 * Form submission metadata stored in message attachments
 */
export interface FormSubmissionMetadata {
  /** Type identifier */
  type: 'form_submission';
  /** The values submitted by the user */
  values: Record<string, string | string[]>;
}

/**
 * Form definition returned by LLM in form mode
 * Uses JSON Schema-inspired format
 */
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
  | 'error'
  | 'fallback';

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