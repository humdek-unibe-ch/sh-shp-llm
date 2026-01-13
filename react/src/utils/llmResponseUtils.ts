/**
 * LLM Response parsing and validation utilities
 * Extracted from types/index.ts to separate concerns
 */

import type {
  StructuredResponse,
  LlmStructuredResponse,
  TextBlock,
  StructuredContent,
  StructuredMeta,
  TextBlockType,
  SafetyAssessment
} from '../types';

/**
 * Check if JSON content appears to be incomplete/truncated
 * This is a conservative check - we only flag as incomplete if clearly truncated
 * @param jsonContent The JSON string to check
 * @returns true if content appears incomplete
 */
function isIncompleteJson(jsonContent: string): boolean {
  // Check for obvious signs of truncation
  const trimmed = jsonContent.trim();

  // Empty content is incomplete
  if (!trimmed) {
    return true;
  }

  // Must start with { or [ to be valid JSON
  if (!trimmed.startsWith('{') && !trimmed.startsWith('[')) {
    return true;
  }

  // Check for incomplete objects/arrays (missing closing braces/brackets)
  let openBraces = 0;
  let openBrackets = 0;
  let inString = false;
  let escapeNext = false;

  for (let i = 0; i < trimmed.length; i++) {
    const char = trimmed[i];

    if (escapeNext) {
      escapeNext = false;
      continue;
    }

    if (char === '\\' && inString) {
      escapeNext = true;
      continue;
    }

    if (char === '"' && !escapeNext) {
      inString = !inString;
      continue;
    }

    if (inString) continue;

    switch (char) {
      case '{':
        openBraces++;
        break;
      case '}':
        openBraces--;
        break;
      case '[':
        openBrackets++;
        break;
      case ']':
        openBrackets--;
        break;
    }
  }

  // If we have unclosed braces or brackets, it's incomplete
  if (openBraces !== 0 || openBrackets !== 0) {
    return true;
  }

  // If we're still in a string, it's incomplete
  if (inString) {
    return true;
  }

  // Check if JSON ends with proper closing character
  const lastChar = trimmed[trimmed.length - 1];
  if (lastChar !== '}' && lastChar !== ']') {
    return true;
  }

  return false;
}

/**
 * Create a fallback StructuredResponse from raw content
 * Used when JSON parsing fails or content is incomplete
 */
function createFallbackFromContent(content: string): StructuredResponse | null {
  // Try to extract any readable text from the content
  const extractedText = extractTextFromRawContent(content);

  if (!extractedText || extractedText.trim().length === 0) {
    return null;
  }

  return {
    content: {
      text_blocks: [{
        type: 'paragraph',
        content: extractedText
      }],
      forms: [],
      media: []
    },
    meta: {
      response_type: 'fallback'
    }
  };
}

/**
 * Create a fallback StructuredResponse from parsed JSON that doesn't match schema
 */
function createFallbackFromJson(parsed: unknown): StructuredResponse | null {
  const extractedText = extractTextFromJsonObject(parsed);

  if (!extractedText || extractedText.trim().length === 0) {
    return null;
  }

  return {
    content: {
      text_blocks: [{
        type: 'paragraph',
        content: extractedText
      }],
      forms: [],
      media: []
    },
    meta: {
      response_type: 'fallback'
    }
  };
}

/**
 * Extract readable text from raw content (possibly truncated JSON)
 */
