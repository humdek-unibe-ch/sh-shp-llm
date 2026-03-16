import React, { useEffect, useMemo, useState } from 'react';
import Select from 'react-select';
import { Alert, Button, Form, Modal, Spinner } from 'react-bootstrap';
import { PromptDiffViewer } from './PromptDiffViewer';
import { JsonInspector, normalizeGeneratedPromptTemplate } from '../shared/JsonInspector';
import type { createPromptLabApi } from './promptApi';
import type {
  PromptBuilderResponse,
  PromptDescriptor,
  PromptModel,
  PromptVariableDefinition,
} from './promptTypes';

interface PromptBuilderModalProps {
  show: boolean;
  onHide: () => void;
  api: ReturnType<typeof createPromptLabApi>;
  descriptor: PromptDescriptor;
  currentPrompt: string;
  models: PromptModel[];
  defaultModel?: string | null;
  onApplySuggestion: (promptTemplate: string, variables: PromptVariableDefinition[], changeSummary: string) => void;
  disabled?: boolean;
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

export const PromptBuilderModal: React.FC<PromptBuilderModalProps> = ({
  show,
  onHide,
  api,
  descriptor,
  currentPrompt,
  models,
  defaultModel,
  onApplySuggestion,
  disabled = false,
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
  }, [defaultModel, show]);

  useEffect(() => {
    const nextPrompt = normalizeGeneratedPromptTemplate(result?.suggestion?.prompt_template || '');
    setEditablePromptTemplate(nextPrompt);
  }, [result]);

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

  const handleClose = () => {
    if (autoApplyOnClose && suggestion) {
      applySuggestionToField();
    }
    onHide();
  };

  const suggestion = result?.suggestion;

  return (
    <Modal show={show} onHide={handleClose} centered dialogClassName="prompt-modal-90 prompt-builder-modal">
      <Modal.Header closeButton className="py-2">
        <Modal.Title className="h6">
          <i className="fas fa-magic mr-2"></i>
          Build With AI
        </Modal.Title>
      </Modal.Header>
      <Modal.Body>
        {error && <Alert variant="danger">{error}</Alert>}

        <Form.Group>
          <details className="prompt-current-collapsible">
            <summary className="small font-weight-bold text-muted">Current Prompt (click to expand)</summary>
            <pre className="bg-light border rounded p-3 prompt-pre small mt-2 mb-0">{currentPrompt || 'No prompt yet.'}</pre>
          </details>
        </Form.Group>

        <Form.Group>
          <Form.Label className="small font-weight-bold">Helper Model</Form.Label>
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

            <div className="d-flex justify-content-end mt-3">
              <Button
                size="sm"
                variant="primary"
                onClick={applySuggestionToField}
              >
                Apply To Field
              </Button>
            </div>
          </div>
        )}
      </Modal.Body>
      <Modal.Footer className="py-2">
        <div className="mr-auto d-flex align-items-center">
          <Form.Check
            id="prompt-builder-auto-apply"
            type="checkbox"
            className="small mb-0"
            checked={autoApplyOnClose}
            onChange={(event) => setAutoApplyOnClose(event.target.checked)}
            label="Auto-apply on close"
            disabled={!suggestion}
          />
        </div>
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
      </Modal.Footer>
    </Modal>
  );
};
