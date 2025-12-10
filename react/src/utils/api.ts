/**
 * API Utility Functions for LLM Chat
 * ===================================
 * 
 * Provides API communication layer for the LLM Chat React component.
 * Uses the same endpoint strategy as the vanilla JS implementation:
 * - All requests go through the current page's controller
 * - Uses window.location for URL construction (security through SelfHelp's ACL)
 * - Supports both regular AJAX and streaming (SSE) requests
 * 
 * @module utils/api
 */

import type {
  Conversation,
  Message,
  GetConversationsResponse,
  GetConversationResponse,
  SendMessageResponse,
  NewConversationResponse,
  DeleteConversationResponse,
  PrepareStreamingResponse,
  StreamingEvent,
  SelectedFile,
  AdminConversationsResponse,
  AdminFiltersResponse,
  AdminMessagesResponse
} from '../types';

// ============================================================================
// API REQUEST HELPERS
// ============================================================================

/**
 * Build URL with action parameter
 * Uses current window.location to maintain security context
 * 
 * @param action - The action to append as query parameter
 * @param extraParams - Additional query parameters
 * @returns URL string with action parameter
 */
function buildUrl(action: string, extraParams: Record<string, string> = {}): string {
  const url = new URL(window.location.href);
  url.searchParams.set('action', action);
  
  Object.entries(extraParams).forEach(([key, value]) => {
    url.searchParams.set(key, value);
  });
  
  return url.toString();
}

/**
 * Make a GET request to the controller
 * 
 * @param action - The action to perform
 * @param params - Additional query parameters
 * @returns Promise resolving to JSON response
 */
async function apiGet<T>(action: string, params: Record<string, string> = {}): Promise<T> {
  const url = buildUrl(action, params);
  
  const response = await fetch(url, {
    method: 'GET',
    headers: {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    },
    credentials: 'same-origin'
  });
  
  if (!response.ok) {
    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
  }
  
  return response.json();
}

/**
 * Make a POST request to the controller
 * Supports both JSON and FormData payloads
 * 
 * @param formData - FormData object with request data
 * @returns Promise resolving to JSON response
 */
async function apiPost<T>(formData: FormData): Promise<T> {
  const response = await fetch(window.location.pathname, {
    method: 'POST',
    body: formData,
    headers: {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    },
    credentials: 'same-origin'
  });
  
  if (!response.ok) {
    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
  }
  
  return response.json();
}

// ============================================================================
// CONVERSATIONS API
// ============================================================================

/**
 * Conversations API namespace
 * Matches the controller actions: get_conversations, new_conversation, delete_conversation
 */
export const conversationsApi = {
  /**
   * Load all conversations for the current user
   * Calls: ?action=get_conversations
   * 
   * @returns Promise resolving to array of conversations
   */
  async getAll(): Promise<Conversation[]> {
    const response = await apiGet<GetConversationsResponse>('get_conversations');
    
    if (response.error) {
      throw new Error(response.error);
    }
    
    return response.conversations || [];
  },
  
  /**
   * Create a new conversation
   * Calls: POST action=new_conversation
   * 
   * @param title - Conversation title
   * @param model - LLM model to use
   * @returns Promise resolving to the new conversation ID
   */
  async create(title: string, model: string): Promise<string> {
    const formData = new FormData();
    formData.append('action', 'new_conversation');
    formData.append('title', title);
    formData.append('model', model);
    
    const response = await apiPost<NewConversationResponse>(formData);
    
    if (response.error) {
      throw new Error(response.error);
    }
    
    if (!response.conversation_id) {
      throw new Error('No conversation ID returned');
    }
    
    return response.conversation_id;
  },
  
  /**
   * Delete a conversation
   * Calls: POST action=delete_conversation
   * 
   * @param conversationId - ID of conversation to delete
   */
  async delete(conversationId: string): Promise<void> {
    const formData = new FormData();
    formData.append('action', 'delete_conversation');
    formData.append('conversation_id', conversationId);
    
    const response = await apiPost<DeleteConversationResponse>(formData);
    
    if (response.error) {
      throw new Error(response.error);
    }
  }
};

// ============================================================================
// MESSAGES API
// ============================================================================

/**
 * Messages API namespace
 * Handles message retrieval and sending
 */
