/**
 * Shared Message Content Renderer Component
 * ==========================================
 * 
 * A unified component for rendering message content used by both:
 * - LlmChat (user-facing chat)
 * - AdminConsole (admin conversation viewer)
 * 
 * This ensures consistent rendering of:
 * - Plain text messages
 * - Markdown content
 * - Form definitions (both legacy and structured response forms)
 * - Form submissions
 * - Structured responses with text blocks, media, etc.
 * 
 * @module components/shared/MessageContentRenderer
 */

import React from 'react';
import type { 
  Message, 
  FormDefinition, 
  StructuredResponse 
} from '../../types';
import { 
  parseFormDefinition, 
  parseFormSubmissionMetadata, 
  parseStructuredResponse,
  extractFormFromMessage
} from '../../types';
import { MarkdownRenderer } from '../styles/shared/MarkdownRenderer';
import { FormRenderer } from '../styles/shared/FormRenderer';
import { FormDisplay } from '../styles/shared/FormDisplay';
import { StructuredResponseRenderer } from '../styles/shared/StructuredResponseRenderer';

/**
 * Props for MessageContentRenderer
 */
export interface MessageContentRendererProps {
  /** The message to render */
  message: Message;
  /** Whether this is the last message in the conversation (affects form interactivity) */
  isLastMessage?: boolean;
  /** Whether the message is currently streaming */
  isStreaming?: boolean;
  /** Whether this is being rendered in admin/read-only mode */
  readOnly?: boolean;
  /** Callback when form is submitted (only used when not readOnly and isLastMessage) */
  onFormSubmit?: (values: Record<string, string | string[]>, readableText: string) => void;
  /** Whether form submission is in progress */
  isFormSubmitting?: boolean;
  /** Callback when a suggestion button is clicked */
  onSuggestionClick?: (suggestion: string) => void;
  /** The next message (used to find user's form submission for historical forms) */
  nextMessage?: Message;
  /** Previous assistant's form definition (used for user form submission display) */
  previousAssistantFormDefinition?: FormDefinition;
}

/**
 * Render result type
 */
interface RenderResult {
  type: 'text' | 'markdown' | 'form' | 'form-historical' | 'form-submission' | 'structured-response';
  formDefinition?: FormDefinition;
  structuredResponse?: StructuredResponse;
  userSubmittedValues?: Record<string, string | string[]>;
}

/**
 * Analyze message content to determine how it should be rendered
 */
function analyzeMessageContent(
  message: Message,
  isLastMessage: boolean,
  nextMessage?: Message,
  previousAssistantFormDefinition?: FormDefinition
): RenderResult {
  const isUser = message.role === 'user';

  // Check if this is a user message that's a form submission
  if (isUser) {
    const submissionMeta = parseFormSubmissionMetadata(message.attachments);
    if (submissionMeta && previousAssistantFormDefinition) {
      return {
        type: 'form-submission',
        formDefinition: previousAssistantFormDefinition,
        userSubmittedValues: submissionMeta.values
      };
    }
    // Regular user message - plain text
    return { type: 'text' };
  }

  // Assistant message - check for structured response first (new format)
  const structuredResponse = parseStructuredResponse(message.content);
  if (structuredResponse) {
    return {
      type: 'structured-response',
      structuredResponse
    };
  }

  // Check for legacy form definition
  const formDefinition = parseFormDefinition(message.content);
  if (formDefinition) {
    if (isLastMessage) {
      // Active form - can be interacted with
      return {
        type: 'form',
        formDefinition
      };
    } else {
      // Historical form - show with user's selections
      let userSubmittedValues: Record<string, string | string[]> | undefined;
      if (nextMessage && nextMessage.role === 'user') {
        const submissionMeta = parseFormSubmissionMetadata(nextMessage.attachments);
        if (submissionMeta) {
          userSubmittedValues = submissionMeta.values;
        }
      }
      return {
        type: 'form-historical',
        formDefinition,
        userSubmittedValues
      };
    }
  }

  // Regular assistant message - markdown
  return { type: 'markdown' };
}

/**
 * Message Content Renderer Component
 * 
 * Unified component for rendering message content in both chat and admin views.
 * Handles all message types: text, markdown, forms, form submissions, and structured responses.
 */
