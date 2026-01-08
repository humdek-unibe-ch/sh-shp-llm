/**
 * LLM Chat Main Component
 * =======================
 * 
 * The main React component for the LLM Chat interface.
 * Provides the same functionality as the vanilla JS implementation:
 * - Conversation management (create, delete, select)
 * - Message sending with file attachments
 * - Responsive Bootstrap-based UI
 * 
 * @module components/LlmChat
 */

import React, { useEffect, useCallback, useState, useRef, useMemo } from 'react';
import { Container, Row, Col, Alert, Card } from 'react-bootstrap';
import { MessageList } from '../shared/MessageList';
import { MessageInput } from '../shared/MessageInput';
import { ConversationSidebar } from '../shared/ConversationSidebar';
import { LoadingIndicator } from '../shared/LoadingIndicator';
import { ProgressIndicator } from '../shared/ProgressIndicator';
import { useChatState } from '../../../hooks/useChatState';
import { createFormApi, createContinueApi, progressApi } from '../../../utils/api';
import type { LlmChatConfig, SelectedFile, Message, ProgressData } from '../../../types';
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
  
  // Create section-specific APIs
  const formApi = useMemo(
    () => createFormApi(config.sectionId),
    [config.sectionId]
  );
  
  const continueApi = useMemo(
    () => createContinueApi(config.sectionId),
    [config.sectionId]
  );

  // Local state for file attachments
  const [selectedFiles, setSelectedFiles] = useState<SelectedFile[]>([]);

  // Local state for tracking message processing
  const [isProcessing, setIsProcessing] = useState(false);

  // Local state for form submission (form mode)
  const [isFormSubmitting, setIsFormSubmitting] = useState(false);

  // Local state to track failed form submissions for retry
  const [lastFailedFormSubmission, setLastFailedFormSubmission] = useState<{
    values: Record<string, string | string[]>;
    readableText: string;
    conversationId: string | null;
    timestamp: number;
  } | null>(null);

  // Local state for progress tracking
  const [progress, setProgress] = useState<ProgressData | null>(null);
  const [isProgressUpdating, setIsProgressUpdating] = useState(false);

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
    isAutoStarting,
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

  /**
   * Load progress data for a conversation
   */
  const loadProgress = useCallback(async (conversationId: string) => {
    if (!config.enableProgressTracking || !config.sectionId) {
      return;
    }
    
    setIsProgressUpdating(true);
    try {
      const response = await progressApi.get(conversationId, config.sectionId);
      if (response.progress) {
        setProgress(response.progress);
      }
    } catch (err) {
      console.error('Failed to load progress:', err);
      // Don't set error - progress loading failure shouldn't block the chat
    } finally {
      setIsProgressUpdating(false);
    }
  }, [config.enableProgressTracking, config.sectionId]);

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
      // Load progress if enabled
      if (config.enableProgressTracking) {
        await loadProgress(config.currentConversationId);
      }
    } else {
      // Try to load conversations to get the last one
      try {
        await loadConversations();
        // The hook will auto-select the first conversation
      } catch (err) {
        // No conversations - show empty state
      }
    }
  }, [config.currentConversationId, loadConversationMessages, loadConversations, config.enableProgressTracking, loadProgress]);
  
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
    if (isProcessing) {
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
      setIsProcessing(true);
      try {
        const result = await sendMessage(message, files);
        
        // Handle danger detection blocked response
        // Show safety message as an assistant response (not an error)
        if (result.blocked && result.type === 'danger_detected') {
          // The safety message should be shown as an assistant response
          // This is handled by adding it to the messages list
          // Note: The user's message was already added above, but it wasn't saved to DB
          // We don't remove it from UI - it shows what triggered the safety response
          return;
        }
        
        if (result.error) {
          setError(result.error);
          return;
        }

        // Update progress if included in response
        if (result.progress && config.enableProgressTracking) {
          setProgress(result.progress);
        }
      } finally {
        setIsProcessing(false);
      }
    } catch (error) {
      // If sending fails, remove the user message from UI since it wasn't actually sent
      console.error('Failed to send message:', error);
      // The error will be shown via the error state
    }
  }, [
    isProcessing,
    currentConversation,
    config.emptyMessageError,
    config.enableProgressTracking,
    addUserMessage,
    sendMessage,
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
   * Handle Continue button click (form mode only)
   * Sends a continue request to the backend to get the next LLM response
   */
  const handleContinue = useCallback(async () => {
    // Prevent concurrent requests
    if (isProcessing) {
      return;
    }

    // Get current conversation ID
    const conversationId = currentConversation?.id || null;
    if (!conversationId) {
      setError('No active conversation');
      return;
    }

    // Force scroll to bottom
    forceScrollToBottom();

    try {
      setIsProcessing(true);
      setIsFormSubmitting(true);
      try {
        const result = await continueApi.continue(conversationId, config.configuredModel);
        
        if (result.error) {
          throw new Error(result.error);
        }
        
        // Update progress if included in response
        if (result.progress && config.enableProgressTracking) {
          setProgress(result.progress);
        }
        
        // Refresh messages
        await loadConversationMessages(conversationId);
      } finally {
        setIsProcessing(false);
        setIsFormSubmitting(false);
      }
    } catch (error) {
      console.error('Failed to continue conversation:', error);
      setError(error instanceof Error ? error.message : 'Failed to continue conversation');
      setIsProcessing(false);
      setIsFormSubmitting(false);
    }
  }, [
    isProcessing,
    currentConversation,
    config.configuredModel,
    config.enableProgressTracking,
    continueApi,
    loadConversationMessages,
    setError,
    forceScrollToBottom
  ]);

  /**
   * Handle form submission (form mode only)
   */
  const handleFormSubmit = useCallback(async (values: Record<string, string | string[]>, readableText: string) => {
    // Prevent concurrent submissions
    if (isFormSubmitting) {
      return;
    }

    // Add user message to UI immediately with readable text
    addUserMessage(readableText, 0);

    // Force scroll to bottom
    forceScrollToBottom();

    // Get current conversation ID
    const conversationId = currentConversation?.id || null;

    // Clear any previous failed submission
    setLastFailedFormSubmission(null);

    setIsFormSubmitting(true);
    setIsProcessing(true);

    try {
      const result = await formApi.submit(
        values,
        readableText,
        conversationId,
        config.configuredModel
      );

      if (result.error) {
        throw new Error(result.error);
      }

      // Update progress if included in response
      if (result.progress && config.enableProgressTracking) {
        setProgress(result.progress);
      }

      // Refresh messages
      if (result.conversation_id) {
        await loadConversationMessages(result.conversation_id);
        if (config.enableConversationsList) {
          await loadConversations();
        }
      }
    } catch (error) {
      console.error('Failed to submit form:', error);
      setError(error instanceof Error ? error.message : 'Failed to submit form');

      // Track the failed submission for retry capability
      setLastFailedFormSubmission({
        values,
        readableText,
        conversationId,
        timestamp: Date.now()
      });
    } finally {
      setIsFormSubmitting(false);
      setIsProcessing(false);
    }
  }, [
    isFormSubmitting,
    currentConversation,
    config.configuredModel,
    config.enableConversationsList,
    config.enableProgressTracking,
    addUserMessage,
    loadConversationMessages,
    loadConversations,
    setError,
    forceScrollToBottom
  ]);

  /**
   * Handle retrying a failed form submission
   */
  const handleRetryFormSubmission = useCallback(async () => {
    if (!lastFailedFormSubmission) return;

    // Clear the failed submission state
    setLastFailedFormSubmission(null);

    // Retry the submission with the same values
    await handleFormSubmit(
      lastFailedFormSubmission.values,
      lastFailedFormSubmission.readableText
    );
  }, [lastFailedFormSubmission, handleFormSubmit]);

  /**
   * Handle file selection
   */
  const handleFilesChange = useCallback((files: SelectedFile[]) => {
    setSelectedFiles(files);
  }, []);

  /**
   * Handle suggestion button click (structured response mode)
   * Sends the suggestion text as a regular message
   */
  const handleSuggestionClick = useCallback((suggestion: string) => {
    // Guard against undefined/null suggestions
    if (!suggestion || typeof suggestion !== 'string') {
      console.error('Invalid suggestion:', suggestion);
      return;
    }
    // Use the same flow as sending a regular message
    handleSendMessage(suggestion, []);
  }, [handleSendMessage]);
  
  /**
   * Load progress when conversation changes
   */
  useEffect(() => {
    if (config.enableProgressTracking && currentConversation?.id) {
      loadProgress(currentConversation.id);
    } else {
      setProgress(null);
    }
  }, [currentConversation?.id, config.enableProgressTracking, loadProgress]);

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

  /**
   * Auto-scroll to bottom when component mounts or messages are first loaded
   */
  useEffect(() => {
    if (messages.length > 0 && messagesContainerRef.current) {
      // Small delay to ensure DOM is fully rendered
      const timer = setTimeout(() => {
        forceScrollToBottom();
      }, 100);
      return () => clearTimeout(timer);
    }
  }, [messages.length, forceScrollToBottom]); // Re-run when messages are loaded

  /**
   * Force scroll to bottom when requested (e.g., when floating panel opens)
   */
  useEffect(() => {
    if (config.forceScrollToBottom && messages.length > 0) {
      // Longer delay to ensure the panel is fully visible and rendered
      const timer = setTimeout(() => {
        forceScrollToBottom();
      }, 300);
      return () => clearTimeout(timer);
    }
  }, [config.forceScrollToBottom, forceScrollToBottom, messagesContainerRef, messages.length]);
  
  // Determine the active model for this conversation
  // - Use conversation's model if it exists
  // - Otherwise use the configured model (for new conversations)
  const activeModel = useMemo(() => {
    if (currentConversation?.model) {
      return currentConversation.model;
    }
    return config.configuredModel;
  }, [currentConversation?.model, config.configuredModel]);

  // Determine if model is mismatched
  const isModelMismatch = currentConversation?.model &&
    currentConversation.model !== config.configuredModel;

  // Determine if conversation is blocked
  const isConversationBlocked = currentConversation?.blocked === true || currentConversation?.blocked === 1;

  return (
    <Container fluid className={`llm-chat-container llm-chat-shell ${config.isFloatingMode ? 'p-0' : 'p-3'}`}>
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

            {/* Progress Indicator */}
            {config.enableProgressTracking && progress && (
              <div className={`chat-header-progress ${isProgressUpdating ? 'updating' : ''} ${config.isFloatingMode ? 'chat-header-progress-compact' : ''}`}>
                <ProgressIndicator
                  progress={progress}
                  barLabel={config.progressBarLabel}
                  completeMessage={config.progressCompleteMessage}
                  showTopics={config.progressShowTopics}
                  compact={config.isFloatingMode}
                />
              </div>
            )}

            {/* Messages Container */}
            <Card.Body
              className="p-0 llm-chat-body d-flex flex-column"
            >
              <div
                ref={messagesContainerRef}
                id="messages-container"
                onScroll={handleScroll}
                className="flex-grow-1 overflow-auto p-3"
              >
                <MessageList
                  messages={messages}
                  isLoading={isLoading && messages.length === 0}
                  isProcessing={isProcessing}
                  config={config}
                  onFormSubmit={handleFormSubmit}
                  isFormSubmitting={isFormSubmitting}
                  onContinue={config.enableFormMode ? handleContinue : undefined}
                  onSuggestionClick={handleSuggestionClick}
                  lastFailedFormSubmission={lastFailedFormSubmission}
                  onRetryFormSubmission={handleRetryFormSubmission}
                />
              </div>
            </Card.Body>

            {/* Processing Indicator */}
            {isProcessing && (
              <LoadingIndicator text={config.aiThinkingText} />
            )}

            {/* Auto-start Indicator */}
            {isAutoStarting && (
              <LoadingIndicator text={config.loadingText || "Starting conversation..."} />
            )}

            {/* Message Input */}
            <Card.Footer className="bg-white border-0 p-3 llm-chat-footer">
              {isConversationBlocked ? (
                <Alert variant="warning" className="mb-0">
                  <i className="fas fa-ban mr-2"></i>
                  <span dangerouslySetInnerHTML={{
                    __html: config.conversationBlockedMessage || 'This conversation has been blocked. You cannot send any more messages.'
                  }} />
                </Alert>
              ) : (
                <MessageInput
                  onSend={handleSendMessage}
                  selectedFiles={selectedFiles}
                  onFilesChange={handleFilesChange}
                  disabled={isProcessing || isLoading || isAutoStarting}
                  config={config}
                />
              )}
            </Card.Footer>
          </Card>
        </Col>
      </Row>
    </Container>
  );
};

export default LlmChat;
