import React, { useEffect, useMemo, useState } from 'react';
import { Alert, Badge, Button, Card, Col, Form, ListGroup, Row, Spinner } from 'react-bootstrap';
import { JsonMonacoEditor } from '../shared/JsonMonacoEditor';
import { PromptBuilderModal } from '../prompts/PromptBuilderModal';
import { PromptDatasetsModal } from '../prompts/PromptDatasetsModal';
import { PromptDiffModal } from '../prompts/PromptDiffModal';
import { PromptEditor } from '../prompts/PromptEditor';
import { PromptPlaygroundModal } from '../prompts/PromptPlaygroundModal';
import { PromptToolbar } from '../prompts/PromptToolbar';
import { PromptVersionsModal } from '../prompts/PromptVersionsModal';
import { createPromptLabApi } from '../prompts/promptApi';
import { usePromptBootstrap } from '../prompts/promptHooks';
import {
  parsePromptMeta,
  stringifyPromptMeta,
  type PromptDescriptor,
  type PromptMetaState,
  type PromptPlaygroundResponse,
  type PromptVariableDefinition,
  type PromptVersion,
} from '../prompts/promptTypes';
import { memoryApi } from '../../utils/api';
import './MemoryRulesEditor.css';

export interface MemoryRuleDraft {
  id: number;
  key: string;
  label: string;
  enabled: boolean;
  memory_key: string;
  source_type: string;
  source_match: Record<string, unknown>;
  trigger_types: string[];
  storage_mode_override: string;
  execution_mode: string;
  field_mapping: Record<string, string>;
  data_config: Array<Record<string, unknown>>;
  llm_model: string;
  llm_temperature: string;
  llm_max_tokens: string;
  refresh_sections: Array<number | string>;
  usage_tags: string[];
  prompt_template: string;
  prompt_meta_json: string;
  sources_count?: number;
}

interface PlaygroundCapture {
  variables: Record<string, unknown>;
  messageHistory: Array<{ role: 'system' | 'user' | 'assistant'; content: string }>;
  runtimeOverrides: Record<string, unknown>;
  runRef?: {
    id_llm_prompt_playground_runs?: number | null;
    id_llmConversations?: number | null;
    id_llmMessages_request?: number | null;
    id_llmMessages_response?: number | null;
  } | null;
}

export interface MemoryRulesEditorPageConfig {
  promptLabEndpoint: string;
  csrfToken?: string;
  pageId?: number | null;
  selectedRuleId?: number | null;
  onRuleSelected?: (ruleId: number | null) => void;
}

interface DiffState {
  initialLeftKey: string;
  initialRightKey: string;
}

function parseJsonObject(value: string, fallback: Record<string, unknown> = {}): Record<string, unknown> {
  if (!value.trim()) return fallback;
  const parsed = JSON.parse(value) as Record<string, unknown>;
  return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : fallback;
}

function parseJsonArray<T = Record<string, unknown>>(value: string, fallback: T[] = []): T[] {
  if (!value.trim()) return fallback;
  const parsed = JSON.parse(value) as T[];
  return Array.isArray(parsed) ? parsed : fallback;
}

function toPrettyJson(value: unknown): string {
  return JSON.stringify(value ?? {}, null, 2);
}

function parseCsvList(value: string): string[] {
  return value.split(',').map((item) => item.trim()).filter(Boolean);
}

function ensurePromptMeta(meta: PromptMetaState): NonNullable<PromptMetaState['prompt']> {
  if (!meta.prompt || typeof meta.prompt !== 'object') {
    meta.prompt = {};
  }
  return meta.prompt;
}

function getDefaultRule(index = 0): MemoryRuleDraft {
  return {
    id: 0,
    key: `memory_rule_${index + 1}`,
    label: `Memory Rule ${index + 1}`,
    enabled: true,
    memory_key: 'global',
    source_type: 'form_action_submit',
    source_match: {},
    trigger_types: ['finished'],
    storage_mode_override: '',
    execution_mode: 'llm_summarize',
    field_mapping: {},
    data_config: [],
    llm_model: '',
    llm_temperature: '0.2',
    llm_max_tokens: '1200',
    refresh_sections: [],
    usage_tags: [],
    prompt_template: '',
    prompt_meta_json: '{}',
    sources_count: 0,
  };
}

