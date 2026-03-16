import React, { useEffect, useMemo, useState } from 'react';
import Select from 'react-select';
import { Alert, Button, Form, Modal, OverlayTrigger, Popover, Spinner, Table } from 'react-bootstrap';
import { JsonInspector } from '../shared/JsonInspector';
import { JsonMonacoEditor } from '../shared/JsonMonacoEditor';
import type { PromptDescriptor, PromptExecutionProfile, PromptModel } from '../prompts/promptTypes';
import type { createDatasetApi } from './datasetApi';
import type { PromptAiImportCaseDraft, PromptAiImportParseResponse } from './datasetTypes';

type WizardStep = 1 | 2 | 3;

interface DatasetAiImportModalProps {
  show: boolean;
  onHide: () => void;
  descriptor: PromptDescriptor;
  executionProfile: PromptExecutionProfile;
  datasetId: number | null;
  datasetApi: ReturnType<typeof createDatasetApi>;
  resolveRuntimeOverrides: () => Record<string, unknown>;
  models: PromptModel[];
  defaultModel?: string | null;
  promptTemplate?: string;
  onImported: (count: number) => void;
}

function safeJsonStringify(value: unknown): string {
  try {
    return JSON.stringify(value ?? {}, null, 2);
  } catch {
    return '{}';
  }
}

function parseJsonObject(value: string): Record<string, unknown> {
  const parsed = JSON.parse(value);
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    throw new Error('Expected a JSON object');
  }
  return parsed as Record<string, unknown>;
}

function normalizeDraft(draft: PromptAiImportCaseDraft): PromptAiImportCaseDraft {
  return {
    title: String(draft.title || 'Imported Case'),
    case_type: draft.case_type || undefined,
    source_type: draft.source_type || undefined,
    input_payload: draft.input_payload && typeof draft.input_payload === 'object'
      ? draft.input_payload
      : {},
    expected_output: draft.expected_output && typeof draft.expected_output === 'object'
      ? draft.expected_output
      : null,
    expected_labels: draft.expected_labels && typeof draft.expected_labels === 'object'
      ? draft.expected_labels
      : null,
    source_ref: draft.source_ref && typeof draft.source_ref === 'object'
      ? draft.source_ref
      : null,
    tags: Array.isArray(draft.tags) ? draft.tags.map((item) => String(item).trim()).filter(Boolean) : [],
    notes: String(draft.notes || ''),
  };
}

function validateDraft(draft: PromptAiImportCaseDraft): string[] {
  const errors: string[] = [];
  if (!draft.title || draft.title.trim() === '') {
    errors.push('Missing title');
  }
  if (!draft.input_payload || typeof draft.input_payload !== 'object' || Array.isArray(draft.input_payload)) {
    errors.push('input_payload must be a JSON object');
  }
  return errors;
}

