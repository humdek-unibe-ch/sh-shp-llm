import React, { useMemo, useState } from 'react';
import { normalizeEscapedText } from '../../utils/text';
import { parseJsonCandidate } from '../../utils/jsonInspector';
import './JsonInspector.css';

interface JsonInspectorProps {
  value: unknown;
  className?: string;
  emptyLabel?: string;
  defaultTreeMode?: boolean;
  maxAutoParseDepth?: number;
}

function JsonValueNode({
  label,
  value,
  depth,
}: {
  label?: string;
  value: unknown;
  depth: number;
}) {
  const isArray = Array.isArray(value);
  const isObject = !!value && typeof value === 'object' && !isArray;

  if (!isArray && !isObject) {
    const maybeNested =
      typeof value === 'string'
        ? parseJsonCandidate(value, 2)
        : { kind: 'text' as const, jsonValue: null, textValue: '' };
    const hasNestedJson = maybeNested.kind === 'json' && maybeNested.jsonValue && typeof maybeNested.jsonValue === 'object';

    if (hasNestedJson) {
      return (
        <details className="json-node json-node-branch" open={depth <= 1}>
          <summary className="json-summary">
            {label ? <span className="json-key">{label}: </span> : null}
            <span className="json-branch-type">JSON string</span>
          </summary>
          <div className="json-children">
            <JsonValueNode value={maybeNested.jsonValue} depth={depth + 1} />
          </div>
        </details>
      );
    }

    return (
      <div className="json-node json-node-leaf">
        {label ? <span className="json-key">{label}: </span> : null}
        <span className={`json-value json-value-${typeof value}`}>
          {typeof value === 'string' ? `"${normalizeEscapedText(value)}"` : String(value)}
        </span>
      </div>
    );
  }

  const entries = isArray
    ? (value as unknown[]).map((item, index) => [String(index), item] as const)
    : Object.entries(value as Record<string, unknown>);

  const typeLabel = isArray ? `Array(${entries.length})` : `Object(${entries.length})`;
  const defaultOpen = depth <= 1;

  return (
    <details className="json-node json-node-branch" open={defaultOpen}>
      <summary className="json-summary">
        {label ? <span className="json-key">{label}: </span> : null}
        <span className="json-branch-type">{typeLabel}</span>
      </summary>
      <div className="json-children">
        {entries.length === 0 ? (
          <div className="json-node json-node-leaf">
            <span className="json-value json-value-empty">{isArray ? '[]' : '{}'}</span>
          </div>
        ) : (
          entries.map(([key, child]) => (
            <JsonValueNode key={`${label || 'root'}-${key}`} label={key} value={child} depth={depth + 1} />
          ))
        )}
      </div>
    </details>
  );
}

export const JsonInspector: React.FC<JsonInspectorProps> = ({
  value,
  className = '',
  emptyLabel = 'No data.',
  defaultTreeMode = true,
  maxAutoParseDepth = 3,
}) => {
  const parsed = useMemo(() => parseJsonCandidate(value, maxAutoParseDepth), [value, maxAutoParseDepth]);
  const [treeMode, setTreeMode] = useState(defaultTreeMode);
  const [copied, setCopied] = useState(false);

  const handleCopy = async () => {
    const textToCopy = parsed.kind === 'json'
      ? parsed.textValue
      : parsed.textValue || String(value ?? '');
    try {
      await navigator.clipboard.writeText(textToCopy);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1800);
    } catch {
      // no-op fallback for environments without clipboard support
    }
  };

  if (parsed.kind === 'empty') {
    return <div className={`json-inspector-empty text-muted small ${className}`}>{emptyLabel}</div>;
  }

  if (parsed.kind === 'text') {
    return <pre className={`json-inspector-pre mb-0 ${className}`}>{parsed.textValue}</pre>;
  }

  return (
    <div className={`json-inspector ${className}`}>
      <div className="json-inspector-toolbar">
        <button
          type="button"
          className={`btn btn-xs ${treeMode ? 'btn-primary' : 'btn-outline-primary'}`}
          onClick={() => setTreeMode(true)}
        >
          Tree
        </button>
        <button
          type="button"
          className={`btn btn-xs ml-2 ${!treeMode ? 'btn-primary' : 'btn-outline-primary'}`}
          onClick={() => setTreeMode(false)}
        >
          Raw
        </button>
        <button
          type="button"
          className={`btn btn-xs ml-auto ${copied ? 'btn-success' : 'btn-outline-secondary'}`}
          onClick={handleCopy}
          title="Copy full raw data"
        >
          <i className={`fas ${copied ? 'fa-check' : 'fa-copy'} mr-1`}></i>
          {copied ? 'Copied' : 'Copy'}
        </button>
      </div>
      {treeMode ? (
        <div className="json-inspector-tree">
          <JsonValueNode value={parsed.jsonValue} depth={0} />
        </div>
      ) : (
        <pre className="json-inspector-pre mb-0">{parsed.textValue}</pre>
      )}
    </div>
  );
};

export function normalizeGeneratedPromptTemplate(value: unknown): string {
  const parsed = parseJsonCandidate(value, 3);
  if (parsed.kind === 'empty') {
    return '';
  }

  if (parsed.kind === 'json' && parsed.jsonValue && typeof parsed.jsonValue === 'object') {
    const obj = parsed.jsonValue as Record<string, unknown>;
    const preferredKeys = ['prompt_template', 'template', 'prompt', 'generated_prompt', 'context'];
    for (const key of preferredKeys) {
      if (typeof obj[key] === 'string' && String(obj[key]).trim() !== '') {
        return normalizeEscapedText(String(obj[key]));
      }
    }
    return JSON.stringify(obj, null, 2);
  }

  return normalizeEscapedText(parsed.textValue);
}