function extractTextFromRawContent(content: string): string {
  // Try to find "content" values in the string
  const contentMatches = content.match(/"content"\s*:\s*"([^"]+)"/g);
  if (contentMatches && contentMatches.length > 0) {
    const texts: string[] = [];
    for (const match of contentMatches) {
      const valueMatch = match.match(/"content"\s*:\s*"([^"]+)"/);
      if (valueMatch && valueMatch[1]) {
        // Unescape JSON string
        try {
          const unescaped = JSON.parse(`"${valueMatch[1]}"`);
          texts.push(unescaped);
        } catch {
          texts.push(valueMatch[1]);
        }
      }
    }
    if (texts.length > 0) {
      return texts.join('\n\n');
    }
  }

  // Try to find "text" values
  const textMatches = content.match(/"text"\s*:\s*"([^"]+)"/g);
  if (textMatches && textMatches.length > 0) {
    const texts: string[] = [];
    for (const match of textMatches) {
      const valueMatch = match.match(/"text"\s*:\s*"([^"]+)"/);
      if (valueMatch && valueMatch[1]) {
        try {
          const unescaped = JSON.parse(`"${valueMatch[1]}"`);
          texts.push(unescaped);
        } catch {
          texts.push(valueMatch[1]);
        }
      }
    }
    if (texts.length > 0) {
      return texts.join('\n\n');
    }
  }

  // Return the raw content if we couldn't extract anything
  // Remove JSON-like characters from the beginning if it looks like broken JSON
  if (content.startsWith('{') || content.startsWith('[')) {
    // Try to clean up broken JSON to make it readable
    return content
      .replace(/^\{|\}$/g, '')
      .replace(/^\[|\]$/g, '')
      .replace(/"type"\s*:\s*"[^"]*",?/g, '')
      .replace(/"style"\s*:\s*"[^"]*",?/g, '')
      .replace(/"content"\s*:\s*/g, '')
      .replace(/"/g, '')
      .trim();
  }

  return content;
}

/**
 * Extract readable text from a JSON object that doesn't match our schema
 */
function extractTextFromJsonObject(obj: unknown): string {
  if (!obj || typeof obj !== 'object') {
    return String(obj || '');
  }

  const texts: string[] = [];
  const record = obj as Record<string, unknown>;

  // Look for common text fields
  const textFields = ['content', 'text', 'message', 'response', 'answer', 'output'];

  for (const field of textFields) {
    if (record[field]) {
      if (typeof record[field] === 'string') {
        texts.push(record[field] as string);
      } else if (Array.isArray(record[field])) {
        // Handle arrays of text blocks
        for (const item of record[field] as unknown[]) {
          if (typeof item === 'string') {
            texts.push(item);
          } else if (item && typeof item === 'object') {
            const itemRecord = item as Record<string, unknown>;
            if (itemRecord.content && typeof itemRecord.content === 'string') {
              texts.push(itemRecord.content);
            } else if (itemRecord.text && typeof itemRecord.text === 'string') {
              texts.push(itemRecord.text);
            }
          }
        }
      } else if (typeof record[field] === 'object') {
        // Recurse into nested objects
        const nested = extractTextFromJsonObject(record[field]);
        if (nested) {
          texts.push(nested);
        }
      }
    }
  }

  // Look for text_blocks specifically
  if (record.content && typeof record.content === 'object') {
    const contentObj = record.content as Record<string, unknown>;
    if (Array.isArray(contentObj.text_blocks)) {
      for (const block of contentObj.text_blocks) {
        if (block && typeof block === 'object') {
          const blockRecord = block as Record<string, unknown>;
          if (blockRecord.content && typeof blockRecord.content === 'string') {
            texts.push(blockRecord.content);
          }
        }
      }
    }
  }

  return texts.join('\n\n');
}

/**
 * Extract suggestion text from suggestion object
 *
 * STRICT FORMAT: Each suggestion MUST be an object with a "text" property.
 * Example: { "text": "Option 1" }
 *
 * @param suggestions Raw suggestions array from LLM response
 * @returns Array of strings suitable for display
 */
function extractSuggestionTexts(suggestions: unknown[] | undefined): string[] {
  if (!suggestions || !Array.isArray(suggestions) || suggestions.length === 0) {
    return [];
  }

  return suggestions
    .map(s => {
      // STRICT: Only accept objects with "text" property
      if (s && typeof s === 'object' && 'text' in s) {
        const obj = s as { text: string };
        return typeof obj.text === 'string' ? obj.text : '';
      }
      // Log invalid format for debugging
      console.warn('[LLM Response] Invalid suggestion format. Expected {text: string}, got:', s);
      return '';
    })
    .filter(Boolean);
}

/**
 * Create a basic structured response from unified schema when full conversion fails
 * This ensures we can at least display the text content
 */
