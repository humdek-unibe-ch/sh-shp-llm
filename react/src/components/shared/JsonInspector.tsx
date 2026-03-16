import React, { useMemo, useState } from 'react';
import { normalizeEscapedText } from '../../utils/text';
import './JsonInspector.css';

interface JsonInspectorProps {
  value: unknown;
  className?: string;
  emptyLabel?: string;
  defaultTreeMode?: boolean;
  maxAutoParseDepth?: number;
}

interface ParsedValue {
  kind: 'json' | 'text' | 'empty';
  jsonValue: unknown | null;
  textValue: string;
}

function parseJsonCandidate(value: unknown, maxDepth: number): ParsedValue {
  if (value == null) {
    return { kind: 'empty', jsonValue: null, textValue: '' };
  }

  if (typeof value !== 'string') {
    return { kind: 'json', jsonValue: value, textValue: JSON.stringify(value, null, 2) };
  }

  let candidate = value.trim();
  if (candidate === '') {
    return { kind: 'empty', jsonValue: null, textValue: '' };
  }

  candidate = candidate.replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/, '');

  let current: unknown = candidate;
  for (let i = 0; i < maxDepth; i += 1) {
    if (typeof current !== 'string') {
      break;
    }

    const next = current.trim();
    if (next === '') {
      return { kind: 'empty', jsonValue: null, textValue: '' };
    }

    const startsLikeJson = next.startsWith('{') || next.startsWith('[') || next.startsWith('"');
    if (!startsLikeJson) {
      return { kind: 'text', jsonValue: null, textValue: normalizeEscapedText(next) };
    }

    try {
      current = JSON.parse(next);
    } catch {
      return { kind: 'text', jsonValue: null, textValue: normalizeEscapedText(next) };
    }
  }

  if (typeof current === 'string') {
    return { kind: 'text', jsonValue: null, textValue: normalizeEscapedText(current) };
  }

  return { kind: 'json', jsonValue: current, textValue: JSON.stringify(current, null, 2) };
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
