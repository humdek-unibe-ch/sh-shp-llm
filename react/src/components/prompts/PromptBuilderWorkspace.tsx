import React, { useCallback, useEffect, useMemo, useState } from 'react';
import Select from 'react-select';
import { Alert, Button, Form, Spinner } from 'react-bootstrap';
import { PromptDiffViewer } from './PromptDiffViewer';
import { PromptBuilderExampleImportModal } from './PromptBuilderExampleImportModal';
import { JsonInspector, normalizeGeneratedPromptTemplate } from '../shared/JsonInspector';
import type { createPromptLabApi } from './promptApi';
import type {
  PromptBuilderExample,
  PromptBuilderResponse,
  PromptContract,
  PromptDescriptor,
  PromptModel,
  PromptVariableDefinition,
} from './promptTypes';

interface PromptBuilderWorkspaceProps {
  show: boolean;
  api: ReturnType<typeof createPromptLabApi>;
  descriptor: PromptDescriptor;
  currentPrompt: string;
  models: PromptModel[];
  defaultModel?: string | null;
  onApplySuggestion: (promptTemplate: string, variables: PromptVariableDefinition[], changeSummary: string) => void;
  onClose: () => void;
  registerCloseHandler?: (handler: () => void) => void;
  disabled?: boolean;
  preferredExampleDatasetId?: number | null;
  showAutoApplyOnClose?: boolean;
  showApplySuggestionButton?: boolean;
  applySuggestionButtonLabel?: string;
}

const selectMenuStyles = {
  menuList: (base: Record<string, unknown>) => ({
    ...base,
    maxHeight: 190,
  }),
};

function buildEffectiveModels(models: PromptModel[], defaultModel?: string | null): PromptModel[] {
  const normalized = Array.isArray(models) ? models.filter((item) => item?.id) : [];
  if (defaultModel && !normalized.some((item) => item.id === defaultModel)) {
    return [{ id: defaultModel }, ...normalized];
  }
  return normalized;
}

function summarizeExample(example: PromptBuilderExample): string {
  const title = String(example.title || example.case_key || `Case ${example.case_id}`);
  const dataset = String(example.dataset_name || 'dataset');
  return `${title} (${dataset})`;
}

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