function createBasicStructuredResponse(unified: LlmStructuredResponse): StructuredResponse {
  const textBlocks: TextBlock[] = [];

  // Extract text blocks
  if (unified.content?.text_blocks) {
    for (const block of unified.content.text_blocks) {
      textBlocks.push({
        type: mapTextBlockType(block.type || 'text'),
        content: block.content || '',
        level: block.type === 'heading' ? 2 : undefined
      });
    }
  }

  // Ensure at least one text block
  if (textBlocks.length === 0) {
    textBlocks.push({ type: 'paragraph', content: 'Response received.' });
  }

  // Convert form if present
  const forms: any[] = [];
  if (unified.content?.form) {
    forms.push({
      id: 'form_1',
      title: unified.content.form.title,
      description: unified.content.form.description,
      fields: unified.content.form.fields || [],
      submit_label: unified.content.form.submit_label
    });
  }

  // Convert suggestions (STRICT: only accepts {text: string} objects)
  const suggestionTexts = extractSuggestionTexts(unified.content?.suggestions as unknown[]);
  const nextStep: any | undefined = suggestionTexts.length > 0
    ? { suggestions: suggestionTexts }
    : undefined;

  return {
    content: {
      text_blocks: textBlocks,
      forms: forms.length > 0 ? forms : undefined,
      media: undefined,
      next_step: nextStep
    },
    meta: {
      response_type: 'conversational',
      emotion: 'neutral'
    }
  };
}

/**
 * Convert new unified LLM response schema to old StructuredResponse format
 * for backwards compatibility with existing rendering code
 */
function convertUnifiedToStructuredResponse(unified: LlmStructuredResponse): StructuredResponse {
  // Map text block types from new schema to old schema
  const mappedTextBlocks = unified.content.text_blocks.map(block => ({
    type: mapTextBlockType(block.type),
    content: block.content,
    level: block.type === 'heading' ? 2 : undefined
  }));

  // Convert forms if present (new schema uses form, old uses forms array)
  const forms: any[] = [];
  if (unified.content.form) {
    forms.push({
      id: 'form_1',
      title: unified.content.form.title,
      description: unified.content.form.description,
      fields: unified.content.form.fields,
      submit_label: unified.content.form.submit_label
    });
  }

  // Convert suggestions (STRICT: only accepts {text: string} objects)
  const suggestionTexts = extractSuggestionTexts(unified.content.suggestions as unknown[]);

  return {
    content: {
      text_blocks: mappedTextBlocks as TextBlock[],
      forms: forms.length > 0 ? forms : undefined,
      media: unified.content.media?.map(m => ({
        type: m.type,
        src: m.url,
        alt: m.alt,
        caption: m.caption
      })),
      next_step: suggestionTexts.length > 0
        ? { suggestions: suggestionTexts }
        : undefined
    },
    meta: {
      response_type: 'conversational',
      emotion: 'neutral',
      progress: unified.progress ? {
        percentage: unified.progress.percentage,
        covered_topics: unified.progress.topics_covered,
        remaining_topics: unified.progress.topics_remaining?.length
      } : undefined
    }
  };
}

/**
 * Map new schema text block types to old schema types
 */
function mapTextBlockType(type: string): TextBlockType {
  const typeMap: Record<string, TextBlockType> = {
    'text': 'paragraph',
    'heading': 'heading',
    'info': 'info',
    'warning': 'warning',
    'error': 'warning', // Map error to warning for display
    'success': 'success',
    'code': 'quote' // Map code to quote for now
  };
  return typeMap[type] || 'paragraph';
}

/**
 * Parse message content to check if it's a structured response
 * Handles both pure JSON and markdown code block wrapped JSON
 * Supports BOTH old schema (meta) and new unified schema (type, safety, metadata)
 *
 * IMPORTANT: This function is now more resilient and will attempt to create
 * a fallback structure for content that doesn't match the schema exactly.
 *
 * @param content Message content to parse
 * @returns StructuredResponse if valid or fallback created, null only for empty content
 */
