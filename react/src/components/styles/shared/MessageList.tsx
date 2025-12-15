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
import { parseFormDefinition } from '../../../types';
import { formatTime } from '../../../utils/formatters';
import { MarkdownRenderer } from './MarkdownRenderer';
import { FormRenderer } from './FormRenderer';

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
 * Render a historical form as a read-only summary
 * Shows what form was presented without the interactive elements
 */
const HistoricalFormSummary: React.FC<{ formDefinition: FormDefinition }> = ({ formDefinition }) => {
  return (
    <div className="historical-form-summary bg-light rounded p-3 border">
      <div className="d-flex align-items-center mb-2">
        <i className="fas fa-list-ul text-primary mr-2"></i>
        <strong className="text-primary">{formDefinition.title || 'Form'}</strong>
        <span className="badge badge-secondary ml-2">Completed</span>
      </div>
      {formDefinition.description && (
        <p className="text-muted small mb-2">{formDefinition.description}</p>
      )}
      <div className="small text-muted">
        <i className="fas fa-check-circle text-success mr-1"></i>
        {formDefinition.fields.length} question{formDefinition.fields.length !== 1 ? 's' : ''} answered
      </div>
    </div>
  );
};

/**
 * Individual message item component
 * Renders a single message with avatar, content, and metadata
 * In form mode, renders forms from assistant messages
 */
const MessageItem: React.FC<MessageItemProps> = ({ 
  message, 
  isStreaming = false, 
  config,
  isLastMessage = false,
  onFormSubmit,
  isFormSubmitting = false
}) => {
  const isUser = message.role === 'user';
  const attachmentCount = getAttachmentCount(message.attachments);
  
  // Check if this assistant message contains a form definition (form mode)
  let formDefinition: FormDefinition | null = null;
  let isHistoricalForm = false;
  
  if (!isUser && config.enableFormMode && !isStreaming) {
    formDefinition = parseFormDefinition(message.content);
    // If it's a form but not the last message, it's historical
    if (formDefinition && !isLastMessage) {
      isHistoricalForm = true;
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
            // User messages: plain text with preserved whitespace
            <div style={{ whiteSpace: 'pre-wrap' }}>{message.content}</div>
          ) : formDefinition && isHistoricalForm ? (
            // Historical form: show as completed summary
            <HistoricalFormSummary formDefinition={formDefinition} />
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
        {!formDefinition && (
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
  isFormSubmitting = false
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
  
  return (
    <div className="message-stack">
      {/* Render all messages */}
      {messages.map((message, index) => {
        // Check if this is a streaming message (last assistant message during streaming)
        const isStreamingMessage = !!(isStreaming && 
          streamingContent && 
          index === messages.length - 1 && 
          message.role === 'assistant');
        
        // Check if this is the last message (for form rendering)
        const isLastMessage = index === messages.length - 1;
        
        return (
          <MessageItem
            key={message.id || `msg-${index}`}
            message={message}
            isStreaming={isStreamingMessage}
            config={config}
            isLastMessage={isLastMessage}
            onFormSubmit={onFormSubmit}
            isFormSubmitting={isFormSubmitting}
          />
        );
      })}
      
      {/* Show thinking indicator */}
      {showThinking && (
        <ThinkingIndicator text={config.aiThinkingText} />
      )}
    </div>
  );
};

export default MessageList;