export const DatasetAiImportModal: React.FC<DatasetAiImportModalProps> = ({
  show,
  onHide,
  descriptor,
  executionProfile,
  datasetId,
  datasetApi,
  resolveRuntimeOverrides,
  models,
  defaultModel,
  promptTemplate = '',
  onImported,
}) => {
  const [step, setStep] = useState<WizardStep>(1);
  const [loading, setLoading] = useState(false);
  const [importing, setImporting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [rawText, setRawText] = useState('');
  const [selectedModel, setSelectedModel] = useState(defaultModel || models[0]?.id || '');
  const [parseResult, setParseResult] = useState<PromptAiImportParseResponse | null>(null);
  const [mappingJson, setMappingJson] = useState('{}');
  const [mappingError, setMappingError] = useState<string | null>(null);
  const [drafts, setDrafts] = useState<PromptAiImportCaseDraft[]>([]);
  const [selectedIndices, setSelectedIndices] = useState<number[]>([]);
  const [rowErrors, setRowErrors] = useState<Record<number, string[]>>({});
  const [draftJsonText, setDraftJsonText] = useState<Record<number, { inputPayload: string; expectedOutput: string }>>({});

  const modelOptions = useMemo(
    () => models.filter((model) => !!model.id).map((model) => ({ value: model.id, label: model.id })),
    [models],
  );
  const placeholderKeys = useMemo(() => {
    const keys = new Set<string>();
    const regex = /\{\{(\w+)\}\}/g;
    let match = regex.exec(promptTemplate || '');
    while (match) {
      if (match[1]) {
        keys.add(match[1]);
      }
      match = regex.exec(promptTemplate || '');
    }
    return Array.from(keys);
  }, [promptTemplate]);

  const textAreaPlaceholder = useMemo(() => {
    const profile = executionProfile || 'text_only';
    if (profile === 'form_runtime') {
      const keyHint = placeholderKeys.length > 0
        ? `Use columns/keys that match your context placeholders: ${placeholderKeys.map((key) => `{{${key}}}`).join(', ')}.`
        : 'Use columns/keys named like your context placeholders, for example {{student_support}}.';
      return `${keyHint}\n\nExample columns: reflection_question | student_support | feedback | notes\nPaste from Excel/Sheets/TSV or free text blocks.`;
    }
    if (profile === 'chat_runtime') {
      return 'Paste conversation-style examples. Best input includes role/content history (user, assistant, system), plus optional expected feedback/output.';
    }
    if (profile === 'script_runtime') {
      return 'Paste examples where input fields map to script variables. Include optional expected output and notes.';
    }
    return 'Paste examples from Excel/Sheets/CSV/TSV or free text blocks. Include clear input fields, expected output, and notes where possible.';
  }, [executionProfile, placeholderKeys]);

  const helpPopover = (
    <Popover id="dataset-ai-import-help-popover">
      <Popover.Title as="h3">AI Import Guidance</Popover.Title>
      <Popover.Content>
        <div className="small">
          <div className="mb-2">
            <strong>How import works:</strong> pasted text is parsed by LLM, normalized into dataset cases, reviewed by you, then imported on explicit approval.
          </div>
          <div className="mb-2">
            <strong>Form runtime:</strong> name fields to match prompt placeholders used in your context (for example <code>{'{{student_support}}'}</code>).
            {placeholderKeys.length > 0 && (
              <div className="mt-1">
                Detected placeholders now: {placeholderKeys.map((key) => `{{${key}}}`).join(', ')}
              </div>
            )}
          </div>
          <div className="mb-2">
            <strong>If no matching variables are available:</strong> replay can fall back to generic user text <code>Form submission</code> for form runtime.
          </div>
          <div className="mb-2">
            <strong>Chat runtime:</strong> provide role/content conversation examples; message history is the primary replay input.
          </div>
          <div>
            <strong>Script runtime:</strong> provide key/value inputs that map to script variables.
          </div>
        </div>
      </Popover.Content>
    </Popover>
  );

  useEffect(() => {
    if (!show) {
      return;
    }
    setStep(1);
    setLoading(false);
    setImporting(false);
    setError(null);
    setSuccess(null);
    setRawText('');
    setSelectedModel(defaultModel || models[0]?.id || '');
    setParseResult(null);
    setMappingJson('{}');
    setMappingError(null);
    setDrafts([]);
    setSelectedIndices([]);
    setRowErrors({});
    setDraftJsonText({});
  }, [show, defaultModel, models]);

  const recalcValidation = (nextDrafts: PromptAiImportCaseDraft[]) => {
    const nextErrors: Record<number, string[]> = {};
    nextDrafts.forEach((draft, index) => {
      const errors = validateDraft(draft);
      if (errors.length > 0) {
        nextErrors[index] = errors;
      }
    });
    setRowErrors(nextErrors);
  };

  const handleParse = async () => {
    if (!datasetId) {
      setError('Select a dataset first.');
      return;
    }
    if (rawText.trim() === '') {
      setError('Paste text is required.');
      return;
    }

    setLoading(true);
    setError(null);
    setSuccess(null);
    try {
      const response = await datasetApi.parseCasesFromText(
        descriptor,
        executionProfile,
        rawText,
        selectedModel || undefined,
        resolveRuntimeOverrides(),
      );
      const nextDrafts = (response.cases || []).map(normalizeDraft);
      setParseResult(response);
      setDrafts(nextDrafts);
      setSelectedIndices(nextDrafts.map((_, index) => index));
      setMappingJson(safeJsonStringify(response.mapping || {}));
      setDraftJsonText(
        nextDrafts.reduce((acc, draft, index) => {
          acc[index] = {
            inputPayload: safeJsonStringify(draft.input_payload || {}),
            expectedOutput: safeJsonStringify(draft.expected_output || {}),
          };
          return acc;
        }, {} as Record<number, { inputPayload: string; expectedOutput: string }>),
      );
      setStep(2);
      recalcValidation(nextDrafts);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to parse pasted text');
    } finally {
      setLoading(false);
    }
  };

  const updateDraft = (index: number, patch: Partial<PromptAiImportCaseDraft>) => {
    setDrafts((current) => {
      const next = [...current];
      const base = normalizeDraft(next[index] || ({} as PromptAiImportCaseDraft));
      next[index] = normalizeDraft({ ...base, ...patch });
      recalcValidation(next);
      return next;
    });
  };

  const applyMappingJson = () => {
    try {
      parseJsonObject(mappingJson);
      setMappingError(null);
      setStep(3);
    } catch (err) {
      setMappingError(err instanceof Error ? err.message : 'Invalid mapping JSON');
    }
  };

  const validSelectedIndices = selectedIndices.filter((index) => !rowErrors[index]);

  const handleImport = async () => {
    if (!datasetId) {
      setError('Select a dataset first.');
      return;
    }
    if (validSelectedIndices.length === 0) {
      setError('Select at least one valid case.');
      return;
    }

    setImporting(true);
    setError(null);
    try {
      const payload = validSelectedIndices.map((index) => normalizeDraft(drafts[index]));
      const inserted = await datasetApi.importParsedCases(
        descriptor,
        datasetId,
        executionProfile,
        payload,
        resolveRuntimeOverrides(),
      );
      const count = inserted.length || payload.length;
      setSuccess(`Imported ${count} case(s).`);
      onImported(count);
      onHide();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to import parsed cases');
    } finally {
      setImporting(false);
    }
  };

  const toggleRow = (index: number) => {
    setSelectedIndices((current) => (
      current.includes(index)
        ? current.filter((value) => value !== index)
        : [...current, index]
    ));
  };

  const selectAll = () => setSelectedIndices(drafts.map((_, index) => index));
  const clearAll = () => setSelectedIndices([]);

  return (
    <Modal show={show} onHide={onHide} centered dialogClassName="prompt-modal-90">
      <Modal.Header closeButton className="py-2">
        <Modal.Title className="h6">Import With AI</Modal.Title>
      </Modal.Header>
      <Modal.Body>
        {error && <Alert variant="danger" className="py-2">{error}</Alert>}
        {success && <Alert variant="success" className="py-2">{success}</Alert>}

        <div className="prompt-ai-import-steps mb-3 small text-muted">
          <span className={step === 1 ? 'font-weight-bold text-dark' : ''}>1. Paste</span>
          <span className="mx-2">→</span>
          <span className={step === 2 ? 'font-weight-bold text-dark' : ''}>2. Parse Preview</span>
          <span className="mx-2">→</span>
          <span className={step === 3 ? 'font-weight-bold text-dark' : ''}>3. Review & Import</span>
        </div>

        {step === 1 && (
          <>
            <Form.Group>
              <Form.Label className="small mb-1">Parser Model</Form.Label>
              <Select
                className="prompt-select"
                classNamePrefix="react-select"
                isSearchable
                options={modelOptions}
                value={modelOptions.find((item) => item.value === selectedModel) || null}
                onChange={(option) => setSelectedModel(option?.value || '')}
              />
            </Form.Group>
            <Form.Group>
              <div className="d-flex align-items-center justify-content-between mb-1">
                <Form.Label className="small mb-0">Paste Cases (tabular or free text)</Form.Label>
                <OverlayTrigger trigger={['hover', 'focus', 'click']} placement="left" overlay={helpPopover} rootClose>
                  <Button size="sm" variant="link" className="p-0 text-info" aria-label="Import guidance">
                    <i className="fas fa-info-circle"></i>
                  </Button>
                </OverlayTrigger>
              </div>
              <Form.Control
                as="textarea"
                rows={14}
                value={rawText}
                onChange={(event) => setRawText(event.target.value)}
                placeholder={textAreaPlaceholder}
              />
            </Form.Group>
          </>
        )}

        {step >= 2 && (
          <>
            <div className="border rounded p-2 bg-light mb-3">
              <div className="small font-weight-bold mb-1">Detected Mapping</div>
              <JsonMonacoEditor
                value={mappingJson}
                onChange={setMappingJson}
                expectObject
                minHeight={160}
              />
              {mappingError && <div className="small text-danger mt-1">{mappingError}</div>}
            </div>

            {parseResult?.warnings && parseResult.warnings.length > 0 && (
              <Alert variant="warning" className="py-2">
                <div className="small font-weight-bold mb-1">Parser Warnings</div>
                <ul className="small mb-0">
                  {parseResult.warnings.map((warning, index) => <li key={index}>{warning}</li>)}
                </ul>
              </Alert>
            )}

            <div className="d-flex justify-content-between align-items-center mb-2">
              <div className="small font-weight-bold">Parsed Cases: {drafts.length}</div>
              <div>
                <Button size="sm" variant="outline-secondary" className="mr-2" onClick={selectAll}>Select All</Button>
                <Button size="sm" variant="outline-secondary" onClick={clearAll}>Clear</Button>
              </div>
            </div>

            <div className="table-responsive">
              <Table size="sm" className="prompt-lab-table mb-0">
                <thead>
                  <tr>
                    <th style={{ width: 38 }}></th>
                    <th style={{ width: 180 }}>Title</th>
                    <th>Input Payload</th>
                    <th>Expected Output</th>
                    <th>Notes</th>
                  </tr>
                </thead>
                <tbody>
                  {drafts.length === 0 ? (
                    <tr>
                      <td colSpan={5} className="small text-muted">No parsed rows.</td>
                    </tr>
                  ) : drafts.map((draft, index) => {
                    const selected = selectedIndices.includes(index);
                    const errors = rowErrors[index] || [];
                    const jsonCell = draftJsonText[index] || {
                      inputPayload: safeJsonStringify(draft.input_payload || {}),
                      expectedOutput: safeJsonStringify(draft.expected_output || {}),
                    };
                    return (
                      <tr key={index} className={selected ? 'table-primary' : ''}>
                        <td>
                          <Form.Check checked={selected} onChange={() => toggleRow(index)} />
                        </td>
                        <td>
                          <Form.Control
                            size="sm"
                            value={draft.title || ''}
                            onChange={(event) => updateDraft(index, { title: event.target.value })}
                          />
                          {errors.length > 0 && <div className="small text-danger mt-1">{errors.join(' · ')}</div>}
                        </td>
                        <td>
                          <JsonMonacoEditor
                            value={jsonCell.inputPayload}
                            onChange={(nextValue) => setDraftJsonText((current) => ({
                              ...current,
                              [index]: {
                                ...(current[index] || jsonCell),
                                inputPayload: nextValue,
                              },
                            }))}
                            onValidParsed={(parsed) => updateDraft(index, { input_payload: parsed as Record<string, unknown> })}
                            expectObject
                            minHeight={150}
                            showToolbar={false}
                          />
                        </td>
                        <td>
                          <JsonMonacoEditor
                            value={jsonCell.expectedOutput}
                            onChange={(nextValue) => setDraftJsonText((current) => ({
                              ...current,
                              [index]: {
                                ...(current[index] || jsonCell),
                                expectedOutput: nextValue,
                              },
                            }))}
                            onValidParsed={(parsed) => updateDraft(index, { expected_output: parsed as Record<string, unknown> })}
                            expectObject
                            minHeight={150}
                            showToolbar={false}
                          />
                        </td>
                        <td>
                          <Form.Control
                            as="textarea"
                            rows={4}
                            value={draft.notes || ''}
                            onChange={(event) => updateDraft(index, { notes: event.target.value })}
                          />
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </Table>
            </div>

            <details className="mt-3">
              <summary className="small text-muted font-weight-bold">Raw Parse Payload</summary>
              <div className="mt-2">
                <JsonInspector value={parseResult?.request_payload || {}} />
              </div>
            </details>
          </>
        )}
      </Modal.Body>
      <Modal.Footer className="py-2">
        <Button size="sm" variant="secondary" onClick={onHide}>Close</Button>
        {step > 1 && (
          <Button size="sm" variant="outline-secondary" onClick={() => setStep(step === 3 ? 2 : 1)}>
            Back
          </Button>
        )}
        {step === 1 && (
          <Button size="sm" variant="primary" onClick={handleParse} disabled={loading || rawText.trim() === '' || !datasetId}>
            {loading ? <><Spinner animation="border" size="sm" className="mr-2" />Parsing...</> : 'Parse With AI'}
          </Button>
        )}
        {step === 2 && (
          <Button size="sm" variant="primary" onClick={applyMappingJson} disabled={drafts.length === 0}>
            Continue To Review
          </Button>
        )}
        {step === 3 && (
          <Button size="sm" variant="success" onClick={handleImport} disabled={importing || validSelectedIndices.length === 0}>
            {importing ? <><Spinner animation="border" size="sm" className="mr-2" />Importing...</> : 'Approve & Import'}
          </Button>
        )}
      </Modal.Footer>
    </Modal>
  );
};
