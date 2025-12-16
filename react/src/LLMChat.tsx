/**
 * LLM Chat React Entry Point
 * ==========================
 * 
 * Main entry point for the LLM Chat React component.
 * Initializes the React application by finding the container element
 * and loading configuration via API or data attributes.
 * 
 * This file is built as a UMD bundle that can be loaded directly
 * in SelfHelp CMS pages without requiring a full React app setup.
 * 
 * Configuration Loading Priority:
 * 1. API endpoint (?action=get_config) - preferred method
 * 2. JSON config from data-config attribute - fallback
 * 3. Individual data attributes - legacy fallback
 * 
 * Usage in HTML:
 * ```html
 * <div id="llm-chat-root" data-user-id="123">
 *   <!-- Config loaded via API -->
 * </div>
 * <script src="js/ext/llm-chat.umd.js"></script>
 * ```
 * 
 * @module main
 */

import React, { useState, useEffect } from 'react';
import ReactDOM from 'react-dom/client';
import 'bootstrap/dist/css/bootstrap.min.css';
import { LlmChat } from './components/styles/chat/LlmChat';
import { FloatingChat } from './components/styles/chat/FloatingChat';
import { configApi } from './utils/api';
import type { LlmChatConfig, FileConfig, FloatingButtonPosition } from './types';
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
function parseConfig(container: HTMLElement) {
  // Get user ID (required)
  const userId = parseInt(container.dataset.userId || '0', 10);
  
  // Get section ID (for multi-section support)
  const sectionId = parseInt(container.dataset.sectionId || '0', 10) || undefined;
  
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

  const hasConversationContext =
    container.dataset.hasConversationContext === '1' ||
    container.dataset.hasConversationContext === 'true' ||
    jsonConfig.hasConversationContext === true;

  const autoStartConversation =
    container.dataset.autoStartConversation === '1' ||
    container.dataset.autoStartConversation === 'true' ||
    jsonConfig.autoStartConversation === true;

  const autoStartMessage = container.dataset.autoStartMessage ||
    jsonConfig.autoStartMessage ||
    'Hello! I\'m here to help you. What would you like to talk about?';

  const enableFormMode =
    container.dataset.enableFormMode === '1' ||
    container.dataset.enableFormMode === 'true' ||
    jsonConfig.enableFormMode === true;

  // Progress tracking configuration
  const enableProgressTracking =
    container.dataset.enableProgressTracking === '1' ||
    container.dataset.enableProgressTracking === 'true' ||
    jsonConfig.enableProgressTracking === true;

  const progressBarLabel = container.dataset.progressBarLabel ||
    jsonConfig.progressBarLabel ||
    'Progress';

  const progressCompleteMessage = container.dataset.progressCompleteMessage ||
    jsonConfig.progressCompleteMessage ||
    'Great job! You have covered all topics.';

  const progressShowTopics =
    container.dataset.progressShowTopics === '1' ||
    container.dataset.progressShowTopics === 'true' ||
    jsonConfig.progressShowTopics === true;

  const enableFloatingButton =
    container.dataset.enableFloatingButton === '1' ||
    container.dataset.enableFloatingButton === 'true' ||
    jsonConfig.enableFloatingButton === true;

  const floatingButtonPosition = (container.dataset.floatingButtonPosition ||
    jsonConfig.floatingButtonPosition ||
    'bottom-right') as FloatingButtonPosition;

  const floatingButtonIcon = container.dataset.floatingButtonIcon ||
    jsonConfig.floatingButtonIcon ||
    'fa-comments';

  const floatingButtonLabel = container.dataset.floatingButtonLabel ||
    jsonConfig.floatingButtonLabel ||
    'Chat';

  const floatingChatTitle = container.dataset.floatingChatTitle ||
    jsonConfig.floatingChatTitle ||
    'AI Assistant';

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

  const aiStreamingText = container.dataset.aiStreamingText ||
    jsonConfig.aiStreamingText ||
    DEFAULT_CONFIG.aiStreamingText!;

  const conversationsHeading = container.dataset.conversationsHeading ||
    jsonConfig.conversationsHeading ||
    DEFAULT_CONFIG.conversationsHeading!;

  const newChatButtonLabel = container.dataset.newChatButtonLabel ||
    jsonConfig.newChatButtonLabel ||
    DEFAULT_CONFIG.newChatButtonLabel!;

  const selectConversationHeading = container.dataset.selectConversationHeading ||
    jsonConfig.selectConversationHeading ||
    DEFAULT_CONFIG.selectConversationHeading!;

  const selectConversationDescription = container.dataset.selectConversationDescription ||
    jsonConfig.selectConversationDescription ||
    DEFAULT_CONFIG.selectConversationDescription!;

  const modelLabelPrefix = container.dataset.modelLabelPrefix ||
    jsonConfig.modelLabelPrefix ||
    DEFAULT_CONFIG.modelLabelPrefix!;

  const noMessagesMessage = container.dataset.noMessagesMessage ||
    jsonConfig.noMessagesMessage ||
    DEFAULT_CONFIG.noMessagesMessage!;

  const loadingText = container.dataset.loadingText ||
    jsonConfig.loadingText ||
    DEFAULT_CONFIG.loadingText!;

  const uploadImageLabel = container.dataset.uploadImageLabel ||
    jsonConfig.uploadImageLabel ||
    DEFAULT_CONFIG.uploadImageLabel!;

  const uploadHelpText = container.dataset.uploadHelpText ||
    jsonConfig.uploadHelpText ||
    DEFAULT_CONFIG.uploadHelpText!;

  const clearButtonLabel = container.dataset.clearButtonLabel ||
    jsonConfig.clearButtonLabel ||
    DEFAULT_CONFIG.clearButtonLabel!;

  const submitButtonLabel = container.dataset.submitButtonLabel ||
    jsonConfig.submitButtonLabel ||
    DEFAULT_CONFIG.submitButtonLabel!;

  const confirmDeleteButtonLabel = container.dataset.confirmDeleteButtonLabel ||
    jsonConfig.confirmDeleteButtonLabel ||
    DEFAULT_CONFIG.confirmDeleteButtonLabel!;

  const cancelDeleteButtonLabel = container.dataset.cancelDeleteButtonLabel ||
    jsonConfig.cancelDeleteButtonLabel ||
    DEFAULT_CONFIG.cancelDeleteButtonLabel!;

  const emptyMessageError = container.dataset.emptyMessageError ||
    jsonConfig.emptyMessageError ||
    DEFAULT_CONFIG.emptyMessageError!;

  const streamingActiveError = container.dataset.streamingActiveError ||
    jsonConfig.streamingActiveError ||
    DEFAULT_CONFIG.streamingActiveError!;

  const defaultChatTitle = container.dataset.defaultChatTitle ||
    jsonConfig.defaultChatTitle ||
    DEFAULT_CONFIG.defaultChatTitle!;

  const deleteButtonTitle = container.dataset.deleteButtonTitle ||
    jsonConfig.deleteButtonTitle ||
    DEFAULT_CONFIG.deleteButtonTitle!;

  const conversationTitlePlaceholder = container.dataset.conversationTitlePlaceholder ||
    jsonConfig.conversationTitlePlaceholder ||
    DEFAULT_CONFIG.conversationTitlePlaceholder!;

  const singleFileAttachedText = container.dataset.singleFileAttachedText ||
    jsonConfig.singleFileAttachedText ||
    DEFAULT_CONFIG.singleFileAttachedText!;

  const multipleFilesAttachedText = container.dataset.multipleFilesAttachedText ||
    jsonConfig.multipleFilesAttachedText ||
    DEFAULT_CONFIG.multipleFilesAttachedText!;

  const emptyStateTitle = container.dataset.emptyStateTitle ||
    jsonConfig.emptyStateTitle ||
    DEFAULT_CONFIG.emptyStateTitle!;

  const emptyStateDescription = container.dataset.emptyStateDescription ||
    jsonConfig.emptyStateDescription ||
    DEFAULT_CONFIG.emptyStateDescription!;

  const loadingMessagesText = container.dataset.loadingMessagesText ||
    jsonConfig.loadingMessagesText ||
    DEFAULT_CONFIG.loadingMessagesText!;

  const streamingInProgressPlaceholder = container.dataset.streamingInProgressPlaceholder ||
    jsonConfig.streamingInProgressPlaceholder ||
    DEFAULT_CONFIG.streamingInProgressPlaceholder!;

  const attachFilesTitle = container.dataset.attachFilesTitle ||
    jsonConfig.attachFilesTitle ||
    DEFAULT_CONFIG.attachFilesTitle!;

  const noVisionSupportTitle = container.dataset.noVisionSupportTitle ||
    jsonConfig.noVisionSupportTitle ||
    DEFAULT_CONFIG.noVisionSupportTitle!;

  const noVisionSupportText = container.dataset.noVisionSupportText ||
    jsonConfig.noVisionSupportText ||
    DEFAULT_CONFIG.noVisionSupportText!;

  const sendMessageTitle = container.dataset.sendMessageTitle ||
    jsonConfig.sendMessageTitle ||
    DEFAULT_CONFIG.sendMessageTitle!;

  const removeFileTitle = container.dataset.removeFileTitle ||
    jsonConfig.removeFileTitle ||
    DEFAULT_CONFIG.removeFileTitle!;

  // Create minimal config first
  const baseConfig = {
    userId,
    sectionId: sectionId || jsonConfig.sectionId,
    currentConversationId,
    configuredModel,
    enableConversationsList,
    enableFileUploads,
    streamingEnabled,
    enableFullPageReload,
    acceptedFileTypes,
    isVisionModel,
    hasConversationContext
  };

  // Add auto-start, form mode, progress tracking, and floating button fields
  const autoStartConfig = {
    ...baseConfig,
    autoStartConversation,
    autoStartMessage,
    enableFormMode,
    enableProgressTracking,
    progressBarLabel,
    progressCompleteMessage,
    progressShowTopics,
    enableFloatingButton,
    floatingButtonPosition,
    floatingButtonIcon,
    floatingButtonLabel,
    floatingChatTitle,
    isFloatingMode: false // Default to false, overridden by components
  };

  // Add remaining fields
  const fullConfig = {
    ...autoStartConfig,
    fileConfig,
    messagePlaceholder,
    noConversationsMessage,
    newConversationTitleLabel,
    conversationTitleLabel,
    cancelButtonLabel,
    createButtonLabel,
    deleteConfirmationTitle,
    deleteConfirmationMessage,
    confirmDeleteButtonLabel,
    cancelDeleteButtonLabel,
    tokensSuffix,
    aiThinkingText,
    aiStreamingText,
    conversationsHeading,
    newChatButtonLabel,
    selectConversationHeading,
    selectConversationDescription,
    modelLabelPrefix,
    noMessagesMessage,
    loadingText,
    uploadImageLabel,
    uploadHelpText,
    clearButtonLabel,
    submitButtonLabel,
    emptyMessageError,
    streamingActiveError,
    defaultChatTitle,
    deleteButtonTitle,
    conversationTitlePlaceholder,
    singleFileAttachedText,
    multipleFilesAttachedText,
    emptyStateTitle,
    emptyStateDescription,
    loadingMessagesText,
    streamingInProgressPlaceholder,
    attachFilesTitle,
    noVisionSupportTitle,
    noVisionSupportText,
    sendMessageTitle,
    removeFileTitle
  };

  return fullConfig as LlmChatConfig;
}

