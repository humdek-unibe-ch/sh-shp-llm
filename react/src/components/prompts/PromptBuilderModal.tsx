import React, { useEffect, useState } from 'react';
import { Alert, Button, Form, Modal, Spinner } from 'react-bootstrap';
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

function buildEffectiveModels(models: PromptModel[], defaultModel?: string | null): PromptModel[] {
  const normalized = Array.isArray(models) ? models.filter((item) => item?.id) : [];
  if (defaultModel && !normalized.some((item) => item.id === defaultModel)) {
    return [{ id: defaultModel }, ...normalized];
  }
  return normalized;
}

function safeStringify(value: unknown): string {
  try {
    return JSON.stringify(value, null, 2);
  } catch {
    return String(value);
  }
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
  const effectiveModels = buildEffectiveModels(models, defaultModel);
  const [instructions, setInstructions] = useState('');
  const [selectedModel, setSelectedModel] = useState(defaultModel || effectiveModels[0]?.id || '');
  const [result, setResult] = useState<PromptBuilderResponse | null>(null);
  const [running, setRunning] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!show) {
      return;
    }
    setInstructions('');
    setSelectedModel(defaultModel || effectiveModels[0]?.id || '');
    setResult(null);
    setError(null);
  }, [defaultModel, effectiveModels, show]);

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
      setResult(nextResult);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Prompt builder failed');
    } finally {
      setRunning(false);
    }
  };

  const suggestion = result?.suggestion;

  return (
    <Modal show={show} onHide={onHide} centered dialogClassName="prompt-modal-90 prompt-builder-modal">
      <Modal.Header closeButton className="py-2">
        <Modal.Title className="h6">
          <i className="fas fa-magic mr-2"></i>
          Build With AI
        </Modal.Title>
      </Modal.Header>
      <Modal.Body>
        {error && <Alert variant="danger">{error}</Alert>}

        <Form.Group>
          <Form.Label className="small font-weight-bold">Current Prompt</Form.Label>
          <pre className="bg-light border rounded p-3 prompt-pre small">{currentPrompt || 'No prompt yet.'}</pre>
        </Form.Group>

        <Form.Group>
          <Form.Label className="small font-weight-bold">Helper Model</Form.Label>
          <Form.Control
            as="select"
            size="sm"
            value={selectedModel}
            onChange={(event) => setSelectedModel(event.target.value)}
          >
            {effectiveModels.map((model) => (
              <option key={model.id} value={model.id}>{model.id}</option>
            ))}
          </Form.Control>
        </Form.Group>

        <Form.Group>
          <Form.Label className="small font-weight-bold">Instructions</Form.Label>
          <Form.Control
            as="textarea"
            rows={5}
            value={instructions}
            onChange={(event) => setInstructions(event.target.value)}
            placeholder="Describe what should improve: tone, output format, variables, constraints, safety, etc."
          />
        </Form.Group>

        {suggestion && (
          <div className="prompt-builder-result border rounded p-3 bg-light">
            <div className="small font-weight-bold text-muted mb-2">Suggested Prompt</div>
            <pre className="border rounded bg-white p-3 prompt-pre small">{suggestion.prompt_template}</pre>

            <div className="small font-weight-bold text-muted mt-3 mb-2">Variables</div>
            {suggestion.variables.length === 0 ? (
              <div className="small text-muted">No variable suggestions.</div>
            ) : (
              <pre className="border rounded bg-white p-3 prompt-pre small">
                {safeStringify(suggestion.variables)}
              </pre>
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
              <pre className="small bg-white border rounded p-3 mt-2 mb-0 prompt-pre">
                {safeStringify(result?.request_payload || {})}
              </pre>
            </details>

            <div className="d-flex justify-content-end mt-3">
              <Button
                size="sm"
                variant="primary"
                onClick={() => onApplySuggestion(
                  suggestion.prompt_template,
                  suggestion.variables,
                  suggestion.change_summary,
                )}
              >
                Apply Suggested Prompt
              </Button>
            </div>
          </div>
        )}
      </Modal.Body>
      <Modal.Footer className="py-2">
        <Button size="sm" variant="secondary" onClick={onHide}>
          Close
        </Button>
        <Button size="sm" variant="success" onClick={handleBuild} disabled={disabled || running}>
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
