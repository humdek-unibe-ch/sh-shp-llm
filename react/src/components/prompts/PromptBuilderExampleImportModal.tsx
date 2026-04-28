/**
 * Prompt Builder Example Import Modal — import dataset cases as builder examples.
 *
 * Lets the admin select from existing dataset cases and import them as
 * input/output examples for the Prompt Builder, improving the quality
 * of LLM-generated prompts.
 *
 * @module components/prompts/PromptBuilderExampleImportModal
 */
import React, { useEffect, useMemo, useState } from 'react';
import { Alert, Button, Form, Modal, Table } from 'react-bootstrap';
import { JsonInspector } from '../shared/JsonInspector';
import type { createPromptLabApi } from './promptApi';
import type { PromptBuilderExample, PromptDescriptor } from './promptTypes';

interface PromptBuilderExampleImportModalProps {
  show: boolean;
  onHide: () => void;
  api: ReturnType<typeof createPromptLabApi>;
  descriptor: PromptDescriptor;
  preferredDatasetId?: number | null;
  onImport: (examples: PromptBuilderExample[]) => void;
}

/** parseJsonSafe utility. */
function parseJsonSafe(value: unknown): unknown {
  if (typeof value !== 'string' || value.trim() === '') {
    return null;
  }
  try {
    return JSON.parse(value);
  } catch {
    return value;
  }
}

/** extractOutputPreview function. */
function extractOutputPreview(example: PromptBuilderExample): unknown {
  const normalized = parseJsonSafe(example.normalized_output_json);
  if (normalized) {
    return normalized;
  }
  return parseJsonSafe(example.output_payload_json);
}

