import React, { useEffect, useMemo, useRef, useState } from 'react';
import Select from 'react-select';
import { Alert, Badge, Button, Col, Form, Modal, Row, Spinner } from 'react-bootstrap';
import { PromptEditor } from './PromptEditor';
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
  PromptPlaygroundRuntimeType,
  PromptVariableDefinition,
} from './promptTypes';

interface PromptPlaygroundModalProps {
  show: boolean;
  onHide: () => void;
  api: ReturnType<typeof createPromptLabApi>;
  descriptor: PromptDescriptor;
  executionProfile: PromptExecutionProfile;
  playgroundRuntimeType?: PromptPlaygroundRuntimeType;
  models: PromptModel[];
  variablesSchema: PromptVariableDefinition[];
  promptValue: string;
  disabled?: boolean;
  defaultModel?: string | null;
  resolveRuntimeOverrides: () => Record<string, unknown>;
  resolveInitialVariables?: () => Record<string, unknown>;
  onRunComplete?: (payload: {
    variables: Record<string, unknown>;
    messageHistory: PromptMessage[];
    runtimeOverrides: Record<string, unknown>;
    response: PromptPlaygroundResponse;
  }) => void;
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

function detectVariablesFromPrompt(prompt: string): PromptVariableDefinition[] {
  const matches = Array.from(prompt.matchAll(/\{\{(\w+)\}\}/g)).map((entry) => entry[1]);
  const unique = Array.from(new Set(matches));
  return unique.map((name) => ({
    name,
    type: 'string',
    required: false,
    description: 'Auto-detected from draft prompt',
  }));
}

function mergeVariableSchemas(
  baseSchema: PromptVariableDefinition[],
  detectedSchema: PromptVariableDefinition[],
): PromptVariableDefinition[] {
  const map = new Map<string, PromptVariableDefinition>();
  baseSchema.forEach((item) => map.set(item.name, item));
  detectedSchema.forEach((item) => {
    if (!map.has(item.name)) {
      map.set(item.name, item);
    }
  });
  return Array.from(map.values());
}

function stableStringify(value: unknown): string {
  try {
    return JSON.stringify(value ?? null);
  } catch {
    return String(value);
  }
}

function tryParseJsonObject(value: string): Record<string, unknown> | null {
  try {
    const parsed = parseJsonObject(value);
    return parsed;
  } catch {
    return null;
  }
}

export const PromptPlaygroundModal: React.FC<PromptPlaygroundModalProps> = ({
  show,
  onHide,
  api,
  descriptor,
  executionProfile,
  playgroundRuntimeType = 'none',
  models,
  variablesSchema,
  promptValue,
  disabled = false,
  defaultModel,
  resolveRuntimeOverrides,
  resolveInitialVariables,
  onRunComplete,
}) => {
  const selectMenuStyles = {
    menuList: (base: Record<string, unknown>) => ({
      ...base,
      maxHeight: 220,
    }),
  };
  const effectiveModels = useMemo(() => buildEffectiveModels(models, defaultModel), [defaultModel, models]);
  const detectedVariables = useMemo(() => detectVariablesFromPrompt(promptValue), [promptValue]);
  const effectiveSchema = useMemo(
    () => mergeVariableSchemas(variablesSchema || [], detectedVariables),
    [detectedVariables, variablesSchema],
  );
  const initialVariables = useMemo(
    () => normalizeInitialValues(effectiveSchema, resolveInitialVariables?.() || {}),
    [effectiveSchema, resolveInitialVariables],
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
  const [showDraft, setShowDraft] = useState(false);
  const previousRawModeRef = useRef(useRawJson);

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
    setShowDraft(false);
  }, [defaultModel, effectiveModels, initialVariables, show]);

  useEffect(() => {
    if (!show) {
      return;
    }

    if (previousRawModeRef.current !== useRawJson) {
      if (useRawJson) {
        setRawJson(JSON.stringify(variables, null, 2));
      } else {
        const parsed = tryParseJsonObject(rawJson);
        if (parsed) {
          setVariables(normalizeInitialValues(effectiveSchema, parsed));
        }
      }
      previousRawModeRef.current = useRawJson;
    }
  }, [effectiveSchema, rawJson, show, useRawJson, variables]);

