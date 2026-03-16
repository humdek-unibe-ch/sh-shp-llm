/**
 * Normalize escaped control sequences (e.g. "\\n") into real characters.
 * Used by markdown renderers so model text displays consistently.
 */
export function normalizeEscapedText(content: string): string {
  if (!content || content.indexOf('\\') === -1) return content;

  return content
    .replace(/\\r\\n/g, '\n')
    .replace(/\\n/g, '\n')
    .replace(/\\r/g, '\r')
    .replace(/\\t/g, '\t');
}

