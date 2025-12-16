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
 * - Streaming message with typing cursor
 * - Thinking indicator
 * - Form mode: renders JSON Schema forms from assistant messages
 * 
 * @module components/MessageList
 */

import React from 'react';
import type { Message, LlmChatConfig, FormDefinition } from '../../../types';
import { parseFormDefinition, parseFormSubmissionMetadata } from '../../../types';
import { formatTime } from '../../../utils/formatters';
import { MarkdownRenderer } from './MarkdownRenderer';
import { FormRenderer } from './FormRenderer';
import { FormDisplay } from './FormDisplay';

/**
 * Props for MessageList component
 */
interface MessageListProps {
  /** Array of messages to display */
  messages: Message[];
  /** Whether currently streaming */
  isStreaming: boolean;
  /** Current streaming content */
  streamingContent: string;
  /** Whether loading initial data */
  isLoading: boolean;
  /** Whether processing non-streaming request */
  isProcessing?: boolean;
  /** Component configuration */
  config: LlmChatConfig;
  /** Callback when form is submitted (form mode only) */
  onFormSubmit?: (values: Record<string, string | string[]>, readableText: string) => void;
  /** Whether form submission is in progress */
  isFormSubmitting?: boolean;
  /** Callback when Continue button is clicked (form mode only) */
  onContinue?: () => void;
}

/**
 * Props for individual message item
 */
interface MessageItemProps {
  /** The message to display */
  message: Message;
  /** Whether this is a streaming message */
  isStreaming?: boolean;
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
 * Detects and renders forms from assistant messages (even when form mode is disabled)
 */
const MessageItem: React.FC<MessageItemProps> = ({ 
  message, 
  isStreaming = false, 
  config,
  isLastMessage = false,
  onFormSubmit,
  isFormSubmitting = false,
  nextMessage,
  previousAssistantFormDefinition
}) => {
  const isUser = message.role === 'user';
  const attachmentCount = getAttachmentCount(message.attachments);
  
  // Check if this assistant message contains a form definition
  // Always try to detect forms, even when form mode is disabled
  // This allows LLMs to return forms dynamically
  let formDefinition: FormDefinition | null = null;
  let isHistoricalForm = false;
  let userSubmittedValues: Record<string, string | string[]> | undefined;

  // Try to parse forms even during streaming for better UX
  // This allows forms to render immediately when the JSON is complete
  if (!isUser) {
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
          ) : formDefinition && isHistoricalForm ? (
            // Historical form: show with user's selections
            <HistoricalFormDisplay 
              formDefinition={formDefinition} 
              submittedValues={userSubmittedValues}
            />
          ) : formDefinition ? (
            // Active form: render interactive form
            <FormRenderer
              formDefinition={formDefinition}
              onSubmit={onFormSubmit || (() => {})}
              isSubmitting={isFormSubmitting}
              disabled={isStreaming}
            />
          ) : (
            // Regular assistant messages: render with markdown
            <MarkdownRenderer 
              content={message.content} 
              isStreaming={isStreaming}
            />
          )}
        </div>
        
        {/* Attachment indicator - hide for forms */}
        {!formDefinition && !isUserFormSubmission && (
          <AttachmentIndicator count={attachmentCount} isUser={isUser} config={config} />
        )}
        
        {/* Message metadata - hide for active forms, show for historical */}
        {(!formDefinition || isHistoricalForm) && (
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
        <div className="streaming-dots mr-3">
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
 * Message List Component
 * 
 * Main component that renders all messages in the conversation
 */
export const MessageList: React.FC<MessageListProps> = ({
  messages,
  isStreaming,
  streamingContent,
  isLoading,
  isProcessing = false,
  config,
  onFormSubmit,
  isFormSubmitting = false,
  onContinue
}) => {
  // Show loading state
  if (isLoading) {
    return <LoadingState config={config} />;
  }

  // Show empty state
  if (messages.length === 0 && !isStreaming) {
    return <EmptyState config={config} />;
  }
  
  // Check if we need to show the thinking indicator
  // Show for both streaming (when no content yet) and non-streaming processing
  const lastMessage = messages[messages.length - 1];
  const showThinking = (isStreaming && !streamingContent && lastMessage?.role === 'user') ||
    (isProcessing && lastMessage?.role === 'user');
  
  // Pre-compute form definitions for each assistant message
  // This allows us to pass the previous form definition to user messages
  const formDefinitionsMap = new Map<number, FormDefinition>();
  messages.forEach((message, index) => {
    if (message.role === 'assistant') {
      const formDef = parseFormDefinition(message.content);
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
  const shouldShowContinueButton = () => {
    if (!config.enableFormMode || !onContinue || isStreaming || messages.length === 0) {
      return false;
    }

    // Find the last assistant message
    const lastAssistantMessage = [...messages].reverse().find(msg => msg.role === 'assistant');
    if (!lastAssistantMessage) {
      return false;
    }

    // Only show continue if the last assistant message has NO form (we're at a dead end)
    const hasForm = parseFormDefinition(lastAssistantMessage.content) !== null;
    return !hasForm;
  };
  
  // Determine if we should show thinking indicator for Continue button area
  // This shows when Continue was clicked and we're waiting for response
  const shouldShowContinueThinking = () => {
    if (!config.enableFormMode || isStreaming || messages.length === 0) {
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
    const hasForm = parseFormDefinition(lastAssistantMessage.content) !== null;
    return !hasForm;
  };

  return (
    <div className="message-stack">
      {/* Render all messages */}
      {messages.map((message, index) => {
        // Check if this is a streaming message (last assistant message during streaming)
        // Don't treat as streaming if we have a valid form
        const hasForm = message.role === 'assistant' && parseFormDefinition(message.content) !== null;
        const isStreamingMessage = !!(isStreaming &&
          streamingContent &&
          index === messages.length - 1 &&
          message.role === 'assistant' &&
          !hasForm);
        
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
            isStreaming={isStreamingMessage}
            config={config}
            isLastMessage={isLastMessage}
            onFormSubmit={onFormSubmit}
            isFormSubmitting={isFormSubmitting}
            nextMessage={nextMessage}
            previousAssistantFormDefinition={previousAssistantFormDefinition}
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
