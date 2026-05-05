/**
 * Message List Component
 * ======================
 * 
 * Displays the list of messages in a conversation.
 * Modern, professional design with smooth animations.
 * 
 * Features:
 * - User messages (right-aligned, gradient blue)
 * - Assistant messages (left-aligned, white with border)
 * - Avatar icons
 * - Markdown rendering with syntax highlighting
 * - Thinking indicator
 * - Form mode: renders JSON Schema forms from assistant messages
 * 
 * @module components/MessageList
 */

import React, { useCallback, useEffect, useMemo, useState } from 'react';
import type { Message, LlmChatConfig, FormDefinition, StructuredResponse, ChatAppearanceSide } from '../../../types';
import { parseFormDefinition, parseFormSubmissionMetadata, messageHasForm } from '../../../utils/formUtils';
import { parseStructuredResponse } from '../../../utils/llmResponseUtils';
import { 
  buildFormDefinitionsMap, 
  findPreviousAssistantFormDefinition 
} from '../../shared/MessageContentRenderer';
import { formatTime } from '../../../utils/formatters';
import { MarkdownRenderer } from './MarkdownRenderer';
import { FormRenderer } from './FormRenderer';
import { FormDisplay } from './FormDisplay';
import { StructuredResponseRenderer } from './StructuredResponseRenderer';

/**
 * Props for MessageList component
 */
interface MessageListProps {
  /** Array of messages to display */
  messages: Message[];
  /** Whether loading initial data */
  isLoading: boolean;
  /** Whether processing request */
  isProcessing?: boolean;
  /** Component configuration */
  config: LlmChatConfig;
  /** Callback when form is submitted (form mode only) */
  onFormSubmit?: (values: Record<string, string | string[]>, readableText: string) => void;
  /** Whether form submission is in progress */
  isFormSubmitting?: boolean;
  /** Callback when Continue button is clicked (form mode only) */
  onContinue?: () => void;
  /** Callback when a suggestion button is clicked (structured response mode) */
  onSuggestionClick?: (suggestion: string) => void;
  /** Information about the last failed form submission for retry */
  lastFailedFormSubmission?: {
    values: Record<string, string | string[]>;
    readableText: string;
    conversationId: string | null;
    timestamp: number;
  } | null;
  /** Callback when retrying a failed form submission */
  onRetryFormSubmission?: () => void;
}

/**
 * Props for individual message item
 */
interface MessageItemProps {
  /** The message to display */
  message: Message;
  /** Configuration */
  config: LlmChatConfig;
  /** Whether this is the last message (for form rendering) */
  isLastMessage?: boolean;
  /** Callback when form is submitted */
  onFormSubmit?: (values: Record<string, string | string[]>, readableText: string) => void;
  /** Whether form submission is in progress */
  isFormSubmitting?: boolean;
  /** The next message (to find user's form submission for historical forms) */
  nextMessage?: Message;
  /** Previous assistant message (to find form definition for user submissions) */
  previousAssistantFormDefinition?: FormDefinition;
  /** Callback when a suggestion button is clicked */
  onSuggestionClick?: (suggestion: string) => void;
}

/**
 * Default appearance fallback used when `config.chatAppearance` is missing.
 * Mirrors `LlmChatModel::getDefaultChatAppearance()` so the React layer
 * never has to render an undefined avatar / colourless bubble even when
 * the standalone harness or admin viewer provides only a partial config.
 */
const DEFAULT_APPEARANCE: { user: ChatAppearanceSide; ai: ChatAppearanceSide } = {
  user: {
    bg: '#DCF8C6',
    text: '#1b5e20',
    border: '#a5d6a7',
    icon: 'fa-user',
    iconMobile: 'person-circle',
    iconImage: ''
  },
  ai: {
    bg: '#F3E5F5',
    text: '#4a148c',
    border: '#ce93d8',
    icon: 'fa-robot',
    iconMobile: 'chatbubble-ellipses',
    iconImage: ''
  }
};

