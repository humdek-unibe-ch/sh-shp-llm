/**
 * LLM Chat Configuration Parser
 * ==============================
 * 
 * Parses LlmChatConfig from HTML container data attributes.
 * Extracted from LlmChat.tsx to keep the entry point clean.
 * 
 * Configuration Loading Priority:
 * 1. Individual data attributes (highest priority)
 * 2. JSON config from data-config attribute
 * 3. DEFAULT_CONFIG values (fallback)
 * 
 * @module utils/configParser
 */

import type { LlmChatConfig, FileConfig, FloatingButtonPosition, ChatAppearance } from '../types';
import { DEFAULT_FILE_CONFIG, DEFAULT_CONFIG } from '../types';

/**
 * Read a string config value with fallback chain:
 * data attribute -> jsonConfig -> default
 */
function str(
  container: DOMStringMap,
  key: string,
  jsonConfig: Partial<LlmChatConfig>,
  jsonKey: keyof LlmChatConfig,
  fallback: string
): string {
  if (container[key] !== undefined) {
    return container[key] as string;
  }

  if (jsonConfig[jsonKey] !== undefined && jsonConfig[jsonKey] !== null) {
    return jsonConfig[jsonKey] as string;
  }

  return fallback;
}

/**
 * Read a boolean config value with fallback chain:
 * data attribute ('1'/'true') -> jsonConfig -> default
 */
function bool(
  container: DOMStringMap,
  key: string,
  jsonConfig: Partial<LlmChatConfig>,
  jsonKey: keyof LlmChatConfig,
  fallback: boolean = false
): boolean {
  const dataVal = container[key];
  if (dataVal === '1' || dataVal === 'true') return true;
  if (dataVal === '0' || dataVal === 'false') return false;
  if (jsonConfig[jsonKey] !== undefined) return !!jsonConfig[jsonKey];
  return fallback;
}

/**
 * Parse configuration from container data attributes
 * 
 * @param container - The container element with data attributes
 * @returns Parsed LlmChatConfig object
 */
