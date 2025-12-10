/**
 * LLM Chat React Entry Point
 * ==========================
 * 
 * Main entry point for the LLM Chat React component.
 * Initializes the React application by finding the container element
 * and extracting configuration from data attributes.
 * 
 * This file is built as a UMD bundle that can be loaded directly
 * in SelfHelp CMS pages without requiring a full React app setup.
 * 
 * Usage in HTML:
 * ```html
 * <div id="llm-chat-root"
 *      data-user-id="123"
 *      data-config='{"configuredModel":"qwen3-vl-8b-instruct",...}'>
 * </div>
 * <script src="js/ext/llm-chat.umd.js"></script>
 * ```
 * 
 * @module main
 */

import React from 'react';
import ReactDOM from 'react-dom/client';
import 'bootstrap/dist/css/bootstrap.min.css';
import { LlmChat } from './components/styles/chat/LlmChat';
import type { LlmChatConfig, FileConfig } from './types';
import { DEFAULT_FILE_CONFIG, DEFAULT_CONFIG } from './types';

/**
 * Default file configuration
 */
const defaultFileConfig: FileConfig = DEFAULT_FILE_CONFIG;

/**
 * Parse configuration from container data attributes
 * 
 * @param container - The container element with data attributes
 * @returns Parsed LlmChatConfig object
 */
function parseConfig(container: HTMLElement): LlmChatConfig {
  // Get user ID (required)
  const userId = parseInt(container.dataset.userId || '0', 10);
  
  // Try to parse JSON config from data-config attribute
  let jsonConfig: Partial<LlmChatConfig> = {};
  const configData = container.dataset.config;
  
  if (configData) {
    try {
      jsonConfig = JSON.parse(configData);
    } catch (e) {
      console.error('Failed to parse LLM Chat config:', e);
    }
  }
  
  // Parse individual data attributes (these override JSON config)
  const currentConversationId = container.dataset.currentConversationId || 
    jsonConfig.currentConversationId;
  
  const configuredModel = container.dataset.configuredModel || 
    jsonConfig.configuredModel || 
    DEFAULT_CONFIG.configuredModel!;
  
  const enableConversationsList = 
    container.dataset.enableConversationsList === '1' ||
    container.dataset.enableConversationsList === 'true' ||
    jsonConfig.enableConversationsList !== false;
  
  const enableFileUploads = 
    container.dataset.enableFileUploads === '1' ||
    container.dataset.enableFileUploads === 'true' ||
    jsonConfig.enableFileUploads !== false;
  
  const streamingEnabled = 
    container.dataset.streamingEnabled === '1' ||
    container.dataset.streamingEnabled === 'true' ||
    jsonConfig.streamingEnabled !== false;
  
  const enableFullPageReload =
    container.dataset.enableFullPageReload === '1' ||
    container.dataset.enableFullPageReload === 'true' ||
    jsonConfig.enableFullPageReload === true;

  const isVisionModel =
    container.dataset.isVisionModel === '1' ||
    container.dataset.isVisionModel === 'true' ||
    jsonConfig.isVisionModel === true;

  const acceptedFileTypes = container.dataset.acceptedFileTypes ||
    jsonConfig.acceptedFileTypes || '';
  
  // Parse file config - merge JSON config with defaults, then override with data attributes
  const fileConfig: FileConfig = { 
    ...defaultFileConfig,
    ...(jsonConfig.fileConfig || {})
  };
  
  if (container.dataset.maxFileSize) {
    fileConfig.maxFileSize = parseInt(container.dataset.maxFileSize, 10);
  }
  if (container.dataset.maxFiles) {
    fileConfig.maxFilesPerMessage = parseInt(container.dataset.maxFiles, 10);
  }
  if (container.dataset.allowedExtensions) {
    fileConfig.allowedExtensions = container.dataset.allowedExtensions
      .split(',')
      .map(ext => ext.trim().toLowerCase());
  }
  
  // Parse UI labels from data attributes
  const messagePlaceholder = container.dataset.messagePlaceholder || 
    jsonConfig.messagePlaceholder || 
    DEFAULT_CONFIG.messagePlaceholder!;
  
  const noConversationsMessage = container.dataset.noConversationsMessage || 
    jsonConfig.noConversationsMessage || 
    DEFAULT_CONFIG.noConversationsMessage!;
  
  const newConversationTitleLabel = container.dataset.newConversationTitleLabel || 
    jsonConfig.newConversationTitleLabel || 
    DEFAULT_CONFIG.newConversationTitleLabel!;
  
  const conversationTitleLabel = container.dataset.conversationTitleLabel || 
    jsonConfig.conversationTitleLabel || 
    DEFAULT_CONFIG.conversationTitleLabel!;
  
  const cancelButtonLabel = container.dataset.cancelButtonLabel || 
    jsonConfig.cancelButtonLabel || 
    DEFAULT_CONFIG.cancelButtonLabel!;
  
  const createButtonLabel = container.dataset.createButtonLabel || 
    jsonConfig.createButtonLabel || 
    DEFAULT_CONFIG.createButtonLabel!;
  
  const deleteConfirmationTitle = container.dataset.deleteConfirmationTitle || 
    jsonConfig.deleteConfirmationTitle || 
    DEFAULT_CONFIG.deleteConfirmationTitle!;
  
  const deleteConfirmationMessage = container.dataset.deleteConfirmationMessage || 
    jsonConfig.deleteConfirmationMessage || 
    DEFAULT_CONFIG.deleteConfirmationMessage!;
  
  const tokensSuffix = container.dataset.tokensSuffix || 
    jsonConfig.tokensSuffix || 
    DEFAULT_CONFIG.tokensSuffix!;
  
  const aiThinkingText = container.dataset.aiThinkingText || 
    jsonConfig.aiThinkingText || 
    DEFAULT_CONFIG.aiThinkingText!;
  
  return {
    userId,
    currentConversationId,
    configuredModel,
    enableConversationsList,
    enableFileUploads,
    streamingEnabled,
    enableFullPageReload,
    acceptedFileTypes,
    isVisionModel,
    fileConfig,
    messagePlaceholder,
    noConversationsMessage,
    newConversationTitleLabel,
    conversationTitleLabel,
    cancelButtonLabel,
    createButtonLabel,
    deleteConfirmationTitle,
    deleteConfirmationMessage,
    tokensSuffix,
    aiThinkingText
  };
}

