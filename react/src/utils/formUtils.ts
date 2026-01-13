/**
 * Form-related utility functions
 * Extracted from types/index.ts to separate concerns
 */

import type {
  FormDefinition,
  FormSubmissionMetadata,
  FormSection,
  FormContentSection,
  FormField,
  StructuredResponse,
  StructuredForm
} from '../types';

/**
 * Parse form submission metadata from message attachments
 * @param attachments JSON string from message.attachments
 * @returns FormSubmissionMetadata if valid, null otherwise
 */
export function parseFormSubmissionMetadata(attachments?: string): FormSubmissionMetadata | null {
  if (!attachments) return null;

  try {
    const parsed = JSON.parse(attachments);

    // Direct form submission format
    if (parsed && parsed.type === 'form_submission' && parsed.values) {
      return parsed as FormSubmissionMetadata;
    }

    // Check if it's wrapped in file-like structure (legacy format)
    // e.g., [{"path": "{\"type\":\"form_submission\",...}", "original_name": "..."}]
    if (Array.isArray(parsed) && parsed.length > 0) {
      const firstItem = parsed[0];
      if (firstItem && firstItem.path) {
        try {
          const innerParsed = JSON.parse(firstItem.path);
          if (innerParsed && innerParsed.type === 'form_submission' && innerParsed.values) {
            return innerParsed as FormSubmissionMetadata;
          }
        } catch {
          // Inner content is not form submission JSON
        }
      }
    }

    // Check if it's a string that needs double-parsing
    if (typeof parsed === 'string') {
      try {
        const doubleParsed = JSON.parse(parsed);
        if (doubleParsed && doubleParsed.type === 'form_submission' && doubleParsed.values) {
          return doubleParsed as FormSubmissionMetadata;
        }
      } catch {
        // Not double-encoded
      }
    }
  } catch {
    // Not valid JSON or not form submission
  }

  return null;
}

/**
 * Validate a parsed form definition object
 * @param parsed The parsed JSON object
 * @returns true if valid form definition, false otherwise
 */
function validateFormDefinition(parsed: unknown): parsed is FormDefinition {
  if (!parsed || typeof parsed !== 'object') return false;

  const obj = parsed as Record<string, unknown>;
  if (obj.type !== 'form' || !Array.isArray(obj.fields)) return false;

  // Validate each field has required properties
  return (obj.fields as FormField[]).every((field: FormField) => {
    // Basic validation
    if (!field.id || !field.type || !field.label) return false;

    // Type-specific validation
    if (['radio', 'checkbox', 'select'].includes(field.type)) {
      // Selection fields need options
      return Array.isArray(field.options) &&
        field.options.every((opt: any) => opt.value && opt.label);
    } else if (['text', 'textarea', 'number'].includes(field.type)) {
      // Text and number fields don't need options
      return true;
    }

    // For unknown types, skip validation but allow the form to render
    // This provides forward compatibility for new field types
    return true;
  });
}

/**
 * Extract JSON object from mixed content (text + JSON)
 * Handles cases where LLM returns text before/after the JSON form
 * @param content The content to extract JSON from
 * @returns Object with extracted JSON, text before, and text after, or null if no valid JSON found
 */
function extractJsonFromContent(content: string): {
  json: unknown;
  textBefore: string;
  textAfter: string;
} | null {
  // Find the first { that could start a JSON object
  const firstBrace = content.indexOf('{');
  if (firstBrace === -1) return null;

  // Find matching closing brace by counting braces
  let braceCount = 0;
  let lastBrace = -1;
  let inString = false;
  let escapeNext = false;

  for (let i = firstBrace; i < content.length; i++) {
    const char = content[i];

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

    if (!inString) {
      if (char === '{') {
        braceCount++;
      } else if (char === '}') {
        braceCount--;
        if (braceCount === 0) {
          lastBrace = i;
          break;
        }
      }
    }
  }

  if (lastBrace === -1) return null;

  const jsonStr = content.substring(firstBrace, lastBrace + 1);
  const textBefore = content.substring(0, firstBrace).trim();
  const textAfter = content.substring(lastBrace + 1).trim();

  try {
    const json = JSON.parse(jsonStr);
    return { json, textBefore, textAfter };
  } catch {
    return null;
  }
}

/**
 * Parse message content to check if it contains a form definition
 * Handles both pure JSON and mixed content (text + JSON)
 * @param content Message content to parse
 * @returns FormDefinition if valid form, null otherwise
 */