/** normalizeText function. */
function normalizeText(value: unknown): string {
  if (typeof value !== 'string') {
    return '';
  }
  return value.replace(/\r\n|\r/g, '\n').replace(/[ \t]+/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
}

/** extractTextFromValue function. */
function extractTextFromValue(value: unknown): string {
  if (typeof value === 'string') {
    return normalizeText(value);
  }
  if (!value || typeof value !== 'object') {
    return '';
  }
  const record = value as Record<string, unknown>;
  const priorityKeys = [
    'student_support',
    'student_answer',
    'student_input',
    'answer',
    'input',
    'prompt',
    'message',
    'content',
    'text',
    'assistant_text',
    'display_content',
    'raw_content',
    'feedback',
  ];
  for (const key of priorityKeys) {
    if (!(key in record)) {
      continue;
    }
    const candidate = extractTextFromValue(record[key]);
    if (candidate) {
      return candidate;
    }
  }
  for (const item of Object.values(record)) {
    const candidate = extractTextFromValue(item);
    if (candidate) {
      return candidate;
    }
  }
  return '';
}

/** extractInputPreview function. */
function extractInputPreview(example: PromptBuilderExample): string {
  const payload = parseJsonSafe(example.input_payload_json);
  const record = payload && typeof payload === 'object' ? payload as Record<string, unknown> : null;
  return extractTextFromValue(record?.variables ?? record?.form_data ?? payload);
}

/** extractApprovedResponsePreview function. */
function extractApprovedResponsePreview(example: PromptBuilderExample): string {
  const normalized = parseJsonSafe(example.normalized_output_json);
  const outputPayload = parseJsonSafe(example.output_payload_json);
  const expected = parseJsonSafe(example.expected_output_json);
  return extractTextFromValue(normalized) || extractTextFromValue(outputPayload) || extractTextFromValue(expected);
}

/** extractExpectedResponsePreview function. */
function extractExpectedResponsePreview(example: PromptBuilderExample): string {
  return extractTextFromValue(parseJsonSafe(example.expected_output_json));
}

/** truncatePreview function. */
function truncatePreview(value: string, maxLength = 200): string {
  const text = normalizeText(value);
  if (text.length <= maxLength) {
    return text;
  }
  return `${text.slice(0, maxLength).trim()}...`;
}

/** Modal dialog for prompt builder example import modal. */
export const PromptBuilderExampleImportModal: React.FC<PromptBuilderExampleImportModalProps> = ({
  show,
  onHide,
  api,
  descriptor,
  preferredDatasetId = null,
  onImport,
}) => {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [examples, setExamples] = useState<PromptBuilderExample[]>([]);
  const [selectedCaseIds, setSelectedCaseIds] = useState<number[]>([]);

  const loadExamples = async (nextSearch = search) => {
    setLoading(true);
    setError(null);
    try {
      const rows = await api.listEvaluationExampleCandidates(descriptor, {
        datasetId: preferredDatasetId,
        search: nextSearch,
        limit: 100,
      }) as PromptBuilderExample[];
      setExamples(rows || []);
      setSelectedCaseIds((current) => current.filter((caseId) => (rows || []).some((row) => Number(row.case_id) === caseId)));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load evaluation examples');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!show) {
      return;
    }
    setSearch('');
    setSelectedCaseIds([]);
    void loadExamples('');
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [show, preferredDatasetId, descriptor.ownerId, descriptor.ownerType]);

  const selectedExamples = useMemo(
    () => examples.filter((row) => selectedCaseIds.includes(Number(row.case_id))),
    [examples, selectedCaseIds],
  );

  return (
    <Modal show={show} onHide={onHide} centered dialogClassName="prompt-modal-90">
      <Modal.Header closeButton className="py-2">
        <Modal.Title className="h6">Import From Evaluations</Modal.Title>
      </Modal.Header>
      <Modal.Body>
        {error && <Alert variant="danger" className="py-2">{error}</Alert>}
        <div className="small text-muted mb-2">
          Only manually approved passing evaluations are listed here.
        </div>
        <Form.Control
          size="sm"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder="Search approved examples"
          className="mb-2"
        />
        <div className="mb-3">
          <Button size="sm" variant="outline-secondary" onClick={() => void loadExamples(search)} disabled={loading}>
            {loading ? 'Loading...' : 'Refresh'}
          </Button>
        </div>
        <div className="table-responsive">
          <Table hover size="sm" className="prompt-lab-table">
            <thead>
              <tr>
                <th style={{ width: 44 }}></th>
                <th>Case</th>
                <th>Student Input</th>
                <th>Approved Response</th>
                <th>Dataset</th>
                <th>Approved</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={7} className="text-muted small">Loading evaluation examples...</td></tr>
              ) : examples.length === 0 ? (
                <tr><td colSpan={7} className="text-muted small">No manually approved evaluation examples found.</td></tr>
              ) : examples.map((example) => {
                const caseId = Number(example.case_id || 0);
                const inputPreview = truncatePreview(extractInputPreview(example), 180);
                const approvedPreview = truncatePreview(extractApprovedResponsePreview(example), 180);
                const expectedPreview = truncatePreview(extractExpectedResponsePreview(example), 180);
                return (
                  <tr key={`${caseId}-${example.score_id || 0}`}>
                    <td>
                      <input
                        type="checkbox"
                        checked={selectedCaseIds.includes(caseId)}
                        onChange={(event) => {
                          setSelectedCaseIds((current) => (
                            event.target.checked
                              ? [...current, caseId]
                              : current.filter((item) => item !== caseId)
                          ));
                        }}
                      />
                    </td>
                    <td className="small">
                      <div className="font-weight-bold">{example.title || example.case_key || `Case ${caseId}`}</div>
                      <div className="text-muted">{example.case_key || '-'}</div>
                    </td>
                    <td className="small" style={{ minWidth: 280 }}>
                      {inputPreview || <span className="text-muted">No student input preview.</span>}
                    </td>
                    <td className="small" style={{ minWidth: 280 }}>
                      {approvedPreview || expectedPreview || <span className="text-muted">No response preview.</span>}
                    </td>
                    <td className="small">{example.dataset_name || '-'}</td>
                    <td className="small">
                      <div>{example.approved_at || '-'}</div>
                      <div className="text-muted">{example.approved_by_name || 'Unknown reviewer'}</div>
                    </td>
                    <td className="small">
                      <details>
                        <summary>Open full preview</summary>
                        <div className="mt-2">
                          <div className="font-weight-bold">Input</div>
                          <JsonInspector value={parseJsonSafe(example.input_payload_json)} className="small" />
                          <div className="font-weight-bold mt-2">Approved Output</div>
                          <JsonInspector value={extractOutputPreview(example)} className="small" />
                          <div className="font-weight-bold mt-2">Expected Output</div>
                          <JsonInspector value={parseJsonSafe(example.expected_output_json)} className="small" />
                        </div>
                      </details>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </Table>
        </div>
      </Modal.Body>
      <Modal.Footer className="py-2">
        <div className="mr-auto small text-muted">Selected: {selectedExamples.length}</div>
        <Button size="sm" variant="secondary" onClick={onHide}>Cancel</Button>
        <Button
          size="sm"
          variant="primary"
          onClick={() => {
            onImport(selectedExamples);
            onHide();
          }}
          disabled={selectedExamples.length === 0}
        >
          Import Selected
        </Button>
      </Modal.Footer>
    </Modal>
  );
};
