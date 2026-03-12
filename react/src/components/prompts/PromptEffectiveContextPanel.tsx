import React, { useState } from 'react';
import { Badge, Button } from 'react-bootstrap';
import type { PromptMessage } from './promptTypes';

interface PromptEffectiveContextPanelProps {
  effectiveContext?: PromptMessage[] | Record<string, unknown> | null;
  title?: string;
  colorIndex?: number;
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
  colorIndex = 0,
}) => {
  const [expanded, setExpanded] = useState(false);
  const palette = ['#0d6efd', '#20c997', '#fd7e14', '#6f42c1', '#d63384', '#198754'];
  const borderColor = palette[colorIndex % palette.length];

  if (!effectiveContext) {
    return null;
  }

  if (!Array.isArray(effectiveContext)) {
    return (
      <div className="prompt-effective-context-card border rounded p-3 mt-3" style={{ borderLeft: `4px solid ${borderColor}` }}>
        <div className="d-flex justify-content-between align-items-center">
          <div className="small font-weight-bold text-muted">{title}</div>
          <div>
            <Button
              size="sm"
              variant="outline-secondary"
              className="mr-2"
              onClick={() => navigator.clipboard.writeText(stringify(effectiveContext))}
            >
              <i className="fas fa-copy mr-1"></i>
              Copy
            </Button>
            <Button
              size="sm"
              variant="outline-secondary"
              onClick={() => setExpanded((current) => !current)}
            >
              {expanded ? 'Collapse' : 'Expand'}
            </Button>
          </div>
        </div>
        {expanded && (
          <pre className="small bg-light border rounded p-3 mt-2 mb-0">
            {stringify(effectiveContext)}
          </pre>
        )}
      </div>
    );
  }

  return (
    <div className="prompt-effective-context-card border rounded p-3 mt-3" style={{ borderLeft: `4px solid ${borderColor}` }}>
      <div className="d-flex justify-content-between align-items-center">
        <div className="small font-weight-bold text-muted">{title}</div>
        <div>
          <Button
            size="sm"
            variant="outline-secondary"
            className="mr-2"
            onClick={() => navigator.clipboard.writeText(stringify(effectiveContext))}
          >
            <i className="fas fa-copy mr-1"></i>
            Copy
          </Button>
          <Button
            size="sm"
            variant="outline-secondary"
            onClick={() => setExpanded((current) => !current)}
          >
            {expanded ? 'Collapse' : 'Expand'}
          </Button>
        </div>
      </div>
      {expanded && (
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
      )}
    </div>
  );
};