function normalizeRule(raw: any, index: number): MemoryRuleDraft {
  const fallback = getDefaultRule(index);
  return {
    id: Number(raw?.id || 0),
    key: typeof raw?.key === 'string' ? raw.key : fallback.key,
    label: typeof raw?.label === 'string' ? raw.label : fallback.label,
    enabled: raw?.enabled !== false,
    memory_key: typeof raw?.memory_key === 'string' && raw.memory_key.trim() !== '' ? raw.memory_key : 'global',
    source_type: typeof raw?.source_type === 'string' ? raw.source_type : fallback.source_type,
    source_match: raw?.source_match && typeof raw.source_match === 'object' && !Array.isArray(raw.source_match) ? raw.source_match : {},
    trigger_types: Array.isArray(raw?.trigger_types) ? raw.trigger_types.map((value: unknown) => String(value)) : ['finished'],
    storage_mode_override: typeof raw?.storage_mode_override === 'string' ? raw.storage_mode_override : '',
    execution_mode: typeof raw?.execution_mode === 'string' ? raw.execution_mode : 'llm_summarize',
    field_mapping: raw?.field_mapping && typeof raw.field_mapping === 'object' && !Array.isArray(raw.field_mapping)
      ? Object.fromEntries(Object.entries(raw.field_mapping).map(([key, value]) => [key, String(value)]))
      : {},
    data_config: Array.isArray(raw?.data_config) ? raw.data_config : [],
    llm_model: typeof raw?.llm_model === 'string' ? raw.llm_model : '',
    llm_temperature: raw?.llm_temperature != null ? String(raw.llm_temperature) : '0.2',
    llm_max_tokens: raw?.llm_max_tokens != null ? String(raw.llm_max_tokens) : '1200',
    refresh_sections: Array.isArray(raw?.refresh_sections) ? raw.refresh_sections : [],
    usage_tags: Array.isArray(raw?.usage_tags) ? raw.usage_tags.map((value: unknown) => String(value)) : [],
    prompt_template: typeof raw?.prompt_template === 'string' ? raw.prompt_template : '',
    prompt_meta_json: typeof raw?.prompt_meta_json === 'string' ? raw.prompt_meta_json : '{}',
    sources_count: Number(raw?.sources_count || 0),
  };
}

function sanitizeRule(rule: MemoryRuleDraft): Record<string, unknown> {
  return {
    id: rule.id,
    key: rule.key.trim(),
    label: rule.label.trim(),
    enabled: !!rule.enabled,
    memory_key: rule.memory_key.trim() || 'global',
    source_type: rule.source_type.trim(),
    source_match: rule.source_match || {},
    trigger_types: rule.trigger_types.filter(Boolean),
    storage_mode_override: rule.storage_mode_override || '',
    execution_mode: rule.execution_mode || 'llm_summarize',
    field_mapping: rule.field_mapping || {},
    data_config: rule.data_config || [],
    llm_model: rule.llm_model || '',
    llm_temperature: rule.llm_temperature || '0.2',
    llm_max_tokens: rule.llm_max_tokens || '1200',
    refresh_sections: rule.refresh_sections || [],
    usage_tags: rule.usage_tags || [],
  };
}

function validateRule(rule: MemoryRuleDraft): string[] {
  const errors: string[] = [];
  if (!rule.key.trim()) errors.push('Rule key is required.');
  if (!rule.source_type.trim()) errors.push('source_type is required.');
  if (!rule.execution_mode.trim()) errors.push('execution_mode is required.');
  return errors;
}

