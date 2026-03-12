import React, { useEffect, useMemo, useState } from 'react';
import { Alert, Badge, Button, Col, Form, Modal, Row, Spinner } from 'react-bootstrap';
import { PromptEffectiveContextPanel } from './PromptEffectiveContextPanel';
import { PromptResultPanel } from './PromptResultPanel';
import { PromptVariableInputs } from './PromptVariableInputs';
import type { createPromptLabApi } from './promptApi';
import type {
  PromptDescriptor,
  PromptExecutionProfile,
  PromptMessage,
  PromptModel,
  PromptPlaygroundResponse,
  PromptVariableDefinition,
} from './promptTypes';

interface PromptPlaygroundModalProps {
  show: boolean;
  onHide: () => void;
  api: ReturnType<typeof createPromptLabApi>;
  descriptor: PromptDescriptor;
  executionProfile: PromptExecutionProfile;
  models: PromptModel[];
  variablesSchema: PromptVariableDefinition[];
  promptValue: string;
  disabled?: boolean;
  defaultModel?: string | null;
  resolveRuntimeOverrides: () => Record<string, unknown>;
  resolveInitialVariables?: () => Record<string, unknown>;
}

function buildEffectiveModels(models: PromptModel[], defaultModel?: string | null): PromptModel[] {
  const normalized = Array.isArray(models) ? models.filter((item) => item?.id) : [];
  if (defaultModel && !normalized.some((item) => item.id === defaultModel)) {
    return [{ id: defaultModel }, ...normalized];
  }
  return normalized;
}

function parseJsonObject(value: string): Record<string, unknown> {
  if (!value.trim()) {
    return {};
  }

  const parsed = JSON.parse(value);
  return parsed && typeof parsed === 'object' ? parsed as Record<string, unknown> : {};
}

function normalizeInitialValues(schema: PromptVariableDefinition[], currentValues: Record<string, unknown>) {
  const next: Record<string, unknown> = {};
  schema.forEach((item) => {
    next[item.name] = currentValues[item.name] ?? '';
  });
  return next;
}

