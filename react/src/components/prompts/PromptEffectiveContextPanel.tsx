/**
 * Prompt Effective Context Panel — inspects the full API context sent to the LLM.
 *
 * Shows the assembled system message, conversation history, and runtime
 * values as a collapsible JSON tree. Useful for debugging prompt behaviour.
 *
 * @module components/prompts/PromptEffectiveContextPanel
 */
import React, { useState } from 'react';
import { Badge, Button } from 'react-bootstrap';
import { JsonInspector } from '../shared/JsonInspector';
import type { PromptMessage } from './promptTypes';

interface PromptEffectiveContextPanelProps {
  effectiveContext?: PromptMessage[] | Record<string, unknown> | null;
  title?: string;
  colorIndex?: number;
}

/** stringify function. */
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

/** Panel component for prompt effective context panel. */
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
          <div className="mt-2">
            <JsonInspector value={effectiveContext} className="small" />
          </div>
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
              <JsonInspector value={message.content} className="small" />
            </div>
          ))}
        </div>
      )}
    </div>
  );
};