/** Resolve the per-side appearance, falling back to DEFAULT_APPEARANCE. */
function getSide(config: LlmChatConfig, isUser: boolean): ChatAppearanceSide {
  const role = isUser ? 'user' : 'ai';
  return config.chatAppearance?.[role] ?? DEFAULT_APPEARANCE[role];
}

/**
 * Detect whether FontAwesome (free) is loaded on the host page.
 *
 * Mounts a hidden `<i className="fas fa-check">`, reads the
 * `font-family` resolved by the browser, and considers FA loaded only
 * when the font name contains "Font Awesome". Result is cached on
 * `window.__llmFontAwesomeAvailable` for subsequent mounts so we only
 * pay the cost once per page load.
 *
 * Returns `null` while detection is pending (effects run after the
 * first render). The avatar renderer treats `null` like "FA available"
 * to avoid a flash of letter-avatars on FA-loaded pages — the truly
 * worst case (FA missing AND we briefly assume it isn't) is a
 * one-frame fallback, which is fine.
 */
function detectFontAwesome(): boolean {
  const w = window as unknown as { __llmFontAwesomeAvailable?: boolean };
  if (typeof w.__llmFontAwesomeAvailable === 'boolean') {
    return w.__llmFontAwesomeAvailable;
  }
  try {
    const probe = document.createElement('i');
    probe.className = 'fas fa-check';
    probe.style.position = 'absolute';
    probe.style.left = '-9999px';
    probe.style.top = '-9999px';
    probe.style.fontSize = '0';
    document.body.appendChild(probe);
    const cs = window.getComputedStyle(probe);
    const family = (cs.fontFamily || '').toLowerCase();
    document.body.removeChild(probe);
    const available = family.includes('font awesome');
    w.__llmFontAwesomeAvailable = available;
    return available;
  } catch {
    return true;
  }
}

/**
 * Hook returning whether FontAwesome is loaded. Defaults to `true`
 * until the post-mount effect has had a chance to verify, so
 * FA-equipped pages render the icon directly without a flash.
 */
function useFontAwesomeAvailable(): boolean {
  const [available, setAvailable] = useState<boolean>(() => {
    const w = window as unknown as { __llmFontAwesomeAvailable?: boolean };
    return typeof w.__llmFontAwesomeAvailable === 'boolean' ? w.__llmFontAwesomeAvailable : true;
  });
  useEffect(() => {
    setAvailable(detectFontAwesome());
  }, []);
  return available;
}

/**
 * Build inline styles for a message bubble from the per-side appearance.
 * AI gets a left rail; user gets a right rail (existing convention).
 */
function getBubbleStyle(isUser: boolean, side: ChatAppearanceSide): React.CSSProperties {
  const style: React.CSSProperties = {};
  if (side.bg) style.backgroundColor = side.bg;
  if (side.text) style.color = side.text;
  if (side.border) {
    if (isUser) style.borderRight = `3px solid ${side.border}`;
    else style.borderLeft = `3px solid ${side.border}`;
  }
  return style;
}

/**
 * Build inline styles for message content/meta that inherit text colour
 */
function getTextStyle(side: ChatAppearanceSide): React.CSSProperties | undefined {
  if (!side.text) return undefined;
  return { color: side.text };
}

/**
 * Build CSS custom properties so shared CSS can use the configured palette.
 */
function getPaletteVariables(appearance?: { user: ChatAppearanceSide; ai: ChatAppearanceSide }): React.CSSProperties {
  const ai = appearance?.ai ?? DEFAULT_APPEARANCE.ai;
  const user = appearance?.user ?? DEFAULT_APPEARANCE.user;
  const style: React.CSSProperties & Record<string, string> = {};

  if (ai.bg) style['--llm-ai-bg'] = ai.bg;
  if (ai.text) style['--llm-ai-text'] = ai.text;
  if (ai.border) style['--llm-ai-border'] = ai.border;

  if (user.bg) style['--llm-user-bg'] = user.bg;
  if (user.text) style['--llm-user-text'] = user.text;
  if (user.border) style['--llm-user-border'] = user.border;

  // Avatar palette follows bubble colours
  if (ai.bg) style['--llm-ai-avatar-bg'] = ai.bg;
  if (ai.text) style['--llm-ai-avatar-text'] = ai.text;
  if (user.bg) style['--llm-user-avatar-bg'] = user.bg;
  if (user.text) style['--llm-user-avatar-text'] = user.text;

  return style;
}