  const isChatRuntime = playgroundRuntimeType === 'chat'
    || (playgroundRuntimeType === 'none' && executionProfile === 'chat_runtime');
  const canRun = !disabled && promptValue.trim() !== '' && selectedModels.length > 0;

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
    setResult(null);
    try {
      const payloadVariables = useRawJson ? parseJsonObject(rawJson) : variables;
      const resolvedRuntimeOverrides = resolveRuntimeOverrides();
      const nextResult = await api.playgroundRun({
        descriptor,
        draftPrompt: promptValue,
        runtimeOverrides: resolvedRuntimeOverrides,
        variables: payloadVariables,
        messageHistory,
        selectedModels,
      });
      setResult(nextResult);
      onRunComplete?.({
        variables: payloadVariables,
        messageHistory,
        runtimeOverrides: resolvedRuntimeOverrides,
        response: nextResult,
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Playground run failed');
    } finally {
      setRunning(false);
    }
  };

  const sharedEffectiveContext = useMemo(() => {
    if (!result?.runs?.length) {
      return null;
    }
    const first = stableStringify(result.runs[0].effective_context);
    const allSame = result.runs.every((run) => stableStringify(run.effective_context) === first);
    return allSame ? result.runs[0].effective_context : null;
  }, [result]);

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
                <Select
                  classNamePrefix="react-select"
                  isMulti
                  isSearchable
                  closeMenuOnSelect={false}
                  options={effectiveModels.map((model) => ({ value: model.id, label: model.id }))}
                  value={selectedModels.map((modelId) => ({ value: modelId, label: modelId }))}
                  onChange={(options) => {
                    const values = (options || []).map((option) => option.value).slice(0, 3);
                    setSelectedModels(values);
                  }}
                  styles={selectMenuStyles as any}
                />
              )}
              <Form.Text className="text-muted">
                Select up to 3 models. Multiple selections enable compare mode.
              </Form.Text>
              {selectedModels.length === 1 && (
                <div className="mt-2">
                  <Badge variant="secondary">Primary</Badge>
                </div>
              )}
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
                <PromptEditor
                  value={rawJson}
                  onChange={setRawJson}
                  editorMode="monaco"
                  language="json"
                  minHeight={260}
                />
              ) : (
                <PromptVariableInputs
                  schema={effectiveSchema}
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
              <div className="d-flex justify-content-between align-items-center">
                <div className="small font-weight-bold text-muted">Draft Prompt</div>
                <Button
                  size="sm"
                  variant="outline-secondary"
                  onClick={() => setShowDraft((current) => !current)}
                >
                  {showDraft ? 'Collapse' : 'Expand'}
                </Button>
              </div>
              {showDraft && (
                <pre className="bg-light border rounded p-3 mt-2 mb-0 prompt-pre">{promptValue}</pre>
              )}
            </div>

            {result?.runs?.length ? (
              result.runs.map((run, index) => (
                <div key={`${run.model}-${index}`} className="mb-3">
                  <PromptResultPanel run={run} colorIndex={index} />
                  {!sharedEffectiveContext && (
                    <PromptEffectiveContextPanel
                      effectiveContext={run.effective_context}
                      title={`Effective Context (${run.model})`}
                      colorIndex={index}
                    />
                  )}
                </div>
              ))
            ) : (
              <div className="prompt-playground-empty border rounded bg-light p-4 text-center text-muted small">
                {running ? (
                  <>
                    <Spinner animation="border" size="sm" className="mr-2" />
                    Generating new result...
                  </>
                ) : (
                  'Run the playground to inspect the effective context, structured result, and raw payload.'
                )}
              </div>
            )}

            {sharedEffectiveContext && (
              <PromptEffectiveContextPanel
                effectiveContext={sharedEffectiveContext}
                title="Effective Context (shared across selected models)"
                colorIndex={0}
              />
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
