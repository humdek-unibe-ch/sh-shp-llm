/**
 * API Utility Functions for LLM Chat
 * ===================================
 * 
 * Provides API communication layer for the LLM Chat React component.
 * Uses the same endpoint strategy as the vanilla JS implementation:
 * - All requests go through the current page's controller
 * - Uses window.location for URL construction (security through SelfHelp's ACL)
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
  SelectedFile,
  AdminConversationsResponse,
  AdminFiltersResponse,
  AdminMessagesResponse,
  LlmChatConfig,
  FormSubmissionResponse,
  GetProgressResponse
} from '../types';

// ============================================================================
// CONFIG API RESPONSE TYPES
// ============================================================================

interface GetConfigResponse {
  config?: LlmChatConfig;
  error?: string;
}

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

  let data;
  try {
    data = await response.json();
  } catch (e) {
    // If we can't parse JSON, fall back to status-based error
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }
    throw e;
  }

  if (!response.ok) {
    // If the response contains an error message, use it
    if (data && typeof data === 'object' && 'error' in data) {
      throw new Error(data.error);
    }
    // Otherwise use the HTTP status
    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
  }

  return data;
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

  let data;
  try {
    data = await response.json();
  } catch (e) {
    // If we can't parse JSON, fall back to status-based error
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }
    throw e;
  }

  if (!response.ok) {
    // If the response contains an error message, use it
    if (data && typeof data === 'object' && 'error' in data) {
      throw new Error(data.error);
    }
    // Otherwise use the HTTP status
    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
  }

  return data;
}

// ============================================================================
// CONFIG API
// ============================================================================

/**
 * Config API namespace
 * Fetches chat configuration from the server
 */
export const configApi = {
  /**
   * Load chat configuration for the current user and section
   * Calls: ?action=get_config&section_id={sectionId}
   *
   * @param sectionId - The section ID to get configuration for
   * @returns Promise resolving to LlmChatConfig
   */
  async get(sectionId?: string | number): Promise<LlmChatConfig> {
    const params = sectionId ? { section_id: sectionId.toString() } : undefined;
    const response = await apiGet<GetConfigResponse>('get_config', params);

    if (response.error) {
      throw new Error(response.error);
    }

    if (!response.config) {
      throw new Error('No configuration returned');
    }

    return response.config;
  }
};

// ============================================================================
// CONVERSATIONS API
// ============================================================================

/**
 * Create conversations API with section ID support
 * Each chat instance should use its own API instance with its section ID
 * 
 * @param sectionId - The section ID for this chat instance
 */
export function createConversationsApi(sectionId?: number) {
  return {
    /**
     * Load all conversations for the current user
     * Calls: ?action=get_conversations&section_id=XXX
     * 
     * @returns Promise resolving to array of conversations
     */
    async getAll(): Promise<Conversation[]> {
      const params: Record<string, string> = {};
      if (sectionId !== undefined) {
        params.section_id = String(sectionId);
      }
      const response = await apiGet<GetConversationsResponse>('get_conversations', params);
      
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
      if (sectionId !== undefined) {
        formData.append('section_id', String(sectionId));
      }
      
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
      if (sectionId !== undefined) {
        formData.append('section_id', String(sectionId));
      }
      
      const response = await apiPost<DeleteConversationResponse>(formData);
      
      if (response.error) {
        throw new Error(response.error);
      }
    }
  };
}

/**
 * Conversations API namespace (backward compatible, no section isolation)
 * @deprecated Use createConversationsApi(sectionId) for section-isolated instances
 */
export const conversationsApi = createConversationsApi();

// ============================================================================
// MESSAGES API
// ============================================================================

/**
 * Create messages API with section ID support
 * Each chat instance should use its own API instance with its section ID
 * 
 * @param sectionId - The section ID for this chat instance
 */
export function createMessagesApi(sectionId?: number) {
  return {
    /**
     * Load messages for a conversation
     * Calls: ?action=get_conversation&conversation_id=XXX&section_id=YYY
     * 
     * @param conversationId - Conversation ID
     * @returns Promise resolving to conversation and messages
     */
    async getByConversation(conversationId: string): Promise<{
      conversation: Conversation;
      messages: Message[];
    }> {
      const params: Record<string, string> = { conversation_id: conversationId };
      if (sectionId !== undefined) {
        params.section_id = String(sectionId);
      }
      const response = await apiGet<GetConversationResponse>('get_conversation', params);
      
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
     * Send a message
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
      if (sectionId !== undefined) {
        formData.append('section_id', String(sectionId));
      }

      if (conversationId) {
        formData.append('conversation_id', conversationId);
      }

      // Add files to FormData
      files.forEach((item) => {
        formData.append('uploaded_files[]', item.file, item.file.name);
      });

      return apiPost<SendMessageResponse>(formData);
    }
  };
}

/**
 * Messages API namespace (backward compatible, no section isolation)
 * @deprecated Use createMessagesApi(sectionId) for section-isolated instances
 */
export const messagesApi = createMessagesApi();

// ============================================================================
// FORM MODE API
// ============================================================================

/**
 * Create form API with section ID support
 * Each chat instance should use its own API instance with its section ID
 * 
 * @param sectionId - The section ID for this chat instance
 */
