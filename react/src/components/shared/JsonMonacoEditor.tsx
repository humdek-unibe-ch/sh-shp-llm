/**
 * JsonMonacoEditor — JSON text editor with live validation and formatting.
 *
 * Wraps the PromptEditor code-mirror component in JSON mode, adding
 * real-time parse-error feedback, a format/minify toolbar, and an
 * optional callback for valid parsed output.
 *
 * Used in the Scripts Manager, Dataset forms, and Evaluation definitions
 * wherever structured JSON needs to be edited by an admin.
 *
 * @module components/shared/JsonMonacoEditor
 */
import React, { useMemo } from 'react';
import { Alert, Button } from 'react-bootstrap';
import { PromptEditor } from '../prompts/PromptEditor';

/** Props for the JsonMonacoEditor component. */
interface JsonMonacoEditorProps {
  value: string;
  onChange: (value: string) => void;
  onValidParsed?: (value: unknown) => void;
  expectObject?: boolean;
  minHeight?: number;
  disabled?: boolean;
  className?: string;
  showToolbar?: boolean;
}

/** tryParseJson utility. */
function tryParseJson(value: string): { parsed: unknown | null; error: string | null } {
  if (!value.trim()) {
    return { parsed: null, error: null };
  }

  try {
    return { parsed: JSON.parse(value), error: null };
  } catch (error) {
    return { parsed: null, error: error instanceof Error ? error.message : 'Invalid JSON' };
  }
}

/** formatJson utility. */
function formatJson(value: string): string {
  const parsed = JSON.parse(value);
  return JSON.stringify(parsed, null, 2);
}

/** JsonMonacoEditor component. */
export const JsonMonacoEditor: React.FC<JsonMonacoEditorProps> = ({
  value,
  onChange,
  onValidParsed,
  expectObject = false,
  minHeight = 180,
  disabled = false,
  className = '',
  showToolbar = true,
}) => {
  const parseState = useMemo(() => {
    const state = tryParseJson(value);
    if (state.error || !expectObject || state.parsed == null) {
      return state;
    }
    if (typeof state.parsed !== 'object' || Array.isArray(state.parsed)) {
      return { parsed: null, error: 'Expected a JSON object' };
    }
    return state;
  }, [expectObject, value]);

  const handleChange = (nextValue: string) => {
    onChange(nextValue);
    const state = tryParseJson(nextValue);
    if (!state.error && state.parsed != null) {
      if (!expectObject || (typeof state.parsed === 'object' && !Array.isArray(state.parsed))) {
        onValidParsed?.(state.parsed);
      }
    }
  };

  const handleFormat = () => {
    try {
      const formatted = formatJson(value);
      onChange(formatted);
      const parsed = JSON.parse(formatted);
      if (!expectObject || (typeof parsed === 'object' && !Array.isArray(parsed))) {
        onValidParsed?.(parsed);
      }
    } catch {
      // Keep current value unchanged when format fails.
    }
  };

  const canFormat = !parseState.error && value.trim() !== '';

  return (
    <div className={`json-monaco-editor ${className}`.trim()}>
      {showToolbar && (
        <div className="json-monaco-toolbar">
          <Button
            size="sm"
            variant="outline-secondary"
            disabled={disabled || !canFormat}
            onClick={handleFormat}
          >
            <i className="fas fa-magic mr-1"></i>
            Format
          </Button>
          <Button
            size="sm"
            variant="outline-secondary"
            className="ml-2"
            disabled={disabled}
            onClick={() => navigator.clipboard.writeText(value || '')}
          >
            <i className="fas fa-copy mr-1"></i>
            Copy
          </Button>
        </div>
      )}
      <PromptEditor
        value={value}
        onChange={handleChange}
        editorMode="monaco"
        language="json"
        minHeight={minHeight}
        disabled={disabled}
      />
      {parseState.error && (
        <Alert variant="warning" className="py-1 px-2 small mt-2 mb-0">
          {parseState.error}
        </Alert>
      )}
    </div>
  );
};
