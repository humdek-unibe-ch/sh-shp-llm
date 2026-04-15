/**
 * JSON Inspector Utilities
 * ========================
 *
 * Parsing utilities for the JsonInspector component and admin popups.
 * Handles extraction and classification of JSON content from LLM responses,
 * context fields, and raw API payloads that may contain mixed text+JSON,
 * markdown fences, labeled sections, or double-encoded strings.
 *
 * @module utils/jsonInspector
 */

import { normalizeEscapedText } from './text';

/** Classified parse result — determines how the UI renders a value. */
export interface ParsedJsonValue {
  /** Whether the value is structured JSON, plain text, or empty */
  kind: 'json' | 'text' | 'empty';
  /** Parsed JSON object/array if `kind === 'json'` */
  jsonValue: unknown | null;
  /** Human-readable text representation */
  textValue: string;
}

/**
 * Extract the outermost balanced JSON object or array from a string.
 *
 * Walks the string character-by-character, tracking brace/bracket depth
 * and string escaping. Returns null if no balanced structure is found.
 *
 * @param value - Raw string potentially containing a JSON fragment
 * @returns The extracted JSON substring, or null
 */
export function extractLikelyJsonFragment(value: string): string | null {
  const input = value.trim();
  if (!input) return null;

  const startBrace = input.indexOf('{');
  const startBracket = input.indexOf('[');
  const candidates = [startBrace, startBracket].filter((candidate) => candidate >= 0);
  if (candidates.length === 0) return null;

  const start = Math.min(...candidates);
  const openChar = input[start];
  const closeChar = openChar === '{' ? '}' : ']';

  let depth = 0;
  let inString = false;
  let escaped = false;
  for (let index = start; index < input.length; index += 1) {
    const char = input[index];
    if (inString) {
      if (escaped) {
        escaped = false;
      } else if (char === '\\') {
        escaped = true;
      } else if (char === '"') {
        inString = false;
      }
      continue;
    }

    if (char === '"') {
      inString = true;
      continue;
    }

    if (char === openChar) depth += 1;
    if (char === closeChar) {
      depth -= 1;
      if (depth === 0) {
        return input.slice(start, index + 1);
      }
    }
  }

  return null;
}

/**
 * Attempt to parse content directly as JSON, stripping markdown code fences.
 *
 * @param content - Value to parse (only strings are processed)
 * @returns Parsed JSON value, or null if parsing fails
 */
export function tryParseDirectJson(content: unknown): unknown | null {
  if (typeof content !== 'string') return null;
  const trimmed = content.trim();
  if (!trimmed) return null;

  const withoutFence = trimmed
    .replace(/^```(?:json)?\s*/i, '')
    .replace(/\s*```$/i, '')
    .trim();
  if (!(withoutFence.startsWith('{') || withoutFence.startsWith('[') || withoutFence.startsWith('"'))) {
    return null;
  }

  try {
    return JSON.parse(withoutFence);
  } catch {
    return null;
  }
}

/**
 * Parse content that may contain labeled sections like "instructions: ... examples: [...]".
 *
 * Handles hybrid text+JSON content commonly found in LLM context fields
 * and prompt builder outputs. Falls back to generic fragment extraction.
 *
 * @param content - Raw string content
 * @returns Parsed object (possibly with `instructions` and `examples` keys), or null
 */
export function tryParseLabeledJsonContent(content: unknown): unknown | null {
  if (typeof content !== 'string') return null;

  const directJson = tryParseDirectJson(content);
  if (directJson !== null) {
    return directJson;
  }

  const trimmed = content.trim();
  if (!trimmed) return null;

  const instructionsMatch = trimmed.match(/instructions\s*:\s*([\s\S]*?)(?:\n+\s*examples\s*:|$)/i);
  const examplesLabelMatch = trimmed.match(/examples\s*:/i);
  if (examplesLabelMatch && instructionsMatch) {
    const examplesStart = examplesLabelMatch.index ?? -1;
    const fragment = examplesStart >= 0
      ? extractLikelyJsonFragment(trimmed.slice(examplesStart))
      : null;

    if (fragment) {
      try {
        return {
          instructions: instructionsMatch[1].trim(),
          examples: JSON.parse(fragment),
        };
      } catch {
        // fall through to generic fragment parsing
      }
    }
  }

  const fragment = extractLikelyJsonFragment(trimmed);
  if (!fragment) {
    return null;
  }

  try {
    const parsedFragment = JSON.parse(fragment);
    const start = trimmed.indexOf(fragment);
    const end = start >= 0 ? start + fragment.length : trimmed.length;
    const prefix = start >= 0 ? trimmed.slice(0, start).trim().replace(/[:\s]+$/, '') : '';
    const suffix = trimmed.slice(end).trim();

    if (!prefix && !suffix) {
      return parsedFragment;
    }

    const normalized: Record<string, unknown> = {
      json_payload: parsedFragment,
    };

    if (prefix) {
      normalized.message_prefix = prefix;
    }
    if (suffix) {
      normalized.message_suffix = suffix;
    }

    return normalized;
  } catch {
    return null;
  }
}

/**
 * Determine whether a value should be rendered using the JsonInspector
 * component rather than plain text.
 *
 * Returns true for non-string objects, parsable JSON strings, and strings
 * with labeled JSON sections (e.g., "mapping: {...}").
 *
 * @param content - Value to classify
 */
export function shouldRenderAsJsonInspector(content: unknown): boolean {
  if (content == null) return false;
  if (typeof content !== 'string') return true;

  const trimmed = content.trim();
  if (!trimmed) return false;

  if (tryParseLabeledJsonContent(trimmed) !== null) {
    return true;
  }

  if (trimmed.startsWith('{') || trimmed.startsWith('[') || trimmed.startsWith('"') || trimmed.startsWith('```')) {
    return true;
  }

  if (/^\s*(mapping|cases|payload|context|instructions|examples)\s*:/i.test(trimmed) && /[{[]/.test(trimmed)) {
    return true;
  }

  return false;
}

/**
 * Classify and parse a value that may be JSON, double-encoded JSON, or plain text.
 *
 * Recursively unwraps double-encoded strings up to `maxDepth` levels.
 * Used by JsonInspector and admin popups to decide between tree view
 * and text rendering.
 *
 * @param value - The raw value to classify
 * @param maxDepth - Maximum unwrap depth for nested JSON strings
 * @returns Classified result with parsed data and text representation
 */
export function parseJsonCandidate(value: unknown, maxDepth: number): ParsedJsonValue {
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

  const labeledJson = tryParseLabeledJsonContent(candidate);
  if (labeledJson !== null) {
    return {
      kind: 'json',
      jsonValue: labeledJson,
      textValue: JSON.stringify(labeledJson, null, 2),
    };
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
      const extracted = extractLikelyJsonFragment(next);
      if (extracted) {
        try {
          current = JSON.parse(extracted);
          continue;
        } catch {
          return { kind: 'text', jsonValue: null, textValue: normalizeEscapedText(next) };
        }
      }
      return { kind: 'text', jsonValue: null, textValue: normalizeEscapedText(next) };
    }
  }

  if (typeof current === 'string') {
    return { kind: 'text', jsonValue: null, textValue: normalizeEscapedText(current) };
  }

  return { kind: 'json', jsonValue: current, textValue: JSON.stringify(current, null, 2) };
}