export function createFormApi(sectionId?: number) {
  return {
    /**
     * Submit form selections
     * Calls: POST action=submit_form
     * 
     * @param formValues - Object mapping field IDs to selected values
     * @param readableText - Human-readable text representation of selections
     * @param conversationId - Conversation ID (optional, creates new if not provided)
     * @param model - LLM model to use
     * @returns Promise resolving to form submission result
     */
    async submit(
      formValues: Record<string, string | string[]>,
      readableText: string,
      conversationId: string | null,
      model: string
    ): Promise<FormSubmissionResponse> {
      const formData = new FormData();
      formData.append('action', 'submit_form');
      formData.append('form_values', JSON.stringify(formValues));
      formData.append('readable_text', readableText);
      formData.append('model', model);
      if (sectionId !== undefined) {
        formData.append('section_id', String(sectionId));
      }

      if (conversationId) {
        formData.append('conversation_id', conversationId);
      }

      return apiPost<FormSubmissionResponse>(formData);
    }
  };
}

/**
 * Form Mode API namespace (backward compatible, no section isolation)
 * @deprecated Use createFormApi(sectionId) for section-isolated instances
 */
export const formApi = createFormApi();

// ============================================================================
// ADMIN API
// ============================================================================

// ============================================================================
// CONTINUE CONVERSATION API
// ============================================================================

/**
 * Create continue conversation API with section ID support
 * Used in form mode when the AI response doesn't contain a form
 * 
 * @param sectionId - The section ID for this chat instance
 */
export function createContinueApi(sectionId?: number) {
  return {
    /**
     * Continue the conversation (triggers next LLM response)
     * Calls: POST action=continue_conversation
     * 
     * @param conversationId - The conversation ID to continue
     * @param model - The LLM model to use
     * @returns Promise resolving to the response
     */
    async continue(
      conversationId: string,
      model: string
    ): Promise<SendMessageResponse> {
      const formData = new FormData();
      formData.append('action', 'continue_conversation');
      formData.append('conversation_id', conversationId);
      formData.append('model', model);
      if (sectionId !== undefined) {
        formData.append('section_id', String(sectionId));
      }

      return apiPost<SendMessageResponse>(formData);
    }
  };
}

// ============================================================================
// AUTO-START API
// ============================================================================

interface AutoStartedResponse {
  auto_started: boolean;
  conversation?: Conversation;
  messages?: Message[];
  error?: string;
}

/**
 * Create auto-start API with section ID support
 *
 * @param sectionId - The section ID for this chat instance
 */
export function createAutoStartApi(sectionId?: number) {
  return {
    /**
     * Check if there's an auto-started conversation for the current session
     * Calls: ?action=get_auto_started&section_id=XXX
     *
     * @returns Promise resolving to auto-start status and data
     */
    async check(): Promise<AutoStartedResponse> {
      const params: Record<string, string> = {};
      if (sectionId !== undefined) {
        params.section_id = String(sectionId);
      }
      return apiGet<AutoStartedResponse>('get_auto_started', params);
    },

    /**
     * Initiate auto-start conversation from client-side
     * Calls: ?action=start_auto_conversation&section_id=XXX
     *
     * @returns Promise resolving to success status
     */
    async start(): Promise<{success: boolean, error?: string}> {
      const params: Record<string, string> = {};
      if (sectionId !== undefined) {
        params.section_id = String(sectionId);
      }

      try {
        await apiGet<{success: boolean, error?: string}>('start_auto_conversation', params);
        return { success: true };
      } catch (error) {
        return {
          success: false,
          error: error instanceof Error ? error.message : 'Failed to start auto conversation'
        };
      }
    }
  };
}

/**
 * Auto-start API (backward compatible, no section isolation)
 * @deprecated Use createAutoStartApi(sectionId) for section-isolated instances
 */
export const autoStartApi = createAutoStartApi();

// ============================================================================
// PROGRESS TRACKING API
// ============================================================================

/**
 * Progress tracking API
 * Fetches progress data for a conversation
 */
export const progressApi = {
  /**
   * Get progress data for a conversation
   * Calls: ?action=get_progress&conversation_id=XXX&section_id=YYY
   * 
   * @param conversationId - The conversation ID
   * @param sectionId - The section ID
   * @returns Promise resolving to progress data
   */
  async get(conversationId: string, sectionId?: number): Promise<GetProgressResponse> {
    const params: Record<string, string> = { conversation_id: conversationId };
    if (sectionId !== undefined) {
      params.section_id = String(sectionId);
    }
    return apiGet<GetProgressResponse>('get_progress', params);
  }
};

// ============================================================================
// ADMIN API
// ============================================================================

// Admin action response types
interface AdminActionResponse {
  success?: boolean;
  error?: string;
  message?: string;
}

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
  },

  /**
   * Delete a conversation (soft delete - sets deleted flag)
   * @param conversationId - The conversation ID to delete
   */
  async deleteConversation(conversationId: string): Promise<AdminActionResponse> {
    const formData = new FormData();
    formData.append('action', 'admin_delete_conversation');
    formData.append('conversation_id', conversationId);
    return apiPost<AdminActionResponse>(formData);
  },

  /**
   * Block a conversation
   * @param conversationId - The conversation ID to block
   * @param reason - Optional reason for blocking
   */
  async blockConversation(conversationId: string, reason?: string): Promise<AdminActionResponse> {
    const formData = new FormData();
    formData.append('action', 'admin_block_conversation');
    formData.append('conversation_id', conversationId);
    if (reason) {
      formData.append('reason', reason);
    }
    return apiPost<AdminActionResponse>(formData);
  },

  /**
   * Unblock a conversation
   * @param conversationId - The conversation ID to unblock
   */
  async unblockConversation(conversationId: string): Promise<AdminActionResponse> {
    const formData = new FormData();
    formData.append('action', 'admin_unblock_conversation');
    formData.append('conversation_id', conversationId);
    return apiPost<AdminActionResponse>(formData);
  }
};

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
