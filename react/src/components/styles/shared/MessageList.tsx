/**
 * Message List Component
 * ======================
 * 
 * Displays the list of messages in a conversation.
 * Modern, professional design with smooth animations.
 * 
 * Features:
 * - User messages (right-aligned, gradient blue)
 * - Assistant messages (left-aligned, white with border)
 * - Avatar icons
 * - Markdown rendering with syntax highlighting
 * - Thinking indicator
 * - Form mode: renders JSON Schema forms from assistant messages
 * 
 * @module components/MessageList
 */

import React, { useCallback } from 'react';
import type { Message, LlmChatConfig, FormDefinition, StructuredResponse } from '../../../types';
import { 
  parseFormDefinition, 
  parseFormSubmissionMetadata, 
  parseStructuredResponse, 
  messageHasForm,
  extractFormFromMessage
} from '../../../types';
import { formatTime } from '../../../utils/formatters';
import { MarkdownRenderer } from './MarkdownRenderer';
import { FormRenderer } from './FormRenderer';
import { FormDisplay } from './FormDisplay';
import { StructuredResponseRenderer } from './StructuredResponseRenderer';

/**
 * Props for MessageList component
 */
interface MessageListProps {
  /** Array of messages to display */
  messages: Message[];
  /** Whether loading initial data */
  isLoading: boolean;
  /** Whether processing request */
  isProcessing?: boolean;
  /** Component configuration */
  config: LlmChatConfig;
  /** Callback when form is submitted (form mode only) */
  onFormSubmit?: (values: Record<string, string | string[]>, readableText: string) => void;
  /** Whether form submission is in progress */
  isFormSubmitting?: boolean;
  /** Callback when Continue button is clicked (form mode only) */
  onContinue?: () => void;
  /** Callback when a suggestion button is clicked (structured response mode) */
  onSuggestionClick?: (suggestion: string) => void;
  /** Information about the last failed form submission for retry */
  lastFailedFormSubmission?: {
    values: Record<string, string | string[]>;
    readableText: string;
    conversationId: string | null;
    timestamp: number;
  } | null;
  /** Callback when retrying a failed form submission */
  onRetryFormSubmission?: () => void;
}

/**
 * Props for individual message item
 */
interface MessageItemProps {
  /** The message to display */
  message: Message;
  /** Configuration */
  config: LlmChatConfig;
  /** Whether this is the last message (for form rendering) */
  isLastMessage?: boolean;
  /** Callback when form is submitted */
  onFormSubmit?: (values: Record<string, string | string[]>, readableText: string) => void;
  /** Whether form submission is in progress */
  isFormSubmitting?: boolean;
  /** The next message (to find user's form submission for historical forms) */
  nextMessage?: Message;
  /** Previous assistant message (to find form definition for user submissions) */
  previousAssistantFormDefinition?: FormDefinition;
  /** Callback when a suggestion button is clicked */
  onSuggestionClick?: (suggestion: string) => void;
}

/**
 * Parse and count attachments from message
 */
function getAttachmentCount(attachments?: string): number {
  if (!attachments) return 0;
  
  try {
    const parsed = JSON.parse(attachments);
    return Array.isArray(parsed) ? parsed.length : (parsed ? 1 : 0);
  } catch {
    return 0;
  }
}

/**
 * Render attachment indicator
 */
const AttachmentIndicator: React.FC<{ count: number; isUser: boolean; config: LlmChatConfig }> = ({ count, isUser, config }) => {
  if (count === 0) return null;
  
  const fileText = count === 1 ? config.singleFileAttachedText : config.multipleFilesAttachedText.replace('{count}', count.toString());
  
  return (
    <div className="mt-2 pt-2" style={{ borderTop: '1px solid rgba(0,0,0,0.08)' }}>
      <small style={{ opacity: 0.7 }}>
        <i className="fas fa-paperclip mr-1"></i>
        {fileText}
      </small>
    </div>
  );
};

/**
 * Render a historical form as a read-only display
 * Shows the form with the user's selections highlighted
 * Uses the same FormDisplay component as admin for consistency
 * 
 * To find the user's selections, we look at the next user message's attachments
 * which contain the form_submission metadata
 */
interface HistoricalFormDisplayProps {
  formDefinition: FormDefinition;
  /** The user's submitted values (from next message's attachments) */
  submittedValues?: Record<string, string | string[]>;
}

