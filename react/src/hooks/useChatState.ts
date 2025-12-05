/**
 * Chat State Management Hook
 * ==========================
 * 
 * Custom React hook for managing LLM chat state.
 * Handles conversations, messages, and API communication.
 * 
 * @module hooks/useChatState
 */

import { useState, useCallback, useRef } from 'react';
import type { Conversation, Message, LlmChatConfig, SelectedFile } from '../types';
import { conversationsApi, messagesApi, handleApiError } from '../utils/api';

/**
 * Return type for useChatState hook
 */
export interface UseChatStateReturn {
  /** List of user's conversations */
  conversations: Conversation[];
  /** Currently selected conversation */
  currentConversation: Conversation | null;
  /** Messages in current conversation */
  messages: Message[];
  /** Loading state */
  isLoading: boolean;
  /** Error message */
  error: string | null;
  
  // Actions
  /** Load all conversations */
  loadConversations: () => Promise<void>;
  /** Load messages for a conversation */
  loadConversationMessages: (conversationId: string) => Promise<void>;
  /** Create a new conversation */
  createConversation: (title?: string) => Promise<string>;
  /** Delete a conversation */
  deleteConversation: (conversationId: string) => Promise<void>;
  /** Select a conversation */
  selectConversation: (conversation: Conversation) => void;
  /** Send a message */
  sendMessage: (message: string, files: SelectedFile[]) => Promise<string | null>;
  /** Add user message to UI */
  addUserMessage: (content: string, fileCount: number) => void;
  /** Clear current conversation */
  clearCurrentConversation: () => void;
  /** Clear error */
  clearError: () => void;
  /** Set error */
  setError: (error: string) => void;
  /** Get the active model (conversation model or configured model) */
  getActiveModel: () => string;
}

/**
 * Custom hook for managing chat state
 * 
 * @param config - LLM Chat configuration
 * @returns Chat state and actions
 */