export function parseStructuredResponse(content: string): StructuredResponse | null {
  if (!content) return null;

  let jsonContent = content.trim();

  // Remove markdown code block wrappers if present
  if (jsonContent.startsWith('```json')) {
    jsonContent = jsonContent.replace(/^```json\s*\n?/, '').replace(/\n?```\s*$/, '');
  } else if (jsonContent.startsWith('```')) {
    jsonContent = jsonContent.replace(/^```\s*\n?/, '').replace(/\n?```\s*$/, '');
  }

  // Check if JSON appears incomplete before parsing
  if (isIncompleteJson(jsonContent)) {
    console.debug('[parseStructuredResponse] JSON appears incomplete, creating fallback');
    // Create fallback for incomplete JSON - try to extract what we can
    return createFallbackFromContent(content);
  }

  try {
    const parsed: unknown = JSON.parse(jsonContent);

    // Check if it's the new unified schema (type: "response" with content.text_blocks)
    const asAny = parsed as Record<string, unknown>;
    if (asAny && asAny.type === 'response' && asAny.content) {
      const contentObj = asAny.content as Record<string, unknown>;
      if (Array.isArray(contentObj.text_blocks) && contentObj.text_blocks.length > 0) {
        // Valid unified schema - convert to StructuredResponse format
        try {
          return convertUnifiedToStructuredResponse(parsed as LlmStructuredResponse);
        } catch (convError) {
          console.debug('[parseStructuredResponse] Conversion error:', convError);
          // Try to return a basic structure
          return createBasicStructuredResponse(parsed as LlmStructuredResponse);
        }
      }
    }

    // Check for old schema (meta.response_type)
    if (isStructuredResponse(parsed)) {
      return parsed;
    }

    // JSON parsed but doesn't match schema - try to extract content
    console.debug('[parseStructuredResponse] Parsed JSON does not match expected schema, creating fallback');
    return createFallbackFromJson(parsed);
  } catch (parseError) {
    console.debug('[parseStructuredResponse] JSON parse error, treating as plain text:', parseError);
  }

  // Not JSON - return null to let it render as markdown
  return null;
}

/**
 * Check if content is a valid structured response
 * Supports both old and new schema text block types
 * @param content Message content to check
 * @returns true if valid structured response
 */
export function isStructuredResponse(content: unknown): content is StructuredResponse {
  if (!content || typeof content !== 'object') return false;

  const obj = content as Record<string, unknown>;

  // Must have content object
  if (!obj.content || typeof obj.content !== 'object') return false;

  const contentObj = obj.content as Record<string, unknown>;

  // Content must have text_blocks array
  if (!Array.isArray(contentObj.text_blocks)) return false;

  // Check for NEW unified schema (type: "response" with safety or metadata)
  // The schema requires: type, safety, content, metadata
  // But we're lenient here - if it has type: "response" and valid content structure,
  // and has either safety or metadata, we accept it
  if (obj.type === 'response') {
    // Has metadata object - valid new schema
    if (obj.metadata && typeof obj.metadata === 'object') {
      return true;
    }
    // Has safety object - valid new schema (metadata may be added server-side)
    if (obj.safety && typeof obj.safety === 'object') {
      return true;
    }
  }

  // Check for OLD schema (meta.response_type)
  if (obj.meta && typeof obj.meta === 'object') {
    const metaObj = obj.meta as Record<string, unknown>;
    if (typeof metaObj.response_type === 'string') {
      return true; // Valid old schema
    }
  }

  return false;
}

/**
 * Convert structured response to markdown for display
 * Used as fallback when structured rendering is not available
 * Supports both old and new schema text block types
 * @param response The structured response
 * @returns Markdown string
 */
export function structuredResponseToMarkdown(response: StructuredResponse): string {
  const parts: string[] = [];

  for (const block of response.content.text_blocks) {
    switch (block.type) {
      case 'heading': {
        const level = block.level || 2;
        const prefix = '#'.repeat(level);
        parts.push(`${prefix} ${block.content}`);
        break;
      }
      case 'quote':
        parts.push(block.content.split('\n').map(l => `> ${l}`).join('\n'));
        break;
      case 'info':
        parts.push(`ℹ️ **Info**: ${block.content}`);
        break;
      case 'warning':
        parts.push(`⚠️ **Warning**: ${block.content}`);
        break;
      case 'success':
        parts.push(`✅ ${block.content}`);
        break;
      case 'tip':
        parts.push(`💡 **Tip**: ${block.content}`);
        break;
      case 'paragraph':
      case 'list':
      default:
        parts.push(block.content);
    }
  }

  return parts.join('\n\n');
}

