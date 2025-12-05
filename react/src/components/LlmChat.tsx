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

import React, { useEffect, useCallback, useState, useRef, useMemo } from 'react';
import { MessageList } from './MessageList';
import { MessageInput } from './MessageInput';
import { ConversationSidebar } from './ConversationSidebar';
import { StreamingIndicator } from './StreamingIndicator';
import { useChatState } from '../hooks/useChatState';
import { useStreaming } from '../hooks/useStreaming';
import type { LlmChatConfig, SelectedFile, Message } from '../types';
import './LlmChat.css';

/**
 * Hook for smart auto-scroll behavior
 * Only scrolls to bottom if user is already near the bottom
 */
function useSmartScroll(containerRef: React.RefObject<HTMLDivElement>) {
  const isNearBottomRef = useRef(true);
  const lastScrollTopRef = useRef(0);
  
  // Threshold for considering user "at bottom" (in pixels)
  const SCROLL_THRESHOLD = 100;
  
  // Check if user is near bottom
  const checkIfNearBottom = useCallback(() => {
    const container = containerRef.current;
    if (!container) return true;
    
    const { scrollTop, scrollHeight, clientHeight } = container;
    return scrollHeight - scrollTop - clientHeight < SCROLL_THRESHOLD;
  }, [containerRef]);
  
  // Update scroll position tracking
  const handleScroll = useCallback(() => {
    const container = containerRef.current;
    if (!container) return;
    
    lastScrollTopRef.current = container.scrollTop;
    isNearBottomRef.current = checkIfNearBottom();
  }, [checkIfNearBottom, containerRef]);
  
  // Smooth scroll to bottom with animation
  const scrollToBottom = useCallback((force = false) => {
    const container = containerRef.current;
    if (!container) return;
    
    // Only scroll if user was already at bottom (or force is true)
    if (force || isNearBottomRef.current) {
      requestAnimationFrame(() => {
        container.scrollTo({
          top: container.scrollHeight,
          behavior: 'smooth'
        });
        isNearBottomRef.current = true;
      });
    }
  }, [containerRef]);
  
  // Force scroll to bottom (for user-initiated messages)
  const forceScrollToBottom = useCallback(() => {
    scrollToBottom(true);
  }, [scrollToBottom]);
  
  return {
    handleScroll,
    scrollToBottom,
    forceScrollToBottom,
    isNearBottom: () => isNearBottomRef.current
  };
}

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
    setError,
    getActiveModel
  } = useChatState(config);
  
  // Local state for file attachments
  const [selectedFiles, setSelectedFiles] = useState<SelectedFile[]>([]);
  
  // Ref for messages container (for smooth scrolling)
  const messagesContainerRef = useRef<HTMLDivElement>(null);
  
  // Smart scroll management
  const {
    handleScroll,
    scrollToBottom,
    forceScrollToBottom
  } = useSmartScroll(messagesContainerRef);
  
  // Callback to refresh messages after streaming (React-only refresh)
  const refreshMessages = useCallback(async (conversationId: string) => {
    await loadConversationMessages(conversationId);
    if (config.enableConversationsList) {
      await loadConversations();
    }
  }, [loadConversationMessages, loadConversations, config.enableConversationsList]);

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
      // Smart scroll - only scrolls if user was at bottom
      scrollToBottom();
    }, [scrollToBottom]),
    onDone: useCallback(() => {
      // Content clearing handled by hook
    }, []),
    onError: useCallback((err: string) => {
      setError(err);
    }, [setError]),
    onStart: useCallback(() => {
      // Content clearing handled by hook
    }, []),
    onRefreshMessages: refreshMessages,
    getActiveModel: getActiveModel
  });
  
  /**
   * Initialize chat on mount
   */
  useEffect(() => {
    const initializeChat = async () => {
      if (config.enableConversationsList) {
        // Load conversations list - this will also load the current conversation's messages
        // if there's a conversation ID in the URL/config
        await loadConversations();
      } else {
        // Single conversation mode - load current or last conversation
        await loadCurrentConversation();
      }
    };
    
    initializeChat();
  }, []); // eslint-disable-line react-hooks/exhaustive-deps
  
  /**
   * Load current conversation for single-conversation mode
   */
  const loadCurrentConversation = useCallback(async () => {
    if (config.currentConversationId) {
      await loadConversationMessages(config.currentConversationId);
    } else {
      // Try to load conversations to get the last one
      try {
        await loadConversations();
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
    
    // Force scroll to bottom when user sends a message
    forceScrollToBottom();
    
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
    forceScrollToBottom
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
   * Smart scroll when messages change (only if near bottom)
   */
  useEffect(() => {
    scrollToBottom();
  }, [messages, scrollToBottom]);
  
  // Determine the active model for this conversation
  // - Use conversation's model if it exists
  // - Otherwise use the configured model (for new conversations)
  const activeModel = useMemo(() => {
    if (currentConversation?.model) {
      return currentConversation.model;
    }
    return config.configuredModel;
  }, [currentConversation?.model, config.configuredModel]);

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
  
  // Determine if model is mismatched
  const isModelMismatch = currentConversation?.model && 
    currentConversation.model !== config.configuredModel;

  return (
    <div className="llm-chat-container">
      {/* Error Alert */}
      {error && (
        <div className="llm-error-alert">
          <div className="d-flex align-items-center">
            <i className="fas fa-exclamation-circle mr-2"></i>
            <span style={{ flex: 1 }}>{error}</span>
            <button
              type="button"
              className="close"
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
          <div className="d-flex flex-column h-100">
            {/* Chat Header */}
            <div className="chat-header">
              <div className="chat-title">
                <div className="chat-icon">
                  <i className="fas fa-robot"></i>
                </div>
                <h5>{currentConversation?.title || 'AI Chat'}</h5>
              </div>
              <div className={`model-badge ${isModelMismatch ? 'model-mismatch' : ''}`}>
                <i className={`fas ${isModelMismatch ? 'fa-exclamation-triangle' : 'fa-microchip'}`}></i>
                <span>{activeModel}</span>
              </div>
            </div>
            
            {/* Messages Container */}
            <div
              ref={messagesContainerRef}
              id="messages-container"
              onScroll={handleScroll}
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
