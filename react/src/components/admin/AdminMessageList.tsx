/**
 * Admin Message List Component
 * ============================
 * 
 * Renders messages in admin view with validation status, context/payload
 * action buttons, and form detection. Uses the shared MessageContentRenderer
 * for consistent rendering with the user-facing chat view.
 * 
 * Extracted from AdminConsole.tsx for modularity.
 * 
 * @module components/admin/AdminMessageList
 */

import React, { useMemo } from 'react';
import { Badge } from 'react-bootstrap';
import {
  MessageContentRenderer,
  buildFormDefinitionsMap,
  findPreviousAssistantFormDefinition
} from '../shared/MessageContentRenderer';
import { JsonInspector } from '../shared/JsonInspector';
import type { Message } from '../../types';

interface AdminMessageListProps {
  messages: Message[];
  formatDate: (date: string) => string;
  setContextPopup: (popup: { show: boolean; message: Message | null; target: HTMLElement | null }) => void;
  setPayloadPopup: (popup: { show: boolean; message: Message | null }) => void;
}

/**
 * Parse attachment JSON to determine count and whether it's a form submission.
 */
function getAttachmentInfo(attachments?: string): { count: number; isFormSubmission: boolean } {
  if (!attachments) return { count: 0, isFormSubmission: false };
  try {
    const parsed = JSON.parse(attachments);
    if (parsed && parsed.type === 'form_submission') {
      return { count: 0, isFormSubmission: true };
    }
    return { count: Array.isArray(parsed) ? parsed.length : 1, isFormSubmission: false };
  } catch {
    return { count: 0, isFormSubmission: false };
  }
}

/**
 * Determine if a message passed schema validation.
 * 
 * - Explicit false / 0 / "0" => failed
 * - Explicit true / 1 / "1"  => passed
 * - undefined / null          => old message, assume valid (backward compat)
 */
function isValidated(message: Message): boolean {
  const val = message.is_validated;
  if (val === false || val === 0 || val === '0') return false;
  if (val === true || val === 1 || val === '1') return true;
  return true;
}

function tryParseMessageJson(content: unknown): unknown | null {
  if (typeof content !== 'string') return null;
  const trimmed = content.trim();
  if (!trimmed) return null;

  const withoutFence = trimmed
    .replace(/^```(?:json)?\s*/i, '')
    .replace(/\s*```$/i, '')
    .trim();
  if (!(withoutFence.startsWith('{') || withoutFence.startsWith('['))) {
    return null;
  }

  try {
    return JSON.parse(withoutFence);
  } catch {
    return null;
  }
}

export const AdminMessageList: React.FC<AdminMessageListProps> = ({
  messages,
  formatDate,
  setContextPopup,
  setPayloadPopup,
}) => {
  const formDefinitionsMap = useMemo(() => buildFormDefinitionsMap(messages), [messages]);

  return (
    <div className="message-stack">
      {messages.map((message, index) => {
        const isUser = message.role === 'user';
        const attachmentInfo = getAttachmentInfo(message.attachments);
        const isLastMessage = index === messages.length - 1;
        const nextMessage = index < messages.length - 1 ? messages[index + 1] : undefined;
        const validated = isValidated(message);
        const parsedJsonContent = tryParseMessageJson(message.content);
        
        const previousAssistantFormDef = isUser
          ? findPreviousAssistantFormDefinition(messages, index, formDefinitionsMap)
          : undefined;

        return (
          <div
            key={message.id}
            className={`message-wrapper ${isUser ? 'user' : 'assistant'} ${!validated ? 'validation-failed' : ''}`}
            style={!validated ? { opacity: 0.7 } : {}}
          >
            {/* Avatar */}
            <div className="message-avatar" style={!validated ? { backgroundColor: '#ffc107' } : {}}>
              <i className={`fas ${isUser ? 'fa-user' : 'fa-robot'}`}></i>
            </div>
            
            {/* Message Bubble */}
            <div
              className={`message-bubble ${isUser ? 'user-message' : 'assistant-message'}`}
              style={!validated ? { borderColor: '#ffc107' } : {}}
            >
              {/* Validation Failed Banner */}
              {!validated && (
                <div className="alert alert-warning py-1 px-2 mb-2 d-flex align-items-center" style={{ fontSize: '0.75rem' }}>
                  <i className="fas fa-exclamation-triangle mr-2"></i>
                  <span className="font-weight-bold">Failed Schema Validation</span>
                  <span className="text-muted ml-2">(retry attempt - not shown to user)</span>
                </div>
              )}
              
              {/* Action buttons row */}
              <div className="d-flex justify-content-end mb-2 flex-wrap" style={{ gap: '4px' }}>
                {/* Validation status badge */}
                {!isUser && (
                  <Badge
                    variant={validated ? 'success' : 'warning'}
                    className="py-1 px-2"
                    style={{ fontSize: '0.65rem' }}
                  >
                    <i className={`fas ${validated ? 'fa-check-circle' : 'fa-exclamation-triangle'} mr-1`}></i>
                    {validated ? 'Valid' : 'Invalid'}
                  </Badge>
                )}
                
                {/* Context button */}
                {message.sent_context && (
                  <button
                    className="btn btn-outline-info btn-sm py-0 px-2"
                    style={{ fontSize: '0.7rem' }}
                    onClick={() => setContextPopup({ show: true, message, target: null })}
                    title="View context sent to AI"
                  >
                    <i className="fas fa-layer-group mr-1"></i>
                    Context
                  </button>
                )}
                
                {/* Payload button */}
                {!isUser && message.request_payload && (
                  <button
                    className={`btn btn-sm py-0 px-2 ${validated ? 'btn-outline-primary' : 'btn-warning'}`}
                    style={{ fontSize: '0.7rem' }}
                    onClick={() => setPayloadPopup({ show: true, message })}
                    title="View API request payload (copy for Postman)"
                  >
                    <i className="fas fa-paper-plane mr-1"></i>
                    Payload
                  </button>
                )}
              </div>
              
              {/* Message Content */}
              <div className="message-content">
                {parsedJsonContent ? (
                  <div className="admin-json-message">
                    <JsonInspector value={parsedJsonContent} />
                  </div>
                ) : (
                  <MessageContentRenderer
                    message={message}
                    isLastMessage={isLastMessage}
                    readOnly={true}
                    nextMessage={nextMessage}
                    previousAssistantFormDefinition={previousAssistantFormDef}
                  />
                )}
              </div>
              
              {/* Attachments (non-form only) */}
              {attachmentInfo.count > 0 && !attachmentInfo.isFormSubmission && (
                <div className="mt-2 small text-muted">
                  <i className="fas fa-paperclip mr-1"></i>
                  {attachmentInfo.count} attachment{attachmentInfo.count !== 1 ? 's' : ''}
                </div>
              )}
              
              {/* Message Meta */}
              <div className="message-meta mt-2">
                <span>
                  <i className="fas fa-clock mr-1"></i>
                  {formatDate(message.timestamp)}
                </span>
                {message.tokens_used && (
                  <span className="tokens">
                    <i className="fas fa-microchip"></i>
                    {message.tokens_used.toLocaleString()} tokens
                  </span>
                )}
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
};
