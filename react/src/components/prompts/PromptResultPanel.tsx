import React from 'react';
import { Alert, Badge } from 'react-bootstrap';
import { StructuredResponseRenderer } from '../styles/shared/StructuredResponseRenderer';
import { MarkdownRenderer } from '../styles/shared/MarkdownRenderer';
import { isStructuredResponse, parseStructuredResponse } from '../../utils/llmResponseUtils';
import type { PromptPlaygroundRun } from './promptTypes';

interface PromptResultPanelProps {
  run: PromptPlaygroundRun;
}

function safeStringify(value: unknown): string {
  if (typeof value === 'string') {
    try {
      return JSON.stringify(JSON.parse(value), null, 2);
    } catch {
      return value;
    }
  }

  try {
    return JSON.stringify(value, null, 2);
  } catch {
    return String(value);
  }
}

export const PromptResultPanel: React.FC<PromptResultPanelProps> = ({ run }) => {
  const parsed = run.parsed_response && typeof run.parsed_response === 'object'
    ? run.parsed_response
    : parseStructuredResponse(run.raw_content);
  const canRenderStructured = !!parsed && isStructuredResponse(parsed);

  return (
    <div className="prompt-result-panel border rounded p-3 bg-white">
      <div className="d-flex justify-content-between align-items-start flex-wrap mb-3">
        <div>
          <div className="font-weight-bold text-dark">{run.model}</div>
          <div className="small text-muted">
            {run.tokens_used ? `${run.tokens_used} tokens` : 'Tokens unavailable'}
          </div>
        </div>
        <div>
          {run.is_fallback && (
            <Badge variant="warning" className="mr-2">
              Fallback
            </Badge>
          )}
          {run.safety && (
            <Badge variant="info">
              Structured
            </Badge>
          )}
        </div>
      </div>

      {run.parse_errors && run.parse_errors.length > 0 && (
        <Alert variant="warning" className="small py-2">
          {run.parse_errors.join(' ')}
        </Alert>
      )}

      <div className="prompt-result-rendered mb-3">
        <div className="small font-weight-bold text-muted mb-2">Rendered Result</div>
        <div className="border rounded p-3 bg-light">
          {canRenderStructured ? (
            <StructuredResponseRenderer response={parsed} />
          ) : (
            <MarkdownRenderer content={run.display_content || run.raw_content || ''} />
          )}
        </div>
      </div>

      <details className="mb-2">
        <summary className="small font-weight-bold text-muted">Raw Response</summary>
        <pre className="small bg-light border rounded p-3 mt-2 mb-0 prompt-pre">
          {safeStringify(run.raw_content)}
        </pre>
      </details>

      <details>
        <summary className="small font-weight-bold text-muted">Request Payload</summary>
        <pre className="small bg-light border rounded p-3 mt-2 mb-0 prompt-pre">
          {safeStringify(run.request_payload || {})}
        </pre>
      </details>
    </div>
  );
};
