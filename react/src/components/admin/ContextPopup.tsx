/**
 * Context Popup Component
 * =======================
 * 
 * Displays the context (system instructions) that was sent to the AI
 * along with a conversation message. Supports JSON array and plain text formats.
 * 
 * Extracted from AdminConsole.tsx for modularity.
 * 
 * @module components/admin/ContextPopup
 */

import React, { useState } from 'react';
import { Button } from 'react-bootstrap';
import { useModalDismiss } from '../../hooks/useModalDismiss';
import { MarkdownRenderer } from '../styles/shared/MarkdownRenderer';
import type { Message } from '../../types';

interface ContextPopupProps {
  message: Message;
  show: boolean;
  onHide: () => void;
}

interface ContextMessageItem {
  role: string;
  content: string;
}

interface NormalizedContext {
  messages: ContextMessageItem[];
  metadata: Record<string, unknown> | null;
}

export const ContextPopup: React.FC<ContextPopupProps> = ({ message, show, onHide }) => {
  const [copied, setCopied] = useState(false);
  const [copyType, setCopyType] = useState<'raw' | 'formatted'>('formatted');
  const backdropRef = useModalDismiss(show, onHide);

  // Safety check for null message
  if (!message || !message.sent_context || typeof message.sent_context !== 'string' || message.sent_context.trim() === '') {
    return null;
  }

  if (!show) return null;

  const normalizeMessages = (value: unknown): ContextMessageItem[] => {
    if (!Array.isArray(value)) return [];
    return value
      .filter((item): item is Record<string, unknown> => !!item && typeof item === 'object')
      .filter((item) => typeof item.content === 'string' && item.content.trim() !== '')
      .map((item) => ({
        role: typeof item.role === 'string' ? item.role : 'system',
        content: String(item.content),
      }));
  };

  const normalizeContext = (parsed: unknown): NormalizedContext => {
    if (Array.isArray(parsed)) {
      return { messages: normalizeMessages(parsed), metadata: null };
    }

    if (parsed && typeof parsed === 'object') {
      const obj = parsed as Record<string, unknown>;

      // Centralized logging wrapper: metadata + llm_context (array of role/content messages)
      if (Array.isArray(obj.llm_context)) {
        const { llm_context, ...metaRest } = obj;
        return {
          messages: normalizeMessages(llm_context),
          metadata: Object.keys(metaRest).length > 0 ? metaRest : null,
        };
      }

      // Fallbacks for other wrapper names
      if (Array.isArray(obj.messages)) {
        const { messages, ...metaRest } = obj;
        return {
          messages: normalizeMessages(messages),
          metadata: Object.keys(metaRest).length > 0 ? metaRest : null,
        };
      }
      if (Array.isArray(obj.context_messages)) {
        const { context_messages, ...metaRest } = obj;
        return {
          messages: normalizeMessages(context_messages),
          metadata: Object.keys(metaRest).length > 0 ? metaRest : null,
        };
      }

      // Single message object
      if (typeof obj.content === 'string' && obj.content.trim() !== '') {
        return {
          messages: [
            {
              role: typeof obj.role === 'string' ? obj.role : 'system',
              content: obj.content,
            },
          ],
          metadata: null,
        };
      }
    }

    return { messages: [], metadata: null };
  };

  const renderRoleIcon = (role: string) =>
    role === 'system' ? 'fa-cogs' : role === 'user' ? 'fa-user' : 'fa-robot';

  const formatRole = (role: string) => role.charAt(0).toUpperCase() + role.slice(1);

  const handleCopy = async (type: 'raw' | 'formatted') => {
    if (message.sent_context) {
      try {
        let textToCopy = message.sent_context;
        
        if (type === 'formatted') {
          try {
            const parsed = JSON.parse(message.sent_context);
            const normalized = normalizeContext(parsed);
            if (normalized.messages.length > 0) {
              const metaText = normalized.metadata
                ? `[METADATA]\n${JSON.stringify(normalized.metadata, null, 2)}\n\n---\n\n`
                : '';
              const messagesText = normalized.messages
                .map((item) => {
                  const plainText = item.content.replace(/<[^>]*>/g, '');
                  return `[${item.role.toUpperCase()}]\n${plainText}`;
                })
                .join('\n\n---\n\n');
              textToCopy = `${metaText}${messagesText}`;
            }
          } catch {
            // Keep original if not JSON
          }
        }
        
        await navigator.clipboard.writeText(textToCopy);
        setCopied(true);
        setCopyType(type);
        setTimeout(() => setCopied(false), 2000);
      } catch (err) {
        console.error('Failed to copy context:', err);
      }
    }
  };

  const renderContent = (content: string) => {
    const hasHtml = /<[^>]+>/.test(content);
    if (hasHtml) {
      return <div className="context-content-body" dangerouslySetInnerHTML={{ __html: content }} />;
    }
    return (
      <div className="context-content-body">
        <MarkdownRenderer content={content} />
      </div>
    );
  };

  const renderScriptContext = (parsed: Record<string, unknown>) => {
    const items: { label: string; icon: string; value: string }[] = [];

    if (parsed.script_template) {
      items.push({ label: 'Script Template', icon: 'fa-file-code', value: String(parsed.script_template) });
    }
    if (parsed.interpolated_prompt) {
      items.push({ label: 'Interpolated Prompt (sent to LLM)', icon: 'fa-paper-plane', value: String(parsed.interpolated_prompt) });
    }
    if (parsed.test_variables && typeof parsed.test_variables === 'object' && Object.keys(parsed.test_variables as object).length > 0) {
      items.push({ label: 'Test Variables', icon: 'fa-flask', value: JSON.stringify(parsed.test_variables, null, 2) });
    }
    if (parsed.data_config && ((Array.isArray(parsed.data_config) && (parsed.data_config as unknown[]).length > 0) || (!Array.isArray(parsed.data_config) && typeof parsed.data_config === 'object' && Object.keys(parsed.data_config as object).length > 0))) {
      items.push({ label: 'Data Config', icon: 'fa-database', value: JSON.stringify(parsed.data_config, null, 2) });
    }
    if (parsed.data_config_values && typeof parsed.data_config_values === 'object' && Object.keys(parsed.data_config_values as object).length > 0) {
      items.push({ label: 'Resolved Data', icon: 'fa-table', value: JSON.stringify(parsed.data_config_values, null, 2) });
    }
    if (parsed.merged_variables && typeof parsed.merged_variables === 'object' && Object.keys(parsed.merged_variables as object).length > 0) {
      items.push({ label: 'All Merged Variables', icon: 'fa-code-branch', value: JSON.stringify(parsed.merged_variables, null, 2) });
    }

    return items.map((item, index) => (
      <div key={index} className="card mb-3">
        <div className="card-header bg-light py-2 d-flex align-items-center">
          <i className={`fas ${item.icon} mr-2 text-info`}></i>
          <span className="font-weight-bold small">{item.label}</span>
        </div>
        <div className="card-body py-3">
          <pre className="mb-0" style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word', fontSize: '0.85rem' }}>{item.value}</pre>
        </div>
      </div>
    ));
  };

  const parseContext = (context: string) => {
    try {
      const parsed = JSON.parse(context);
      const normalized = normalizeContext(parsed);

      // Script execution context (has script_template or interpolated_prompt)
      if (parsed && typeof parsed === 'object' && !Array.isArray(parsed) && (parsed.script_template || parsed.interpolated_prompt)) {
        return renderScriptContext(parsed);
      }

      if (normalized.messages.length > 0) {
        const cards: JSX.Element[] = [];

        if (normalized.metadata) {
          cards.push(
            <div key="ctx-meta" className="card mb-3">
              <div className="card-header bg-light py-2 d-flex align-items-center">
                <i className="fas fa-info-circle mr-2 text-info"></i>
                <span className="font-weight-bold text-uppercase small">Context Metadata</span>
              </div>
              <div className="card-body py-3">
                <pre className="mb-0" style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word', fontSize: '0.85rem' }}>
                  {JSON.stringify(normalized.metadata, null, 2)}
                </pre>
              </div>
            </div>
          );
        }

        const messageCards = normalized.messages.map((item, index) => (
            <div key={index} className="card mb-3">
              <div className="card-header bg-light py-2 d-flex align-items-center">
                <i className={`fas ${renderRoleIcon(item.role)} mr-2 text-info`}></i>
                <span className="font-weight-bold text-uppercase small">
                  {formatRole(item.role)}
                </span>
              </div>
              <div className="card-body py-3">
                {renderContent(item.content)}
              </div>
            </div>
          ));

        return [...cards, ...messageCards];
      }
    } catch {
      // Not JSON
    }

    return (
      <div className="card">
        <div className="card-header bg-light py-2 d-flex align-items-center">
          <i className="fas fa-cogs mr-2 text-info"></i>
          <span className="font-weight-bold text-uppercase small">System Context</span>
        </div>
        <div className="card-body py-3">
          {renderContent(context)}
        </div>
      </div>
    );
  };

  return (
    <div ref={backdropRef} className="context-modal-backdrop">
      <div className="context-modal bg-white rounded shadow-lg overflow-hidden">
        <div className="d-flex align-items-center justify-content-between p-3 bg-light border-bottom">
          <div className="d-flex align-items-center">
            <div className="bg-info text-white rounded d-flex align-items-center justify-content-center mr-3" style={{ width: '40px', height: '40px' }}>
              <i className="fas fa-layer-group"></i>
            </div>
            <div>
              <h5 className="mb-0 font-weight-bold">Context Sent to AI</h5>
              <small className="text-muted">System instructions provided with this message</small>
            </div>
          </div>
          <button className="btn btn-outline-secondary btn-sm" onClick={onHide} title="Close (Esc)">
            <i className="fas fa-times"></i>
          </button>
        </div>
        
        <div className="context-modal-body p-3">
          {parseContext(message.sent_context)}
        </div>
        
        <div className="d-flex align-items-center justify-content-between p-3 bg-light border-top">
          <small className="text-muted">
            <i className="fas fa-info-circle mr-1"></i>
            This context was sent to the AI model along with the conversation history
          </small>
          <div className="btn-group">
            <Button variant="outline-secondary" size="sm" onClick={() => handleCopy('raw')} title="Copy raw JSON data">
              <i className={`fas ${copied && copyType === 'raw' ? 'fa-check text-success' : 'fa-code'} mr-1`}></i>
              {copied && copyType === 'raw' ? 'Copied!' : 'Copy Raw'}
            </Button>
            <Button variant="info" size="sm" onClick={() => handleCopy('formatted')} title="Copy formatted text">
              <i className={`fas ${copied && copyType === 'formatted' ? 'fa-check' : 'fa-copy'} mr-1`}></i>
              {copied && copyType === 'formatted' ? 'Copied!' : 'Copy Text'}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
};