/**
 * Loading wrapper component
 * Uses the fallback config from data attributes directly to ensure each instance
 * gets its own correct configuration (especially sectionId for multi-instance pages)
 */
const LlmChatLoader: React.FC<{ fallbackConfig?: LlmChatConfig }> = ({ fallbackConfig }) => {
  const [config, setConfig] = useState<LlmChatConfig | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    async function loadConfig() {
      try {
        // Try to load config from API if we have a sectionId from fallback config
        if (fallbackConfig?.sectionId) {
          const apiConfig = await configApi.get(fallbackConfig.sectionId);
          setConfig(apiConfig);
        } else if (fallbackConfig) {
          // Use fallback config if no sectionId available
          setConfig(fallbackConfig);
        } else {
          setError('Configuration not provided');
        }
      } catch (err) {
        console.warn('Failed to load config from API, using fallback:', err);
        // Fall back to provided config if API fails
        if (fallbackConfig) {
          setConfig(fallbackConfig);
        } else {
          setError('Failed to load configuration');
        }
      } finally {
        setLoading(false);
      }
    }

    loadConfig();
  }, [fallbackConfig]);

  // In floating mode, show the floating button immediately without loading state
  if (fallbackConfig?.enableFloatingButton) {
    // Use fallback config initially, will update when API config loads
    const displayConfig = config || fallbackConfig;
    return <FloatingChat config={displayConfig} />;
  }

  if (loading) {
    return (
      <div className="d-flex justify-content-center align-items-center p-5">
        <div className="spinner-border text-primary" role="status">
          <span className="sr-only">Loading...</span>
        </div>
      </div>
    );
  }

  if (error && !config) {
    return (
      <div className="alert alert-danger m-3">
        <i className="fas fa-exclamation-circle mr-2"></i>
        {error}
      </div>
    );
  }

  if (!config) {
    return (
      <div className="alert alert-warning m-3">
        <i className="fas fa-exclamation-triangle mr-2"></i>
        Configuration not available.
      </div>
    );
  }

  if (!config.userId || config.userId === 0) {
    return (
      <div className="alert alert-warning m-3">
        <i className="fas fa-exclamation-triangle mr-2"></i>
        Please log in to use the chat feature.
      </div>
    );
  }

  // Render floating chat or regular chat based on configuration
  if (config.enableFloatingButton) {
    return <FloatingChat config={config} />;
  }

  return <LlmChat config={config} />;
};

