import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  Col,
  Container,
  Dropdown,
  Form,
  Modal,
  Row,
  Spinner,
  Table,
} from 'react-bootstrap';
import type { ScriptsConfig } from '../../scripts';
import {
  createScriptsApi,
  type LlmDefaults,
  type LlmModel,
  type LlmScript,
  type SectionInfo,
} from './scriptsApi';
import { formatDateTime } from '../../utils/formatters';
import { createPromptLabApi } from '../prompts/promptApi';
import { PromptBuilderModal } from '../prompts/PromptBuilderModal';
import { PromptDatasetsModal } from '../prompts/PromptDatasetsModal';
import { PromptDiffModal } from '../prompts/PromptDiffModal';
import { PromptEditor } from '../prompts/PromptEditor';
import { PromptPlaygroundModal } from '../prompts/PromptPlaygroundModal';
import { PromptToolbar } from '../prompts/PromptToolbar';
import { PromptVersionsModal } from '../prompts/PromptVersionsModal';
import { usePromptBootstrap } from '../prompts/promptHooks';
import type {
  PromptDescriptor,
  PromptPlaygroundResponse,
  PromptVariableDefinition,
  PromptVersion,
} from '../prompts/promptTypes';
import '../prompts/PromptLab.css';
import './ScriptsManager.css';

declare const $: any;

type ViewMode = 'list' | 'editor';

interface AclPerms {
  select: boolean;
  insert: boolean;
  update: boolean;
  delete: boolean;
}

interface ScriptFormState {
  name: string;
  script: string;
  test_variables: string;
  async: number;
  data_config: string;
  model: string;
  temperature: string;
  max_tokens: string;
  refresh_sections: string;
}

interface DiffState {
  initialLeftKey: string;
  initialRightKey: string;
}

