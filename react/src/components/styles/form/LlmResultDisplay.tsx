/**
 * LLM Result Display Component
 * ============================
 * 
 * Renders the LLM result in the configured panel type (default, card, modal, collapse).
 * Supports placement, closable behavior, custom CSS classes, and action buttons.
 * Uses the shared MarkdownRenderer for formatting LLM output.
 */

import React, { useState, useEffect } from 'react';
import { MarkdownRenderer } from '../shared/MarkdownRenderer';
import type { LlmFormConfig, LlmResultMeta } from '../../../types/form';

interface LlmResultDisplayProps {
  config: LlmFormConfig;
  result: string | null;
  meta: LlmResultMeta | null;
  loading: boolean;
  error: string | null;
  freshResponse?: boolean;
  onClose?: () => void;
  onRetry?: () => void;
  onRegenerate?: () => void;
}

export const LlmResultDisplay: React.FC<LlmResultDisplayProps> = ({
  config,
  result,
  meta,
  loading,
  error,
  freshResponse = false,
  onClose,
  onRetry,
  onRegenerate,
}) => {
  const [collapsed, setCollapsed] = useState(false);
  const [modalVisible, setModalVisible] = useState(false);

  useEffect(() => {
    if (freshResponse && config.llmResultPanel === 'modal' && result) {
      setModalVisible(true);
    }
  }, [freshResponse, result, config.llmResultPanel]);

  const extraCss = config.llmResultCss || '';
  const mobileCss = config.llmResultCssMobile || '';
  const containerClasses = `llm-result-panel llm-result-panel--${config.llmResultPanel} ${extraCss} ${mobileCss}`.trim();

  const renderContent = () => (
    <>
      {loading && (
        <div className="llm-result-loading d-flex align-items-center justify-content-center p-4">
          <div className="spinner-border spinner-border-sm text-primary mr-2" role="status">
            <span className="sr-only">{config.llmGeneratingText}</span>
          </div>
          <span className="text-muted">{config.llmGeneratingText}</span>
        </div>
      )}

      {error && config.llmShowErrors && (
        <div className="alert alert-danger alert-dismissible fade show mb-3" role="alert">
          <i className="fas fa-exclamation-circle mr-2"></i>
          {error}
          <button type="button" className="close" onClick={() => {}} data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
      )}

      {!loading && result && (
        <div className="llm-result-content">
          <MarkdownRenderer content={result} />
        </div>
      )}

      {!loading && (result || error) && (
        <div className="llm-result-actions mt-3 d-flex flex-wrap gap-2">
          {onRetry && error && (
            <button
              type="button"
              className="btn btn-outline-secondary btn-sm mr-2"
              onClick={onRetry}
              disabled={loading}
            >
              <i className="fas fa-redo mr-1"></i>
              {config.llmRetryLabel}
            </button>
          )}
          {onRegenerate && result && (
            <button
              type="button"
              className="btn btn-outline-primary btn-sm mr-2"
              onClick={onRegenerate}
              disabled={loading}
            >
              <i className="fas fa-sync-alt mr-1"></i>
              {config.llmRegenerateLabel}
            </button>
          )}
        </div>
      )}

      {!loading && meta && meta.status === 'success' && (
        <div className="llm-result-meta mt-2 small text-muted">
          {/* <span className="mr-3">
            <i className="fas fa-robot mr-1"></i>{meta.model}
          </span>
          {meta.tokens_used > 0 && (
            <span className="mr-3">
              <i className="fas fa-coins mr-1"></i>{meta.tokens_used} tokens
            </span>
          )} */}
          <span>
            <i className="fas fa-clock mr-1"></i>{meta.timestamp}
          </span>
        </div>
      )}
    </>
  );

  const renderCloseButton = () => {
    if (!config.llmResultClosable || !onClose) return null;
    return (
      <button type="button" className="close ml-auto" onClick={onClose} aria-label="Close">
        <span aria-hidden="true">&times;</span>
      </button>
    );
  };

  const renderTitle = () => {
    if (!config.llmResultTitle) return null;
    return <span className="llm-result-title font-weight-bold">{config.llmResultTitle}</span>;
  };

  // Panel: default (inline)
  if (config.llmResultPanel === 'default') {
    return (
      <div className={containerClasses}>
        <div className="d-flex align-items-center mb-2">
          {renderTitle()}
          {renderCloseButton()}
        </div>
        {renderContent()}
      </div>
    );
  }

  // Panel: card
  if (config.llmResultPanel === 'card') {
    return (
      <div className={`card ${containerClasses}`}>
        <div className="card-header d-flex align-items-center">
          {renderTitle()}
          {renderCloseButton()}
        </div>
        <div className="card-body">
          {renderContent()}
        </div>
      </div>
    );
  }

  // Panel: collapse
  if (config.llmResultPanel === 'collapse') {
    return (
      <div className={containerClasses}>
        <div
          className="d-flex align-items-center mb-2 llm-result-collapse-header"
          onClick={() => setCollapsed(!collapsed)}
          style={{ cursor: 'pointer' }}
          role="button"
          aria-expanded={!collapsed}
        >
          <i className={`fas fa-chevron-${collapsed ? 'right' : 'down'} mr-2`}></i>
          {renderTitle()}
          {renderCloseButton()}
        </div>
        {!collapsed && (
          <div className="llm-result-collapse-body">
            {renderContent()}
          </div>
        )}
      </div>
    );
  }

  // Panel: modal - only show as modal for fresh responses, otherwise render as card
  if (config.llmResultPanel === 'modal') {
    if (modalVisible) {
      return (
        <div className={`modal fade show d-block ${containerClasses}`} tabIndex={-1} role="dialog" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="modal-dialog modal-lg" role="document">
            <div className="modal-content">
              <div className="modal-header">
                <h5 className="modal-title">{config.llmResultTitle}</h5>
                {config.llmResultClosable && (
                  <button type="button" className="close" onClick={() => { setModalVisible(false); onClose?.(); }} aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                  </button>
                )}
              </div>
              <div className="modal-body">
                {renderContent()}
              </div>
            </div>
          </div>
        </div>
      );
    }
    // Previous result with modal panel: render as inline card instead
    return (
      <div className={`card ${containerClasses}`}>
        <div className="card-header d-flex align-items-center">
          {renderTitle()}
          {renderCloseButton()}
        </div>
        <div className="card-body">
          {renderContent()}
        </div>
      </div>
    );
  }

  return <div className={containerClasses}>{renderContent()}</div>;
};
