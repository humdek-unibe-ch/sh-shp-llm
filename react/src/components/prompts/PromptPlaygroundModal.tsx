import React, { useEffect, useMemo, useState } from 'react';
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
  const next: Record<string, unknown> = { ...currentValues };
  if (!schema.length) {
    return next;
  }
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

function deriveVariableSchemaFromValues(values: Record<string, unknown>): PromptVariableDefinition[] {
  return Object.keys(values || {}).map((name) => ({
    name,
    type: 'string',
    required: false,
    description: 'Derived from JSON payload',
  }));
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

function isValidMessageRole(role: string): role is PromptMessage['role'] {
  return role === 'system' || role === 'user' || role === 'assistant';
}

function normalizeMessageHistory(messages: unknown): PromptMessage[] {
  if (!Array.isArray(messages)) {
    return [];
  }

  return messages
    .map((item) => {
      if (!item || typeof item !== 'object') {
        return null;
      }
      const role = String((item as Record<string, unknown>).role || '');
      const content = String((item as Record<string, unknown>).content || '').trim();
      if (!isValidMessageRole(role) || content === '') {
        return null;
      }
      return { role, content };
    })
    .filter((item): item is PromptMessage => item !== null);
}

function buildRawPayload(
  isChatRuntime: boolean,
  variables: Record<string, unknown>,
  messageHistory: PromptMessage[],
): Record<string, unknown> {
  if (!isChatRuntime) {
    return variables;
  }

  return {
    variables,
    message_history: messageHistory,
  };
}

const messageRoleOptions = [
  { value: 'user', label: 'user' },
  { value: 'assistant', label: 'assistant' },
  { value: 'system', label: 'system' },
];

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
  const initialRuntimeValues = useMemo(() => resolveInitialVariables?.() || {}, [resolveInitialVariables]);
  const valueDerivedSchema = useMemo(
    () => deriveVariableSchemaFromValues(initialRuntimeValues),
    [initialRuntimeValues],
  );
  const effectiveSchema = useMemo(() => {
    const merged = mergeVariableSchemas(variablesSchema || [], detectedVariables);
    return mergeVariableSchemas(merged, valueDerivedSchema);
  }, [detectedVariables, valueDerivedSchema, variablesSchema]);
  const initialVariables = useMemo(() => normalizeInitialValues(effectiveSchema, initialRuntimeValues), [effectiveSchema, initialRuntimeValues]);
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
  const [jsonSyncError, setJsonSyncError] = useState<string | null>(null);
  const [showDraft, setShowDraft] = useState(false);
  const [schemaFromJson, setSchemaFromJson] = useState<PromptVariableDefinition[]>([]);
  const isChatRuntime = playgroundRuntimeType === 'chat'
    || (playgroundRuntimeType === 'none' && executionProfile === 'chat_runtime');

  useEffect(() => {
    if (!show) {
      return;
    }

    const initialModel = defaultModel || effectiveModels[0]?.id || '';
    setSelectedModels(initialModel ? [initialModel] : []);
    setVariables(initialVariables);
    setMessageHistory([{ role: 'user', content: 'Test this prompt in playground mode.' }]);
    setRawJson(JSON.stringify(buildRawPayload(isChatRuntime, initialVariables, [{ role: 'user', content: 'Test this prompt in playground mode.' }]), null, 2));
    setResult(null);
    setError(null);
    setJsonSyncError(null);
    setSchemaFromJson([]);
    setUseRawJson(false);
    setShowDraft(false);
  }, [defaultModel, effectiveModels, initialVariables, isChatRuntime, show]);

  useEffect(() => {
    if (!show || !useRawJson) {
      return;
    }
    const parsed = tryParseJsonObject(rawJson);
    if (parsed) {
      if (isChatRuntime) {
        const parsedVariables = (parsed.variables && typeof parsed.variables === 'object' && !Array.isArray(parsed.variables))
          ? (parsed.variables as Record<string, unknown>)
          : {};
        const parsedHistory = normalizeMessageHistory(parsed.message_history);
        setVariables(normalizeInitialValues(effectiveSchema, parsedVariables));
        if (parsedHistory.length > 0) {
          setMessageHistory(parsedHistory);
        }
        setSchemaFromJson(deriveVariableSchemaFromValues(parsedVariables));
      } else {
        setVariables(normalizeInitialValues(effectiveSchema, parsed));
        setSchemaFromJson(deriveVariableSchemaFromValues(parsed));
      }
      setJsonSyncError(null);
    }
  }, [effectiveSchema, isChatRuntime, rawJson, show, useRawJson]);

  useEffect(() => {
    if (!show || useRawJson) {
      return;
    }
    setRawJson(JSON.stringify(buildRawPayload(isChatRuntime, variables, messageHistory), null, 2));
  }, [isChatRuntime, messageHistory, show, useRawJson, variables]);

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

  const handleRawToggle = (checked: boolean) => {
    if (checked) {
      setRawJson(JSON.stringify(buildRawPayload(isChatRuntime, variables, messageHistory), null, 2));
      setUseRawJson(true);
      setJsonSyncError(null);
      return;
    }

    const parsed = tryParseJsonObject(rawJson);
    if (!parsed) {
      setJsonSyncError('JSON is invalid. Fix it before switching back to variable inputs.');
      return;
    }

    if (isChatRuntime) {
      const parsedVariables = (parsed.variables && typeof parsed.variables === 'object' && !Array.isArray(parsed.variables))
        ? (parsed.variables as Record<string, unknown>)
        : {};
      const parsedHistory = normalizeMessageHistory(parsed.message_history);
      setVariables(normalizeInitialValues(effectiveSchema, parsedVariables));
      setSchemaFromJson(deriveVariableSchemaFromValues(parsedVariables));
      if (parsedHistory.length > 0) {
        setMessageHistory(parsedHistory);
      }
    } else {
      setVariables(normalizeInitialValues(effectiveSchema, parsed));
      setSchemaFromJson(deriveVariableSchemaFromValues(parsed));
    }
    setUseRawJson(false);
    setJsonSyncError(null);
  };

  const displaySchema = useMemo(() => {
    if (!schemaFromJson.length) {
      return effectiveSchema;
    }
    return mergeVariableSchemas(effectiveSchema, schemaFromJson);
  }, [effectiveSchema, schemaFromJson]);

  const handleRun = async () => {
    if (!canRun) {
      return;
    }

    setRunning(true);
    setError(null);
    setResult(null);
    try {
      let payloadVariables = variables;
      let payloadMessageHistory = messageHistory;
      if (useRawJson) {
        const parsed = parseJsonObject(rawJson);
        if (isChatRuntime) {
          const parsedVariables = (parsed.variables && typeof parsed.variables === 'object' && !Array.isArray(parsed.variables))
            ? (parsed.variables as Record<string, unknown>)
            : {};
          const parsedHistory = normalizeMessageHistory(parsed.message_history);
          payloadVariables = parsedVariables;
          if (parsedHistory.length > 0) {
            payloadMessageHistory = parsedHistory;
          }
        } else {
          payloadVariables = parsed;
        }
      }
      const resolvedRuntimeOverrides = resolveRuntimeOverrides();
      const nextResult = await api.playgroundRun({
        descriptor,
        draftPrompt: promptValue,
        runtimeOverrides: resolvedRuntimeOverrides,
        variables: payloadVariables,
        messageHistory: payloadMessageHistory,
        selectedModels,
      });
      setResult(nextResult);
      onRunComplete?.({
        variables: payloadVariables,
        messageHistory: payloadMessageHistory,
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
                onChange={(event) => handleRawToggle(event.target.checked)}
                label={<span className="small">Advanced raw JSON input</span>}
              />
              {jsonSyncError && (
                <Alert variant="warning" className="py-1 px-2 small mb-2">
                  {jsonSyncError}
                </Alert>
              )}
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
                  schema={displaySchema}
                  values={variables}
                  onChange={(name, value) => setVariables((current) => ({ ...current, [name]: value }))}
                />
              )}
            </div>

            {isChatRuntime && !useRawJson && (
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
                        <Select
                          classNamePrefix="react-select"
                          isSearchable={false}
                          options={messageRoleOptions}
                          value={messageRoleOptions.find((option) => option.value === message.role) || messageRoleOptions[0]}
                          onChange={(option) => updateMessage(index, 'role', option?.value || 'user')}
                        />
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