function normalizeText(value: unknown): string {
  if (typeof value !== 'string') {
    return '';
  }
  return value.replace(/\r\n|\r/g, '\n').replace(/[ \t]+/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
}

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
    'feedback',
    'assistant_text',
    'display_content',
    'raw_content',
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

function extractExampleInputPreview(example: PromptBuilderExample): string {
  const payload = parseJsonSafe(example.input_payload_json);
  const record = payload && typeof payload === 'object' ? payload as Record<string, unknown> : null;
  return extractTextFromValue(record?.variables ?? record?.form_data ?? payload);
}

function extractExampleApprovedPreview(example: PromptBuilderExample): string {
  const normalized = parseJsonSafe(example.normalized_output_json);
  const outputPayload = parseJsonSafe(example.output_payload_json);
  const expected = parseJsonSafe(example.expected_output_json);
  return extractTextFromValue(normalized) || extractTextFromValue(outputPayload) || extractTextFromValue(expected);
}

function truncatePreview(value: string, maxLength = 220): string {
  const text = normalizeText(value);
  if (text.length <= maxLength) {
    return text;
  }
  return `${text.slice(0, maxLength).trim()}...`;
}

export const PromptBuilderWorkspace: React.FC<PromptBuilderWorkspaceProps> = ({
  show,
  api,
  descriptor,
  currentPrompt,
  models,
  defaultModel,
  onApplySuggestion,
  onClose,
  registerCloseHandler,
  disabled = false,
  preferredExampleDatasetId = null,
  showAutoApplyOnClose = true,
  showApplySuggestionButton = false,
  applySuggestionButtonLabel = 'Apply To Field',
}) => {
  const effectiveModels = useMemo(
    () => buildEffectiveModels(models, defaultModel),
    [defaultModel, models],
  );
  const [instructions, setInstructions] = useState('');
  const [selectedModel, setSelectedModel] = useState(defaultModel || effectiveModels[0]?.id || '');
  const [result, setResult] = useState<PromptBuilderResponse | null>(null);
  const [editablePromptTemplate, setEditablePromptTemplate] = useState('');
  const [autoApplyOnClose, setAutoApplyOnClose] = useState(true);
  const [running, setRunning] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [selectedExamples, setSelectedExamples] = useState<PromptBuilderExample[]>([]);
  const [showExampleImport, setShowExampleImport] = useState(false);
  const canGenerate = !disabled && !running && instructions.trim().length > 0;

  useEffect(() => {
    if (!show) {
      return;
    }
    setInstructions('');
    setSelectedModel(defaultModel || effectiveModels[0]?.id || '');
    setResult(null);
    setEditablePromptTemplate('');
    setAutoApplyOnClose(true);
    setError(null);
    setSelectedExamples([]);
  }, [show, defaultModel, effectiveModels]);

  useEffect(() => {
    const nextPrompt = normalizeGeneratedPromptTemplate(result?.suggestion?.prompt_template || '');
    setEditablePromptTemplate(nextPrompt);
  }, [result]);

  const suggestion = result?.suggestion;
  const promptContract = result?.prompt_contract as PromptContract | null | undefined;

  const handleBuild = async () => {
    if (disabled) {
      return;
    }

    setRunning(true);
    setError(null);
    try {
      const nextResult = await api.builderRun({
        descriptor,
        currentPrompt,
        instructions,
        selectedModel,
        examples: selectedExamples,
      });
      const normalizedTemplate = normalizeGeneratedPromptTemplate(nextResult?.suggestion?.prompt_template || '');
      setEditablePromptTemplate(normalizedTemplate);
      setResult({
        ...nextResult,
        suggestion: {
          ...nextResult.suggestion,
          prompt_template: normalizedTemplate,
        },
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Prompt builder failed');
    } finally {
      setRunning(false);
    }
  };

  const applySuggestionToField = () => {
    if (!suggestion) {
      return;
    }
    onApplySuggestion(
      editablePromptTemplate,
      suggestion.variables,
      suggestion.change_summary,
    );
  };

  const handleClose = useCallback(() => {
    if (autoApplyOnClose && suggestion) {
      applySuggestionToField();
    }
    onClose();
  }, [autoApplyOnClose, onClose, suggestion, editablePromptTemplate, result]);

  useEffect(() => {
    registerCloseHandler?.(handleClose);
  }, [handleClose, registerCloseHandler]);

  return (
    <>
      {error && <Alert variant="danger">{error}</Alert>}

      <Form.Group>
        <details className="prompt-current-collapsible">
          <summary className="small font-weight-bold text-muted">Current Prompt (click to expand)</summary>
          <pre className="bg-light border rounded p-3 prompt-pre small mt-2 mb-0">{currentPrompt || 'No prompt yet.'}</pre>
        </details>
      </Form.Group>

      {promptContract?.section_order?.length ? (
        <Alert variant="secondary" className="py-2 small">
          <div className="font-weight-bold mb-1">Shared Prompt Scaffold</div>
          <div>{promptContract.section_order.join(' -> ')}</div>
        </Alert>
      ) : null}

      <Form.Group>
        <div className="d-flex justify-content-between align-items-center mb-1">
          <Form.Label className="small font-weight-bold mb-0">Helper Model</Form.Label>
          <Button size="sm" variant="outline-info" onClick={() => setShowExampleImport(true)} disabled={disabled}>
            Import From Evaluations
          </Button>
        </div>
        <Select
          className="prompt-builder-select"
          classNamePrefix="react-select"
          options={effectiveModels.map((model) => ({ value: model.id, label: model.id }))}
          value={effectiveModels.find((item) => item.id === selectedModel)
            ? { value: selectedModel, label: selectedModel }
            : null}
          onChange={(option) => setSelectedModel(option?.value || '')}
          isSearchable
          isDisabled={disabled}
          styles={selectMenuStyles as any}
        />
      </Form.Group>

      <Form.Group>
        <Form.Label className="small font-weight-bold">Instructions</Form.Label>
        <div className="small text-muted mb-2">
          Describe what you want to build or improve here: tone, constraints, output format, examples, structure, safety, or missing context.
        </div>
        <Form.Control
          as="textarea"
          rows={5}
          className="prompt-builder-instructions"
          value={instructions}
          disabled={disabled}
          onChange={(event) => setInstructions(event.target.value)}
          placeholder="Describe what should improve: tone, output format, variables, constraints, safety, etc."
        />
      </Form.Group>

      <div className="border rounded p-2 bg-light mb-3">
        <div className="small font-weight-bold text-muted mb-2">Selected Examples</div>
        {selectedExamples.length === 0 ? (
          <div className="small text-muted">No evaluation-backed examples selected yet.</div>
        ) : (
          <>
            <div className="mb-2">
              {selectedExamples.map((example) => {
                const inputPreview = truncatePreview(extractExampleInputPreview(example), 240);
                const responsePreview = truncatePreview(extractExampleApprovedPreview(example), 240);
                return (
                  <div key={`${example.case_id}-${example.score_id || 0}`} className="border rounded bg-white p-2 mb-2">
                    <div className="d-flex justify-content-between align-items-start">
                      <div className="mr-3">
                        <div className="small font-weight-bold">{summarizeExample(example)}</div>
                        <div className="small text-muted">
                          Approved by {example.approved_by_name || 'Unknown reviewer'}
                          {example.approved_at ? ` on ${example.approved_at}` : ''}
                        </div>
                      </div>
                      <Button
                        type="button"
                        size="sm"
                        variant="outline-secondary"
                        onClick={() => setSelectedExamples((current) => current.filter((item) => item.case_id !== example.case_id))}
                      >
                        Remove
                      </Button>
                    </div>
                    <div className="mt-2 small">
                      <div className="font-weight-bold text-muted">Student Input</div>
                      <div>{inputPreview || 'No student input preview available.'}</div>
                    </div>
                    <div className="mt-2 small">
                      <div className="font-weight-bold text-muted">Approved Response</div>
                      <div>{responsePreview || 'No approved response preview available.'}</div>
                    </div>
                  </div>
                );
              })}
            </div>
            <details>
              <summary className="small text-muted">Preview structured example payload</summary>
              <div className="mt-2">
                <JsonInspector value={selectedExamples} className="small" />
              </div>
            </details>
          </>
        )}
      </div>

      {running && (
        <Alert variant="info" className="py-2">
          Building prompt suggestion...
        </Alert>
      )}

      {suggestion && (
        <div className="prompt-builder-result border rounded p-3 bg-light">
          <div className="small font-weight-bold text-muted mb-2">Prompt Diff (Current vs Generated)</div>
          <div className="prompt-builder-diff mb-3">
            <PromptDiffViewer
              leftContent={currentPrompt || ''}
              rightContent={editablePromptTemplate || ''}
              readOnly={false}
              onRightContentChange={setEditablePromptTemplate}
            />
          </div>

          <div className="small font-weight-bold text-muted mt-3 mb-2">Variables</div>
          {suggestion.variables.length === 0 ? (
            <div className="small text-muted">No variable suggestions.</div>
          ) : (
            <JsonInspector value={suggestion.variables} className="small" />
          )}

          <div className="small font-weight-bold text-muted mt-3 mb-2">Notes</div>
          {suggestion.notes.length === 0 ? (
            <div className="small text-muted">No extra notes.</div>
          ) : (
            <ul className="small mb-0">
              {suggestion.notes.map((note, index) => (
                <li key={index}>{note}</li>
              ))}
            </ul>
          )}

          <div className="small font-weight-bold text-muted mt-3 mb-1">Change Summary</div>
          <div className="small">{suggestion.change_summary || 'No summary returned.'}</div>

          <details className="mt-3">
            <summary className="small font-weight-bold text-muted">Builder Request Payload</summary>
            <div className="mt-2">
              <JsonInspector value={result?.request_payload || {}} className="small" />
            </div>
          </details>

          {showApplySuggestionButton && (
            <div className="d-flex justify-content-end mt-3">
              <Button
                size="sm"
                variant="primary"
                onClick={applySuggestionToField}
              >
                {applySuggestionButtonLabel}
              </Button>
            </div>
          )}
        </div>
      )}

      <div className="d-flex justify-content-between align-items-center mt-3">
        <div className="mr-auto d-flex align-items-center">
          {showAutoApplyOnClose && (
            <Form.Check
              id="prompt-builder-auto-apply"
              type="checkbox"
              className="small mb-0"
              checked={autoApplyOnClose}
              onChange={(event) => setAutoApplyOnClose(event.target.checked)}
              label="Auto-apply on close"
              disabled={!suggestion}
            />
          )}
        </div>
        <div className="d-flex align-items-center" style={{ gap: 8 }}>
          <Button size="sm" variant="secondary" onClick={handleClose}>
            Close
          </Button>
          <Button size="sm" variant="success" onClick={handleBuild} disabled={!canGenerate}>
            {running ? (
              <>
                <Spinner animation="border" size="sm" className="mr-2" />
                Building...
              </>
            ) : (
              <>
                <i className="fas fa-wand-magic-sparkles mr-2"></i>
                Generate Suggestion
              </>
            )}
          </Button>
        </div>
      </div>

      <PromptBuilderExampleImportModal
        show={showExampleImport}
        onHide={() => setShowExampleImport(false)}
        api={api}
        descriptor={descriptor}
        preferredDatasetId={preferredExampleDatasetId}
        onImport={(examples) => {
          setSelectedExamples((current) => {
            const seen = new Set(current.map((item) => item.case_id));
            const merged = [...current];
            examples.forEach((example) => {
              if (!seen.has(example.case_id)) {
                seen.add(example.case_id);
                merged.push(example);
              }
            });
            return merged;
          });
        }}
      />
    </>
  );
};