/**
 * Initialize a single LLM Chat instance
 * 
 * @param container - The container element to mount the chat in
 * @param instanceIndex - Index for logging purposes
 */
function initializeSingleInstance(container: HTMLElement, instanceIndex: number): void {
  // Parse fallback configuration from data attributes (for initial render)
  let fallbackConfig: LlmChatConfig | undefined;
  
  try {
    fallbackConfig = parseConfig(container);
    // Only use fallback if it has a valid user ID
    if (!fallbackConfig.userId || fallbackConfig.userId === 0) {
      fallbackConfig = undefined;
    }
  } catch (e) {
    console.debug(`LLM Chat [${instanceIndex}]: Could not parse fallback config from data attributes`);
    fallbackConfig = undefined;
  }
  
  // Create React root and render the application
  try {
    const root = ReactDOM.createRoot(container);
    root.render(
      <React.StrictMode>
        <LlmChatLoader fallbackConfig={fallbackConfig} />
      </React.StrictMode>
    );
    
    console.debug(`LLM Chat [${instanceIndex}]: Initialized successfully`);
  } catch (error) {
    console.error(`LLM Chat [${instanceIndex}]: Failed to initialize`, error);
    container.innerHTML = `
      <div class="alert alert-danger m-3">
        <i class="fas fa-exclamation-circle mr-2"></i>
        Failed to load chat interface. Please refresh the page.
      </div>
    `;
  }
}

