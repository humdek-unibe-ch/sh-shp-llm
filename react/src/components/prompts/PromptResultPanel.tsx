/**
 * Prompt Result Panel — displays playground execution results.
 *
 * Renders the LLM response as markdown/structured content, shows model
 * metadata (tokens, latency), and provides a "Save as test case" action.
 *
 * @module components/prompts/PromptResultPanel
 */
import React from 'react';
import { Alert, Badge } from 'react-bootstrap';
import { StructuredResponseRenderer } from '../styles/shared/StructuredResponseRenderer';
import { MarkdownRenderer } from '../styles/shared/MarkdownRenderer';
import { JsonInspector } from '../shared/JsonInspector';
import { isStructuredResponse, parseStructuredResponse } from '../../utils/llmResponseUtils';
import type { PromptPlaygroundRun } from './promptTypes';

interface PromptResultPanelProps {
  run: PromptPlaygroundRun;
  colorIndex?: number;
}

/** colorFromIndex function. */
function colorFromIndex(index: number): string {
  const palette = ['#0d6efd', '#20c997', '#fd7e14', '#6f42c1', '#d63384', '#198754'];
  return palette[index % palette.length];
}

/**
 * Coerce a raw_content payload that may have been parsed into an object on
 * the wire back into a markdown-displayable string. Empty/missing values
 * resolve to '' so the markdown renderer never blows up.
 */
function rawContentToString(raw: unknown): string {
  if (raw == null) {
    return '';
  }
  if (typeof raw === 'string') {
    return raw;
  }
  try {
    return '```json\n' + JSON.stringify(raw, null, 2) + '\n```';
  } catch {
    return String(raw);
  }
}

/** Panel component for prompt result panel. */
export const PromptResultPanel: React.FC<PromptResultPanelProps> = ({ run, colorIndex = 0 }) => {
  const color = colorFromIndex(colorIndex);
  const parsedFromRun = run.parsed_response && typeof run.parsed_response === 'object'
    ? run.parsed_response
    : null;
  const rawContentString = rawContentToString(run.raw_content);
  const parsed = parsedFromRun && isStructuredResponse(parsedFromRun)
    ? (parsedFromRun as any)
    : parseStructuredResponse(rawContentString);
  const normalizedStructured = parsed && isStructuredResponse(parsed)
    ? ({
      ...parsed,
      meta: (parsed as any).meta || {},
    } as any)
    : null;
  const canRenderStructured = !!normalizedStructured;
  const markdownContent = run.display_content && run.display_content.trim() !== ''
    ? run.display_content
    : rawContentString;
  const hasAnyContent = canRenderStructured || markdownContent.trim() !== '';

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
          ) : hasAnyContent ? (
            <MarkdownRenderer content={markdownContent} />
          ) : (
            <div className="text-muted small">
              <em>No content returned from the model.</em>
              {' '}Inspect the Raw Response and Request Payload sections below for diagnostics.
            </div>
          )}
        </div>
      </div>

      <details className="mb-2">
        <summary className="small font-weight-bold text-muted">Raw Response</summary>
        <div className="position-relative mt-2">
          <button
            type="button"
            className="btn btn-sm btn-outline-secondary prompt-copy-btn"
            onClick={() => navigator.clipboard.writeText(typeof run.raw_content === 'string' ? run.raw_content : JSON.stringify(run.raw_content ?? {}, null, 2))}
          >
            <i className="fas fa-copy mr-1"></i>
            Copy
          </button>
          <div className="small bg-light border rounded p-3 mb-0 prompt-pre">
            <JsonInspector value={run.raw_content} />
          </div>
        </div>
      </details>

      <details>
        <summary className="small font-weight-bold text-muted">Request Payload</summary>
        <div className="position-relative mt-2">
          <button
            type="button"
            className="btn btn-sm btn-outline-secondary prompt-copy-btn"
            onClick={() => navigator.clipboard.writeText(JSON.stringify(run.request_payload || {}, null, 2))}
          >
            <i className="fas fa-copy mr-1"></i>
            Copy
          </button>
          <div className="small bg-light border rounded p-3 mb-0 prompt-pre">
            <JsonInspector value={run.request_payload || {}} />
          </div>
        </div>
      </details>
    </div>
  );
};
