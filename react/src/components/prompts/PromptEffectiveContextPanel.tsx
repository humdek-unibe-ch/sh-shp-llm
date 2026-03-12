import React from 'react';
import { Badge } from 'react-bootstrap';
import type { PromptMessage } from './promptTypes';

interface PromptEffectiveContextPanelProps {
  effectiveContext?: PromptMessage[] | Record<string, unknown> | null;
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
}) => {
  if (!effectiveContext) {
    return null;
  }

  if (!Array.isArray(effectiveContext)) {
    return (
      <details className="prompt-effective-context mt-3">
        <summary className="small font-weight-bold text-muted">Effective Context</summary>
        <pre className="small bg-light border rounded p-3 mt-2 mb-0">
          {stringify(effectiveContext)}
        </pre>
      </details>
    );
  }

  return (
    <details className="prompt-effective-context mt-3">
      <summary className="small font-weight-bold text-muted">Effective Context</summary>
      <div className="mt-2">
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