/**
 * Parse message content to unified LLM response
 * @param content Raw message content
 * @returns LlmStructuredResponse if valid, null otherwise
 */
export function parseLlmResponse(content: string): LlmStructuredResponse | null {
  if (!content) return null;

  let jsonContent = content.trim();

  // Remove markdown code block wrappers if present
  if (jsonContent.startsWith('```json')) {
    jsonContent = jsonContent.replace(/^```json\s*\n?/, '').replace(/\n?```\s*$/, '');
  } else if (jsonContent.startsWith('```')) {
    jsonContent = jsonContent.replace(/^```\s*\n?/, '').replace(/\n?```\s*$/, '');
  }

  try {
    const parsed = JSON.parse(jsonContent);
    if (isLlmStructuredResponse(parsed)) {
      return parsed;
    }
  } catch {
    // Not valid JSON
  }

  return null;
}

/**
 * Check if parsed object is a valid LlmStructuredResponse
 * @param obj Object to check
 * @returns true if valid unified response
 */
export function isLlmStructuredResponse(obj: unknown): obj is LlmStructuredResponse {
  if (!obj || typeof obj !== 'object') return false;

  const response = obj as Record<string, unknown>;

  // Check required fields - type must be 'response'
  if (response.type !== 'response') return false;

  // Must have safety object (core to the unified schema)
  if (!response.safety || typeof response.safety !== 'object') return false;

  // Must have content object
  if (!response.content || typeof response.content !== 'object') return false;

  // Metadata is technically required by schema, but be lenient -
  // accept responses without metadata (can be added server-side)
  // This allows partial responses to still be valid

  // Check content.text_blocks
  const content = response.content as Record<string, unknown>;
  if (!Array.isArray(content.text_blocks)) return false;
  if (content.text_blocks.length === 0) return false;

  return true;
}

/**
 * Convert unified LLM response to markdown for display
 * @param response Parsed LLM response
 * @returns Markdown string
 */
export function llmResponseToMarkdown(response: LlmStructuredResponse): string {
  const parts: string[] = [];

  // Add safety message if danger detected
  if (!response.safety.is_safe && response.safety.safety_message) {
    parts.push(`⚠️ **Safety Notice**: ${response.safety.safety_message}`);
    parts.push('');
  }

  // Convert text blocks
  for (const block of response.content.text_blocks) {
    switch (block.type) {
      case 'heading':
        parts.push(`## ${block.content}`);
        break;
      case 'info':
        parts.push(`ℹ️ **Info**: ${block.content}`);
        break;
      case 'warning':
        parts.push(`⚠️ **Warning**: ${block.content}`);
        break;
      case 'error':
        parts.push(`🚨 **Important**: ${block.content}`);
        break;
      case 'success':
        parts.push(`✅ ${block.content}`);
        break;
      case 'code':
        parts.push('```\n' + block.content + '\n```');
        break;
      default:
        parts.push(block.content);
    }
  }

  return parts.join('\n\n');
}

/**
 * Check if LLM response indicates danger requiring intervention
 * @param response Parsed LLM response or safety assessment
 * @returns true if intervention is required
 */
export function requiresSafetyIntervention(response: LlmStructuredResponse | SafetyAssessment): boolean {
  const safety = 'safety' in response ? response.safety : response;
  return safety.requires_intervention === true || safety.danger_level === 'emergency';
}

/**
 * Get form from unified LLM response
 * @param response Parsed LLM response
 * @returns FormDefinition if form present, null otherwise
 */
export function getFormFromLlmResponse(response: LlmStructuredResponse): any | null {
  if (!response.content.form) return null;

  const form = response.content.form;
  return {
    type: 'form',
    title: form.title,
    description: form.description,
    fields: form.fields,
    submitLabel: form.submit_label
  };
}