export const messagesApi = {
  /**
   * Load messages for a conversation
   * Calls: ?action=get_conversation&conversation_id=XXX
   * 
   * @param conversationId - Conversation ID
   * @returns Promise resolving to conversation and messages
   */
  async getByConversation(conversationId: string): Promise<{
    conversation: Conversation;
    messages: Message[];
  }> {
    const response = await apiGet<GetConversationResponse>('get_conversation', {
      conversation_id: conversationId
    });
    
    if (response.error) {
      throw new Error(response.error);
    }
    
    if (!response.conversation || !response.messages) {
      throw new Error('Invalid response format');
    }
    
    return {
      conversation: response.conversation,
      messages: response.messages
    };
  },
  
  /**
   * Send a message (non-streaming)
   * Calls: POST action=send_message
   * 
   * @param message - Message content
   * @param conversationId - Conversation ID (optional, creates new if not provided)
   * @param model - LLM model to use
   * @param files - Array of files to attach
   * @returns Promise resolving to send result
   */
  async send(
    message: string,
    conversationId: string | null,
    model: string,
    files: SelectedFile[] = []
  ): Promise<SendMessageResponse> {
    const formData = new FormData();
    formData.append('action', 'send_message');
    formData.append('message', message);
    formData.append('model', model);

    if (conversationId) {
      formData.append('conversation_id', conversationId);
    }

    // Add files to FormData
    files.forEach((item) => {
      formData.append('uploaded_files[]', item.file, item.file.name);
    });

    return apiPost<SendMessageResponse>(formData);
  },
  
  /**
   * Prepare for streaming
   * Calls: POST action=send_message with prepare_streaming=1
   * Saves the user message and prepares for SSE connection
   * 
   * @param message - Message content
   * @param conversationId - Conversation ID (optional)
   * @param model - LLM model to use
   * @param files - Array of files to attach
   * @returns Promise resolving to preparation result with conversation ID
   */
  async prepareStreaming(
    message: string,
    conversationId: string | null,
    model: string,
    files: SelectedFile[] = []
  ): Promise<PrepareStreamingResponse> {
    const formData = new FormData();
    formData.append('action', 'send_message');
    formData.append('message', message);
    formData.append('model', model);
    formData.append('prepare_streaming', '1');

    if (conversationId) {
      formData.append('conversation_id', conversationId);
    }

    // Add files to FormData
    files.forEach((item) => {
      formData.append('uploaded_files[]', item.file, item.file.name);
    });
    
    // Check for test mode
    const isTestMode = window.location.search.includes('test=1');
    const url = window.location.pathname + (isTestMode ? '?test=1' : '');
    
    const response = await fetch(url, {
      method: 'POST',
      body: formData,
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin'
    });
    
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }
    
    return response.json();
  }
};

// ============================================================================
// ADMIN API
// ============================================================================

export const adminApi = {
  async getFilters() {
    return apiGet<AdminFiltersResponse>('admin_filters');
  },

  async getConversations(params: {
    page?: number;
    per_page?: number;
    user_id?: string;
    section_id?: string;
    q?: string;
    date_from?: string;
    date_to?: string;
  }) {
    const cleanParams: Record<string, string> = {};
    if (params.page) cleanParams.page = String(params.page);
    if (params.per_page) cleanParams.per_page = String(params.per_page);
    if (params.user_id) cleanParams.user_id = params.user_id;
    if (params.section_id) cleanParams.section_id = params.section_id;
    if (params.q) cleanParams.q = params.q;
    if (params.date_from) cleanParams.date_from = params.date_from;
    if (params.date_to) cleanParams.date_to = params.date_to;

    return apiGet<AdminConversationsResponse>('admin_conversations', cleanParams);
  },

  async getMessages(conversationId: string) {
    return apiGet<AdminMessagesResponse>('admin_messages', {
      conversation_id: conversationId
    });
  }
};

// ============================================================================
// STREAMING API
// ============================================================================

/**
 * Streaming API class for Server-Sent Events
 * Manages SSE connection for real-time message streaming
 */
export class StreamingApi {
  private eventSource: EventSource | null = null;
  private conversationId: string;
  
  /**
   * Create a new StreamingApi instance
   * 
   * @param conversationId - The conversation ID to stream
   */
  constructor(conversationId: string) {
    this.conversationId = conversationId;
  }
  
  /**
   * Build the streaming URL
   * Uses current page URL with streaming parameters
   */
  private buildStreamingUrl(): string {
    const url = new URL(window.location.href);
    url.searchParams.set('streaming', '1');
    url.searchParams.set('conversation', this.conversationId);
    return url.toString();
  }
  
  /**
   * Connect to the SSE stream
   * 
   * @param onMessage - Callback for incoming messages
   * @param onError - Callback for connection errors
   */
  connect(
    onMessage: (event: StreamingEvent) => void,
    onError?: (error: Event) => void
  ): void {
    // Close any existing connection
    this.disconnect();
    
    const url = this.buildStreamingUrl();
    this.eventSource = new EventSource(url);
    
    this.eventSource.onmessage = (event) => {
      try {
        const data: StreamingEvent = JSON.parse(event.data);
        onMessage(data);
        
        // Auto-close on done or error
        if (data.type === 'done' || data.type === 'error' || data.type === 'close') {
          this.disconnect();
        }
      } catch (e) {
        // Error parsing SSE data - silently ignore
      }
    };
    
    this.eventSource.onerror = (error) => {
      onError?.(error);
      this.disconnect();
    };
  }
  
  /**
   * Disconnect from the SSE stream
   */
  disconnect(): void {
    if (this.eventSource) {
      this.eventSource.close();
      this.eventSource = null;
    }
  }
  
  /**
   * Check if currently connected
   */
  isConnected(): boolean {
    return this.eventSource !== null && this.eventSource.readyState === EventSource.OPEN;
  }
}

// ============================================================================
// ERROR HANDLING
// ============================================================================

/**
 * Handle API errors and extract user-friendly message
 * 
 * @param error - The error object
 * @returns User-friendly error message
 */
export function handleApiError(error: unknown): string {
  if (error instanceof Error) {
    return error.message;
  }
  
  if (typeof error === 'string') {
    return error;
  }
  
  if (error && typeof error === 'object') {
    const errorObj = error as Record<string, unknown>;
    if (typeof errorObj.error === 'string') {
      return errorObj.error;
    }
    if (typeof errorObj.message === 'string') {
      return errorObj.message;
    }
  }
  
  return 'An unexpected error occurred';
}