/**
 * Initialize the LLM Chat React application
 * Supports multiple chat instances on the same page using class selector
 * Falls back to ID selector for backward compatibility
 */
function initializeLlmChat(): void {
  // Find all containers with the llm-chat-root class (supports multiple instances)
  const containersByClass = document.querySelectorAll('.llm-chat-root');
  
  // Also check for the legacy ID selector (backward compatibility)
  const containerById = document.getElementById('llm-chat-root');
  
  // Combine containers, avoiding duplicates
  const containers: HTMLElement[] = [];
  
  // Add class-based containers
  containersByClass.forEach((el) => {
    containers.push(el as HTMLElement);
  });
  
  // Add ID-based container if not already included
  if (containerById && !containers.includes(containerById)) {
    containers.push(containerById);
  }
  
  if (containers.length === 0) {
    // No containers found - this is not necessarily an error
    // The page might not have the chat component
    console.debug('LLM Chat: No containers found (.llm-chat-root or #llm-chat-root)');
    return;
  }
  
  // Initialize each container as a separate React instance
  containers.forEach((container, index) => {
    initializeSingleInstance(container, index);
  });
  
  console.log(`LLM Chat: Initialized ${containers.length} instance(s) successfully`);
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
 * Export the LlmChat and FloatingChat components for direct usage
 * This allows the components to be imported and used directly
 * in other React applications if needed
 */
export { LlmChat, FloatingChat };
export type { LlmChatConfig };