/**
 * Render the bubble avatar.
 *
 * Priority:
 *   1. Custom image (`side.iconImage`) — rendered as `<img>` so CSS
 *      rounding + object-fit cover crops any aspect ratio cleanly
 *      into the avatar slot. Wins over icon classes on every platform.
 *   2. FontAwesome class (`side.icon`, e.g. `fa-user` or
 *      `fas fa-user`). When the runtime FA detection reports the font
 *      is missing, we drop to a coloured letter avatar so the layout
 *      never breaks.
 *
 * The image URL is fully resolved server-side by
 * `LlmChatModel::getChatAppearance()` (interpolation applied, BASE_PATH
 * prepended for absolute paths). The React layer treats it as opaque.
 */
const BubbleAvatar: React.FC<{
  isUser: boolean;
  config: LlmChatConfig;
  /** Override styling on the wrapper (e.g. validation tint in admin viewer). */
  wrapperStyle?: React.CSSProperties;
}> = ({ isUser, config, wrapperStyle }) => {
  const side = getSide(config, isUser);
  const faAvailable = useFontAwesomeAvailable();
  const alt = isUser ? 'User' : 'AI';

  // Image always wins — works on every platform, no font dependency.
  if (side.iconImage) {
    return (
      <div className="message-avatar" style={wrapperStyle}>
        <img
          src={side.iconImage}
          alt={alt}
          className="message-avatar-img"
          loading="lazy"
        />
      </div>
    );
  }

  // FontAwesome icon when available. Accept both `fa-user` shorthand and
  // full `fas fa-user` syntax — if the author already prefixed the style
  // class we don't double-prefix.
  if (faAvailable && side.icon) {
    const cls = /(^|\s)fa[srldb]?(\s|$)/.test(side.icon)
      ? side.icon
      : `fas ${side.icon}`;
    return (
      <div className="message-avatar" style={wrapperStyle}>
        <i className={cls} aria-label={alt}></i>
      </div>
    );
  }

  // Last-resort letter avatar — keeps the layout intact when FontAwesome
  // is missing AND no custom image is configured.
  const letter = isUser ? 'U' : 'AI';
  return (
    <div className="message-avatar message-avatar-letter" style={wrapperStyle} aria-label={alt}>
      <span>{letter}</span>
    </div>
  );
};

/**
 * Parse and count attachments from message
 */
function getAttachmentCount(attachments?: string): number {
  if (!attachments) return 0;
  
  try {
    const parsed = JSON.parse(attachments);
    return Array.isArray(parsed) ? parsed.length : (parsed ? 1 : 0);
  } catch {
    return 0;
  }
}

/**
 * Render attachment indicator
 */
const AttachmentIndicator: React.FC<{ count: number; isUser: boolean; config: LlmChatConfig }> = ({ count, isUser, config }) => {
  if (count === 0) return null;
  
  const fileText = count === 1 ? config.singleFileAttachedText : config.multipleFilesAttachedText.replace('{count}', count.toString());
  
  return (
    <div className="mt-2 pt-2" style={{ borderTop: '1px solid rgba(0,0,0,0.08)' }}>
      <small style={{ opacity: 0.7 }}>
        <i className="fas fa-paperclip mr-1"></i>
        {fileText}
      </small>
    </div>
  );
};

/**
 * Individual message item component
 * Renders a single message with avatar, content, and metadata
 * Detects and renders structured responses, forms, or markdown from assistant messages
 */