const HistoricalFormDisplay: React.FC<HistoricalFormDisplayProps> = ({ 
  formDefinition, 
  submittedValues 
}) => {
  // Use the FormDisplay component with submitted values
  // compact=false to match admin view style
  return (
    <FormDisplay
      formDefinition={formDefinition}
      submittedValues={submittedValues}
      compact={false}
    />
  );
};

/**
 * Render a user's form submission
 * Uses the same FormDisplay component as admin for consistency
 */
const UserFormSubmissionDisplay: React.FC<{
  formDefinition: FormDefinition;
  submittedValues: Record<string, string | string[]>;
}> = ({ formDefinition, submittedValues }) => {
  return (
    <FormDisplay
      formDefinition={formDefinition}
      submittedValues={submittedValues}
      compact={false}
    />
  );
};

/**
 * Individual message item component
 * Renders a single message with avatar, content, and metadata
 * Detects and renders structured responses, forms, or markdown from assistant messages
 */
const MessageItem: React.FC<MessageItemProps> = ({ 
  message, 
  config,
  isLastMessage = false,
  onFormSubmit,
  isFormSubmitting = false,
  nextMessage,
  previousAssistantFormDefinition,
  onSuggestionClick
}) => {
  const isUser = message.role === 'user';
  const attachmentCount = getAttachmentCount(message.attachments);
  
  // Check if this assistant message contains a structured response (new format)
  // Priority: Structured Response > Legacy Form > Markdown
  let structuredResponse: StructuredResponse | null = null;
  let formDefinition: FormDefinition | null = null;
  let isHistoricalForm = false;
  let userSubmittedValues: Record<string, string | string[]> | undefined;

  // Check if content appears to be malformed structured response
  const appearsToBeStructuredResponse = !isUser && (
    message.content.trim().startsWith('{') &&
    (message.content.includes('"content":') || message.content.includes('"text_blocks":') || message.content.includes('"forms":'))
  );

  // Try to parse responses
  let isIncompleteStructuredResponse = false;
  if (!isUser) {
    // First, try to parse as structured response (new format)
    structuredResponse = parseStructuredResponse(message.content);

    // If parsing failed but content looks like structured response, it might be incomplete
    isIncompleteStructuredResponse = appearsToBeStructuredResponse && !structuredResponse;

    // If not structured response, try legacy form format
    if (!structuredResponse && !isIncompleteStructuredResponse) {
      formDefinition = parseFormDefinition(message.content);
      // If it's a form but not the last message, it's historical
      if (formDefinition && !isLastMessage) {
        isHistoricalForm = true;
        // Try to find the user's submission from the next message
        if (nextMessage && nextMessage.role === 'user') {
          const submissionMeta = parseFormSubmissionMetadata(nextMessage.attachments);
          if (submissionMeta) {
            userSubmittedValues = submissionMeta.values;
          }
        }
      }
    }
  }
  
  // Check if this is a user message that's a form submission
  let isUserFormSubmission = false;
  let userFormDefinition: FormDefinition | null = null;
  let userFormValues: Record<string, string | string[]> | undefined;
  
  if (isUser) {
    const submissionMeta = parseFormSubmissionMetadata(message.attachments);
    if (submissionMeta) {
      isUserFormSubmission = true;
      userFormValues = submissionMeta.values;
      // Use the previous assistant's form definition if available
      userFormDefinition = previousAssistantFormDefinition || null;
    }
  }

  // Determine if we should hide metadata (for structured responses and active forms)
  const hideMetadata = structuredResponse || (formDefinition && !isHistoricalForm);
  
  return (
    <div className={`message-wrapper ${isUser ? 'user' : 'assistant'}`}>
      {/* Avatar */}
      <div className="message-avatar">
        <i className={`fas ${isUser ? 'fa-user' : 'fa-robot'}`}></i>
      </div>
      
      {/* Message Bubble */}
      <div className="message-bubble">
        {/* Message content */}
        <div className="message-content">
          {isUser ? (
            // User messages
            isUserFormSubmission && userFormDefinition && userFormValues ? (
              // User form submission: show as summary with selections
              <UserFormSubmissionDisplay
                formDefinition={userFormDefinition}
                submittedValues={userFormValues}
              />
            ) : (
              // Regular user message: plain text with preserved whitespace
              <div style={{ whiteSpace: 'pre-wrap' }}>{message.content}</div>
            )
          ) : structuredResponse ? (
            // Structured response: render with StructuredResponseRenderer
            <StructuredResponseRenderer
              response={structuredResponse}
              isLastMessage={isLastMessage}
              onFormSubmit={onFormSubmit}
              isFormSubmitting={isFormSubmitting}
              onSuggestionClick={onSuggestionClick}
            />
          ) : formDefinition && isHistoricalForm ? (
            // Historical form: show with user's selections
            <HistoricalFormDisplay 
              formDefinition={formDefinition} 
              submittedValues={userSubmittedValues}
            />
          ) : isIncompleteStructuredResponse ? (
            // Incomplete structured response: show error message
            <div className="alert alert-warning">
              <i className="fas fa-exclamation-triangle mr-2"></i>
              The AI response was interrupted. Please try again.
            </div>
          ) : formDefinition ? (
            // Active form: render interactive form
            <FormRenderer
              formDefinition={formDefinition}
              onSubmit={onFormSubmit || (() => {})}
              isSubmitting={isFormSubmitting}
              disabled={false}
            />
          ) : (
            // Regular assistant messages: render with markdown
            <MarkdownRenderer
              content={message.content}
            />
          )}
        </div>
        
        {/* Attachment indicator - hide for forms and structured responses */}
        {!formDefinition && !structuredResponse && !isUserFormSubmission && (
          <AttachmentIndicator count={attachmentCount} isUser={isUser} config={config} />
        )}
        
        {/* Message metadata - hide for active forms and structured responses */}
        {!hideMetadata && (
          <div className="message-meta">
            <span>{formatTime(message.timestamp)}</span>
            {message.tokens_used && (
              <span className="tokens">
                <i className="fas fa-coins fa-xs"></i>
                {message.tokens_used}{config.tokensSuffix}
              </span>
            )}
          </div>
        )}
      </div>
    </div>
  );
};