export const PromptPlaygroundModal: React.FC<PromptPlaygroundModalProps> = ({
  show,
  onHide,
  api,
  descriptor,
  executionProfile,
  models,
  variablesSchema,
  promptValue,
  disabled = false,
  defaultModel,
  resolveRuntimeOverrides,
  resolveInitialVariables,
}) => {
  const effectiveModels = useMemo(() => buildEffectiveModels(models, defaultModel), [defaultModel, models]);
  const initialVariables = useMemo(
    () => normalizeInitialValues(variablesSchema, resolveInitialVariables?.() || {}),
    [resolveInitialVariables, variablesSchema],
  );
  const [selectedModels, setSelectedModels] = useState<string[]>([]);
  const [variables, setVariables] = useState<Record<string, unknown>>(initialVariables);
  const [useRawJson, setUseRawJson] = useState(false);
  const [rawJson, setRawJson] = useState(JSON.stringify(initialVariables, null, 2));
  const [messageHistory, setMessageHistory] = useState<PromptMessage[]>([
    { role: 'user', content: 'Test this prompt in playground mode.' },
  ]);
  const [result, setResult] = useState<PromptPlaygroundResponse | null>(null);
  const [running, setRunning] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!show) {
      return;
    }

    const initialModel = defaultModel || effectiveModels[0]?.id || '';
    setSelectedModels(initialModel ? [initialModel] : []);
    setVariables(initialVariables);
    setRawJson(JSON.stringify(initialVariables, null, 2));
    setMessageHistory([{ role: 'user', content: 'Test this prompt in playground mode.' }]);
    setResult(null);
    setError(null);
    setUseRawJson(false);
  }, [defaultModel, effectiveModels, initialVariables, show]);

  const isChatRuntime = executionProfile === 'chat_runtime' || executionProfile === 'therapy_chat_runtime';
  const canRun = !disabled && promptValue.trim() !== '' && selectedModels.length > 0;

  const toggleModel = (modelId: string) => {
    setSelectedModels((current) => {
      if (current.includes(modelId)) {
        return current.filter((item) => item !== modelId);
      }
      if (current.length >= 3) {
        return current;
      }
      return [...current, modelId];
    });
  };

  const updateMessage = (index: number, field: keyof PromptMessage, value: string) => {
    setMessageHistory((current) => current.map((item, itemIndex) => (
      itemIndex === index ? { ...item, [field]: value } : item
    )));
  };

  const addMessage = () => {
    setMessageHistory((current) => [...current, { role: 'user', content: '' }]);
  };

  const removeMessage = (index: number) => {
    setMessageHistory((current) => current.filter((_, itemIndex) => itemIndex !== index));
  };

  const handleRun = async () => {
    if (!canRun) {
      return;
    }

    setRunning(true);
    setError(null);
    try {
      const payloadVariables = useRawJson ? parseJsonObject(rawJson) : variables;
      const nextResult = await api.playgroundRun({
        descriptor,
        draftPrompt: promptValue,
        runtimeOverrides: resolveRuntimeOverrides(),
        variables: payloadVariables,
        messageHistory,
        selectedModels,
      });
      setResult(nextResult);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Playground run failed');
    } finally {
      setRunning(false);
    }
  };

  return (
    <Modal show={show} onHide={onHide} centered dialogClassName="prompt-modal-90 prompt-playground-modal">
      <Modal.Header closeButton className="py-2">
        <Modal.Title className="h6">
          <i className="fas fa-flask mr-2"></i>
          Prompt Playground
        </Modal.Title>
      </Modal.Header>
      <Modal.Body>
        {error && <Alert variant="danger">{error}</Alert>}

        <Row>
          <Col lg={4}>
            <div className="border rounded bg-light p-3 mb-3">
              <div className="small font-weight-bold text-muted mb-2">Models</div>
              {effectiveModels.length === 0 ? (
                <div className="small text-muted">No models available.</div>
              ) : (
                effectiveModels.map((model) => (
                  <Form.Check
                    key={model.id}
                    id={`prompt-model-${model.id}`}
                    type="checkbox"
                    label={
                      <span className="small">
                        {model.id}
                        {selectedModels[0] === model.id && selectedModels.length === 1 ? (
                          <Badge variant="secondary" className="ml-2">Primary</Badge>
                        ) : null}
                      </span>
                    }
                    checked={selectedModels.includes(model.id)}
                    disabled={!selectedModels.includes(model.id) && selectedModels.length >= 3}
                    onChange={() => toggleModel(model.id)}
                  />
                ))
              )}
              <Form.Text className="text-muted">
                Select up to 3 models. Multiple selections enable compare mode.
              </Form.Text>
            </div>

            <div className="border rounded p-3 mb-3">
              <div className="small font-weight-bold text-muted mb-2">Prompt Variables</div>
              <Form.Check
                id="prompt-playground-raw-json-toggle"
                type="switch"
                className="mb-2"
                checked={useRawJson}
                onChange={(event) => setUseRawJson(event.target.checked)}
                label={<span className="small">Advanced raw JSON input</span>}
              />
              {useRawJson ? (
                <Form.Control
                  as="textarea"
                  rows={10}
                  value={rawJson}
                  onChange={(event) => setRawJson(event.target.value)}
                  className="font-monospace small"
                />
              ) : (
                <PromptVariableInputs
                  schema={variablesSchema}
                  values={variables}
                  onChange={(name, value) => setVariables((current) => ({ ...current, [name]: value }))}
                />
              )}
            </div>

            {isChatRuntime && (
              <div className="border rounded p-3">
                <div className="d-flex justify-content-between align-items-center mb-2">
                  <div className="small font-weight-bold text-muted">Conversation Messages</div>
                  <Button size="sm" variant="outline-secondary" onClick={addMessage}>
                    Add
                  </Button>
                </div>
                {messageHistory.map((message, index) => (
                  <div key={`${message.role}-${index}`} className="border rounded p-2 mb-2 bg-light">
                    <Row>
                      <Col sm={4}>
                        <Form.Control
                          as="select"
                          size="sm"
                          value={message.role}
                          onChange={(event) => updateMessage(index, 'role', event.target.value)}
                        >
                          <option value="user">user</option>
                          <option value="assistant">assistant</option>
                          <option value="system">system</option>
                        </Form.Control>
                      </Col>
                      <Col sm={8} className="text-right">
                        <Button
                          size="sm"
                          variant="link"
                          className="text-danger p-0"
                          onClick={() => removeMessage(index)}
                          disabled={messageHistory.length <= 1}
                        >
                          Remove
                        </Button>
                      </Col>
                    </Row>
                    <Form.Control
                      as="textarea"
                      rows={3}
                      className="mt-2 small"
                      value={message.content}
                      onChange={(event) => updateMessage(index, 'content', event.target.value)}
                    />
                  </div>
                ))}
              </div>
            )}
          </Col>

          <Col lg={8}>
            <div className="border rounded p-3 mb-3">
              <div className="small font-weight-bold text-muted mb-2">Draft Prompt</div>
              <pre className="bg-light border rounded p-3 mb-0 prompt-pre">{promptValue}</pre>
            </div>

            {result?.runs?.length ? (
              result.runs.map((run, index) => (
                <div key={`${run.model}-${index}`} className="mb-3">
                  <PromptResultPanel run={run} />
                  <PromptEffectiveContextPanel effectiveContext={run.effective_context} />
                </div>
              ))
            ) : (
              <div className="prompt-playground-empty border rounded bg-light p-4 text-center text-muted small">
                Run the playground to inspect the effective context, structured result, and raw payload.
              </div>
            )}
          </Col>
        </Row>
      </Modal.Body>
      <Modal.Footer className="py-2">
        <Button size="sm" variant="secondary" onClick={onHide}>
          Close
        </Button>
        <Button size="sm" variant="primary" onClick={handleRun} disabled={!canRun || running}>
          {running ? (
            <>
              <Spinner animation="border" size="sm" className="mr-2" />
              Running...
            </>
          ) : (
            <>
              <i className="fas fa-play mr-2"></i>
              Run Playground
            </>
          )}
        </Button>
      </Modal.Footer>
    </Modal>
  );
};