/**
 * Initialize the LLM Chat React application
 * Finds the container element and mounts the React component
 */
function initializeLlmChat(): void {
  // Find the container element
  const container = document.getElementById('llm-chat-root');
  
  if (!container) {
    // Container not found - this is not necessarily an error
    // The page might not have the chat component
    console.debug('LLM Chat: Container #llm-chat-root not found');
    return;
  }
  
  // Parse configuration from data attributes
  const config = parseConfig(container);
  
  // Validate required configuration
  if (!config.userId || config.userId === 0) {
    console.error('LLM Chat: User ID not provided');
    container.innerHTML = `
      <div class="alert alert-warning m-3">
        <i class="fas fa-exclamation-triangle mr-2"></i>
        Please log in to use the chat feature.
      </div>
    `;
    return;
  }
  
  // Create React root and render the application
  try {
    const root = ReactDOM.createRoot(container);
    root.render(
      <React.StrictMode>
        <LlmChat config={config} />
      </React.StrictMode>
    );
    
    console.debug('LLM Chat: Initialized successfully', {
      userId: config.userId,
      model: config.configuredModel,
      streaming: config.streamingEnabled
    });
  } catch (error) {
    console.error('LLM Chat: Failed to initialize', error);
    container.innerHTML = `
      <div class="alert alert-danger m-3">
        <i class="fas fa-exclamation-circle mr-2"></i>
        Failed to load chat interface. Please refresh the page.
      </div>
    `;
  }
}

/**
 * Auto-initialize when DOM is ready
 * Supports both DOMContentLoaded and already-loaded states
 */
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializeLlmChat);
} else {
  // DOM is already ready
  initializeLlmChat();
}

/**
 * Export the LlmChat component for direct usage
 * This allows the component to be imported and used directly
 * in other React applications if needed
 */
export { LlmChat };
export type { LlmChatConfig };