const MessageItem: React.FC<MessageItemProps> = ({ 
  message, 
  config,
  isLastMessage = false,
  onFormSubmit,
  isFormSubmitting = false,
  nextMessage,
  previousAssistantFormDefinition,
  onSuggestionClick
}) => {
  const isUser = message.role === 'user';
  const attachmentCount = getAttachmentCount(message.attachments);
  
  // Check if this assistant message contains a structured response (new format)
  // Priority: Structured Response > Legacy Form > Markdown
  let structuredResponse: StructuredResponse | null = null;
  let formDefinition: FormDefinition | null = null;
  let isHistoricalForm = false;
  let userSubmittedValues: Record<string, string | string[]> | undefined;

  // Check if content appears to be malformed structured response
  const appearsToBeStructuredResponse = !isUser && (
    message.content.trim().startsWith('{') &&
    (message.content.includes('"content":') || message.content.includes('"text_blocks":') || message.content.includes('"forms":'))
  );

  // Try to parse responses
  let isIncompleteStructuredResponse = false;
  if (!isUser) {
    // First, try to parse as structured response (new format)
    structuredResponse = parseStructuredResponse(message.content);

    // If parsing failed but content looks like structured response, it might be incomplete
    isIncompleteStructuredResponse = appearsToBeStructuredResponse && !structuredResponse;

    // If not structured response, try legacy form format
    if (!structuredResponse && !isIncompleteStructuredResponse) {
      formDefinition = parseFormDefinition(message.content);
      // If it's a form but not the last message, it's historical
      if (formDefinition && !isLastMessage) {
        isHistoricalForm = true;
        // Try to find the user's submission from the next message
        if (nextMessage && nextMessage.role === 'user') {
          const submissionMeta = parseFormSubmissionMetadata(nextMessage.attachments);
          if (submissionMeta) {
            userSubmittedValues = submissionMeta.values;
          }
        }
      }
    }
  }
  
  // Check if this is a user message that's a form submission
  let isUserFormSubmission = false;
  let userFormDefinition: FormDefinition | null = null;
  let userFormValues: Record<string, string | string[]> | undefined;
  
  if (isUser) {
    const submissionMeta = parseFormSubmissionMetadata(message.attachments);
    if (submissionMeta) {
      isUserFormSubmission = true;
      userFormValues = submissionMeta.values;
      // Use the previous assistant's form definition if available
      userFormDefinition = previousAssistantFormDefinition || null;
    }
  }

  // Determine if we should hide metadata (for structured responses and active forms)
  const hideMetadata = structuredResponse || (formDefinition && !isHistoricalForm);

  const side = useMemo(() => getSide(config, isUser), [config, isUser]);
  const bubbleStyle = useMemo(() => getBubbleStyle(isUser, side), [isUser, side]);
  const textStyle = useMemo(() => getTextStyle(side), [side]);
  
  return (
    <div className={`message-wrapper ${isUser ? 'user' : 'assistant'}`}>
      {/* Avatar — image when configured, FontAwesome fallback otherwise. */}
      <BubbleAvatar isUser={isUser} config={config} />

      {/* Message Bubble */}
      <div className="message-bubble" style={bubbleStyle}>
        {/* Message content */}
        <div className="message-content" style={textStyle}>
          {isUser ? (
            // User messages
            isUserFormSubmission && userFormDefinition && userFormValues ? (
              // User form submission: show as summary with selections
              <FormDisplay
                formDefinition={userFormDefinition}
                submittedValues={userFormValues}
                compact={false}
              />
            ) : (
              // Regular user message: plain text with preserved whitespace
              <div style={{ whiteSpace: 'pre-wrap' }}>{message.content}</div>
            )
          ) : structuredResponse ? (
            // Structured response: render with StructuredResponseRenderer
            // v1.3.0+: defer to `config.enableHintSuggestions` (defaults true)
            // so authors can hide quick-reply buttons via the
            // `enable_hint_suggestions` checkbox on the chat style.
            <StructuredResponseRenderer
              response={structuredResponse}
              isLastMessage={isLastMessage}
              onFormSubmit={onFormSubmit}
              isFormSubmitting={isFormSubmitting}
              onSuggestionClick={onSuggestionClick}
              showSuggestions={config.enableHintSuggestions !== false}
            />
          ) : formDefinition && isHistoricalForm ? (
            // Historical form: show with user's selections
            <FormDisplay 
              formDefinition={formDefinition} 
              submittedValues={userSubmittedValues}
              compact={false}
            />
          ) : isIncompleteStructuredResponse ? (
            // Incomplete structured response: show error message
            <div className="alert alert-warning">
              <i className="fas fa-exclamation-triangle mr-2"></i>
              The AI response was interrupted. Please try again.
            </div>
          ) : formDefinition ? (
            // Active form: render interactive form
            <FormRenderer
              formDefinition={formDefinition}
              onSubmit={onFormSubmit || (() => {})}
              isSubmitting={isFormSubmitting}
              disabled={false}
            />
          ) : (
            // Regular assistant messages: render with markdown
            <MarkdownRenderer
              content={message.content}
            />
          )}
        </div>
        
        {/* Attachment indicator - hide for forms and structured responses */}
        {!formDefinition && !structuredResponse && !isUserFormSubmission && (
          <AttachmentIndicator count={attachmentCount} isUser={isUser} config={config} />
        )}
        
        {/* Message metadata - hide for active forms and structured responses */}
        {!hideMetadata && (
          <div className="message-meta" style={textStyle ? { ...textStyle, opacity: 0.75 } : undefined}>
            <span>{formatTime(message.timestamp)}</span>
            {message.tokens_used && (
              <span className="tokens">
                <i className="fas fa-coins fa-xs"></i>
                {message.tokens_used}{config.tokensSuffix}
              </span>
            )}
          </div>
        )}
      </div>
    </div>
  );
};

