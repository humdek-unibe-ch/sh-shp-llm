import React from 'react';
import { Alert, Badge } from 'react-bootstrap';
import { StructuredResponseRenderer } from '../styles/shared/StructuredResponseRenderer';
import { MarkdownRenderer } from '../styles/shared/MarkdownRenderer';
import { isStructuredResponse, parseStructuredResponse } from '../../utils/llmResponseUtils';
import type { PromptPlaygroundRun } from './promptTypes';

interface PromptResultPanelProps {
  run: PromptPlaygroundRun;
  colorIndex?: number;
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

function colorFromIndex(index: number): string {
  const palette = ['#0d6efd', '#20c997', '#fd7e14', '#6f42c1', '#d63384', '#198754'];
  return palette[index % palette.length];
}

export const PromptResultPanel: React.FC<PromptResultPanelProps> = ({ run, colorIndex = 0 }) => {
  const color = colorFromIndex(colorIndex);
  const parsedFromRun = run.parsed_response && typeof run.parsed_response === 'object'
    ? run.parsed_response
    : null;
  const parsed = parsedFromRun && isStructuredResponse(parsedFromRun)
    ? (parsedFromRun as any)
    : parseStructuredResponse(run.raw_content);
  const normalizedStructured = parsed && isStructuredResponse(parsed)
    ? ({
      ...parsed,
      meta: (parsed as any).meta || {},
    } as any)
    : null;
  const canRenderStructured = !!normalizedStructured;

  return (
    <div
      className="prompt-result-panel border rounded p-3 bg-white"
      style={{ borderLeft: `4px solid ${color}` }}
    >
      <div className="d-flex justify-content-between align-items-start flex-wrap mb-3">
        <div>
          <div className="font-weight-bold text-dark d-flex align-items-center">
            <span className="prompt-model-dot mr-2" style={{ backgroundColor: color }}></span>
            {run.model}
          </div>
          <div className="small text-muted">
            {run.tokens_used ? `${run.tokens_used} tokens` : 'Tokens unavailable'}
            {typeof run.duration_ms === 'number' ? ` • ${run.duration_ms} ms` : ''}
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
            <StructuredResponseRenderer response={normalizedStructured as any} />
          ) : (
            <MarkdownRenderer content={run.display_content || run.raw_content || ''} />
          )}
        </div>
      </div>

      <details className="mb-2">
        <summary className="small font-weight-bold text-muted">Raw Response</summary>
        <div className="position-relative mt-2">
          <button
            type="button"
            className="btn btn-sm btn-outline-secondary prompt-copy-btn"
            onClick={() => navigator.clipboard.writeText(safeStringify(run.raw_content))}
          >
            <i className="fas fa-copy mr-1"></i>
            Copy
          </button>
          <pre className="small bg-light border rounded p-3 mb-0 prompt-pre">
            {safeStringify(run.raw_content)}
          </pre>
        </div>
      </details>

      <details>
        <summary className="small font-weight-bold text-muted">Request Payload</summary>
        <div className="position-relative mt-2">
          <button
            type="button"
            className="btn btn-sm btn-outline-secondary prompt-copy-btn"
            onClick={() => navigator.clipboard.writeText(safeStringify(run.request_payload || {}))}
          >
            <i className="fas fa-copy mr-1"></i>
            Copy
          </button>
          <pre className="small bg-light border rounded p-3 mb-0 prompt-pre">
            {safeStringify(run.request_payload || {})}
          </pre>
        </div>
      </details>
    </div>
  );
};