function parseJsonObject(value: string): Record<string, unknown> {
  if (!value.trim()) {
    return {};
  }

  try {
    const parsed = JSON.parse(value) as Record<string, unknown>;
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
}

function buildPromptMetaJson(
  promptChangeNote: string,
  promptVariablesSchema: PromptVariableDefinition[] | null,
): string | null {
  const prompt: Record<string, unknown> = {};

  if (promptChangeNote.trim() !== '') {
    prompt.pendingChangeNote = promptChangeNote.trim();
  }

  if (promptVariablesSchema && promptVariablesSchema.length > 0) {
    prompt.variablesSchema = promptVariablesSchema;
  }

  if (Object.keys(prompt).length === 0) {
    return null;
  }

  return JSON.stringify({ prompt });
}

function getEmptyForm(): ScriptFormState {
  return {
    name: '',
    script: '',
    test_variables: '',
    async: 0,
    data_config: '',
    model: '',
    temperature: '',
    max_tokens: '',
    refresh_sections: '[]',
  };
}

export const ScriptsManager: React.FC<{ config: ScriptsConfig }> = ({ config }) => {
  const api = useRef(createScriptsApi());
  const promptApi = useMemo(
    () => createPromptLabApi(config.promptLabEndpoint || window.location.pathname, config.csrfToken),
    [config.csrfToken, config.promptLabEndpoint],
  );
  const creatingRef = useRef(false);

  const [scripts, setScripts] = useState<LlmScript[]>([]);
  const [selectedScript, setSelectedScript] = useState<LlmScript | null>(null);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [viewMode, setViewMode] = useState<ViewMode>('list');
  const [deleteConfirm, setDeleteConfirm] = useState<LlmScript | null>(null);
  const [deleteInput, setDeleteInput] = useState('');
  const [models, setModels] = useState<LlmModel[]>([]);
  const [sections, setSections] = useState<SectionInfo[]>([]);
  const [defaults, setDefaults] = useState<LlmDefaults | null>(null);
  const [acl, setAcl] = useState<AclPerms>({ select: true, insert: true, update: true, delete: true });
  const [sectionSearch, setSectionSearch] = useState('');
  const [form, setForm] = useState<ScriptFormState>(getEmptyForm());
  const [promptChangeNote, setPromptChangeNote] = useState('');
  const [promptVariablesSchema, setPromptVariablesSchema] = useState<PromptVariableDefinition[] | null>(null);
  const [showVersions, setShowVersions] = useState(false);
  const [showDiff, setShowDiff] = useState(false);
  const [showPlayground, setShowPlayground] = useState(false);
  const [showDatasets, setShowDatasets] = useState(false);
  const [showBuilder, setShowBuilder] = useState(false);
  const [lastPlaygroundCapture, setLastPlaygroundCapture] = useState<{
    variables: Record<string, unknown>;
    messageHistory: Array<{ role: 'system' | 'user' | 'assistant'; content: string }>;
    runtimeOverrides: Record<string, unknown>;
    runRef: {
      id_llm_prompt_playground_runs?: number | null;
      id_llmConversations?: number | null;
      id_llmMessages_request?: number | null;
      id_llmMessages_response?: number | null;
    } | null;
  } | null>(null);
  const [diffState, setDiffState] = useState<DiffState>({
    initialLeftKey: 'draft',
    initialRightKey: 'draft',
  });

  useEffect(() => {
    api.current.getConfig().then((cfg) => {
      setDefaults(cfg);
      if (cfg.acl) {
        setAcl(cfg.acl);
      }
    }).catch(() => undefined);

    api.current.getModels().then((items) => setModels(items)).catch(() => undefined);
    api.current.getSections().then((items) => setSections(items)).catch(() => undefined);

    const modal = document.querySelector('#data-config-builder-wrapper .data_config_builder_modal_holder');
    if (modal) {
      document.body.appendChild(modal);
    }
  }, []);

  const loadScripts = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const list = await api.current.list();
      setScripts(list);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadScripts();
  }, [loadScripts]);

  const promptDescriptor = useMemo<PromptDescriptor>(() => ({
    ownerType: 'llm_script',
    ownerId: selectedScript?.id || 0,
    promptSlot: 'script',
    languageId: 1,
    title: form.name || selectedScript?.name || 'Script Prompt',
  }), [form.name, selectedScript]);

  const promptMetaJson = useMemo(
    () => buildPromptMetaJson(promptChangeNote, promptVariablesSchema) || '{}',
    [promptChangeNote, promptVariablesSchema],
  );

  const promptRuntimeOverrides = useMemo(() => ({
    name: form.name,
    model: form.model || null,
    temperature: form.temperature || null,
    max_tokens: form.max_tokens || null,
    data_config: form.data_config,
    test_variables: form.test_variables,
  }), [
    form.data_config,
    form.max_tokens,
    form.model,
    form.name,
    form.temperature,
    form.test_variables,
  ]);

  const {
    bootstrap: promptBootstrap,
    loading: promptLoading,
    error: promptError,
    reload: reloadPromptBootstrap,
  } = usePromptBootstrap({
    api: promptApi,
    descriptor: promptDescriptor,
    currentContent: form.script,
    currentMeta: promptMetaJson,
    runtimeOverrides: promptRuntimeOverrides,
    enabled: !!config.promptLabEndpoint && !!selectedScript && viewMode === 'editor',
  });

  useEffect(() => {
    if (!selectedScript) {
      return;
    }

    if (!promptVariablesSchema && promptBootstrap?.variables_schema?.length) {
      setPromptVariablesSchema(promptBootstrap.variables_schema);
    }
  }, [promptBootstrap, promptVariablesSchema, selectedScript]);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const sidParam = params.get('sid');
    if (!sidParam) {
      return;
    }

    const sid = parseInt(sidParam, 10);
    if (sid <= 0) {
      return;
    }

    api.current.get(sid).then((script) => {
      openScriptDirect(script);
    }).catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const getRefreshSectionIds = (): number[] => {
    if (!form.refresh_sections) {
      return [];
    }
    try {
      const parsed = JSON.parse(form.refresh_sections);
      if (Array.isArray(parsed)) {
        return parsed.map(Number).filter((value) => !Number.isNaN(value));
      }
    } catch {
      return [];
    }
    return [];
  };

  const setRefreshSectionIds = (ids: number[]) => {
    setForm((prev) => ({ ...prev, refresh_sections: JSON.stringify(ids) }));
  };

  const openScriptDirect = (full: LlmScript) => {
    setError(null);
    setSuccess(null);
    setSelectedScript(full);
    setPromptChangeNote('');
    setPromptVariablesSchema(null);
    setForm({
      name: full.name || '',
      script: full.script || '',
      test_variables: full.test_variables || '{\n  \n}',
      async: full.async ? 1 : 0,
      data_config: full.data_config || '',
      model: full.model || '',
      temperature: full.temperature != null ? String(full.temperature) : '',
      max_tokens: full.max_tokens != null ? String(full.max_tokens) : '',
      refresh_sections: full.refresh_sections || '[]',
    });
    setViewMode('editor');
  };

  const openScript = async (script: LlmScript) => {
    setError(null);
    setSuccess(null);
    try {
      const full = await api.current.get(script.id);
      openScriptDirect(full);
      const url = new URL(window.location.href);
      url.searchParams.set('sid', String(script.id));
      window.history.replaceState({}, '', url.toString());
    } catch (err) {
      setError((err as Error).message);
    }
  };

  const handleCreate = async () => {
    if (creatingRef.current) {
      return;
    }
    creatingRef.current = true;
    setLoading(true);
    setError(null);
    try {
      const newScript = await api.current.create();
      await loadScripts();
      await openScript(newScript);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setLoading(false);
      creatingRef.current = false;
    }
  };

  const handleSave = async () => {
    if (!selectedScript) {
      return;
    }

    setSaving(true);
    setError(null);
    setSuccess(null);
    try {
      const updated = await api.current.update({
        sid: selectedScript.id,
        name: form.name,
        script: form.script,
        test_variables: form.test_variables,
        async: form.async,
        data_config: form.data_config,
        model: form.model || null,
        temperature: form.temperature || null,
        max_tokens: form.max_tokens ? parseInt(form.max_tokens, 10) : null,
        refresh_sections: form.refresh_sections || null,
        prompt_change_note: promptChangeNote || null,
        prompt_meta_json: buildPromptMetaJson(promptChangeNote, promptVariablesSchema),
      });
      setSelectedScript(updated);
      setPromptChangeNote('');
      setSuccess(`Script saved at ${new Date().toLocaleTimeString()}`);
      await reloadPromptBootstrap();
      await loadScripts();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!deleteConfirm) {
      return;
    }
    if (deleteInput !== deleteConfirm.generated_id) {
      setError('Verification text does not match the generated ID.');
      return;
    }

    setLoading(true);
    setError(null);
    try {
      await api.current.remove(deleteConfirm.id);
      setDeleteConfirm(null);
      setDeleteInput('');
      setSelectedScript(null);
      setViewMode('list');
      await loadScripts();
      setSuccess('Script deleted successfully.');
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setLoading(false);
    }
  };

  const backToList = () => {
    setViewMode('list');
    setSelectedScript(null);
    setPromptChangeNote('');
    setPromptVariablesSchema(null);
    setForm(getEmptyForm());
    setError(null);
    setSuccess(null);
    const url = new URL(window.location.href);
    url.searchParams.delete('sid');
    url.searchParams.delete('action');
    window.history.replaceState({}, '', url.toString());
    loadScripts();
  };

  const openDataConfigModal = () => {
    if (typeof $ === 'undefined') {
      return;
    }

    try {
      const textarea = document.querySelector('textarea[name="data_config"]') as HTMLTextAreaElement | null;
      if (textarea) {
        textarea.value = form.data_config || '';
        textarea.dispatchEvent(new Event('change'));
      }

      if (typeof (window as any).dataConfigEditor !== 'undefined' && (window as any).dataConfigEditor) {
        try {
          const value = form.data_config ? JSON.parse(form.data_config) : [];
          (window as any).dataConfigEditor.setValue(value);
        } catch {
          // keep editor empty
        }
      }

      const saveBtn = document.querySelector('.saveDataConfig');
      if (saveBtn) {
        saveBtn.setAttribute('data-dismiss', 'modal');
      }

      $('.saveDataConfig').off('click.llmscripts').on('click.llmscripts', () => {
        let value = '';
        if (typeof (window as any).dataConfigEditor !== 'undefined' && (window as any).dataConfigEditor) {
          const editorValue = (window as any).dataConfigEditor.getValue();
          value = JSON.stringify(editorValue, null, 3);
          if (value === '[]') {
            value = '';
          }
        } else if (textarea) {
          value = textarea.value;
        }

        setForm((prev) => ({ ...prev, data_config: value }));
      });

      $('.data_config_builder_modal_holder').modal({ backdrop: false });
    } catch {
      // keep silent, same as existing behavior
    }
  };

  const filteredSections = sections.filter((section) => (
    !sectionSearch || (section.name && section.name.toLowerCase().includes(sectionSearch.toLowerCase()))
  ));

  const selectedSectionIds = getRefreshSectionIds();

  const toggleSection = (sectionId: number) => {
    const current = getRefreshSectionIds();
    if (current.includes(sectionId)) {
      setRefreshSectionIds(current.filter((id) => id !== sectionId));
      return;
    }

    setRefreshSectionIds([...current, sectionId]);
  };

  const getDataConfigLabel = (): string => {
    if (!form.data_config) {
      return 'Add Data Config';
    }
    try {
      const parsed = JSON.parse(form.data_config);
      if (parsed && (Array.isArray(parsed) ? parsed.length > 0 : Object.keys(parsed).length > 0)) {
        return 'Edit Data Config';
      }
    } catch {
      return 'Edit Data Config';
    }
    return 'Add Data Config';
  };

  const openDiffWithVersion = (version: PromptVersion) => {
    setShowVersions(false);
    setDiffState({
      initialLeftKey: `v:${version.id}`,
      initialRightKey: 'draft',
    });
    setShowDiff(true);
  };

  const handleUseVersion = (version: PromptVersion) => {
    setForm((prev) => ({ ...prev, script: version.template_raw || '' }));
    setPromptChangeNote((current) => current || `Restored from version ${version.version_no}`);
    setShowVersions(false);
  };

  const handleBuilderApply = (
    nextPrompt: string,
    variables: PromptVariableDefinition[],
    changeSummary: string,
  ) => {
    setForm((prev) => ({ ...prev, script: nextPrompt }));
    if (variables.length > 0) {
      setPromptVariablesSchema(variables);
    }
    if (!promptChangeNote && changeSummary) {
      setPromptChangeNote(changeSummary);
    }
    setShowBuilder(false);
  };

  const effectiveVariablesSchema = promptVariablesSchema || promptBootstrap?.variables_schema || [];
  const activeVersion = promptBootstrap?.active_version || null;
  const promptDisabled = !acl.update || !config.promptLabEndpoint;

  if (viewMode === 'list') {
    return (
      <Container fluid className="llm-scripts-manager py-3">
        <Row className="mb-3">
          <Col>
            <div className="d-flex justify-content-between align-items-center flex-wrap">
              <div className="d-flex align-items-center">
                <h5 className="text-dark mb-0 font-weight-bold">
                  <i className="fas fa-scroll mr-2 text-secondary"></i>
                  LLM Scripts
                </h5>
                <Badge variant="secondary" className="ml-2">
                  {scripts.length}
                </Badge>
              </div>
              <div>
                {acl.insert && (
                  <Button size="sm" variant="primary" onClick={handleCreate} disabled={loading} className="mr-2">
                    <i className="fas fa-plus mr-1"></i>
                    New Script
                  </Button>
                )}
                <Button size="sm" variant="outline-secondary" onClick={loadScripts} disabled={loading}>
                  <i className={`fas fa-sync-alt ${loading ? 'fa-spin' : ''}`}></i>
                </Button>
              </div>
            </div>
          </Col>
        </Row>

        {error && (
          <Row className="mb-3">
            <Col>
              <Alert variant="danger" dismissible onClose={() => setError(null)}>
                <i className="fas fa-exclamation-triangle mr-2"></i>
                {error}
              </Alert>
            </Col>
          </Row>
        )}

        {success && (
          <Row className="mb-3">
            <Col>
              <Alert variant="success" dismissible onClose={() => setSuccess(null)}>
                <i className="fas fa-check-circle mr-2"></i>
                {success}
              </Alert>
            </Col>
          </Row>
        )}

        <Row>
          <Col>
            <Card className="border">
              <Card.Header className="bg-secondary text-white py-2">
                <span className="font-weight-bold small">
                  <i className="fas fa-list mr-2"></i>
                  All Scripts
                </span>
              </Card.Header>
              <div className="table-responsive">
                {loading && scripts.length === 0 ? (
                  <div className="text-center py-5">
                    <Spinner animation="border" variant="secondary" className="mb-3" />
                    <div className="text-muted">Loading scripts...</div>
                  </div>
                ) : scripts.length === 0 ? (
                  <div className="text-center py-5 px-3">
                    <i className="fas fa-scroll fa-3x text-muted mb-3"></i>
                    <h6 className="text-muted">No LLM scripts yet</h6>
                    <p className="text-muted small mb-0">
                      Click &quot;New Script&quot; to create a reusable LLM prompt template.
                    </p>
                  </div>
                ) : (
                  <Table hover size="sm" className="mb-0 scripts-table">
                    <thead>
                      <tr>
                        <th>ID</th>
                        <th>Generated ID</th>
                        <th>Name</th>
                        <th>Mode</th>
                        <th>Model</th>
                        <th>Created</th>
                        <th>Updated</th>
                      </tr>
                    </thead>
                    <tbody>
                      {scripts.map((script) => (
                        <tr key={script.id} className="cursor-pointer" onClick={() => openScript(script)}>
                          <td>{script.id}</td>
                          <td><code className="small">{script.generated_id}</code></td>
                          <td className="font-weight-bold">{script.name}</td>
                          <td>
                            {script.async ? (
                              <Badge variant="info">Async</Badge>
                            ) : (
                              <Badge variant="secondary">Sync</Badge>
                            )}
                          </td>
                          <td className="small">{script.model || <em className="text-muted">default</em>}</td>
                          <td className="small text-muted">{formatDateTime(script.created_at)}</td>
                          <td className="small text-muted">{formatDateTime(script.updated_at)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </Table>
                )}
              </div>
            </Card>
          </Col>
        </Row>
      </Container>
    );
  }

  return (
    <Container fluid className="llm-scripts-manager py-3">
      <Row className="mb-3">
        <Col>
          <div className="d-flex justify-content-between align-items-center flex-wrap">
            <div className="d-flex align-items-center">
              <Button size="sm" variant="outline-secondary" onClick={backToList} className="mr-2">
                <i className="fas fa-arrow-left mr-1"></i>
                All Scripts
              </Button>
              <h5 className="text-dark mb-0 font-weight-bold">
                {selectedScript?.name || 'New Script'}
              </h5>
              {selectedScript && (
                <Badge variant="warning" className="ml-2 small">
                  <code>{selectedScript.generated_id}</code>
                </Badge>
              )}
            </div>
            <div>
              {acl.update && (
                <Button
                  size="sm"
                  variant="success"
                  onClick={handleSave}
                  disabled={saving || !form.name}
                  className="mr-1"
                >
                  {saving ? (
                    <>
                      <Spinner animation="border" size="sm" className="mr-1" />
                      Saving...
                    </>
                  ) : (
                    <>
                      <i className="fas fa-save mr-1"></i>
                      Save
                    </>
                  )}
                </Button>
              )}
              {acl.delete && selectedScript && (
                <Button
                  size="sm"
                  variant="outline-danger"
                  onClick={() => setDeleteConfirm(selectedScript)}
                >
                  <i className="fas fa-trash-alt mr-1"></i>
                  Delete
                </Button>
              )}
            </div>
          </div>
        </Col>
      </Row>

      {error && (
        <Row className="mb-2">
          <Col>
            <Alert variant="danger" dismissible onClose={() => setError(null)} className="mb-0 py-2 small">
              <i className="fas fa-exclamation-triangle mr-2"></i>
              {error}
            </Alert>
          </Col>
        </Row>
      )}

      {promptError && (
        <Row className="mb-2">
          <Col>
            <Alert variant="warning" className="mb-0 py-2 small">
              <i className="fas fa-info-circle mr-2"></i>
              {promptError}
            </Alert>
          </Col>
        </Row>
      )}

      {success && (
        <Row className="mb-2">
          <Col>
            <Alert variant="success" dismissible onClose={() => setSuccess(null)} className="mb-0 py-2 small">
              <i className="fas fa-check-circle mr-2"></i>
              {success}
            </Alert>
          </Col>
        </Row>
      )}

      <Row>
        <Col lg={8} className="mb-3 mb-lg-0">
          <PromptToolbar
            activeVersion={activeVersion}
            dirty={form.script !== (activeVersion?.template_raw || '')}
            disabled={promptDisabled}
            changeNote={promptChangeNote}
            onChangeNote={setPromptChangeNote}
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
            showDatasets
          />

          <Card className="border h-100">
            <Card.Header className="bg-warning text-dark py-2 d-flex justify-content-between align-items-center">
              <span className="font-weight-bold small">
                <i className="fas fa-code mr-2"></i>
                LLM Script Prompt
              </span>
              {promptLoading && (
                <span className="small text-muted">
                  <Spinner animation="border" size="sm" className="mr-1" />
                  Loading prompt history...
                </span>
              )}
            </Card.Header>
            <Card.Body className="p-0">
              <PromptEditor
                value={form.script}
                onChange={(value) => setForm((prev) => ({ ...prev, script: value }))}
                editorMode="monaco"
                minHeight={560}
                placeholder="Write the script prompt template here"
              />
            </Card.Body>
          </Card>
        </Col>

        <Col lg={4}>
          <Card className="border mb-3">
            <Card.Header className="bg-light py-2">
              <span className="font-weight-bold small">
                <i className="fas fa-cog mr-2"></i>
                Configuration
              </span>
            </Card.Header>
            <Card.Body className="py-2 px-3">
              <Form.Group className="mb-2">
                <Form.Label className="small font-weight-bold mb-1">Script Name *</Form.Label>
                <Form.Control
                  size="sm"
                  type="text"
                  value={form.name}
                  onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))}
                  placeholder="Enter script name"
                />
              </Form.Group>

              <Form.Group className="mb-2">
                <Form.Check
                  type="checkbox"
                  label={<span className="small">Async (run via cron, not immediately)</span>}
                  checked={!!form.async}
                  onChange={(event) => setForm((prev) => ({ ...prev, async: event.target.checked ? 1 : 0 }))}
                />
              </Form.Group>

              <Form.Group className="mb-2">
                <Form.Label className="small font-weight-bold mb-1">Model</Form.Label>
                <Form.Control
                  as="select"
                  size="sm"
                  value={form.model}
                  onChange={(event) => setForm((prev) => ({ ...prev, model: event.target.value }))}
                >
                  <option value="">
                    {defaults ? `Default (${defaults.default_model})` : 'Default'}
                  </option>
                  {models.map((model) => (
                    <option key={model.id} value={model.id}>{model.id}</option>
                  ))}
                </Form.Control>
              </Form.Group>

              <Form.Group className="mb-2">
                <Form.Label className="small font-weight-bold mb-1">Temperature</Form.Label>
                <Form.Control
                  size="sm"
                  type="number"
                  step="0.1"
                  min="0"
                  max="2"
                  value={form.temperature}
                  onChange={(event) => setForm((prev) => ({ ...prev, temperature: event.target.value }))}
                  placeholder={defaults ? `Default: ${defaults.default_temperature}` : 'e.g. 0.7'}
                />
              </Form.Group>

              <Form.Group className="mb-2">
                <Form.Label className="small font-weight-bold mb-1">Max Tokens</Form.Label>
                <Form.Control
                  size="sm"
                  type="number"
                  value={form.max_tokens}
                  onChange={(event) => setForm((prev) => ({ ...prev, max_tokens: event.target.value }))}
                  placeholder={defaults ? `Default: ${defaults.default_max_tokens}` : 'e.g. 2048'}
                />
              </Form.Group>

              <Form.Group className="mb-2">
                <Form.Label className="small font-weight-bold mb-1">Refresh Sections</Form.Label>
                <Dropdown>
                  <Dropdown.Toggle
                    size="sm"
                    variant="outline-secondary"
                    className="w-100 text-left d-flex justify-content-between align-items-center"
                  >
                    <span className="text-truncate">
                      {selectedSectionIds.length === 0
                        ? 'Select sections...'
                        : `${selectedSectionIds.length} section${selectedSectionIds.length > 1 ? 's' : ''} selected`}
                    </span>
                  </Dropdown.Toggle>
                  <Dropdown.Menu className="w-100 sections-dropdown-menu" style={{ maxHeight: '250px', overflowY: 'auto' }}>
                    <div className="px-2 pb-2">
                      <Form.Control
                        size="sm"
                        type="text"
                        placeholder="Search sections..."
                        value={sectionSearch}
                        onChange={(event) => setSectionSearch(event.target.value)}
                        onClick={(event) => event.stopPropagation()}
                      />
                    </div>
                    {filteredSections.length === 0 ? (
                      <Dropdown.ItemText className="text-muted small">No sections found</Dropdown.ItemText>
                    ) : (
                      filteredSections.map((section) => (
                        <Dropdown.Item
                          key={section.id}
                          as="button"
                          className="small py-1"
                          active={selectedSectionIds.includes(Number(section.id))}
                          onClick={(event) => {
                            event.preventDefault();
                            event.stopPropagation();
                            toggleSection(Number(section.id));
                          }}
                        >
                          <Form.Check
                            type="checkbox"
                            checked={selectedSectionIds.includes(Number(section.id))}
                            onChange={() => undefined}
                            label={<span>{section.name} <small className="text-muted">({section.id})</small></span>}
                            className="mb-0"
                          />
                        </Dropdown.Item>
                      ))
                    )}
                  </Dropdown.Menu>
                </Dropdown>
                {selectedSectionIds.length > 0 && (
                  <div className="mt-1">
                    {selectedSectionIds.map((id) => {
                      const section = sections.find((item) => Number(item.id) === id);
                      return (
                        <Badge
                          key={id}
                          variant="info"
                          className="mr-1 mb-1 cursor-pointer"
                          onClick={() => toggleSection(id)}
                        >
                          {section?.name || id} <i className="fas fa-times ml-1"></i>
                        </Badge>
                      );
                    })}
                  </div>
                )}
                <Form.Text className="text-muted" style={{ fontSize: '0.75rem' }}>
                  Sections to refresh after async execution completes.
                </Form.Text>
              </Form.Group>
            </Card.Body>
          </Card>

          <Card className="border mb-3">
            <Card.Header className="bg-light py-2">
              <span className="font-weight-bold small">
                <i className="fas fa-database mr-2"></i>
                Data Config
              </span>
            </Card.Header>
            <Card.Body className="py-2 px-3">
              <Button
                size="sm"
                variant={getDataConfigLabel() === 'Edit Data Config' ? 'warning' : 'primary'}
                className="w-100"
                onClick={openDataConfigModal}
              >
                <i className="fas fa-wrench mr-1"></i>
                {getDataConfigLabel()}
              </Button>
              {form.data_config && (
                <pre className="mt-2 mb-0 small p-2 bg-light border rounded prompt-pre" style={{ maxHeight: '120px', overflow: 'auto', fontSize: '0.7rem' }}>
                  {(() => {
                    try {
                      return JSON.stringify(JSON.parse(form.data_config), null, 2);
                    } catch {
                      return form.data_config;
                    }
                  })()}
                </pre>
              )}
            </Card.Body>
          </Card>

          <Card className="border">
            <Card.Header className="bg-light py-2">
              <span className="font-weight-bold small">
                <i className="fas fa-flask mr-2"></i>
                Test Variables (JSON)
              </span>
            </Card.Header>
            <Card.Body className="p-0">
              <PromptEditor
                value={form.test_variables || '{\n  \n}'}
                onChange={(value) => setForm((prev) => ({ ...prev, test_variables: value }))}
                editorMode="monaco"
                language="json"
                minHeight={220}
              />
            </Card.Body>
          </Card>
        </Col>
      </Row>

      <PromptVersionsModal
        show={showVersions}
        onHide={() => setShowVersions(false)}
        versions={promptBootstrap?.versions || []}
        activeVersionId={activeVersion?.id}
        disabled={promptDisabled}
        onUseVersion={handleUseVersion}
        onCompareVersion={openDiffWithVersion}
      />

      <PromptDiffModal
        show={showDiff}
        onHide={() => setShowDiff(false)}
        api={promptApi}
        descriptor={promptDescriptor}
        versions={promptBootstrap?.versions || []}
        draftContent={form.script}
        initialLeftKey={diffState.initialLeftKey}
        initialRightKey={diffState.initialRightKey}
      />

      <PromptPlaygroundModal
        show={showPlayground}
        onHide={() => setShowPlayground(false)}
        api={promptApi}
        descriptor={promptDescriptor}
        executionProfile={promptBootstrap?.execution_profile || 'script_runtime'}
        models={promptBootstrap?.models || models}
        variablesSchema={effectiveVariablesSchema}
        promptValue={form.script}
        disabled={promptDisabled}
        defaultModel={form.model || defaults?.default_model || models[0]?.id || null}
        resolveRuntimeOverrides={() => promptRuntimeOverrides}
        resolveInitialVariables={() => parseJsonObject(form.test_variables)}
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
        api={promptApi}
        descriptor={promptDescriptor}
        versions={promptBootstrap?.versions || []}
        activeVersionId={activeVersion?.id || null}
        executionProfile={promptBootstrap?.execution_profile || 'script_runtime'}
        promptValue={form.script}
        disabled={promptDisabled}
        defaultModel={form.model || defaults?.default_model || models[0]?.id || null}
        resolveRuntimeOverrides={() => promptRuntimeOverrides}
        lastPlaygroundCapture={lastPlaygroundCapture}
      />

      <PromptBuilderModal
        show={showBuilder}
        onHide={() => setShowBuilder(false)}
        api={promptApi}
        descriptor={promptDescriptor}
        currentPrompt={form.script}
        models={promptBootstrap?.models || models}
        defaultModel={form.model || defaults?.default_model || models[0]?.id || null}
        disabled={promptDisabled}
        onApplySuggestion={handleBuilderApply}
      />

      <Modal show={!!deleteConfirm} onHide={() => { setDeleteConfirm(null); setDeleteInput(''); }} centered size="sm">
        <Modal.Header closeButton className="bg-danger text-white py-2">
          <Modal.Title className="h6">
            <i className="fas fa-trash-alt mr-2"></i>
            Delete Script
          </Modal.Title>
        </Modal.Header>
        <Modal.Body>
          <p className="small">
            Delete <code>{deleteConfirm?.generated_id}</code>? All related jobs will stop working.
          </p>
          <p className="text-danger small font-weight-bold">This cannot be undone!</p>
          <Form.Control
            size="sm"
            type="text"
            value={deleteInput}
            onChange={(event) => setDeleteInput(event.target.value)}
            placeholder={`Type: ${deleteConfirm?.generated_id}`}
          />
        </Modal.Body>
        <Modal.Footer className="py-2">
          <Button size="sm" variant="secondary" onClick={() => { setDeleteConfirm(null); setDeleteInput(''); }}>
            Cancel
          </Button>
          <Button
            size="sm"
            variant="danger"
            onClick={handleDelete}
            disabled={deleteInput !== deleteConfirm?.generated_id}
          >
            Delete
          </Button>
        </Modal.Footer>
      </Modal>
    </Container>
  );
};
