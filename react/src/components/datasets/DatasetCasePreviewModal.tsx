import React from 'react';
import { Button, Modal } from 'react-bootstrap';
import { JsonInspector } from '../shared/JsonInspector';
import type { PromptDatasetCase } from './datasetTypes';

function parseJsonSafe(value: unknown): unknown {
  if (typeof value !== 'string' || value.trim() === '') {
    return {};
  }
  try {
    return JSON.parse(value);
  } catch {
    return {};
  }
}

function sanitizeScalar(value: unknown): string {
  if (value == null) return '';
  if (typeof value === 'string') return value.trim();
  if (typeof value === 'number' || typeof value === 'boolean') return String(value);
  try {
    return JSON.stringify(value);
  } catch {
    return '';
  }
}

function extractPlaceholders(template: string): string[] {
  const keys = new Set<string>();
  const regex = /\{\{(\w+)\}\}/g;
  let match = regex.exec(template || '');
  while (match) {
    if (match[1]) {
      keys.add(match[1]);
    }
    match = regex.exec(template || '');
  }
  return Array.from(keys);
}

function interpolateTemplate(template: string, values: Record<string, unknown>): string {
  return (template || '').replace(/\{\{(\w+)\}\}/g, (_, key: string) => {
    const value = sanitizeScalar(values[key]);
    return value !== '' ? value : `{{${key}}}`;
  });
}

function toScalarMap(values: unknown): Record<string, string> {
  const out: Record<string, string> = {};
  if (!values || typeof values !== 'object' || Array.isArray(values)) {
    return out;
  }
  Object.entries(values as Record<string, unknown>).forEach(([key, value]) => {
    const scalar = sanitizeScalar(value);
    if (scalar !== '') {
      out[key] = scalar;
    }
  });
  return out;
}

function buildFormUserPrompt(values: Record<string, string>): string {
  const lines: string[] = [];
  Object.entries(values).forEach(([key, value]) => {
    if (!value) return;
    const label = key.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
    lines.push(`${label}: ${value}`);
  });
  return lines.join('\n');
}

function buildReplayPreview(inputPayload: Record<string, unknown>, promptTemplate: string): Record<string, unknown> {
  const executionProfile = String(inputPayload.execution_profile || '');
  const runtimeOverrides = (inputPayload.runtime_overrides && typeof inputPayload.runtime_overrides === 'object')
    ? inputPayload.runtime_overrides
    : {};

  if (executionProfile === 'form_runtime') {
    const rawVariables = toScalarMap(inputPayload.variables || inputPayload.form_data || {});
    const placeholders = extractPlaceholders(promptTemplate || '');
    const filtered: Record<string, string> = {};
    if (placeholders.length > 0) {
      placeholders.forEach((key) => {
        if (rawVariables[key]) {
          filtered[key] = rawVariables[key];
        }
      });
    }
    const fallbackValues = Object.keys(filtered).length > 0 ? filtered : rawVariables;
    const userPrompt = buildFormUserPrompt(fallbackValues) || 'Form submission';
    const systemPrompt = interpolateTemplate(promptTemplate || '', fallbackValues);

    return {
      preview_type: 'form_runtime_llm_messages',
      note: 'Preview of form replay messages used for evaluation with current prompt draft.',
      messages: [
        { role: 'system', content: systemPrompt },
        { role: 'user', content: userPrompt },
      ],
      runtime_overrides: runtimeOverrides,
    };
  }

  if (executionProfile === 'chat_runtime' || executionProfile.includes('chat')) {
    const history = Array.isArray(inputPayload.message_history) ? inputPayload.message_history : [];
    return {
      preview_type: 'chat_runtime_llm_messages',
      note: history.length > 0
        ? 'Preview of chat replay message history used for evaluation.'
        : 'No message history in this case. Runtime may inject a default starter user prompt.',
      messages: history,
      runtime_overrides: runtimeOverrides,
    };
  }

  if (executionProfile === 'script_runtime') {
    return {
      preview_type: 'script_runtime_replay_payload',
      note: 'Script replay uses script runtime execution with variables/data config.',
      variables: inputPayload.variables || {},
      runtime_overrides: runtimeOverrides,
    };
  }

  return {
    preview_type: 'generic_replay_payload',
    note: 'Replay preview for this execution profile.',
    input_payload: inputPayload,
  };
}

export const DatasetCasePreviewModal: React.FC<{
  datasetCase: PromptDatasetCase | null;
  promptTemplate?: string;
  onHide: () => void;
}> = ({ datasetCase, promptTemplate = '', onHide }) => (
  <Modal show={!!datasetCase} onHide={onHide} centered dialogClassName="prompt-modal-90">
    <Modal.Header closeButton className="py-2">
      <Modal.Title className="h6">Case Preview</Modal.Title>
    </Modal.Header>
    <Modal.Body>
      {datasetCase && (
        <>
          {(() => {
            const inputPayload = parseJsonSafe(datasetCase.input_payload_json) as Record<string, unknown>;
            const replayPreview = buildReplayPreview(inputPayload, promptTemplate);
            return (
              <>
          <div className="small mb-2"><strong>Title:</strong> {datasetCase.title || datasetCase.case_key}</div>
          <div className="small mb-2"><strong>Type:</strong> {datasetCase.case_type_code || '-'}</div>
          <div className="small mb-2"><strong>Source:</strong> {datasetCase.source_type_code || '-'}</div>
          <div className="small text-muted mb-1">Input Payload</div>
          <div className="small border rounded bg-light p-2 mb-2 prompt-json-preview">
            <JsonInspector value={inputPayload} />
          </div>
          <div className="small text-muted mb-1">Evaluation Replay Request Preview (What Will Be Sent)</div>
          <div className="small border rounded bg-light p-2 mb-2 prompt-json-preview">
            <JsonInspector value={replayPreview} />
          </div>
          <div className="small text-muted mb-1">Expected Labels</div>
          <div className="small border rounded bg-light p-2 mb-0 prompt-json-preview">
            <JsonInspector value={parseJsonSafe(datasetCase.expected_labels_json)} />
          </div>
              </>
            );
          })()}
        </>
      )}
    </Modal.Body>
    <Modal.Footer className="py-2">
      <Button size="sm" variant="secondary" onClick={onHide}>Close</Button>
    </Modal.Footer>
  </Modal>
);