export function useChatState(config: LlmChatConfig): UseChatStateReturn {
  // State
  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [currentConversation, setCurrentConversation] = useState<Conversation | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  
  // Refs to track current conversation ID for async operations
  const currentConversationIdRef = useRef<string | null>(config.currentConversationId || null);
  
  /**
   * Load all conversations for the current user
   */
  const loadConversations = useCallback(async () => {
    try {
      setIsLoading(true);
      setError(null);
      const convs = await conversationsApi.getAll();
      setConversations(convs);
      
      // If we have a current conversation ID (from URL or config), load it
      if (currentConversationIdRef.current) {
        const currentIdStr = String(currentConversationIdRef.current);
        // Check if current conversation still exists (compare as strings)
        const exists = convs.some(c => String(c.id) === currentIdStr);
        if (exists) {
          const conv = convs.find(c => String(c.id) === currentIdStr)!;
          setCurrentConversation(conv);
          // Load messages for the current conversation
          await loadConversationMessagesInternal(String(conv.id));
        } else if (convs.length > 0) {
          // Current conversation was deleted, select first
          const firstConv = convs[0];
          setCurrentConversation(firstConv);
          currentConversationIdRef.current = String(firstConv.id);
          await loadConversationMessagesInternal(String(firstConv.id));
          updateUrl(String(firstConv.id));
        }
      } else if (convs.length > 0) {
        // No current conversation set, select the first one
        const firstConv = convs[0];
        setCurrentConversation(firstConv);
        currentConversationIdRef.current = String(firstConv.id);
        
        // Load messages for the first conversation
        await loadConversationMessagesInternal(String(firstConv.id));
        
        // Update URL
        updateUrl(String(firstConv.id));
      }
    } catch (err) {
      console.error('Failed to load conversations:', err);
      setError(handleApiError(err));
    } finally {
      setIsLoading(false);
    }
  }, []);
  
  /**
   * Internal function to load messages (without setting loading state)
   */
  const loadConversationMessagesInternal = async (conversationId: string) => {
    const { conversation, messages: msgs } = await messagesApi.getByConversation(conversationId);
    setCurrentConversation(conversation);
    setMessages(msgs);
    return { conversation, messages: msgs };
  };
  
  /**
   * Load messages for a specific conversation
   */
  const loadConversationMessages = useCallback(async (conversationId: string) => {
    try {
      setIsLoading(true);
      setError(null);
      currentConversationIdRef.current = conversationId;
      await loadConversationMessagesInternal(conversationId);
    } catch (err) {
      console.error('Failed to load messages:', err);
      setError(handleApiError(err));
    } finally {
      setIsLoading(false);
    }
  }, []);
  
  /**
   * Create a new conversation
   */
  const createConversation = useCallback(async (title?: string): Promise<string> => {
    try {
      setIsLoading(true);
      setError(null);

      // Generate title if not provided
      const finalTitle = title?.trim() || generateDefaultTitle();

      const conversationId = await conversationsApi.create(finalTitle, config.configuredModel);

      // Convert to string for consistent comparison
      const conversationIdStr = String(conversationId);

      // Update current conversation ref FIRST
      currentConversationIdRef.current = conversationIdStr;

      // Update URL
      updateUrl(conversationIdStr);

      // Use loadConversations to properly load and select the new conversation
      // This ensures the conversation list is updated and the new conversation is selected
      await loadConversations();

      // Clear messages for the new conversation
      setMessages([]);

      return conversationIdStr;
    } catch (err) {
      console.error('Failed to create conversation:', err);
      setError(handleApiError(err));
      throw err;
    } finally {
      setIsLoading(false);
    }
  }, [config.configuredModel, loadConversations]);
  
  /**
   * Delete a conversation
   */
  const deleteConversation = useCallback(async (conversationId: string) => {
    try {
      setIsLoading(true);
      setError(null);
      
      await conversationsApi.delete(conversationId);
      
      // If deleting current conversation, clear it
      if (currentConversationIdRef.current === conversationId) {
        setCurrentConversation(null);
        setMessages([]);
        currentConversationIdRef.current = null;
      }
      
      // Reload conversations
      await loadConversations();
    } catch (err) {
      console.error('Failed to delete conversation:', err);
      setError(handleApiError(err));
      throw err;
    } finally {
      setIsLoading(false);
    }
  }, [loadConversations]);
  
  /**
   * Select a conversation
   */
  const selectConversation = useCallback((conversation: Conversation) => {
    // Convert to string for consistent comparison
    const conversationIdStr = String(conversation.id);
    
    setCurrentConversation(conversation);
    currentConversationIdRef.current = conversationIdStr;
    
    // Update UI state
    setMessages([]);
    
    // Update URL
    updateUrl(conversationIdStr);
    
    // Highlight in conversations list
    // (This is done via React state, no jQuery needed)
    
    // Load messages
    loadConversationMessages(conversationIdStr);
  }, [loadConversationMessages]);
  
  /**
   * Get the active model for API calls
   * Uses conversation model if exists, otherwise configured model
   */
  const getActiveModel = useCallback((): string => {
    if (currentConversation?.model) {
      return currentConversation.model;
    }
    return config.configuredModel;
  }, [currentConversation?.model, config.configuredModel]);

  /**
   * Send a message (non-streaming mode)
   */
  const sendMessage = useCallback(async (
    message: string,
    files: SelectedFile[]
  ): Promise<string | null> => {
    try {
      setError(null);
      
      // Use the active model (conversation model or configured model)
      const activeModel = getActiveModel();
      
      // Get the current conversation ID
      const conversationId = currentConversationIdRef.current;
      
      const response = await messagesApi.send(
        message,
        conversationId,
        activeModel,
        files
      );
      
      if (response.error) {
        setError(response.error);
        return null;
      }
      
      // Update conversation ID if new or changed
      if (response.conversation_id) {
        const responseIdStr = String(response.conversation_id);
        const isNewConversation = !conversationId || response.is_new_conversation;
        
        if (isNewConversation) {
          currentConversationIdRef.current = responseIdStr;
          updateUrl(responseIdStr);
        }
        
        // Add assistant message to UI
        if (response.message) {
          const assistantMessage: Message = {
            id: 'assistant-' + Date.now(),
            role: 'assistant',
            content: response.message,
            timestamp: new Date().toISOString()
          };
          setMessages(prev => [...prev, assistantMessage]);
        }
        
        // Reload conversations list if it was a new conversation
        if (isNewConversation && config.enableConversationsList) {
          // Use loadConversations to properly load and select the new conversation
          await loadConversations();
        }
        
        return responseIdStr;
      }
      
      return null;
    } catch (err) {
      console.error('Failed to send message:', err);
      setError(handleApiError(err));
      return null;
    }
  }, [config.enableConversationsList, getActiveModel]);
  
  /**
   * Add user message to UI immediately
   */
  const addUserMessage = useCallback((content: string, fileCount: number) => {
    const userMessage: Message = {
      id: 'user-' + Date.now(),
      role: 'user',
      content: content,
      timestamp: new Date().toISOString(),
      attachments: fileCount > 0 ? JSON.stringify(Array(fileCount).fill({})) : undefined
    };
    
    setMessages(prev => [...prev, userMessage]);
  }, []);
  
  /**
   * Clear current conversation
   */
  const clearCurrentConversation = useCallback(() => {
    setCurrentConversation(null);
    setMessages([]);
    currentConversationIdRef.current = null;
  }, []);
  
  /**
   * Clear error
   */
  const clearError = useCallback(() => {
    setError(null);
  }, []);
  
  /**
   * Get current conversation ID ref
   */
  const getCurrentConversationId = (): string | null => {
    return currentConversationIdRef.current;
  };
  
  // Expose ref for streaming hook
  (useChatState as any).getCurrentConversationId = getCurrentConversationId;
  
  return {
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
    clearCurrentConversation,
    clearError,
    setError,
    getActiveModel
  };
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Generate default conversation title
 */
function generateDefaultTitle(): string {
  const now = new Date();
  return 'Conversation ' + now.toLocaleDateString() + ' ' + 
    now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

/**
 * Update browser URL with conversation ID
 * Maintains bookmarkable links
 */
function updateUrl(conversationId: string): void {
  const url = new URL(window.location.href);
  url.searchParams.set('conversation', conversationId);
  window.history.pushState({}, '', url.toString());
}
