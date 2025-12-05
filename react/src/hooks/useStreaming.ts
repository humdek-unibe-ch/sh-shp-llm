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
  const { config, onChunk, onDone, onError, onStart, onRefreshMessages, getActiveModel } = options;
  
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
    files: SelectedFile[]
  ): Promise<string | null> => {
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
              // Streaming completed
              setIsStreaming(false);
              onDone?.(event.tokens_used || 0);
              
              // Update URL with conversation ID
              const url = new URL(window.location.href);
              url.searchParams.set('conversation', streamConversationId);
              window.history.pushState({}, '', url.toString());
              
              // Choose refresh strategy based on config
              if (config.enableFullPageReload) {
                // Full page reload requested
                setTimeout(() => {
                  smoothPageReload();
                }, 300);
              } else if (onRefreshMessages) {
                // React-only refresh - reload messages via API
                // Use a short delay to let the server save the message
                setTimeout(async () => {
                  try {
                    await onRefreshMessages(streamConversationId);
                    setStreamingContent(''); // Clear streaming content after refresh
                  } catch (err) {
                    console.error('Failed to refresh messages:', err);
                    // Fall back to page reload
                    smoothPageReload();
                  }
                }, 500);
              } else {
                // Default: page reload
                setTimeout(() => {
                  smoothPageReload();
                }, 300);
              }
              break;
              
            case 'error':
              setIsStreaming(false);
              onError?.(event.message || 'Streaming error occurred');
              break;
              
            case 'close':
              setIsStreaming(false);
              break;
          }
        },
        (error) => {
          console.error('SSE connection error:', error);
          setIsStreaming(false);
          onError?.('Streaming connection lost');
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
