/**
 * Payload Popup Component
 * =======================
 * 
 * Displays the raw API request payload that was sent to the LLM API.
 * Useful for debugging, testing in Postman, and inspecting failed validations.
 * 
 * Extracted from AdminConsole.tsx for modularity.
 * 
 * @module components/admin/PayloadPopup
 */

import React, { useState } from 'react';
import { Button, Badge } from 'react-bootstrap';
import { useModalDismiss } from '../../hooks/useModalDismiss';
import { JsonInspector } from '../shared/JsonInspector';
import type { Message } from '../../types';

interface PayloadPopupProps {
  message: Message;
  show: boolean;
  onHide: () => void;
}

/** Fetch or retrieve payload popup data. */
export const PayloadPopup: React.FC<PayloadPopupProps> = ({ message, show, onHide }) => {
  const [copied, setCopied] = useState(false);
  const backdropRef = useModalDismiss(show, onHide);

  // Safety check
  if (!message || !message.request_payload || typeof message.request_payload !== 'string' || message.request_payload.trim() === '') {
    return null;
  }

  if (!show) return null;

  const handleCopy = async () => {
    if (message.request_payload) {
      try {
        let textToCopy = message.request_payload;
        try {
          const parsed = JSON.parse(message.request_payload);
          textToCopy = JSON.stringify(parsed, null, 2);
        } catch {
          // Keep original if not valid JSON
        }
        
        await navigator.clipboard.writeText(textToCopy);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
      } catch (err) {
        console.error('Failed to copy payload:', err);
      }
    }
  };

  const formatPayload = (payload: string) => {
    try {
      const parsed = JSON.parse(payload);
      
      if (Array.isArray(parsed)) {
        return (
          <div className="payload-messages">
            {parsed.map((msg, index) => (
              <div key={index} className="card mb-3">
                <div className="card-header bg-light py-2 d-flex align-items-center">
                  <i className={`fas ${msg.role === 'system' ? 'fa-cogs' : msg.role === 'user' ? 'fa-user' : 'fa-robot'} mr-2 text-primary`}></i>
                  <span className="font-weight-bold text-uppercase small">
                    {msg.role?.charAt(0).toUpperCase() + msg.role?.slice(1) || 'Unknown'}
                  </span>
                  <span className="ml-auto badge badge-secondary">Message {index + 1}</span>
                </div>
                <div className="card-body py-2">
                  <JsonInspector
                    value={typeof msg.content === 'string' ? msg.content : msg.content ?? ''}
                    className="small"
                  />
                </div>
              </div>
            ))}
          </div>
        );
      }
      
      return <JsonInspector value={parsed} className="small" />;
    } catch {
      return <JsonInspector value={payload} className="small" />;
    }
  };

  // Check if message passed validation (handles string values from DB)
  const val = message.is_validated;
  const isValidated = val === true || val === 1 || val === '1';

  return (
    <div ref={backdropRef} className="context-modal-backdrop">
      <div className="context-modal bg-white rounded shadow-lg overflow-hidden" style={{ maxWidth: '900px' }}>
        <div className="d-flex align-items-center justify-content-between p-3 bg-light border-bottom">
          <div className="d-flex align-items-center">
            <div className={`${isValidated ? 'bg-primary' : 'bg-warning'} text-white rounded d-flex align-items-center justify-content-center mr-3`} style={{ width: '40px', height: '40px' }}>
              <i className="fas fa-paper-plane"></i>
            </div>
            <div>
              <h5 className="mb-0 font-weight-bold">
                API Request Payload
                {!isValidated && (
                  <Badge variant="warning" className="ml-2">
                    <i className="fas fa-exclamation-triangle mr-1"></i>
                    Failed Validation
                  </Badge>
                )}
              </h5>
              <small className="text-muted">The exact payload sent to the LLM API (copy for Postman/testing)</small>
            </div>
          </div>
          <button className="btn btn-outline-secondary btn-sm" onClick={onHide} title="Close (Esc)">
            <i className="fas fa-times"></i>
          </button>
        </div>
        
        <div className="context-modal-body p-3">
          {formatPayload(message.request_payload)}
        </div>
        
        <div className="d-flex align-items-center justify-content-between p-3 bg-light border-top">
          <small className="text-muted">
            <i className="fas fa-info-circle mr-1"></i>
            Copy this payload to test in Postman or other API tools
          </small>
          <Button variant="primary" size="sm" onClick={handleCopy} title="Copy payload as JSON">
            <i className={`fas ${copied ? 'fa-check' : 'fa-copy'} mr-1`}></i>
            {copied ? 'Copied!' : 'Copy Payload'}
          </Button>
        </div>
      </div>
    </div>
  );
};