export const MemoryRulesEditorApp: React.FC<{ config: MemoryRulesEditorPageConfig }> = ({ config }) => {
  const [rules, setRules] = useState<MemoryRuleDraft[]>([]);
  const [selectedRuleId, setSelectedRuleId] = useState<number | null>(config.selectedRuleId ?? null);
  const [draft, setDraft] = useState<MemoryRuleDraft | null>(null);
  const [metaState, setMetaState] = useState<PromptMetaState>(parsePromptMeta('{}'));
  const [lastPlaygroundCapture, setLastPlaygroundCapture] = useState<PlaygroundCapture | null>(null);
  const [filter, setFilter] = useState('');
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [sourceMatchJson, setSourceMatchJson] = useState('{}');
  const [fieldMappingJson, setFieldMappingJson] = useState('{}');
  const [dataConfigJson, setDataConfigJson] = useState('[]');
  const [refreshSectionsJson, setRefreshSectionsJson] = useState('[]');
  const [showVersions, setShowVersions] = useState(false);
  const [showDiff, setShowDiff] = useState(false);
  const [showPlayground, setShowPlayground] = useState(false);
  const [showDatasets, setShowDatasets] = useState(false);
  const [showBuilder, setShowBuilder] = useState(false);
  const [diffState, setDiffState] = useState<DiffState>({ initialLeftKey: 'draft', initialRightKey: 'draft' });

  const api = useMemo(() => createPromptLabApi(config.promptLabEndpoint, config.csrfToken), [config.csrfToken, config.promptLabEndpoint]);

  const loadRule = async (ruleId: number, currentRules?: MemoryRuleDraft[]) => {
    const existing = (currentRules || rules).find((rule) => rule.id === ruleId);
    setSelectedRuleId(ruleId);
    config.onRuleSelected?.(ruleId);
    setSuccess(null);
    try {
      const response = await memoryApi.getRule(ruleId);
      if (response.error) throw new Error(response.error);
      const normalized = normalizeRule(response.rule, existing ? (currentRules || rules).indexOf(existing) : 0);
      setDraft(normalized);
      setMetaState(parsePromptMeta(normalized.prompt_meta_json));
      setLastPlaygroundCapture(null);
      setSourceMatchJson(toPrettyJson(normalized.source_match));
      setFieldMappingJson(toPrettyJson(normalized.field_mapping));
      setDataConfigJson(toPrettyJson(normalized.data_config));
      setRefreshSectionsJson(toPrettyJson(normalized.refresh_sections));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load rule');
    }
  };

  const loadRules = async (nextSelectedId?: number | null) => {
    setLoading(true);
    setError(null);
    try {
      const response = await memoryApi.getRules();
      if (response.error) throw new Error(response.error);
      const nextRules = (response.rules || []).map((rule, index) => normalizeRule(rule, index));
      setRules(nextRules);
      const preferredId = nextSelectedId ?? selectedRuleId ?? config.selectedRuleId ?? nextRules[0]?.id ?? null;
      if (preferredId) {
        await loadRule(preferredId, nextRules);
      } else {
        setSelectedRuleId(null);
        setDraft(null);
        setMetaState(parsePromptMeta('{}'));
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load rules');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadRules(config.selectedRuleId ?? null).catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (config.selectedRuleId && config.selectedRuleId !== selectedRuleId) {
      loadRule(config.selectedRuleId).catch(() => undefined);
    }
  }, [config.selectedRuleId, selectedRuleId]);

  const filteredRules = useMemo(() => {
    const query = filter.trim().toLowerCase();
    if (!query) return rules;
    return rules.filter((rule) =>
      rule.key.toLowerCase().includes(query)
      || rule.label.toLowerCase().includes(query)
      || rule.source_type.toLowerCase().includes(query)
    );
  }, [filter, rules]);

  const promptDescriptor = useMemo<PromptDescriptor | null>(() => {
    if (!draft?.id) {
      return null;
    }
    return {
      ownerType: 'llm_memory_rule',
      ownerId: draft.id,
      promptSlot: 'memory_rule',
      languageId: 1,
      pageId: config.pageId ?? null,
      title: draft.label || draft.key,
    };
  }, [config.pageId, draft?.id, draft?.key, draft?.label]);

  const promptRuntimeOverrides = useMemo(() => ({
    llm_model: draft?.llm_model || '',
    llm_temperature: draft?.llm_temperature || '0.2',
    llm_max_tokens: draft?.llm_max_tokens || '1200',
  }), [draft?.llm_max_tokens, draft?.llm_model, draft?.llm_temperature]);

  const { bootstrap: promptBootstrap, loading: promptLoading, error: promptError, reload: reloadPromptBootstrap } = usePromptBootstrap({
    api,
    descriptor: promptDescriptor || {
      ownerType: 'llm_memory_rule',
      ownerId: 0,
      promptSlot: 'memory_rule',
      languageId: 1,
      pageId: config.pageId ?? null,
      title: 'Memory Rule Prompt',
    },
    currentContent: draft?.prompt_template || '',
    currentMeta: stringifyPromptMeta(metaState),
    runtimeOverrides: promptRuntimeOverrides,
    enabled: !!promptDescriptor,
  });

  const activeVersion = promptBootstrap?.active_version || null;
  const versions = promptBootstrap?.versions || [];
  const effectiveVariablesSchema = metaState.prompt?.variablesSchema || promptBootstrap?.variables_schema || [];
  const promptChangeNote = metaState.prompt?.pendingChangeNote || '';
  const isDirty = (draft?.prompt_template || '') !== (activeVersion?.template_raw || '');
  const defaultPromptModel =
    String(promptRuntimeOverrides.llm_model || '') ||
    promptBootstrap?.models?.[0]?.id ||
    null;

  const setDraftPatch = (patch: Partial<MemoryRuleDraft>) => {
    setDraft((current) => current ? { ...current, ...patch } : current);
  };

  const syncPromptTemplate = (nextValue: string) => {
    setDraftPatch({ prompt_template: nextValue });
  };

  const syncMeta = (nextMeta: PromptMetaState) => {
    setMetaState(nextMeta);
    setDraft((current) => current ? { ...current, prompt_meta_json: stringifyPromptMeta(nextMeta) } : current);
  };

  const handleChangeNote = (value: string) => {
    const nextMeta = { ...metaState };
    const prompt = ensurePromptMeta(nextMeta);
    prompt.pendingChangeNote = value;
    syncMeta(nextMeta);
  };

  const handleUseVersion = (version: PromptVersion) => {
    syncPromptTemplate(version.template_raw || '');
    handleChangeNote(promptChangeNote || `Restored from version ${version.version_no}`);
    setShowVersions(false);
  };

  const openDiffWithVersion = (version: PromptVersion) => {
    const nextMeta = { ...metaState };
    const prompt = ensurePromptMeta(nextMeta);
    prompt.lastComparedVersionId = version.id;
    syncMeta(nextMeta);
    setShowVersions(false);
    setDiffState({
      initialLeftKey: `v:${version.id}`,
      initialRightKey: 'draft',
    });
    setShowDiff(true);
  };

  const handleBuilderApply = (nextPrompt: string, variables: PromptVariableDefinition[], changeSummary: string) => {
    syncPromptTemplate(nextPrompt);
    if (variables.length > 0) {
      const nextMeta = { ...metaState };
      const prompt = ensurePromptMeta(nextMeta);
      prompt.variablesSchema = variables;
      if (!prompt.pendingChangeNote && changeSummary) {
        prompt.pendingChangeNote = changeSummary;
      }
      syncMeta(nextMeta);
    } else if (changeSummary && !promptChangeNote) {
      handleChangeNote(changeSummary);
    }
    setShowBuilder(false);
  };

  const handleCreate = async () => {
    const baseRule = getDefaultRule(rules.length);
    setSaving(true);
    setError(null);
    try {
      const created = await memoryApi.createRule(sanitizeRule(baseRule), baseRule.prompt_template, baseRule.prompt_meta_json, '');
      if (created.error) throw new Error(created.error);
      await loadRules(created.rule.id);
      setSuccess('Rule created.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create rule');
    } finally {
      setSaving(false);
    }
  };

  const handleDuplicate = async () => {
    if (!draft?.id) return;
    setSaving(true);
    setError(null);
    try {
      const duplicated = await memoryApi.duplicateRule(draft.id);
      if (duplicated.error) throw new Error(duplicated.error);
      await loadRules(duplicated.rule.id);
      setSuccess('Rule duplicated.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to duplicate rule');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!draft?.id || !window.confirm(`Delete memory rule "${draft.label || draft.key}"?`)) return;
    setSaving(true);
    setError(null);
    try {
      const response = await memoryApi.deleteRule(draft.id);
      if (response.error) throw new Error(response.error);
      await loadRules(null);
      setSuccess('Rule deleted.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to delete rule');
    } finally {
      setSaving(false);
    }
  };

  const handleSave = async () => {
    if (!draft) return;
    let nextDraft: MemoryRuleDraft;
    try {
      nextDraft = {
        ...draft,
        source_match: parseJsonObject(sourceMatchJson),
        field_mapping: parseJsonObject(fieldMappingJson) as Record<string, string>,
        data_config: parseJsonArray<Record<string, unknown>>(dataConfigJson),
        refresh_sections: parseJsonArray<number | string>(refreshSectionsJson),
        prompt_meta_json: stringifyPromptMeta(metaState),
      };
    } catch (err) {
      setError(err instanceof Error ? err.message : 'One of the JSON fields is invalid.');
      return;
    }

    const errors = validateRule(nextDraft);
    if (errors.length > 0) {
      setError(errors.join(' '));
      return;
    }

    setSaving(true);
    setError(null);
    try {
      const response = await memoryApi.updateRule(
        nextDraft.id,
        sanitizeRule(nextDraft),
        nextDraft.prompt_template,
        nextDraft.prompt_meta_json,
        promptChangeNote,
      );
      if (response.error) throw new Error(response.error);
      await loadRules(nextDraft.id);
      await reloadPromptBootstrap().catch(() => undefined);
      setSuccess('Rule saved.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save rule');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="llm-memory-rules-editor-root">
      {error && <Alert variant="danger" onClose={() => setError(null)} dismissible>{error}</Alert>}
      {success && <Alert variant="success" onClose={() => setSuccess(null)} dismissible>{success}</Alert>}
      {promptError && <Alert variant="warning">Prompt Lab: {promptError}</Alert>}
      <Row>
        <Col lg={4} className="mb-3">
          <Card>
            <Card.Header className="d-flex justify-content-between align-items-center">
              <strong>Rules</strong>
              <Button size="sm" onClick={handleCreate} disabled={saving}>New Rule</Button>
            </Card.Header>
            <Card.Body>
              <Form.Control
                type="text"
                size="sm"
                placeholder="Filter rules..."
                value={filter}
                onChange={(event) => setFilter(event.target.value)}
                className="mb-2"
              />
              {loading ? (
                <div className="text-center py-3"><Spinner animation="border" size="sm" /></div>
              ) : (
                <ListGroup className="memory-rule-list">
                  {filteredRules.map((rule) => (
                    <ListGroup.Item key={rule.id} action active={rule.id === selectedRuleId} onClick={() => loadRule(rule.id).catch(() => undefined)}>
                      <div className="d-flex justify-content-between align-items-start">
                        <div>
                          <div className="font-weight-bold">{rule.label || rule.key}</div>
                          <div className="memory-rule-subtitle text-muted">{rule.key}</div>
                          <div className="memory-rule-subtitle text-muted">{rule.source_type} | {rule.execution_mode}</div>
                        </div>
                        <div className="text-right">
                          <Badge variant={rule.enabled ? 'success' : 'secondary'}>{rule.enabled ? 'ON' : 'OFF'}</Badge>
                          <div className="memory-rule-subtitle text-muted mt-1">{rule.sources_count || 0} sources</div>
                        </div>
                      </div>
                    </ListGroup.Item>
                  ))}
                  {filteredRules.length === 0 && <ListGroup.Item className="text-muted">No rules found.</ListGroup.Item>}
                </ListGroup>
              )}
            </Card.Body>
          </Card>
        </Col>
        <Col lg={8}>
          {!draft ? (
            <Card>
              <Card.Body className="text-muted text-center py-5">Select a memory rule to edit it.</Card.Body>
            </Card>
          ) : (
            <Card>
              <Card.Header className="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                  <strong>{draft.label || draft.key}</strong>
                  <div className="memory-rule-subtitle text-muted">Rule ID {draft.id}</div>
                </div>
                <div className="d-flex align-items-center">
                  <Button size="sm" variant="outline-secondary" className="mr-2" onClick={handleDuplicate} disabled={saving}>Duplicate</Button>
                  <Button size="sm" variant="outline-danger" className="mr-2" onClick={handleDelete} disabled={saving}>Delete</Button>
                  <Button size="sm" onClick={handleSave} disabled={saving || promptLoading}>{saving ? 'Saving...' : 'Save Rule'}</Button>
                </div>
              </Card.Header>
              <Card.Body>
                <Row>
                  <Col md={6}>
                    <Form.Group>
                      <Form.Label>Rule Key</Form.Label>
                      <Form.Control value={draft.key} onChange={(event) => setDraftPatch({ key: event.target.value })} />
                    </Form.Group>
                  </Col>
                  <Col md={6}>
                    <Form.Group>
                      <Form.Label>Label</Form.Label>
                      <Form.Control value={draft.label} onChange={(event) => setDraftPatch({ label: event.target.value })} />
                    </Form.Group>
                  </Col>
                </Row>
                <Row>
                  <Col md={4}>
                    <Form.Group>
                      <Form.Label>Source Type</Form.Label>
                      <Form.Control as="select" value={draft.source_type} onChange={(event) => setDraftPatch({ source_type: event.target.value })}>
                        <option value="form_action_submit">form_action_submit</option>
                        <option value="llm_chat_form_submit">llm_chat_form_submit</option>
                        <option value="login">login</option>
                        <option value="profile_name_change">profile_name_change</option>
                      </Form.Control>
                    </Form.Group>
                  </Col>
                  <Col md={4}>
                    <Form.Group>
                      <Form.Label>Execution Mode</Form.Label>
                      <Form.Control as="select" value={draft.execution_mode} onChange={(event) => setDraftPatch({ execution_mode: event.target.value })}>
                        <option value="llm_summarize">llm_summarize</option>
                        <option value="direct_mapping">direct_mapping</option>
                      </Form.Control>
                    </Form.Group>
                  </Col>
                  <Col md={4}>
                    <Form.Group>
                      <Form.Label>Storage Override</Form.Label>
                      <Form.Control as="select" value={draft.storage_mode_override} onChange={(event) => setDraftPatch({ storage_mode_override: event.target.value })}>
                        <option value="">Use module default</option>
                        <option value="record">record</option>
                        <option value="log">log</option>
                        <option value="both">both</option>
                      </Form.Control>
                    </Form.Group>
                  </Col>
                </Row>
                <Row>
                  <Col md={4}>
                    <Form.Group>
                      <Form.Label>Memory Key</Form.Label>
                      <Form.Control value={draft.memory_key} onChange={(event) => setDraftPatch({ memory_key: event.target.value })} />
                    </Form.Group>
                  </Col>
                  <Col md={4}>
                    <Form.Group>
                      <Form.Label>Temperature</Form.Label>
                      <Form.Control value={draft.llm_temperature} onChange={(event) => setDraftPatch({ llm_temperature: event.target.value })} />
                    </Form.Group>
                  </Col>
                  <Col md={4}>
                    <Form.Group>
                      <Form.Label>Max Tokens</Form.Label>
                      <Form.Control value={draft.llm_max_tokens} onChange={(event) => setDraftPatch({ llm_max_tokens: event.target.value })} />
                    </Form.Group>
                  </Col>
                </Row>
                <Row>
                  <Col md={6}>
                    <Form.Group>
                      <Form.Label>LLM Model</Form.Label>
                      <Form.Control value={draft.llm_model} onChange={(event) => setDraftPatch({ llm_model: event.target.value })} placeholder="Use module default when blank" />
                    </Form.Group>
                  </Col>
                  <Col md={6}>
                    <Form.Group>
                      <Form.Label>Usage Tags</Form.Label>
                      <Form.Control value={draft.usage_tags.join(', ')} onChange={(event) => setDraftPatch({ usage_tags: parseCsvList(event.target.value) })} placeholder="analytics, onboarding" />
                    </Form.Group>
                  </Col>
                </Row>
                <Row>
                  <Col md={6}>
                    <Form.Group>
                      <Form.Label>Enabled</Form.Label>
                      <Form.Check type="switch" checked={draft.enabled} onChange={(event) => setDraftPatch({ enabled: event.target.checked })} label={draft.enabled ? 'Enabled' : 'Disabled'} />
                    </Form.Group>
                  </Col>
                  <Col md={6}>
                    <Form.Group>
                      <Form.Label>Trigger Types</Form.Label>
                      <Form.Control value={draft.trigger_types.join(', ')} onChange={(event) => setDraftPatch({ trigger_types: parseCsvList(event.target.value) })} placeholder="finished, updated" />
                    </Form.Group>
                  </Col>
                </Row>
                <Row>
                  <Col lg={6} className="mb-3">
                    <Form.Label>Source Match JSON</Form.Label>
                    <JsonMonacoEditor value={sourceMatchJson} minHeight={180} expectObject onChange={setSourceMatchJson} />
                  </Col>
                  <Col lg={6} className="mb-3">
                    <Form.Label>Field Mapping JSON</Form.Label>
                    <JsonMonacoEditor value={fieldMappingJson} minHeight={180} expectObject onChange={setFieldMappingJson} />
                  </Col>
                </Row>
                <Row>
                  <Col lg={6} className="mb-3">
                    <Form.Label>Data Config JSON</Form.Label>
                    <JsonMonacoEditor value={dataConfigJson} minHeight={220} onChange={setDataConfigJson} />
                  </Col>
                  <Col lg={6} className="mb-3">
                    <Form.Label>Refresh Sections JSON</Form.Label>
                    <JsonMonacoEditor value={refreshSectionsJson} minHeight={220} onChange={setRefreshSectionsJson} />
                  </Col>
                </Row>

                <PromptToolbar
                  activeVersion={activeVersion}
                  dirty={isDirty}
                  disabled={!promptDescriptor || promptLoading}
                  changeNote={promptChangeNote}
                  onChangeNote={handleChangeNote}
                  onOpenVersions={() => {
                    reloadPromptBootstrap().catch(() => undefined);
                    setShowVersions(true);
                  }}
                  onOpenCompare={() => {
                    const activeKey = activeVersion ? `v:${activeVersion.id}` : 'draft';
                    setDiffState({
                      initialLeftKey: activeKey,
                      initialRightKey: 'draft',
                    });
                    setShowDiff(true);
                  }}
                  onOpenPlayground={() => setShowPlayground(true)}
                  onOpenDatasets={() => setShowDatasets(true)}
                  onOpenBuilder={() => setShowBuilder(true)}
                />

                <PromptEditor
                  value={draft.prompt_template}
                  language="markdown"
                  onChange={syncPromptTemplate}
                  minHeight={260}
                />
              </Card.Body>
            </Card>
          )}
        </Col>
      </Row>

      {promptDescriptor && draft && (
        <>
          <PromptVersionsModal
            show={showVersions}
            onHide={() => setShowVersions(false)}
            versions={versions}
            activeVersionId={activeVersion?.id || null}
            disabled={!promptDescriptor}
            onUseVersion={handleUseVersion}
            onCompareVersion={openDiffWithVersion}
          />

          <PromptDiffModal
            show={showDiff}
            onHide={() => setShowDiff(false)}
            api={api}
            descriptor={promptDescriptor}
            versions={versions}
            draftContent={draft.prompt_template}
            initialLeftKey={diffState.initialLeftKey}
            initialRightKey={diffState.initialRightKey}
          />

          <PromptPlaygroundModal
            show={showPlayground}
            onHide={() => setShowPlayground(false)}
            api={api}
            descriptor={promptDescriptor}
            executionProfile={promptBootstrap?.execution_profile || 'memory_runtime'}
            playgroundRuntimeType={promptBootstrap?.playground_runtime_type || 'script'}
            models={promptBootstrap?.models || []}
            variablesSchema={effectiveVariablesSchema}
            promptValue={draft.prompt_template}
            disabled={!promptDescriptor || (promptBootstrap?.playground_runtime_type || 'script') === 'none'}
            defaultModel={defaultPromptModel}
            resolveRuntimeOverrides={() => promptRuntimeOverrides}
            onApplyDraft={handleBuilderApply}
            onRunComplete={({ variables, messageHistory, runtimeOverrides, response }: {
              variables: Record<string, unknown>;
              messageHistory: Array<{ role: 'system' | 'user' | 'assistant'; content: string }>;
              runtimeOverrides: Record<string, unknown>;
              response: PromptPlaygroundResponse;
            }) => {
              const firstRun = response.runs?.[0];
              setLastPlaygroundCapture({
                variables,
                messageHistory,
                runtimeOverrides,
                runRef: firstRun
                  ? {
                    id_llm_prompt_playground_runs: firstRun.id_llm_prompt_playground_runs ?? null,
                    id_llmConversations: firstRun.id_llmConversations ?? null,
                    id_llmMessages_request: firstRun.id_llmMessages_request ?? null,
                    id_llmMessages_response: firstRun.id_llmMessages_response ?? null,
                  }
                  : null,
              });
            }}
          />

          <PromptDatasetsModal
            show={showDatasets}
            onHide={() => setShowDatasets(false)}
            api={api}
            descriptor={promptDescriptor}
            versions={versions}
            activeVersionId={activeVersion?.id || null}
            models={promptBootstrap?.models || []}
            executionProfile={promptBootstrap?.execution_profile || 'memory_runtime'}
            promptValue={draft.prompt_template}
            disabled={!promptDescriptor}
            defaultModel={defaultPromptModel}
            resolveRuntimeOverrides={() => promptRuntimeOverrides}
            lastPlaygroundCapture={lastPlaygroundCapture}
          />

          <PromptBuilderModal
            show={showBuilder}
            onHide={() => setShowBuilder(false)}
            api={api}
            descriptor={promptDescriptor}
            currentPrompt={draft.prompt_template}
            models={promptBootstrap?.models || []}
            defaultModel={defaultPromptModel}
            disabled={!promptDescriptor}
            onApplySuggestion={handleBuilderApply}
          />
        </>
      )}
    </div>
  );
};