export function parseConfig(container: HTMLElement): LlmChatConfig {
  const ds = container.dataset;

  // Get core IDs
  const userId = parseInt(ds.userId || '0', 10);
  const sectionId = parseInt(ds.sectionId || '0', 10) || undefined;

  // Try to parse JSON config from data-config attribute
  let jsonConfig: Partial<LlmChatConfig> = {};
  if (ds.config) {
    try {
      jsonConfig = JSON.parse(ds.config);
    } catch (e) {
      console.error('Failed to parse LLM Chat config:', e);
    }
  }

  // Parse file config
  const fileConfig: FileConfig = {
    ...DEFAULT_FILE_CONFIG,
    ...(jsonConfig.fileConfig || {})
  };
  if (ds.maxFileSize) fileConfig.maxFileSize = parseInt(ds.maxFileSize, 10);
  if (ds.maxFiles) fileConfig.maxFilesPerMessage = parseInt(ds.maxFiles, 10);
  if (ds.allowedExtensions) {
    fileConfig.allowedExtensions = ds.allowedExtensions.split(',').map(ext => ext.trim().toLowerCase());
  }

  const D = DEFAULT_CONFIG;

  return {
    userId,
    sectionId: sectionId || jsonConfig.sectionId,
    fileConfig,

    // Core settings
    currentConversationId: ds.currentConversationId || jsonConfig.currentConversationId,
    configuredModel: str(ds, 'configuredModel', jsonConfig, 'configuredModel', D.configuredModel!),
    acceptedFileTypes: str(ds, 'acceptedFileTypes', jsonConfig, 'acceptedFileTypes', ''),

    // Boolean flags
    enableConversationsList: bool(ds, 'enableConversationsList', jsonConfig, 'enableConversationsList', true),
    enableFileUploads: bool(ds, 'enableFileUploads', jsonConfig, 'enableFileUploads', true),
    enableFullPageReload: bool(ds, 'enableFullPageReload', jsonConfig, 'enableFullPageReload'),
    isVisionModel: bool(ds, 'isVisionModel', jsonConfig, 'isVisionModel'),
    hasConversationContext: bool(ds, 'hasConversationContext', jsonConfig, 'hasConversationContext'),
    autoStartConversation: bool(ds, 'autoStartConversation', jsonConfig, 'autoStartConversation'),
    enableFormMode: bool(ds, 'enableFormMode', jsonConfig, 'enableFormMode'),
    enableProgressTracking: bool(ds, 'enableProgressTracking', jsonConfig, 'enableProgressTracking'),
    enableSpeechToText: bool(ds, 'enableSpeechToText', jsonConfig, 'enableSpeechToText'),
    enableFloatingButton: bool(ds, 'enableFloatingButton', jsonConfig, 'enableFloatingButton'),
    progressShowTopics: bool(ds, 'progressShowTopics', jsonConfig, 'progressShowTopics'),
    isFloatingMode: false,

    // Floating button
    floatingButtonPosition: str(ds, 'floatingButtonPosition', jsonConfig, 'floatingButtonPosition', 'bottom-right') as FloatingButtonPosition,
    floatingButtonIcon: str(ds, 'floatingButtonIcon', jsonConfig, 'floatingButtonIcon', 'fa-comments'),
    floatingButtonLabel: str(ds, 'floatingButtonLabel', jsonConfig, 'floatingButtonLabel', ''),
    floatingChatTitle: str(ds, 'floatingChatTitle', jsonConfig, 'floatingChatTitle', 'AI Assistant'),

    // Auto-start + speech
    autoStartMessage: str(ds, 'autoStartMessage', jsonConfig, 'autoStartMessage', "Hello! I'm here to help you. What would you like to talk about?"),
    speechToTextModel: str(ds, 'speechToTextModel', jsonConfig, 'speechToTextModel', ''),

    // Progress tracking
    progressBarLabel: str(ds, 'progressBarLabel', jsonConfig, 'progressBarLabel', D.progressBarLabel || 'Progress'),
    progressCompleteMessage: str(ds, 'progressCompleteMessage', jsonConfig, 'progressCompleteMessage', D.progressCompleteMessage || 'Great job! You have covered all topics.'),

    // UI Labels
    messagePlaceholder: str(ds, 'messagePlaceholder', jsonConfig, 'messagePlaceholder', D.messagePlaceholder!),
    noConversationsMessage: str(ds, 'noConversationsMessage', jsonConfig, 'noConversationsMessage', D.noConversationsMessage!),
    newConversationTitleLabel: str(ds, 'newConversationTitleLabel', jsonConfig, 'newConversationTitleLabel', D.newConversationTitleLabel!),
    conversationTitleLabel: str(ds, 'conversationTitleLabel', jsonConfig, 'conversationTitleLabel', D.conversationTitleLabel!),
    cancelButtonLabel: str(ds, 'cancelButtonLabel', jsonConfig, 'cancelButtonLabel', D.cancelButtonLabel!),
    createButtonLabel: str(ds, 'createButtonLabel', jsonConfig, 'createButtonLabel', D.createButtonLabel!),
    deleteConfirmationTitle: str(ds, 'deleteConfirmationTitle', jsonConfig, 'deleteConfirmationTitle', D.deleteConfirmationTitle!),
    deleteConfirmationMessage: str(ds, 'deleteConfirmationMessage', jsonConfig, 'deleteConfirmationMessage', D.deleteConfirmationMessage!),
    confirmDeleteButtonLabel: str(ds, 'confirmDeleteButtonLabel', jsonConfig, 'confirmDeleteButtonLabel', D.confirmDeleteButtonLabel!),
    cancelDeleteButtonLabel: str(ds, 'cancelDeleteButtonLabel', jsonConfig, 'cancelDeleteButtonLabel', D.cancelDeleteButtonLabel!),
    tokensSuffix: str(ds, 'tokensSuffix', jsonConfig, 'tokensSuffix', D.tokensSuffix!),
    aiThinkingText: str(ds, 'aiThinkingText', jsonConfig, 'aiThinkingText', D.aiThinkingText!),
    conversationsHeading: str(ds, 'conversationsHeading', jsonConfig, 'conversationsHeading', D.conversationsHeading!),
    newChatButtonLabel: str(ds, 'newChatButtonLabel', jsonConfig, 'newChatButtonLabel', D.newChatButtonLabel!),
    selectConversationHeading: str(ds, 'selectConversationHeading', jsonConfig, 'selectConversationHeading', D.selectConversationHeading!),
    selectConversationDescription: str(ds, 'selectConversationDescription', jsonConfig, 'selectConversationDescription', D.selectConversationDescription!),
    modelLabelPrefix: str(ds, 'modelLabelPrefix', jsonConfig, 'modelLabelPrefix', D.modelLabelPrefix!),
    noMessagesMessage: str(ds, 'noMessagesMessage', jsonConfig, 'noMessagesMessage', D.noMessagesMessage!),
    loadingText: str(ds, 'loadingText', jsonConfig, 'loadingText', D.loadingText!),
    uploadImageLabel: str(ds, 'uploadImageLabel', jsonConfig, 'uploadImageLabel', D.uploadImageLabel!),
    uploadHelpText: str(ds, 'uploadHelpText', jsonConfig, 'uploadHelpText', D.uploadHelpText!),
    clearButtonLabel: str(ds, 'clearButtonLabel', jsonConfig, 'clearButtonLabel', D.clearButtonLabel!),
    submitButtonLabel: str(ds, 'submitButtonLabel', jsonConfig, 'submitButtonLabel', D.submitButtonLabel!),
    emptyMessageError: str(ds, 'emptyMessageError', jsonConfig, 'emptyMessageError', D.emptyMessageError!),
    defaultChatTitle: str(ds, 'defaultChatTitle', jsonConfig, 'defaultChatTitle', D.defaultChatTitle!),
    deleteButtonTitle: str(ds, 'deleteButtonTitle', jsonConfig, 'deleteButtonTitle', D.deleteButtonTitle!),
    conversationTitlePlaceholder: str(ds, 'conversationTitlePlaceholder', jsonConfig, 'conversationTitlePlaceholder', D.conversationTitlePlaceholder!),
    singleFileAttachedText: str(ds, 'singleFileAttachedText', jsonConfig, 'singleFileAttachedText', D.singleFileAttachedText!),
    multipleFilesAttachedText: str(ds, 'multipleFilesAttachedText', jsonConfig, 'multipleFilesAttachedText', D.multipleFilesAttachedText!),
    emptyStateTitle: str(ds, 'emptyStateTitle', jsonConfig, 'emptyStateTitle', D.emptyStateTitle!),
    emptyStateDescription: str(ds, 'emptyStateDescription', jsonConfig, 'emptyStateDescription', D.emptyStateDescription!),
    loadingMessagesText: str(ds, 'loadingMessagesText', jsonConfig, 'loadingMessagesText', D.loadingMessagesText!),
    attachFilesTitle: str(ds, 'attachFilesTitle', jsonConfig, 'attachFilesTitle', D.attachFilesTitle!),
    noVisionSupportTitle: str(ds, 'noVisionSupportTitle', jsonConfig, 'noVisionSupportTitle', D.noVisionSupportTitle!),
    noVisionSupportText: str(ds, 'noVisionSupportText', jsonConfig, 'noVisionSupportText', D.noVisionSupportText!),
    sendMessageTitle: str(ds, 'sendMessageTitle', jsonConfig, 'sendMessageTitle', D.sendMessageTitle!),
    removeFileTitle: str(ds, 'removeFileTitle', jsonConfig, 'removeFileTitle', D.removeFileTitle!),
    conversationBlockedMessage: str(ds, 'conversationBlockedMessage', jsonConfig, 'conversationBlockedMessage', D.conversationBlockedMessage!),

    // Chat appearance (v1.3.0+: unified colours + icons + image overrides).
    // PHP serialises a complete tree (defaults merged with author overrides),
    // so we just pass it through. When the JSON config is missing the key
    // entirely (admin viewer / standalone harnesses), `undefined` triggers
    // the React fallback to FontAwesome / default colours.
    chatAppearance: (jsonConfig.chatAppearance && typeof jsonConfig.chatAppearance === 'object'
      ? jsonConfig.chatAppearance
      : undefined) as ChatAppearance | undefined,

    // Floating shortcuts (v1.4.0+)
    floatingShortcuts: (jsonConfig.floatingShortcuts && Array.isArray(jsonConfig.floatingShortcuts)
      ? jsonConfig.floatingShortcuts
      : []),
  } as LlmChatConfig;
}
