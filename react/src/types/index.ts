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
export interface LlmChatConfig {
  /** Current user ID */
  userId: number;
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
  /** File configuration */
  fileConfig: FileConfig;
  
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
  fileConfig: DEFAULT_FILE_CONFIG,
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
