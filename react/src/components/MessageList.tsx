/**
 * Message List Component
 * ======================
 * 
 * Displays the list of messages in a conversation.
 * Matches the exact styling of the vanilla JS implementation.
 * 
 * Features:
 * - User messages (right-aligned, blue background)
 * - Assistant messages (left-aligned, light background)
 * - Attachment indicators
 * - Markdown rendering
 * - Streaming message with typing cursor
 * - Thinking indicator
 * 
 * @module components/MessageList
 */

import React from 'react';
import type { Message, LlmChatConfig } from '../types';
import { formatTime, parseMarkdown, escapeHtml } from '../utils/formatters';

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
  /** Component configuration */
  config: LlmChatConfig;
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
}

/**
 * Generate avatar HTML for a message
 * Matches generateAvatar() from vanilla JS
 */
const Avatar: React.FC<{ role: 'user' | 'assistant'; isRightAligned?: boolean }> = ({
  role,
  isRightAligned = false
}) => {
  const icon = role === 'user' ? 'fa-user' : 'fa-robot';
  const bgClass = role === 'user' ? 'bg-primary' : 'bg-success';
  const marginClass = isRightAligned ? 'ml-3' : 'mr-3';
  
  return (
    <div
      className={`rounded-circle d-flex align-items-center justify-content-center ${marginClass} flex-shrink-0 ${bgClass}`}
      style={{ width: 38, height: 38 }}
    >
      <i className={`fas ${icon}`}></i>
    </div>
  );
};

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
const AttachmentIndicator: React.FC<{ count: number; isUser: boolean }> = ({ count, isUser }) => {
  if (count === 0) return null;
  
  const fileText = count === 1 ? '1 file attached' : `${count} files attached`;
  const textClass = isUser ? 'text-white-50' : 'text-muted';
  
  return (
    <div className="attachment-indicator mt-1">
      <small className={textClass}>
        <i className="fas fa-paperclip mr-1"></i>
        {fileText}
      </small>
    </div>
  );
};

/**
 * Individual message item component
 * Renders a single message with avatar, content, and metadata
 */
const MessageItem: React.FC<MessageItemProps> = ({ message, isStreaming = false, config }) => {
  const isUser = message.role === 'user';
  const attachmentCount = getAttachmentCount(message.attachments);
  
  // Format content - use pre-formatted if available, otherwise parse markdown
  const formattedContent = message.formatted_content || 
    (isUser ? escapeHtml(message.content) : parseMarkdown(message.content));
  
  return (
    <div className={`d-flex mb-3 ${isUser ? 'justify-content-end' : 'justify-content-start'}`}>
      {/* Avatar - position depends on user/assistant */}
      {!isUser && <Avatar role="assistant" />}
      
      {/* Message content */}
      <div className={`llm-message-content ${isUser ? 'bg-primary text-white' : 'bg-light'} p-3 rounded border`}>
        {/* Message text */}
        <div 
          className="mb-2"
          dangerouslySetInnerHTML={{ __html: formattedContent }}
        />
        
        {/* Typing cursor for streaming */}
        {isStreaming && (
          <span 
            className="border-left border-primary ml-1"
            style={{ 
              height: '1.2em', 
              animation: 'blink 1s infinite',
              display: 'inline-block'
            }}
          />
        )}
        
        {/* Attachment indicator */}
        <AttachmentIndicator count={attachmentCount} isUser={isUser} />
        
        {/* Message metadata */}
        <div className="mt-2">
          <small className={isUser ? 'text-white-50' : 'text-muted'}>
            {formatTime(message.timestamp)}
            {message.tokens_used && (
              <> • {message.tokens_used}{config.tokensSuffix}</>
            )}
          </small>
        </div>
      </div>
      
      {/* User avatar on right side */}
      {isUser && <Avatar role="user" isRightAligned />}
    </div>
  );
};

/**
 * Thinking indicator component
 * Shows while waiting for AI response
 */
const ThinkingIndicator: React.FC<{ text: string }> = ({ text }) => (
  <div className="d-flex mb-3 justify-content-start">
    <Avatar role="assistant" />
    <div className="llm-message-content bg-light p-3 rounded border">
      <div className="d-flex align-items-center">
        <div className="spinner-border spinner-border-sm text-primary mr-2" role="status">
          <span className="sr-only">Loading...</span>
        </div>
        <small className="text-muted">{text}</small>
      </div>
    </div>
  </div>
);

/**
 * Empty state component
 * Shows when no messages exist
 */
const EmptyState: React.FC = () => (
  <div className="d-flex align-items-center justify-content-center h-100">
    <p className="text-center text-muted">
      No messages yet. Send your first message!
    </p>
  </div>
);

/**
 * Loading state component
 * Shows while loading initial data
 */
const LoadingState: React.FC = () => (
  <div className="d-flex align-items-center justify-content-center h-100">
    <div className="text-center text-muted">
      <div className="spinner-border text-primary mb-3" role="status">
        <span className="sr-only">Loading...</span>
      </div>
      <p>Loading messages...</p>
    </div>
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
  config
}) => {
  // Show loading state
  if (isLoading) {
    return <LoadingState />;
  }
  
  // Show empty state
  if (messages.length === 0 && !isStreaming) {
    return <EmptyState />;
  }
  
  // Check if the last message is a user message and we're streaming
  // This means we need to show the thinking indicator
  const lastMessage = messages[messages.length - 1];
  const showThinking = isStreaming && 
    !streamingContent && 
    lastMessage?.role === 'user';
  
  return (
    <>
      {/* Render all messages */}
      {messages.map((message, index) => {
        // Check if this is a streaming message (last assistant message during streaming)
        const isStreamingMessage = !!(isStreaming && 
          streamingContent && 
          index === messages.length - 1 && 
          message.role === 'assistant');
        
        return (
          <MessageItem
            key={message.id || `msg-${index}`}
            message={message}
            isStreaming={isStreamingMessage}
            config={config}
          />
        );
      })}
      
      {/* Show thinking indicator */}
      {showThinking && (
        <ThinkingIndicator text={config.aiThinkingText} />
      )}
    </>
  );
};

export default MessageList;
