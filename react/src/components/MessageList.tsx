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
 * 
 * @module components/MessageList
 */

import React from 'react';
import type { Message, LlmChatConfig } from '../types';
import { formatTime } from '../utils/formatters';
import { MarkdownRenderer } from './MarkdownRenderer';

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
 * Individual message item component
 * Renders a single message with avatar, content, and metadata
 */
const MessageItem: React.FC<MessageItemProps> = ({ message, isStreaming = false, config }) => {
  const isUser = message.role === 'user';
  const attachmentCount = getAttachmentCount(message.attachments);
  
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
          ) : (
            // Assistant messages: render with markdown
            <MarkdownRenderer 
              content={message.content} 
              isStreaming={isStreaming}
            />
          )}
        </div>
        
        {/* Attachment indicator */}
        <AttachmentIndicator count={attachmentCount} isUser={isUser} />
        
        {/* Message metadata */}
        <div className="message-meta">
          <span>{formatTime(message.timestamp)}</span>
          {message.tokens_used && (
            <span className="tokens">
              <i className="fas fa-coins fa-xs"></i>
              {message.tokens_used}{config.tokensSuffix}
            </span>
          )}
        </div>
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
const EmptyState: React.FC = () => (
  <div className="empty-chat-state">
    <i className="fas fa-comments"></i>
    <h5>Start a conversation</h5>
    <p>Send a message to start chatting with the AI assistant.</p>
  </div>
);

/**
 * Loading state component
 * Shows while loading initial data
 */
const LoadingState: React.FC = () => (
  <div className="loading-spinner">
    <div className="spinner-border mb-3" role="status">
      <span className="sr-only">Loading...</span>
    </div>
    <p>Loading messages...</p>
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
