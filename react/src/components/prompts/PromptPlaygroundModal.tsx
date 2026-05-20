/**
 * Prompt Playground Modal — interactive test execution environment.
 *
 * Allows the admin to run the current prompt draft against one or more
 * models with variable inputs, view the raw/rendered response, save
 * runs as dataset test cases, and compare multi-model outputs side by side.
 *
 * @module components/prompts/PromptPlaygroundModal
 */
import React, { useEffect, useMemo, useRef, useState } from 'react';
import Select from 'react-select';
import { Alert, Badge, Button, Col, Form, Modal, Row, Spinner } from 'react-bootstrap';
import { PromptBuilderWorkspace } from './PromptBuilderWorkspace';
import { JsonMonacoEditor } from '../shared/JsonMonacoEditor';
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
  onApplyDraft?: (promptTemplate: string, variables: PromptVariableDefinition[], changeSummary: string) => void;
  onRunComplete?: (payload: {
    variables: Record<string, unknown>;
    messageHistory: PromptMessage[];
    runtimeOverrides: Record<string, unknown>;
    response: PromptPlaygroundResponse;
  }) => void;
}

/** buildEffectiveModels function. */
function buildEffectiveModels(models: PromptModel[], defaultModel?: string | null): PromptModel[] {
  const normalized = Array.isArray(models) ? models.filter((item) => item?.id) : [];
  if (defaultModel && !normalized.some((item) => item.id === defaultModel)) {
    return [{ id: defaultModel }, ...normalized];
  }
  return normalized;
}

/** parseJsonObject utility. */
function parseJsonObject(value: string): Record<string, unknown> {
  if (!value.trim()) {
    return {};
  }

  const parsed = JSON.parse(value);
  return parsed && typeof parsed === 'object' ? parsed as Record<string, unknown> : {};
}

/** normalizeInitialValues function. */
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

/** detectVariablesFromPrompt function. */
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

/** mergeVariableSchemas function. */
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

/** deriveVariableSchemaFromValues function. */
function deriveVariableSchemaFromValues(values: Record<string, unknown>): PromptVariableDefinition[] {
  return Object.keys(values || {}).map((name) => ({
    name,
    type: 'string',
    required: false,
    description: 'Derived from JSON payload',
  }));
}

/** stableStringify function. */
function stableStringify(value: unknown): string {
  try {
    return JSON.stringify(value ?? null);
  } catch {
    return String(value);
  }
}

/** tryParseJsonObject utility. */
function tryParseJsonObject(value: string): Record<string, unknown> | null {
  try {
    const parsed = parseJsonObject(value);
    return parsed;
  } catch {
    return null;
  }
}

/** isValidMessageRole function. */
function isValidMessageRole(role: string): role is PromptMessage['role'] {
  return role === 'system' || role === 'user' || role === 'assistant';
}

/** normalizeMessageHistory function. */
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

/** Fetch or retrieve build raw payload data. */
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