/**
 * Thinking indicator component.
 * Shows while waiting for an AI response. v1.3.0+: respects the
 * `chatAppearance.ai` override (image / FA icon / letter fallback)
 * so the placeholder avatar matches the rest of the conversation.
 */
const ThinkingIndicator: React.FC<{ text: string; config: LlmChatConfig }> = ({ text, config }) => (
  <div className="message-wrapper assistant">
    <BubbleAvatar isUser={false} config={config} />
    <div className="message-bubble">
      <div className="d-flex align-items-center">
        <div className="thinking-dots mr-3">
          <span className="dot"></span>
          <span className="dot"></span>
          <span className="dot"></span>
        </div>
        <span style={{ color: 'var(--llm-text-secondary)', fontSize: '14px' }}>{text}</span>
      </div>
    </div>
  </div>
);

/**
 * Empty state component
 * Shows when no messages exist
 */
const EmptyState: React.FC<{ config: LlmChatConfig }> = ({ config }) => (
  <div className="empty-chat-state">
    <i className="fas fa-comments"></i>
    <h5>{config.emptyStateTitle}</h5>
    <p>{config.emptyStateDescription}</p>
  </div>
);

/**
 * Loading state component
 * Shows while loading initial data
 */
const LoadingState: React.FC<{ config: LlmChatConfig }> = ({ config }) => (
  <div className="loading-spinner">
    <div className="spinner-border mb-3" role="status">
      <span className="sr-only">{config.loadingText}</span>
    </div>
    <p>{config.loadingMessagesText}</p>
  </div>
);

/**
 * Continue Button Component
 * Shows when form mode is enabled but last assistant message has no form
 */
const ContinueButton: React.FC<{
  label: string;
  onClick: () => void;
  disabled: boolean;
}> = ({ label, onClick, disabled }) => (
  <div className="continue-button-wrapper text-center py-4">
    <button
      className="btn btn-primary btn-lg px-5"
      onClick={onClick}
      disabled={disabled}
    >
      {disabled ? (
        <>
          <span className="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span>
          {label}
        </>
      ) : (
        <>
          <i className="fas fa-arrow-right mr-2"></i>
          {label}
        </>
      )}
    </button>
  </div>
);

/**
 * Retry Form Component
 * Shows when a form submission failed and allows the user to retry
 */
