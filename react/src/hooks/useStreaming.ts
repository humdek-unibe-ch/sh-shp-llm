/**
 * Streaming Hook for Server-Sent Events
 * ======================================
 * 
 * Custom React hook for managing SSE streaming connections.
 * Handles real-time message streaming from the LLM API.
 * 
 * @module hooks/useStreaming
 */

import { useState, useCallback, useRef, useEffect } from 'react';
import { StreamingApi, messagesApi, handleApiError } from '../utils/api';
import type { StreamingEvent, LlmChatConfig, SelectedFile } from '../types';

/**
 * Options for useStreaming hook
 */
export interface UseStreamingOptions {
  /** LLM Chat configuration */
  config: LlmChatConfig;
  /** Callback when chunk is received */
  onChunk?: (content: string) => void;
  /** Callback when streaming is done */
  onDone?: (tokensUsed: number) => void;
  /** Callback when error occurs */
  onError?: (error: string) => void;
  /** Callback when streaming starts */
  onStart?: () => void;
  /** Callback to refresh messages after streaming (for React-only refresh) */
  onRefreshMessages?: (conversationId: string) => Promise<void>;
  /** Callback to get the active model for the current conversation */
  getActiveModel?: () => string;
  /** Callback when a new conversation is created */
  onNewConversation?: (conversationId: string, model: string) => void;
}

/**
 * Return type for useStreaming hook
 */
export interface UseStreamingReturn {
  /** Whether currently streaming */
  isStreaming: boolean;
  /** Accumulated streaming content */
  streamingContent: string;
  /** Start streaming for a message */
  sendStreamingMessage: (
    message: string,
    conversationId: string | null,
    files: SelectedFile[]
  ) => Promise<string | null>;
  /** Stop streaming */
  stopStreaming: () => void;
  /** Clear streaming content */
  clearStreamingContent: () => void;
}

/**
 * Perform page reload
 * For React-based apps, we simply use window.location.reload()
 * which will reinitialize the React app properly
 */
async function smoothPageReload(): Promise<void> {
  // Simple page reload - React will reinitialize from the new page
  window.location.reload();
}

/**
 * Custom hook for managing streaming connections
 * 
 * @param options - Streaming options
 * @returns Streaming state and controls
 */
