import React from 'react';
import { Badge } from 'react-bootstrap';
import type { PromptMessage } from './promptTypes';

interface PromptEffectiveContextPanelProps {
  effectiveContext?: PromptMessage[] | Record<string, unknown> | null;
  title?: string;
}

function stringify(value: unknown): string {
  if (typeof value === 'string') {
    return value;
  }

  try {
    return JSON.stringify(value, null, 2);
  } catch {
    return String(value);
  }
}

export const PromptEffectiveContextPanel: React.FC<PromptEffectiveContextPanelProps> = ({
  effectiveContext,
  title = 'Effective Context',
}) => {
  if (!effectiveContext) {
    return null;
  }

  if (!Array.isArray(effectiveContext)) {
    return (
      <details className="prompt-effective-context mt-3">
        <summary className="small font-weight-bold text-muted">{title}</summary>
        <div className="position-relative mt-2">
          <button
            type="button"
            className="btn btn-sm btn-outline-secondary prompt-copy-btn"
            onClick={() => navigator.clipboard.writeText(stringify(effectiveContext))}
          >
            <i className="fas fa-copy mr-1"></i>
            Copy
          </button>
          <pre className="small bg-light border rounded p-3 mb-0">
            {stringify(effectiveContext)}
          </pre>
        </div>
      </details>
    );
  }

  return (
    <details className="prompt-effective-context mt-3">
      <summary className="small font-weight-bold text-muted">{title}</summary>
      <div className="mt-2">
        <div className="d-flex justify-content-end mb-2">
          <button
            type="button"
            className="btn btn-sm btn-outline-secondary prompt-copy-btn"
            onClick={() => navigator.clipboard.writeText(stringify(effectiveContext))}
          >
            <i className="fas fa-copy mr-1"></i>
            Copy
          </button>
        </div>
        {effectiveContext.map((message, index) => (
          <div key={`${message.role}-${index}`} className="prompt-effective-message border rounded p-2 mb-2">
            <div className="mb-2">
              <Badge variant="secondary">{message.role}</Badge>
            </div>
            <pre className="small mb-0 prompt-pre">{stringify(message.content)}</pre>
          </div>
        ))}
      </div>
    </details>
  );
};
