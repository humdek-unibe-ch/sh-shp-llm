/**
 * LLM Chat Main Component
 * =======================
 * 
 * The main React component for the LLM Chat interface.
 * Provides the same functionality as the vanilla JS implementation:
 * - Conversation management (create, delete, select)
 * - Message sending with file attachments
 * - Real-time streaming via SSE
 * - Responsive Bootstrap-based UI
 * 
 * @module components/LlmChat
 */

import React, { useEffect, useCallback, useState, useRef } from 'react';
import { MessageList } from './MessageList';
import { MessageInput } from './MessageInput';
import { ConversationSidebar } from './ConversationSidebar';
import { StreamingIndicator } from './StreamingIndicator';
import { useChatState } from '../hooks/useChatState';
import { useStreaming } from '../hooks/useStreaming';
import type { LlmChatConfig, SelectedFile, Message } from '../types';
import './LlmChat.css';

/**
 * Props for LlmChat component
 */
interface LlmChatProps {
  /** Component configuration from PHP backend */
  config: LlmChatConfig;
}

/**
 * LLM Chat Component
 * 
 * Main chat interface component that orchestrates all sub-components
 * and manages the overall chat state.
 * 
 * @param props - Component props
 */
export const LlmChat: React.FC<LlmChatProps> = ({ config }) => {
  // Chat state management
  const {
    conversations,
    currentConversation,
    messages,
    isLoading,
    error,
    loadConversations,
    loadConversationMessages,
    createConversation,
    deleteConversation,
    selectConversation,
    sendMessage,
    addUserMessage,
    clearError,
    setError
  } = useChatState(config);
  
  // Local state for file attachments
  const [selectedFiles, setSelectedFiles] = useState<SelectedFile[]>([]);
  
  // Ref for messages container (for smooth scrolling)
  const messagesContainerRef = useRef<HTMLDivElement>(null);
  
  // Streaming state management
  const {
    isStreaming,
    streamingContent,
    sendStreamingMessage,
    stopStreaming,
    clearStreamingContent
  } = useStreaming({
    config,
    onChunk: useCallback(() => {
      // Scroll to bottom on each chunk
      smoothScrollToBottom();
    }, []),
    onDone: useCallback(() => {
      // Content clearing handled by hook
    }, []),
    onError: useCallback((err: string) => {
      setError(err);
    }, [setError]),
    onStart: useCallback(() => {
      // Content clearing handled by hook
    }, [])
  });
  
  /**
   * Smooth scroll to bottom of messages
   */
  const smoothScrollToBottom = useCallback(() => {
    requestAnimationFrame(() => {
      if (messagesContainerRef.current) {
        messagesContainerRef.current.scrollTo({
          top: messagesContainerRef.current.scrollHeight,
          behavior: 'smooth'
        });
      }
    });
  }, []);
  
  /**
   * Initialize chat on mount
   */
  useEffect(() => {
    if (config.enableConversationsList) {
      loadConversations();
    } else {
      // Single conversation mode - load current or last conversation
      loadCurrentConversation();
    }
  }, []);
  
  /**
   * Load current conversation for single-conversation mode
   */
  const loadCurrentConversation = useCallback(async () => {
    if (config.currentConversationId) {
      loadConversationMessages(config.currentConversationId);
    } else {
      // Try to load the last conversation
      try {
        const convs = await loadConversations();
        // The hook will auto-select the first conversation
      } catch (err) {
        // No conversations - show empty state
      }
    }
  }, [config.currentConversationId, loadConversationMessages, loadConversations]);
  
  /**
   * Handle sending a message
   */
  const handleSendMessage = useCallback(async (message: string, files: SelectedFile[]) => {
    // Validate input
    if (!message.trim() && files.length === 0) {
      setError('Please enter a message or attach a file');
      return;
    }
    
    // Prevent concurrent sends
    if (isStreaming) {
      setError('Please wait for the current response to complete');
      return;
    }
    
    // Add user message to UI immediately
    addUserMessage(message, files.length);
    
    // Clear selected files
    setSelectedFiles([]);
    
    // Scroll to bottom
    smoothScrollToBottom();
    
    // Get current conversation ID
    const conversationId = currentConversation?.id || null;
    
    if (config.streamingEnabled) {
      // Use streaming mode
      await sendStreamingMessage(message, conversationId, files);
    } else {
      // Use regular AJAX mode
      await sendMessage(message, files);
      
      // Reload conversations if new
      if (config.enableConversationsList) {
        loadConversations();
      }
    }
  }, [
    isStreaming,
    currentConversation,
    config.streamingEnabled,
    config.enableConversationsList,
    addUserMessage,
    sendMessage,
    sendStreamingMessage,
    loadConversations,
    setError,
    smoothScrollToBottom
  ]);
  
  /**
   * Handle conversation creation
   */
  const handleCreateConversation = useCallback(async (title?: string) => {
    try {
      await createConversation(title);
    } catch (err) {
      // Error is handled in the hook
    }
  }, [createConversation]);
  
  /**
   * Handle conversation deletion
   */
  const handleDeleteConversation = useCallback(async (conversationId: string) => {
    try {
      await deleteConversation(conversationId);
    } catch (err) {
      // Error is handled in the hook
    }
  }, [deleteConversation]);
  
  /**
   * Handle file selection
   */
  const handleFilesChange = useCallback((files: SelectedFile[]) => {
    setSelectedFiles(files);
  }, []);
  
  /**
   * Auto-dismiss error after timeout
   */
  useEffect(() => {
    if (error) {
      const timer = setTimeout(() => {
        clearError();
      }, 6000);
      return () => clearTimeout(timer);
    }
  }, [error, clearError]);
  
  /**
   * Scroll to bottom when messages change
   */
  useEffect(() => {
    smoothScrollToBottom();
  }, [messages, smoothScrollToBottom]);
  
  // Build messages array with streaming message if active
  const displayMessages: Message[] = [...messages];
  if (isStreaming && streamingContent) {
    displayMessages.push({
      id: 'streaming-' + Date.now(),
      role: 'assistant',
      content: streamingContent,
      timestamp: new Date().toISOString()
    });
  }
  
  return (
    <div className="llm-chat-container">
      {/* Error Alert */}
      {error && (
        <div className="alert alert-danger alert-dismissible fade show llm-error-alert mb-2" role="alert">
          <div className="d-flex align-items-center">
            <i className="fas fa-exclamation-circle mr-2"></i>
            <span>{error}</span>
            <button
              type="button"
              className="close ml-auto"
              onClick={clearError}
              aria-label="Close"
            >
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
        </div>
      )}
      
      <div className="row no-gutters h-100">
        {/* Conversations Sidebar */}
        {config.enableConversationsList && (
          <div className="col-md-4 col-lg-3">
            <ConversationSidebar
              conversations={conversations}
              currentConversation={currentConversation}
              onSelect={selectConversation}
              onCreate={handleCreateConversation}
              onDelete={handleDeleteConversation}
              isLoading={isLoading}
              config={config}
            />
          </div>
        )}
        
        {/* Main Chat Area */}
        <div className={config.enableConversationsList ? "col-md-8 col-lg-9" : "col-12"}>
          <div className="card h-100">
            {/* Chat Header */}
            <div className="card-header bg-primary text-white">
              <div className="d-flex justify-content-between align-items-center">
                <h6 className="mb-0">
                  {currentConversation?.title || 'AI Chat'}
                </h6>
                <small>
                  Model: {currentConversation?.model || config.configuredModel}
                </small>
              </div>
            </div>
            
            {/* Messages Container */}
            <div
              ref={messagesContainerRef}
              id="messages-container"
              className="card-body overflow-auto"
              style={{ height: 'calc(100% - 150px)', minHeight: '300px' }}
            >
              <MessageList
                messages={displayMessages}
                isStreaming={isStreaming}
                streamingContent={streamingContent}
                isLoading={isLoading && messages.length === 0}
                config={config}
              />
            </div>
            
            {/* Streaming Indicator */}
            {isStreaming && (
              <StreamingIndicator text={config.aiThinkingText} />
            )}
            
            {/* Message Input */}
            <div className="card-footer">
              <MessageInput
                onSend={handleSendMessage}
                selectedFiles={selectedFiles}
                onFilesChange={handleFilesChange}
                disabled={isStreaming || isLoading}
                config={config}
              />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default LlmChat;