const RetryForm: React.FC<{
  failedSubmission: {
    values: Record<string, string | string[]>;
    readableText: string;
    conversationId: string | null;
    timestamp: number;
  };
  onRetry: () => void;
  isSubmitting: boolean;
  config: LlmChatConfig;
}> = ({ failedSubmission, onRetry, isSubmitting, config }) => {
  const handleRetry = useCallback(() => {
    onRetry();
  }, [onRetry]);

  return (
    <div className="retry-form-wrapper">
      <div className="alert alert-warning mb-3">
        <i className="fas fa-exclamation-triangle mr-2"></i>
        <strong>{config.formSubmissionError || 'Form submission failed'}</strong>
        <br />
        <small>Your previous form submission could not be processed. Please try again.</small>
      </div>

      <div className="text-center py-3">
        <button
          className="btn btn-warning btn-lg px-5"
          onClick={handleRetry}
          disabled={isSubmitting}
        >
          {isSubmitting ? (
            <>
              <span className="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span>
              Retrying...
            </>
          ) : (
            <>
              <i className="fas fa-redo mr-2"></i>
              Retry Form Submission
            </>
          )}
        </button>
      </div>
    </div>
  );
};

/**
 * Message List Component
 *
 * Main component that renders all messages in the conversation
 */
export const MessageList: React.FC<MessageListProps> = ({
  messages,
  isLoading,
  isProcessing = false,
  config,
  onFormSubmit,
  isFormSubmitting = false,
  onContinue,
  onSuggestionClick,
  lastFailedFormSubmission,
  onRetryFormSubmission
}) => {
  const paletteVars = useMemo(() => getPaletteVariables(config.chatAppearance), [config.chatAppearance]);

  // Show loading state
  if (isLoading) {
    return <LoadingState config={config} />;
  }

  // Show empty state
  if (messages.length === 0) {
    return <EmptyState config={config} />;
  }
  
  // Check if we need to show the thinking indicator
  const lastMessage = messages[messages.length - 1];
  const showThinking = isProcessing && lastMessage?.role === 'user';
  
  // Pre-compute form definitions for each assistant message
  // Uses shared utility that extracts from BOTH legacy forms AND structured response forms
  const formDefinitionsMap = buildFormDefinitionsMap(messages);

  // Determine if we should show the Continue button or thinking state
  // Only show when we're at a dead end (no form to answer) in form mode
  // UNIFIED: Uses messageHasForm() which checks BOTH legacy forms AND structured response forms
  const shouldShowContinueButton = () => {
    if (!config.enableFormMode || !onContinue || messages.length === 0) {
      return false;
    }

    // If there's a failed form submission, don't show continue button
    if (lastFailedFormSubmission) {
      return false;
    }

    // Find the last assistant message
    const lastAssistantMessage = [...messages].reverse().find(msg => msg.role === 'assistant');
    if (!lastAssistantMessage) {
      return false;
    }

    // Only show continue if the last assistant message has NO form (we're at a dead end)
    // UNIFIED: messageHasForm checks both legacy FormDefinition AND StructuredResponse.forms
    const hasForm = messageHasForm(lastAssistantMessage.content);
    return !hasForm;
  };

  // Determine if we should show the retry form
  // Show when there's a failed form submission (either from state or detected from conversation)
  const shouldShowRetryForm = () => {
    if (!config.enableFormMode || messages.length === 0) {
      return false;
    }

    const lastMessage = messages[messages.length - 1];

    // First check if we have an active failed submission in state
    if (lastFailedFormSubmission && lastMessage && lastMessage.role === 'user') {
      return true;
    }

    // Then check if we can detect a failed submission from conversation history
    // A failed submission is: last message is user + has form metadata + no assistant response after
    if (lastMessage && lastMessage.role === 'user') {
      // Check if this user message has form submission metadata
      const submissionMeta = parseFormSubmissionMetadata(lastMessage.attachments);
      if (submissionMeta) {
        // This is a form submission with no response after it - consider it failed
        return true;
      }
    }

    return false;
  };

  // Get retry form data - either from state or detected from conversation
  const getRetryFormData = () => {
    // First priority: use data from component state if available
    if (lastFailedFormSubmission) {
      return lastFailedFormSubmission;
    }

    // Second priority: extract from conversation history
    const lastMessage = messages[messages.length - 1];
    if (lastMessage && lastMessage.role === 'user') {
      const submissionMeta = parseFormSubmissionMetadata(lastMessage.attachments);
      if (submissionMeta) {
        // Find the form definition from previous assistant message
        const previousFormDef = findPreviousAssistantFormDefinition(messages, messages.length - 1, formDefinitionsMap);
        if (previousFormDef) {
          return {
            values: submissionMeta.values,
            readableText: lastMessage.content,
            conversationId: null, // We don't need this for retry from history
            timestamp: new Date(lastMessage.timestamp).getTime()
          };
        }
      }
    }

    return null;
  };
  
  // Determine if we should show thinking indicator for Continue button area
  // This shows when Continue was clicked and we're waiting for response
  const shouldShowContinueThinking = () => {
    if (!config.enableFormMode || messages.length === 0) {
      return false;
    }
    
    // Show thinking when processing in form mode
    if (!isProcessing && !isFormSubmitting) {
      return false;
    }

    // Find the last assistant message
    const lastAssistantMessage = [...messages].reverse().find(msg => msg.role === 'assistant');
    if (!lastAssistantMessage) {
      return false;
    }

    // Only show thinking if the last assistant message has NO form (we clicked Continue)
    // UNIFIED: messageHasForm checks both legacy FormDefinition AND StructuredResponse.forms
    const hasForm = messageHasForm(lastAssistantMessage.content);
    return !hasForm;
  };

  return (
    <div className="message-stack" style={paletteVars}>
      {/* Render all messages */}
      {messages.map((message, index) => {
        // Check if this is the last message (for form rendering)
        const isLastMessage = index === messages.length - 1;
        
        // Get next message (for finding user's form submission)
        const nextMessage = index < messages.length - 1 ? messages[index + 1] : undefined;
        
        // Get previous assistant's form definition (for user form submissions)
        const previousAssistantFormDefinition = message.role === 'user' 
          ? findPreviousAssistantFormDefinition(messages, index, formDefinitionsMap) 
          : undefined;
        
        return (
          <MessageItem
            key={message.id || `msg-${index}`}
            message={message}
            config={config}
            isLastMessage={isLastMessage}
            onFormSubmit={onFormSubmit}
            isFormSubmitting={isFormSubmitting}
            nextMessage={nextMessage}
            previousAssistantFormDefinition={previousAssistantFormDefinition}
            onSuggestionClick={onSuggestionClick}
          />
        );
      })}
      
      {/* Show thinking indicator */}
      {showThinking && (
        <ThinkingIndicator text={config.aiThinkingText} config={config} />
      )}

      {/* Show thinking indicator for Continue button area when processing */}
      {shouldShowContinueThinking() && (
        <ThinkingIndicator text={config.aiThinkingText} config={config} />
      )}
      
      {/* Show retry form when there's a failed form submission */}
      {(() => {
        const retryData = getRetryFormData();
        const showRetry = shouldShowRetryForm() && retryData;

        if (!showRetry) return null;

        // For retries from state, use the onRetryFormSubmission function
        // For retries from conversation history, create a synthetic retry function
        const retryFunction = lastFailedFormSubmission && onRetryFormSubmission
          ? onRetryFormSubmission
          : () => {
              // Retry from conversation history - call onFormSubmit with saved values
              if (onFormSubmit && retryData) {
                onFormSubmit(retryData.values, retryData.readableText);
              }
            };

        return (
          <RetryForm
            failedSubmission={retryData}
            onRetry={retryFunction}
            isSubmitting={isFormSubmitting}
            config={config}
          />
        );
      })()}

      {/* Show Continue button in form mode when last assistant message has no form */}
      {shouldShowContinueButton() && !isProcessing && !isFormSubmitting && onContinue && (
        <ContinueButton
          label={config.continueButtonLabel}
          onClick={onContinue}
          disabled={false}
        />
      )}
    </div>
  );
};

/** Default export for this module. */
export default MessageList;
