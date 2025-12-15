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
import { Container, Row, Col, Alert, Card } from 'react-bootstrap';
import { MessageList } from '../shared/MessageList';
import { MessageInput } from '../shared/MessageInput';
import { ConversationSidebar } from '../shared/ConversationSidebar';
import { StreamingIndicator } from '../shared/StreamingIndicator';
import { useChatState } from '../../../hooks/useChatState';
import { useStreaming } from '../../../hooks/useStreaming';
import type { LlmChatConfig, SelectedFile, Message } from '../../../types';
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
  
  // Local state for file attachments
  const [selectedFiles, setSelectedFiles] = useState<SelectedFile[]>([]);

  // Local state for tracking non-streaming processing
  const [isProcessing, setIsProcessing] = useState(false);

  // Ref for messages container (for smooth scrolling)
  const messagesContainerRef = useRef<HTMLDivElement>(null);

  // Smart scroll management
  const {
    handleScroll,
    scrollToBottom,
    forceScrollToBottom
  } = useSmartScroll(messagesContainerRef);

  // Chat state management (must come first to provide functions)
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
    setCurrentConversation,
    getActiveModel
  } = useChatState(config);

  // Set up proper error handling and message refresh for streaming
  const streamingErrorHandler = useCallback((err: string) => {
    setError(err);
  }, [setError]);

  const messageRefreshHandler = useCallback(async (conversationId: string) => {
    await loadConversationMessages(conversationId);
    if (config.enableConversationsList) {
      await loadConversations();
    }
  }, [loadConversationMessages, loadConversations, config.enableConversationsList]);

  const newConversationHandler = useCallback((conversationId: string, model: string) => {
    // Update current conversation for single conversation mode
    if (!config.enableConversationsList) {
      const newConversation = {
        id: conversationId,
        title: 'New Conversation',
        model: model,
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString()
      };
      setCurrentConversation(newConversation);
    }
  }, [config.enableConversationsList, setCurrentConversation]);

  // Streaming state management (comes after chat state)
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
    onError: streamingErrorHandler,
    onStart: useCallback(() => {
      // Content clearing handled by hook
    }, []),
    onRefreshMessages: messageRefreshHandler,
    onNewConversation: newConversationHandler,
    getActiveModel: useCallback(() => config.configuredModel, [config.configuredModel])
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
    // Validate input - always require a message, even with file attachments
    if (!message.trim()) {
      setError(config.emptyMessageError);
      return;
    }
    
    // Prevent concurrent sends
    if (isStreaming) {
      setError(config.streamingActiveError);
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
    
    try {
      if (config.streamingEnabled) {
        // Use streaming mode
        await sendStreamingMessage(message, conversationId, files);
      } else {
        // Use regular AJAX mode
        setIsProcessing(true);
        try {
          await sendMessage(message, files);
        } finally {
          setIsProcessing(false);
        }
      }
    } catch (error) {
      // If sending fails, remove the user message from UI since it wasn't actually sent
      console.error('Failed to send message:', error);
      // The error will be shown via the error state
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
    <Container fluid className="llm-chat-container llm-chat-shell">
      {/* Error Alert */}
      {error && (
        <Alert variant="danger" dismissible onClose={clearError} className="mb-3">
          <i className="fas fa-exclamation-circle mr-2"></i>
          {error}
        </Alert>
      )}

      <Row className="no-gutters h-100 llm-chat-grid">
        {/* Conversations Sidebar */}
        {config.enableConversationsList && (
          <Col md={4} lg={3}>
            <ConversationSidebar
              conversations={conversations}
              currentConversation={currentConversation}
              onSelect={selectConversation}
              onCreate={handleCreateConversation}
              onDelete={handleDeleteConversation}
              isLoading={isLoading}
              config={config}
            />
          </Col>
        )}

        {/* Main Chat Area */}
        <Col className={config.enableConversationsList ? "" : "col-12"}>
          <Card className="h-100 border-0 shadow-sm llm-chat-panel d-flex flex-column">
            {/* Chat Header */}
            <Card.Header className="llm-chat-header bg-white border-0 d-flex justify-content-between align-items-center">
              <div className="d-flex align-items-center">
                <div className="bg-primary rounded-circle d-flex align-items-center justify-content-center mr-3" style={{width: '40px', height: '40px'}}>
                  <i className="fas fa-robot text-white"></i>
                </div>
                <h5 className="mb-0">{currentConversation?.title || config.defaultChatTitle}</h5>
              </div>
              <div className="d-flex align-items-center">
                <span className={`badge ${isModelMismatch ? 'badge-warning' : 'badge-secondary'} llm-model-badge`}>
                  <i className={`fas ${isModelMismatch ? 'fa-exclamation-triangle' : 'fa-microchip'} mr-1`}></i>
                  {activeModel}
                </span>
              </div>
            </Card.Header>

            {/* Messages Container */}
            <Card.Body
              ref={messagesContainerRef}
              id="messages-container"
              onScroll={handleScroll}
              className="p-3 flex-grow-1 overflow-auto llm-chat-body"
            >
              <MessageList
                messages={displayMessages}
                isStreaming={isStreaming}
                streamingContent={streamingContent}
                isLoading={isLoading && messages.length === 0}
                isProcessing={isProcessing}
                config={config}
              />
            </Card.Body>

            {/* Streaming Indicator */}
            {isStreaming && (
              <StreamingIndicator text={config.aiThinkingText} />
            )}

            {/* Message Input */}
            <Card.Footer className="bg-white border-0 p-3 llm-chat-footer">
              <MessageInput
                onSend={handleSendMessage}
                selectedFiles={selectedFiles}
                onFilesChange={handleFilesChange}
                disabled={isStreaming || isLoading}
                config={config}
              />
            </Card.Footer>
          </Card>
        </Col>
      </Row>
    </Container>
  );
};

export default LlmChat;