export function parseFormDefinition(content: string): FormDefinition | null {
  if (!content) return null;

  // First, try direct JSON parsing (most common case)
  try {
    const parsed = JSON.parse(content.trim());
    if (validateFormDefinition(parsed)) {
      return parsed;
    }
  } catch {
    // Not pure JSON, try to extract from mixed content
  }

  // Try to extract JSON from mixed content (text before/after JSON)
  const extracted = extractJsonFromContent(content);
  if (extracted && validateFormDefinition(extracted.json)) {
    const formDef = extracted.json as FormDefinition;

    // If there's text before the JSON, add it as contentBefore
    // (only if contentBefore is not already set)
    if (extracted.textBefore && !formDef.contentBefore) {
      formDef.contentBefore = extracted.textBefore;
    }

    // If there's text after the JSON, add it as contentAfter
    // (only if contentAfter is not already set)
    if (extracted.textAfter && !formDef.contentAfter) {
      formDef.contentAfter = extracted.textAfter;
    }

    return formDef;
  }

  return null;
}

/**
 * Format form selections as readable text for display
 * @param formDefinition The form definition
 * @param values Selected values
 * @returns Human-readable text representation
 */
export function formatFormSelectionsAsText(
  formDefinition: FormDefinition,
  values: Record<string, string | string[]>
): string {
  const parts: string[] = [];

  // Add form title if available
  if (formDefinition.title) {
    parts.push(`**${formDefinition.title}**`);
    parts.push(''); // Empty line after title
  }

  for (const field of formDefinition.fields) {
    const fieldValue = values[field.id];
    if (!fieldValue || (Array.isArray(fieldValue) && fieldValue.length === 0)) {
      continue;
    }

    // Handle text/textarea/number fields
    if (field.type === 'text' || field.type === 'textarea' || field.type === 'number') {
      if (typeof fieldValue === 'string' && fieldValue.trim()) {
        parts.push(`${field.label}: ${fieldValue}`);
      }
      continue;
    }

    // Get labels for selected values (radio, checkbox, select)
    const selectedValues = Array.isArray(fieldValue) ? fieldValue : [fieldValue];
    const selectedLabels = selectedValues
      .map(val => {
        const option = field.options?.find(opt => opt.value === val);
        return option?.label || val;
      })
      .filter(Boolean);

    if (selectedLabels.length > 0) {
      if (selectedLabels.length === 1) {
        parts.push(`${field.label}: ${selectedLabels[0]}`);
      } else {
        parts.push(`${field.label}: ${selectedLabels.join(', ')}`);
      }
    }
  }

  // If no selections were made, return a default message
  if (parts.length === 0 || (parts.length === 2 && parts[1] === '')) {
    return 'Form submitted (no selections)';
  }

  return parts.join('\n');
}

/**
 * Check if a message contains any form (either legacy FormDefinition or StructuredForm)
 * Used for Continue button detection - shows Continue only when NO form is present
 * @param content Message content to check
 * @returns true if content contains a form
 */
export function messageHasForm(content: string): boolean {
  // First check for legacy form definition
  const legacyForm = parseFormDefinition(content);
  if (legacyForm) return true;

  // Then check for structured response with forms
  const structuredResponse = parseStructuredResponse(content);
  if (structuredResponse && structuredResponse.content.forms && structuredResponse.content.forms.length > 0) {
    return true;
  }

  return false;
}

/**
 * Extract form definition from any message format (legacy or structured)
 * Returns the first form found, converted to FormDefinition format
 * @param content Message content to parse
 * @returns FormDefinition if found, null otherwise
 */
export function extractFormFromMessage(content: string): FormDefinition | null {
  // First check for legacy form definition
  const legacyForm = parseFormDefinition(content);
  if (legacyForm) return legacyForm;

  // Then check for structured response with forms
  const structuredResponse = parseStructuredResponse(content);
  if (structuredResponse && structuredResponse.content.forms && structuredResponse.content.forms.length > 0) {
    return structuredFormToFormDefinition(structuredResponse.content.forms[0]);
  }

  return null;
}

/**
 * Extract forms from structured response as legacy FormDefinitions
 * @param response The structured response
 * @returns Array of FormDefinitions
 */
export function extractFormsFromStructuredResponse(response: StructuredResponse): FormDefinition[] {
  if (!response.content.forms || response.content.forms.length === 0) {
    return [];
  }

  return response.content.forms.map(structuredFormToFormDefinition);
}

/**
 * Convert structured form to legacy FormDefinition for backwards compatibility
 * @param structuredForm The structured form
 * @returns Legacy FormDefinition
 */
export function structuredFormToFormDefinition(structuredForm: StructuredForm): FormDefinition {
  return {
    type: 'form',
    title: structuredForm.title,
    description: structuredForm.description,
    fields: structuredForm.fields,
    submitLabel: structuredForm.submit_label,
    contentBefore: structuredForm.contentBefore,
    contentAfter: structuredForm.contentAfter
  };
}

// Import here to avoid circular dependencies
import { parseStructuredResponse } from './llmResponseUtils';