export function useStreaming(options: UseStreamingOptions): UseStreamingReturn {
  const { config, onChunk, onDone, onError, onStart, onRefreshMessages, getActiveModel, onNewConversation } = options;
  
  // State
  const [isStreaming, setIsStreaming] = useState(false);
  const [streamingContent, setStreamingContent] = useState('');
  
  // Refs for cleanup and tracking
  const streamingApiRef = useRef<StreamingApi | null>(null);
  const renderTimeoutRef = useRef<number | null>(null);
  
  /**
   * Stop any active streaming connection
   */
  const stopStreaming = useCallback(() => {
    if (streamingApiRef.current) {
      streamingApiRef.current.disconnect();
      streamingApiRef.current = null;
    }
    
    if (renderTimeoutRef.current) {
      cancelAnimationFrame(renderTimeoutRef.current);
      renderTimeoutRef.current = null;
    }
    
    setIsStreaming(false);
  }, []);
  
  /**
   * Clear streaming content
   */
  const clearStreamingContent = useCallback(() => {
    setStreamingContent('');
  }, []);
  
  /**
   * Send a message with streaming response
   * 
   * @param message - Message content
   * @param conversationId - Conversation ID (null for new conversation)
   * @param files - Files to attach
   * @returns Promise resolving to conversation ID or null on error
   */
  const sendStreamingMessage = useCallback(async (
    message: string,
    conversationId: string | null,
    files: SelectedFile[],
    retryCount: number = 0
  ): Promise<string | null> => {
    const MAX_RETRIES = 2;

    try {
      // Stop any existing streaming
      stopStreaming();

      // Clear previous content
      setStreamingContent('');

      // Determine the model to use (conversation model or configured model)
      const modelToUse = getActiveModel ? getActiveModel() : config.configuredModel;

      // Step 1: Prepare streaming (save user message to database)
      const prepResponse = await messagesApi.prepareStreaming(
        message,
        conversationId,
        modelToUse,
        files
      );

      if (prepResponse.error) {
        onError?.(prepResponse.error);
        return null;
      }

      if (!prepResponse.conversation_id) {
        onError?.('No conversation ID returned');
        return null;
      }

      const streamConversationId = prepResponse.conversation_id;

      // Notify if a new conversation was created
      if (prepResponse.is_new_conversation && onNewConversation) {
        onNewConversation(streamConversationId, modelToUse);
      }

      // Handle user message data if returned from preparation
      if (prepResponse.user_message) {
        // Add the user message to the chat state immediately
        // This will be handled by the parent component that uses this hook
        // The user message should already be added to the UI by addUserMessage() call
        // but we can use this data for consistency checks if needed
      }
      
      // Step 2: Start SSE streaming
      setIsStreaming(true);
      onStart?.();
      
      streamingApiRef.current = new StreamingApi(streamConversationId);

      let accumulatedContent = '';

      // Add timeout for streaming connection
      const streamingTimeout = setTimeout(() => {
        if (streamingApiRef.current && streamingApiRef.current.isConnected()) {
          console.warn('Streaming timeout - disconnecting');
          streamingApiRef.current.disconnect();
          setIsStreaming(false);
          onError?.('Request timed out. Please try again.');
        }
      }, 60000); // 60 second timeout

      streamingApiRef.current.connect(
        (event: StreamingEvent) => {
          switch (event.type) {
            case 'connected':
              // Connection established
              break;

            case 'start':
              // Streaming is about to begin
              break;

            case 'chunk':
              if (event.content) {
                accumulatedContent += event.content;

                // Debounced render for smooth updates
                if (renderTimeoutRef.current) {
                  cancelAnimationFrame(renderTimeoutRef.current);
                }

                renderTimeoutRef.current = requestAnimationFrame(() => {
                  setStreamingContent(accumulatedContent);
                  onChunk?.(event.content!);
                });
              }
              break;

            case 'done':
              // Clear timeout on successful completion
              clearTimeout(streamingTimeout);
              // Streaming completed - industry standard: single atomic save
              setIsStreaming(false);
              onDone?.(event.tokens_used || 0);

              // Clear streaming content immediately (server has saved complete message)
              setStreamingContent('');

              // Update URL with conversation ID (only if conversations list is enabled)
              if (config.enableConversationsList) {
                const url = new URL(window.location.href);
                url.searchParams.set('conversation', streamConversationId);
                window.history.pushState({}, '', url.toString());
              }

              // Choose refresh strategy based on config
              if (config.enableFullPageReload) {
                // Full page reload requested
                setTimeout(() => {
                  smoothPageReload();
                }, 300);
              } else if (onRefreshMessages) {
                // React-only refresh - reload messages via API
                // Use a short delay to ensure server has committed the message
                setTimeout(async () => {
                  try {
                    await onRefreshMessages(streamConversationId);
                  } catch (err) {
                    console.error('Failed to refresh messages:', err);
                    // Fall back to page reload
                    smoothPageReload();
                  }
                }, 200); // Reduced delay since no partial saves
              } else {
                // Default: page reload
                setTimeout(() => {
                  smoothPageReload();
                }, 300);
              }
              break;

            case 'error':
              clearTimeout(streamingTimeout);
              setIsStreaming(false);
              // Provide more user-friendly error messages
              let errorMessage = event.message || 'Streaming error occurred';
              let shouldRetry = false;

              if (errorMessage.includes('HTTP 403')) {
                errorMessage = 'Access denied. Please check your API configuration or contact support.';
              } else if (errorMessage.includes('HTTP 429')) {
                errorMessage = 'Too many requests. Please wait a moment and try again.';
                shouldRetry = retryCount < MAX_RETRIES;
              } else if (errorMessage.includes('HTTP 5')) {
                errorMessage = 'Server error. Please try again later.';
                shouldRetry = retryCount < MAX_RETRIES;
              } else if (errorMessage.includes('HTTP 4')) {
                errorMessage = 'Request error. Please check your input and try again.';
              }

              // Retry for certain recoverable errors
              if (shouldRetry) {
                console.log(`Retrying streaming due to error (attempt ${retryCount + 1}/${MAX_RETRIES + 1}): ${errorMessage}`);
                setTimeout(() => {
                  sendStreamingMessage(message, conversationId, files, retryCount + 1);
                }, 3000 * (retryCount + 1)); // Longer delay for server errors
                return;
              }

              // For critical errors, offer fallback to non-streaming mode
              if (retryCount >= MAX_RETRIES && !errorMessage.includes('Access denied') && !errorMessage.includes('Authentication failed')) {
                errorMessage += ' You can try sending your message again.';
              }

              // Show error alert to user
              onError?.(errorMessage);

              // Refresh messages to show the error message that was saved to database
              if (onRefreshMessages) {
                setTimeout(async () => {
                  try {
                    await onRefreshMessages(streamConversationId);
                  } catch (err) {
                    console.error('Failed to refresh messages after error:', err);
                    // Fall back to page reload if refresh fails
                    smoothPageReload();
                  }
                }, 500); // Give server time to save the error message
              } else {
                // Fallback to page reload
                setTimeout(() => {
                  smoothPageReload();
                }, 500);
              }
              break;

            case 'close':
              clearTimeout(streamingTimeout);
              setIsStreaming(false);
              break;
          }
        },
        (error) => {
          console.error('SSE connection error:', error);
          setIsStreaming(false);

          // For connection errors, try to retry if we haven't exceeded max retries
          const shouldRetry = retryCount < MAX_RETRIES;
          if (shouldRetry) {
            console.log(`Retrying streaming (attempt ${retryCount + 1}/${MAX_RETRIES + 1})`);
            setTimeout(() => {
              sendStreamingMessage(message, conversationId, files, retryCount + 1);
            }, 2000 * (retryCount + 1)); // Exponential backoff
            return;
          }

          onError?.('Connection lost. Please check your internet connection and try again.');
        }
      );
      
      return streamConversationId;
    } catch (err) {
      console.error('Failed to start streaming:', err);
      setIsStreaming(false);
      onError?.(handleApiError(err));
      return null;
    }
  }, [config.configuredModel, config.enableFullPageReload, onChunk, onDone, onError, onStart, onRefreshMessages, stopStreaming, getActiveModel]);
  
  // Cleanup on unmount
  useEffect(() => {
    return () => {
      stopStreaming();
    };
  }, [stopStreaming]);
  
  return {
    isStreaming,
    streamingContent,
    sendStreamingMessage,
    stopStreaming,
    clearStreamingContent
  };
}