export const MessageContentRenderer: React.FC<MessageContentRendererProps> = ({
  message,
  isLastMessage = false,
  isStreaming = false,
  readOnly = false,
  onFormSubmit,
  isFormSubmitting = false,
  onSuggestionClick,
  nextMessage,
  previousAssistantFormDefinition
}) => {
  const isUser = message.role === 'user';
  
  // Analyze the message to determine render type
  const renderResult = analyzeMessageContent(
    message,
    isLastMessage,
    nextMessage,
    previousAssistantFormDefinition
  );

  // Render based on type
  switch (renderResult.type) {
    case 'text':
      // User plain text message
      return (
        <div style={{ whiteSpace: 'pre-wrap', wordWrap: 'break-word' }}>
          {message.content}
        </div>
      );

    case 'form-submission':
      // User form submission - show with FormDisplay
      if (renderResult.formDefinition && renderResult.userSubmittedValues) {
        return (
          <FormDisplay
            formDefinition={renderResult.formDefinition}
            submittedValues={renderResult.userSubmittedValues}
            compact={false}
          />
        );
      }
      // Fallback to plain text if form definition not available
      return (
        <div style={{ whiteSpace: 'pre-wrap', wordWrap: 'break-word' }}>
          {message.content}
        </div>
      );

    case 'structured-response':
      // Structured response - render with StructuredResponseRenderer
      if (renderResult.structuredResponse) {
        return (
          <StructuredResponseRenderer
            response={renderResult.structuredResponse}
            isLastMessage={isLastMessage && !readOnly}
            onFormSubmit={readOnly ? undefined : onFormSubmit}
            isFormSubmitting={isFormSubmitting}
            onSuggestionClick={readOnly ? undefined : onSuggestionClick}
          />
        );
      }
      // Fallback to markdown
      return <MarkdownRenderer content={message.content} isStreaming={isStreaming} />;

    case 'form':
      // Active form - render interactive or read-only based on readOnly prop
      if (renderResult.formDefinition) {
        if (readOnly) {
          // In admin view, show as historical form without user selections
          return (
            <FormDisplay
              formDefinition={renderResult.formDefinition}
              submittedValues={undefined}
              compact={false}
            />
          );
        }
        // Interactive form
        return (
          <FormRenderer
            formDefinition={renderResult.formDefinition}
            onSubmit={onFormSubmit || (() => {})}
            isSubmitting={isFormSubmitting}
            disabled={isStreaming}
          />
        );
      }
      return <MarkdownRenderer content={message.content} isStreaming={isStreaming} />;

    case 'form-historical':
      // Historical form - show with user's selections
      if (renderResult.formDefinition) {
        return (
          <FormDisplay
            formDefinition={renderResult.formDefinition}
            submittedValues={renderResult.userSubmittedValues}
            compact={false}
          />
        );
      }
      return <MarkdownRenderer content={message.content} isStreaming={isStreaming} />;

    case 'markdown':
    default:
      // Regular markdown content
      return <MarkdownRenderer content={message.content} isStreaming={isStreaming} />;
  }
};

/**
 * Pre-compute form definitions for a list of messages
 * Returns a map of message index -> FormDefinition
 * 
 * This is useful for finding the previous assistant's form definition
 * when rendering user form submissions.
 */
export function buildFormDefinitionsMap(messages: Message[]): Map<number, FormDefinition> {
  const map = new Map<number, FormDefinition>();
  messages.forEach((message, index) => {
    if (message.role === 'assistant') {
      // extractFormFromMessage checks both legacy FormDefinition AND StructuredResponse.forms
      const formDef = extractFormFromMessage(message.content);
      if (formDef) {
        map.set(index, formDef);
      }
    }
  });
  return map;
}

/**
 * Find the previous assistant's form definition for a given message index
 */
export function findPreviousAssistantFormDefinition(
  messages: Message[],
  currentIndex: number,
  formDefinitionsMap: Map<number, FormDefinition>
): FormDefinition | undefined {
  for (let i = currentIndex - 1; i >= 0; i--) {
    if (messages[i].role === 'assistant') {
      return formDefinitionsMap.get(i);
    }
  }
  return undefined;
}

export default MessageContentRenderer;