/**
 * Thinking indicator component
 * Shows while waiting for AI response
 */
const ThinkingIndicator: React.FC<{ text: string }> = ({ text }) => (
  <div className="message-wrapper assistant">
    <div className="message-avatar">
      <i className="fas fa-robot"></i>
    </div>
    <div className="message-bubble">
      <div className="d-flex align-items-center">
        <div className="thinking-dots mr-3">
          <span className="dot"></span>
          <span className="dot"></span>
          <span className="dot"></span>
        </div>
        <span style={{ color: 'var(--llm-text-secondary)', fontSize: '14px' }}>{text}</span>
      </div>
    </div>
  </div>
);

/**
 * Empty state component
 * Shows when no messages exist
 */
const EmptyState: React.FC<{ config: LlmChatConfig }> = ({ config }) => (
  <div className="empty-chat-state">
    <i className="fas fa-comments"></i>
    <h5>{config.emptyStateTitle}</h5>
    <p>{config.emptyStateDescription}</p>
  </div>
);

/**
 * Loading state component
 * Shows while loading initial data
 */
const LoadingState: React.FC<{ config: LlmChatConfig }> = ({ config }) => (
  <div className="loading-spinner">
    <div className="spinner-border mb-3" role="status">
      <span className="sr-only">{config.loadingText}</span>
    </div>
    <p>{config.loadingMessagesText}</p>
  </div>
);

/**
 * Continue Button Component
 * Shows when form mode is enabled but last assistant message has no form
 */
const ContinueButton: React.FC<{
  label: string;
  onClick: () => void;
  disabled: boolean;
}> = ({ label, onClick, disabled }) => (
  <div className="continue-button-wrapper text-center py-4">
    <button
      className="btn btn-primary btn-lg px-5"
      onClick={onClick}
      disabled={disabled}
    >
      {disabled ? (
        <>
          <span className="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span>
          {label}
        </>
      ) : (
        <>
          <i className="fas fa-arrow-right mr-2"></i>
          {label}
        </>
      )}
    </button>
  </div>
);

/**
 * Retry Form Component
 * Shows when a form submission failed and allows the user to retry
 */
