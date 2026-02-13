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

export const ContextPopup: React.FC<ContextPopupProps> = ({ message, show, onHide }) => {
  const [copied, setCopied] = useState(false);
  const [copyType, setCopyType] = useState<'raw' | 'formatted'>('formatted');
  const backdropRef = useModalDismiss(show, onHide);

  // Safety check for null message
  if (!message || !message.sent_context || typeof message.sent_context !== 'string' || message.sent_context.trim() === '') {
    return null;
  }

  if (!show) return null;

  const handleCopy = async (type: 'raw' | 'formatted') => {
    if (message.sent_context) {
      try {
        let textToCopy = message.sent_context;
        
        if (type === 'formatted') {
          try {
            const parsed = JSON.parse(message.sent_context);
            if (Array.isArray(parsed)) {
              textToCopy = parsed
                .filter((item) => item && typeof item === 'object' && item.content)
                .map((item) => {
                  const plainText = item.content.replace(/<[^>]*>/g, '');
                  return `[${item.role?.toUpperCase() || 'SYSTEM'}]\n${plainText}`;
                })
                .join('\n\n---\n\n');
            } else if (parsed && typeof parsed === 'object' && parsed.content) {
              const plainText = parsed.content.replace(/<[^>]*>/g, '');
              textToCopy = `[${parsed.role?.toUpperCase() || 'SYSTEM'}]\n${plainText}`;
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

  const parseContext = (context: string) => {
    try {
      const parsed = JSON.parse(context);
      if (Array.isArray(parsed)) {
        const validMessages = parsed
          .filter((item) => item && typeof item === 'object' && item.content)
          .map((item, index) => (
            <div key={index} className="card mb-3">
              <div className="card-header bg-light py-2 d-flex align-items-center">
                <i className={`fas ${item.role === 'system' ? 'fa-cogs' : item.role === 'user' ? 'fa-user' : 'fa-robot'} mr-2 text-info`}></i>
                <span className="font-weight-bold text-uppercase small">
                  {item.role?.charAt(0).toUpperCase() + item.role?.slice(1) || 'System'}
                </span>
              </div>
              <div className="card-body py-3">
                {renderContent(item.content)}
              </div>
            </div>
          ));

        if (validMessages.length > 0) return validMessages;
      } else if (parsed && typeof parsed === 'object' && parsed.content) {
        return (
          <div className="card">
            <div className="card-header bg-light py-2 d-flex align-items-center">
              <i className={`fas ${parsed.role === 'system' ? 'fa-cogs' : parsed.role === 'user' ? 'fa-user' : 'fa-robot'} mr-2 text-info`}></i>
              <span className="font-weight-bold text-uppercase small">
                {parsed.role?.charAt(0).toUpperCase() + parsed.role?.slice(1) || 'System'}
              </span>
            </div>
            <div className="card-body py-3">
              {renderContent(parsed.content)}
            </div>
          </div>
        );
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