/** Modal dialog for prompt playground modal. */
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
  onApplyDraft,
  onRunComplete,
}) => {
  const selectMenuStyles = {
    menuList: (base: Record<string, unknown>) => ({
      ...base,
      maxHeight: 220,
    }),
  };
  const effectiveModels = useMemo(() => buildEffectiveModels(models, defaultModel), [defaultModel, models]);
  const [localPromptValue, setLocalPromptValue] = useState(promptValue);
  const [localVariablesSchema, setLocalVariablesSchema] = useState<PromptVariableDefinition[]>(variablesSchema || []);
  const [valueDerivedSchema, setValueDerivedSchema] = useState<PromptVariableDefinition[]>([]);
  const detectedVariables = useMemo(() => detectVariablesFromPrompt(localPromptValue), [localPromptValue]);
  const effectiveSchema = useMemo(() => {
    const merged = mergeVariableSchemas(localVariablesSchema || [], detectedVariables);
    return mergeVariableSchemas(merged, valueDerivedSchema);
  }, [detectedVariables, localVariablesSchema, valueDerivedSchema]);
  const [selectedModels, setSelectedModels] = useState<string[]>([]);
  const [variables, setVariables] = useState<Record<string, unknown>>({});
  const [useRawJson, setUseRawJson] = useState(false);
  const [rawJson, setRawJson] = useState('{}');
  const [messageHistory, setMessageHistory] = useState<PromptMessage[]>([
    { role: 'user', content: 'Test this prompt in playground mode.' },
  ]);
  const [result, setResult] = useState<PromptPlaygroundResponse | null>(null);
  const [running, setRunning] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [jsonSyncError, setJsonSyncError] = useState<string | null>(null);
  const [showBuilderPanel, setShowBuilderPanel] = useState(false);
  const [schemaFromJson, setSchemaFromJson] = useState<PromptVariableDefinition[]>([]);
  const isChatRuntime = playgroundRuntimeType === 'chat'
    || (playgroundRuntimeType === 'none' && executionProfile === 'chat_runtime');

  // Snapshot the latest "opening" props in a ref so the reset effect can read
  // them without subscribing — the reset must only fire on an explicit
  // false→true transition of `show`, never on a parent rerender that
  // happens while the modal is already open.
  const openSnapshotRef = useRef({
    defaultModel,
    effectiveModels,
    isChatRuntime,
    promptValue,
    variablesSchema,
    resolveInitialVariables,
  });
  openSnapshotRef.current = {
    defaultModel,
    effectiveModels,
    isChatRuntime,
    promptValue,
    variablesSchema,
    resolveInitialVariables,
  };

  const wasOpenRef = useRef(false);
  useEffect(() => {
    if (!show) {
      wasOpenRef.current = false;
      return;
    }

    if (wasOpenRef.current) {
      // Already initialized for this open session — do not stomp on the
      // user's local edits because the parent happened to rerender (for
      // example after onRunComplete fires and the parent captures state).
      return;
    }
    wasOpenRef.current = true;

    const snapshot = openSnapshotRef.current;
    const freshInitialRuntimeValues = snapshot.resolveInitialVariables?.() || {};
    const initialModel = snapshot.defaultModel || snapshot.effectiveModels[0]?.id || '';
    const nextValueDerivedSchema = deriveVariableSchemaFromValues(freshInitialRuntimeValues);
    const promptSchema = mergeVariableSchemas(
      mergeVariableSchemas(snapshot.variablesSchema || [], detectVariablesFromPrompt(snapshot.promptValue)),
      nextValueDerivedSchema,
    );
    const promptVariables = normalizeInitialValues(promptSchema, freshInitialRuntimeValues);
    const defaultMessages = [{ role: 'user', content: 'Test this prompt in playground mode.' } as PromptMessage];

    setSelectedModels(initialModel ? [initialModel] : []);
    setLocalPromptValue(snapshot.promptValue);
    setLocalVariablesSchema(snapshot.variablesSchema || []);
    setValueDerivedSchema(nextValueDerivedSchema);
    setVariables(promptVariables);
    setMessageHistory(defaultMessages);
    setRawJson(JSON.stringify(buildRawPayload(snapshot.isChatRuntime, promptVariables, defaultMessages), null, 2));
    setResult(null);
    setError(null);
    setJsonSyncError(null);
    setSchemaFromJson([]);
    setUseRawJson(false);
    setShowBuilderPanel(false);
  }, [show]);

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

  const canRun = !disabled && localPromptValue.trim() !== '' && selectedModels.length > 0;

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
    // Intentionally do NOT clear `result` here. Keeping the previous result
    // visible while the new request is in flight prevents the panel from
    // flashing blank between runs. `setResult(nextResult)` below will
    // atomically replace the old runs once the new response arrives.
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
        draftPrompt: localPromptValue,
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
                <JsonMonacoEditor
                  value={rawJson}
                  onChange={setRawJson}
                  minHeight={260}
                  expectObject
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
              <div className="d-flex justify-content-between align-items-center flex-wrap" style={{ gap: 8 }}>
                <div>
                  <div className="small font-weight-bold text-muted">Playground Draft Workspace</div>
                  <div className="small text-muted">Edit locally here, test it, then apply it back to the saved draft only when you are happy.</div>
                </div>
                <div className="d-flex align-items-center flex-wrap" style={{ gap: 8 }}>
                  <Button size="sm" variant="outline-info" onClick={() => setShowBuilderPanel((current) => !current)} disabled={disabled}>
                    {showBuilderPanel ? 'Hide Build With AI' : 'Build With AI'}
                  </Button>
                  <Button size="sm" variant="outline-secondary" onClick={() => {
                    setLocalPromptValue(promptValue);
                    setLocalVariablesSchema(variablesSchema || []);
                  }} disabled={disabled}>
                    Reset From Draft
                  </Button>
                  <Button
                    size="sm"
                    variant="primary"
                    onClick={() => onApplyDraft?.(localPromptValue, effectiveSchema, 'Applied from playground')}
                    disabled={disabled || !onApplyDraft}
                  >
                    Apply To Draft
                  </Button>
                </div>
              </div>
              <Form.Control
                as="textarea"
                rows={10}
                className="mt-3 prompt-builder-instructions"
                value={localPromptValue}
                onChange={(event) => setLocalPromptValue(event.target.value)}
                disabled={disabled}
              />
              {showBuilderPanel && (
                <div className="border rounded bg-light p-3 mt-3">
                  <div className="small font-weight-bold text-muted mb-2">Build With AI</div>
                  <div className="small text-muted mb-3">
                    Describe what you want to improve, then generate a draft directly against this playground copy.
                  </div>
                  <PromptBuilderWorkspace
                    show={showBuilderPanel}
                    api={api}
                    descriptor={descriptor}
                    currentPrompt={localPromptValue}
                    models={models}
                    defaultModel={defaultModel}
                    onApplySuggestion={(nextPrompt, nextVariables) => {
                      setLocalPromptValue(nextPrompt);
                      setLocalVariablesSchema(nextVariables);
                    }}
                    onClose={() => setShowBuilderPanel(false)}
                    disabled={disabled}
                    showAutoApplyOnClose={false}
                    showApplySuggestionButton
                    applySuggestionButtonLabel="Apply To Draft"
                  />
                </div>
              )}
            </div>

            {result?.runs?.length ? (
              <>
                {running && (
                  <div className="border rounded bg-light text-muted small py-2 px-3 mb-2 d-flex align-items-center">
                    <Spinner animation="border" size="sm" className="mr-2" />
                    Generating new result… previous result still shown.
                  </div>
                )}
                {result.runs.map((run, index) => (
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
                ))}
              </>
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