const RetryForm: React.FC<{
  failedSubmission: {
    values: Record<string, string | string[]>;
    readableText: string;
    conversationId: string | null;
    timestamp: number;
  };
  onRetry: () => void;
  isSubmitting: boolean;
  config: LlmChatConfig;
}> = ({ failedSubmission, onRetry, isSubmitting, config }) => {
  const handleRetry = useCallback(() => {
    onRetry();
  }, [onRetry]);

  return (
    <div className="retry-form-wrapper">
      <div className="alert alert-warning mb-3">
        <i className="fas fa-exclamation-triangle mr-2"></i>
        <strong>{config.formSubmissionError || 'Form submission failed'}</strong>
        <br />
        <small>Your previous form submission could not be processed. Please try again.</small>
      </div>

      <div className="text-center py-3">
        <button
          className="btn btn-warning btn-lg px-5"
          onClick={handleRetry}
          disabled={isSubmitting}
        >
          {isSubmitting ? (
            <>
              <span className="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span>
              Retrying...
            </>
          ) : (
            <>
              <i className="fas fa-redo mr-2"></i>
              Retry Form Submission
            </>
          )}
        </button>
      </div>
    </div>
  );
};

/**
 * Message List Component
 *
 * Main component that renders all messages in the conversation
 */
export const MessageList: React.FC<MessageListProps> = ({
  messages,
  isLoading,
  isProcessing = false,
  config,
  onFormSubmit,
  isFormSubmitting = false,
  onContinue,
  onSuggestionClick,
  lastFailedFormSubmission,
  onRetryFormSubmission
}) => {
  // Show loading state
  if (isLoading) {
    return <LoadingState config={config} />;
  }

  // Show empty state
  if (messages.length === 0) {
    return <EmptyState config={config} />;
  }
  
  // Check if we need to show the thinking indicator
  const lastMessage = messages[messages.length - 1];
  const showThinking = isProcessing && lastMessage?.role === 'user';
  
  // Pre-compute form definitions for each assistant message
  // This allows us to pass the previous form definition to user messages
  // UNIFIED: Uses extractFormFromMessage() which extracts from BOTH legacy forms AND structured response forms
  const formDefinitionsMap = new Map<number, FormDefinition>();
  messages.forEach((message, index) => {
    if (message.role === 'assistant') {
      // UNIFIED: extractFormFromMessage checks both legacy FormDefinition AND StructuredResponse.forms
      const formDef = extractFormFromMessage(message.content);
      if (formDef) {
        formDefinitionsMap.set(index, formDef);
      }
    }
  });

  // Find the previous assistant's form definition for a given index
  const findPreviousAssistantFormDefinition = (currentIndex: number): FormDefinition | undefined => {
    for (let i = currentIndex - 1; i >= 0; i--) {
      if (messages[i].role === 'assistant') {
        return formDefinitionsMap.get(i);
      }
    }
    return undefined;
  };

  // Determine if we should show the Continue button or thinking state
  // Only show when we're at a dead end (no form to answer) in form mode
  // UNIFIED: Uses messageHasForm() which checks BOTH legacy forms AND structured response forms
  const shouldShowContinueButton = () => {
    if (!config.enableFormMode || !onContinue || messages.length === 0) {
      return false;
    }

    // If there's a failed form submission, don't show continue button
    if (lastFailedFormSubmission) {
      return false;
    }

    // Find the last assistant message
    const lastAssistantMessage = [...messages].reverse().find(msg => msg.role === 'assistant');
    if (!lastAssistantMessage) {
      return false;
    }

    // Only show continue if the last assistant message has NO form (we're at a dead end)
    // UNIFIED: messageHasForm checks both legacy FormDefinition AND StructuredResponse.forms
    const hasForm = messageHasForm(lastAssistantMessage.content);
    return !hasForm;
  };

  // Determine if we should show the retry form
  // Show when there's a failed form submission (either from state or detected from conversation)
  const shouldShowRetryForm = () => {
    if (!config.enableFormMode || messages.length === 0) {
      return false;
    }

    const lastMessage = messages[messages.length - 1];

    // First check if we have an active failed submission in state
    if (lastFailedFormSubmission && lastMessage && lastMessage.role === 'user') {
      return true;
    }

    // Then check if we can detect a failed submission from conversation history
    // A failed submission is: last message is user + has form metadata + no assistant response after
    if (lastMessage && lastMessage.role === 'user') {
      // Check if this user message has form submission metadata
      const submissionMeta = parseFormSubmissionMetadata(lastMessage.attachments);
      if (submissionMeta) {
        // This is a form submission with no response after it - consider it failed
        return true;
      }
    }

    return false;
  };

  // Get retry form data - either from state or detected from conversation
  const getRetryFormData = () => {
    // First priority: use data from component state if available
    if (lastFailedFormSubmission) {
      return lastFailedFormSubmission;
    }

    // Second priority: extract from conversation history
    const lastMessage = messages[messages.length - 1];
    if (lastMessage && lastMessage.role === 'user') {
      const submissionMeta = parseFormSubmissionMetadata(lastMessage.attachments);
      if (submissionMeta) {
        // Find the form definition from previous assistant message
        const previousFormDef = findPreviousAssistantFormDefinition(messages.length - 1);
        if (previousFormDef) {
          return {
            values: submissionMeta.values,
            readableText: lastMessage.content,
            conversationId: null, // We don't need this for retry from history
            timestamp: new Date(lastMessage.timestamp).getTime()
          };
        }
      }
    }

    return null;
  };
  
  // Determine if we should show thinking indicator for Continue button area
  // This shows when Continue was clicked and we're waiting for response
  const shouldShowContinueThinking = () => {
    if (!config.enableFormMode || messages.length === 0) {
      return false;
    }
    
    // Show thinking when processing in form mode
    if (!isProcessing && !isFormSubmitting) {
      return false;
    }

    // Find the last assistant message
    const lastAssistantMessage = [...messages].reverse().find(msg => msg.role === 'assistant');
    if (!lastAssistantMessage) {
      return false;
    }

    // Only show thinking if the last assistant message has NO form (we clicked Continue)
    // UNIFIED: messageHasForm checks both legacy FormDefinition AND StructuredResponse.forms
    const hasForm = messageHasForm(lastAssistantMessage.content);
    return !hasForm;
  };

  return (
    <div className="message-stack">
      {/* Render all messages */}
      {messages.map((message, index) => {
        // Check if this is the last message (for form rendering)
        const isLastMessage = index === messages.length - 1;
        
        // Get next message (for finding user's form submission)
        const nextMessage = index < messages.length - 1 ? messages[index + 1] : undefined;
        
        // Get previous assistant's form definition (for user form submissions)
        const previousAssistantFormDefinition = message.role === 'user' 
          ? findPreviousAssistantFormDefinition(index) 
          : undefined;
        
        return (
          <MessageItem
            key={message.id || `msg-${index}`}
            message={message}
            config={config}
            isLastMessage={isLastMessage}
            onFormSubmit={onFormSubmit}
            isFormSubmitting={isFormSubmitting}
            nextMessage={nextMessage}
            previousAssistantFormDefinition={previousAssistantFormDefinition}
            onSuggestionClick={onSuggestionClick}
          />
        );
      })}
      
      {/* Show thinking indicator */}
      {showThinking && (
        <ThinkingIndicator text={config.aiThinkingText} />
      )}
      
      {/* Show thinking indicator for Continue button area when processing */}
      {shouldShowContinueThinking() && (
        <ThinkingIndicator text={config.aiThinkingText} />
      )}
      
      {/* Show retry form when there's a failed form submission */}
      {(() => {
        const retryData = getRetryFormData();
        const showRetry = shouldShowRetryForm() && retryData;

        if (!showRetry) return null;

        // For retries from state, use the onRetryFormSubmission function
        // For retries from conversation history, create a synthetic retry function
        const retryFunction = lastFailedFormSubmission && onRetryFormSubmission
          ? onRetryFormSubmission
          : () => {
              // Retry from conversation history - call onFormSubmit with saved values
              if (onFormSubmit && retryData) {
                onFormSubmit(retryData.values, retryData.readableText);
              }
            };

        return (
          <RetryForm
            failedSubmission={retryData}
            onRetry={retryFunction}
            isSubmitting={isFormSubmitting}
            config={config}
          />
        );
      })()}

      {/* Show Continue button in form mode when last assistant message has no form */}
      {shouldShowContinueButton() && !isProcessing && !isFormSubmitting && onContinue && (
        <ContinueButton
          label={config.continueButtonLabel}
          onClick={onContinue}
          disabled={false}
        />
      )}
    </div>
  );
};

export default MessageList;
