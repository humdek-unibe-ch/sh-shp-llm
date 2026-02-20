import React, { useEffect, useState, useRef, useCallback } from 'react';
import {
  Container, Row, Col, Card, Button, Badge, Alert, Spinner,
  Table, Modal, Form, Dropdown
} from 'react-bootstrap';
import type { ScriptsConfig } from '../../scripts';
import { createScriptsApi, type LlmScript, type LlmModel, type SectionInfo, type LlmDefaults } from './scriptsApi';
import { MarkdownRenderer } from '../styles/shared/MarkdownRenderer';
import './ScriptsManager.css';

declare const monaco: any;
declare const require: any;
declare const BASE_PATH: string;
declare const $: any;

const formatDate = (dateString: string): string => {
  const date = new Date(dateString);
  return date.toLocaleDateString(undefined, {
    year: 'numeric', month: 'short', day: 'numeric',
    hour: '2-digit', minute: '2-digit'
  });
};

type ViewMode = 'list' | 'editor';

interface AclPerms {
  select: boolean;
  insert: boolean;
  update: boolean;
  delete: boolean;
}

export const ScriptsManager: React.FC<{ config: ScriptsConfig }> = ({ config }) => {
  const api = useRef(createScriptsApi());
  const copyFeedbackTimerRef = useRef<number | null>(null);

  const [scripts, setScripts] = useState<LlmScript[]>([]);
  const [selectedScript, setSelectedScript] = useState<LlmScript | null>(null);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [viewMode, setViewMode] = useState<ViewMode>('list');
  const [testResult, setTestResult] = useState<Record<string, unknown> | null>(null);
  const [copiedType, setCopiedType] = useState<'raw' | 'payload' | null>(null);
  const [testing, setTesting] = useState(false);
  const [deleteConfirm, setDeleteConfirm] = useState<LlmScript | null>(null);
  const [deleteInput, setDeleteInput] = useState('');

  // Config data loaded from controller
  const [models, setModels] = useState<LlmModel[]>([]);
  const [sections, setSections] = useState<SectionInfo[]>([]);
  const [defaults, setDefaults] = useState<LlmDefaults | null>(null);
  const [acl, setAcl] = useState<AclPerms>({ select: true, insert: true, update: true, delete: true });

  // Section search filter
  const [sectionSearch, setSectionSearch] = useState('');

  // Editor form state
  const [form, setForm] = useState({
    name: '',
    script: '',
    test_variables: '',
    async: 0,
    data_config: '',
    model: '',
    temperature: '',
    max_tokens: '',
    refresh_sections: '' as string,
  });

  const editorRef = useRef<any>(null);
  const editorContainerRef = useRef<HTMLDivElement>(null);
  const testVarsEditorRef = useRef<any>(null);
  const testVarsContainerRef = useRef<HTMLDivElement>(null);

  // Parse refresh_sections as array of numbers
  const getRefreshSectionIds = (): number[] => {
    if (!form.refresh_sections) return [];
    try {
      const parsed = JSON.parse(form.refresh_sections);
      if (Array.isArray(parsed)) return parsed.map(Number).filter(n => !isNaN(n));
    } catch { /* ignore */ }
    return [];
  };

  const setRefreshSectionIds = (ids: number[]) => {
    setForm(prev => ({ ...prev, refresh_sections: JSON.stringify(ids) }));
  };

  // Load configuration, models, sections on mount + relocate dataConfig modal to body
  useEffect(() => {
    api.current.getConfig().then(cfg => {
      setDefaults(cfg);
      if (cfg.acl) setAcl(cfg.acl);
    }).catch(() => {});

    api.current.getModels().then(m => setModels(m)).catch(() => {});
    api.current.getSections().then(s => setSections(s)).catch(() => {});

    // Move the core dataConfigBuilder modal from the hidden wrapper to body
    // so Bootstrap can show/hide it independently of the wrapper's display:none
    const modal = document.querySelector('#data-config-builder-wrapper .data_config_builder_modal_holder');
    if (modal) document.body.appendChild(modal);
  }, []);

  useEffect(() => {
    return () => {
      if (copyFeedbackTimerRef.current) {
        window.clearTimeout(copyFeedbackTimerRef.current);
      }
    };
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

  // Check URL for ?sid= parameter on mount to open script directly
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const sidParam = params.get('sid');
    if (sidParam) {
      const sid = parseInt(sidParam, 10);
      if (sid > 0) {
        api.current.get(sid).then(script => {
          openScriptDirect(script);
        }).catch(() => {});
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Initialize Monaco prompt editor when entering editor mode
  useEffect(() => {
    if (viewMode !== 'editor' || !editorContainerRef.current) return;

    if (editorRef.current) {
      editorRef.current.dispose();
      editorRef.current = null;
    }

    if (typeof window !== 'undefined' && typeof require !== 'undefined') {
      try {
        require.config({ paths: { vs: BASE_PATH + '/js/ext/vs' } });
        require(['vs/editor/editor.main'], () => {
          if (!editorContainerRef.current) return;
          const editor = monaco.editor.create(editorContainerRef.current, {
            value: form.script,
            language: 'markdown',
            automaticLayout: true,
            renderLineHighlight: 'none',
            wordWrap: 'on',
            minimap: { enabled: false },
            fontSize: 14,
            lineNumbers: 'on',
            scrollBeyondLastLine: false,
          });
          editorRef.current = editor;

          editor.onDidChangeModelContent(() => {
            setForm(prev => ({ ...prev, script: editor.getValue() }));
          });
        });
      } catch { /* Monaco not available */ }
    }

    return () => {
      if (editorRef.current) {
        editorRef.current.dispose();
        editorRef.current = null;
      }
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [viewMode]);

  // Initialize Monaco test variables editor when entering editor mode
  useEffect(() => {
    if (viewMode !== 'editor' || !testVarsContainerRef.current) return;

    if (testVarsEditorRef.current) {
      testVarsEditorRef.current.dispose();
      testVarsEditorRef.current = null;
    }

    if (typeof window !== 'undefined' && typeof require !== 'undefined') {
      try {
        require.config({ paths: { vs: BASE_PATH + '/js/ext/vs' } });
        require(['vs/editor/editor.main'], () => {
          if (!testVarsContainerRef.current) return;
          const editor = monaco.editor.create(testVarsContainerRef.current, {
            value: form.test_variables || '{\n  \n}',
            language: 'json',
            automaticLayout: true,
            renderLineHighlight: 'none',
            wordWrap: 'on',
            minimap: { enabled: false },
            fontSize: 13,
            lineNumbers: 'on',
            scrollBeyondLastLine: false,
          });
          testVarsEditorRef.current = editor;

          editor.onDidChangeModelContent(() => {
            setForm(prev => ({ ...prev, test_variables: editor.getValue() }));
          });
        });
      } catch { /* Monaco not available */ }
    }

    return () => {
      if (testVarsEditorRef.current) {
        testVarsEditorRef.current.dispose();
        testVarsEditorRef.current = null;
      }
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [viewMode]);

  const openScriptDirect = (full: LlmScript) => {
    setError(null);
    setSuccess(null);
    setTestResult(null);
    setSelectedScript(full);
    setForm({
      name: full.name || '',
      script: full.script || '',
      test_variables: full.test_variables || '',
      async: full.async ? 1 : 0,
      data_config: full.data_config || '',
      model: full.model || '',
      temperature: full.temperature != null ? String(full.temperature) : '',
      max_tokens: full.max_tokens ? String(full.max_tokens) : '',
      refresh_sections: full.refresh_sections || '[]',
    });
    setViewMode('editor');
  };

  const openScript = async (script: LlmScript) => {
    setError(null);
    setSuccess(null);
    setTestResult(null);
    try {
      const full = await api.current.get(script.id);
      openScriptDirect(full);
      // Update URL with script ID for deep linking
      const url = new URL(window.location.href);
      url.searchParams.set('sid', String(script.id));
      window.history.replaceState({}, '', url.toString());
    } catch (err) {
      setError((err as Error).message);
    }
  };

  const creatingRef = useRef(false);
  const handleCreate = async () => {
    if (creatingRef.current) return;
    creatingRef.current = true;
    setLoading(true);
    setError(null);
    try {
      const newScript = await api.current.create();
      await loadScripts();
      openScript(newScript);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setLoading(false);
      creatingRef.current = false;
    }
  };

  const handleSave = async () => {
    if (!selectedScript) return;
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
        max_tokens: form.max_tokens ? parseInt(form.max_tokens) : null,
        refresh_sections: form.refresh_sections || null,
      } as any);
      setSelectedScript(updated);
      setSuccess('Script saved at ' + new Date().toLocaleTimeString());
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!deleteConfirm) return;
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

  const handleTest = async () => {
    setTesting(true);
    setTestResult(null);
    setError(null);
    try {
      const result = await api.current.test({
        script: form.script,
        script_name: form.name || selectedScript?.name || 'Unnamed Script',
        sid: selectedScript?.id?.toString() || '',
        test_variables: form.test_variables,
        data_config: form.data_config,
        model: form.model,
        temperature: form.temperature,
        max_tokens: form.max_tokens,
      });
      setTestResult(result);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setTesting(false);
    }
  };

  const setCopyFeedback = (type: 'raw' | 'payload') => {
    setCopiedType(type);
    if (copyFeedbackTimerRef.current) {
      window.clearTimeout(copyFeedbackTimerRef.current);
    }
    copyFeedbackTimerRef.current = window.setTimeout(() => {
      setCopiedType(null);
      copyFeedbackTimerRef.current = null;
    }, 1800);
  };

  const copyRawResponse = () => {
    if (!testResult) return;
    const data = testResult.data as any;
    const raw = data?.raw_response || JSON.stringify(testResult, null, 2);
    navigator.clipboard.writeText(typeof raw === 'string' ? raw : JSON.stringify(raw, null, 2))
      .then(() => setCopyFeedback('raw'))
      .catch(() => setError('Failed to copy raw response'));
  };

  const getRequestPayloadFromTestResult = (): unknown => {
    if (!testResult) return null;

    const resultAny = testResult as any;
    const data = resultAny?.data as any;

    if (resultAny?.request_payload) return resultAny.request_payload;
    if (data?.request_payload) return data.request_payload;

    const raw = data?.raw_response ?? resultAny?.raw_response;
    if (!raw) return null;

    let parsedRaw: any = raw;
    if (typeof raw === 'string') {
      try {
        parsedRaw = JSON.parse(raw);
      } catch {
        return null;
      }
    }

    return parsedRaw?.request_payload ?? null;
  };

  const copyPayload = () => {
    const payload = getRequestPayloadFromTestResult();
    if (!payload) {
      setError('No payload available to copy');
      return;
    }
    const payloadString = typeof payload === 'string' ? payload : JSON.stringify(payload, null, 2);
    navigator.clipboard.writeText(payloadString)
      .then(() => setCopyFeedback('payload'))
      .catch(() => setError('Failed to copy payload'));
  };

  const openDataConfigModal = () => {
    if (typeof $ === 'undefined') return;

    try {
      const textarea = document.querySelector('textarea[name="data_config"]') as HTMLTextAreaElement;
      if (textarea) {
        textarea.value = form.data_config || '';
        textarea.dispatchEvent(new Event('change'));
      }

      // If JSONEditor is already initialized, set its value from our form state
      if (typeof (window as any).dataConfigEditor !== 'undefined' && (window as any).dataConfigEditor) {
        try {
          const val = form.data_config ? JSON.parse(form.data_config) : [];
          (window as any).dataConfigEditor.setValue(val);
        } catch { /* invalid JSON, editor will use empty */ }
      }

      // Ensure save button has data-dismiss so Bootstrap closes the modal
      const saveBtn = document.querySelector('.saveDataConfig');
      if (saveBtn) {
        saveBtn.setAttribute('data-dismiss', 'modal');
      }

      // Rebind save handler: read from JSONEditor, update React state
      $('.saveDataConfig').off('click.llmscripts').on('click.llmscripts', () => {
        let val = '';
        if (typeof (window as any).dataConfigEditor !== 'undefined' && (window as any).dataConfigEditor) {
          const editorVal = (window as any).dataConfigEditor.getValue();
          val = JSON.stringify(editorVal, null, 3);
          if (val === '[]') val = '';
        } else {
          const ta = document.querySelector('textarea[name="data_config"]') as HTMLTextAreaElement;
          if (ta) val = ta.value;
        }
        setForm(prev => ({ ...prev, data_config: val }));
      });

      // Open the modal
      $('.data_config_builder_modal_holder').modal({ backdrop: false });
    } catch { /* jQuery/modal not available */ }
  };

  const backToList = () => {
    setViewMode('list');
    setSelectedScript(null);
    setTestResult(null);
    setError(null);
    setSuccess(null);
    // Remove sid from URL
    const url = new URL(window.location.href);
    url.searchParams.delete('sid');
    url.searchParams.delete('action');
    window.history.replaceState({}, '', url.toString());
    loadScripts();
  };

  // Filtered sections for the dropdown
  const filteredSections = sections.filter(s =>
    !sectionSearch || (s.name && s.name.toLowerCase().includes(sectionSearch.toLowerCase()))
  );

  const selectedSectionIds = getRefreshSectionIds();

  const toggleSection = (sectionId: number) => {
    const current = getRefreshSectionIds();
    if (current.includes(sectionId)) {
      setRefreshSectionIds(current.filter(id => id !== sectionId));
    } else {
      setRefreshSectionIds([...current, sectionId]);
    }
  };

  const getDataConfigLabel = (): string => {
    if (!form.data_config) return 'Add Data Config';
    try {
      const parsed = JSON.parse(form.data_config);
      if (parsed && (Array.isArray(parsed) ? parsed.length > 0 : Object.keys(parsed).length > 0)) {
        return 'Edit Data Config';
      }
    } catch { /* ignore */ }
    return 'Add Data Config';
  };

  // === LIST VIEW ===
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
                    <i className="fas fa-plus mr-1"></i> New Script
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
                <i className="fas fa-exclamation-triangle mr-2"></i>{error}
              </Alert>
            </Col>
          </Row>
        )}

        {success && (
          <Row className="mb-3">
            <Col>
              <Alert variant="success" dismissible onClose={() => setSuccess(null)}>
                <i className="fas fa-check-circle mr-2"></i>{success}
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
                      Click "New Script" to create a reusable LLM prompt template.
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
                      {scripts.map(script => (
                        <tr
                          key={script.id}
                          className="cursor-pointer"
                          onClick={() => openScript(script)}
                        >
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
                          <td className="small text-muted">{formatDate(script.created_at)}</td>
                          <td className="small text-muted">{formatDate(script.updated_at)}</td>
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

  // === EDITOR VIEW ===
  return (
    <Container fluid className="llm-scripts-manager py-3">
      <Row className="mb-3">
        <Col>
          <div className="d-flex justify-content-between align-items-center flex-wrap">
            <div className="d-flex align-items-center">
              <Button size="sm" variant="outline-secondary" onClick={backToList} className="mr-2">
                <i className="fas fa-arrow-left mr-1"></i> All Scripts
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
              <Button
                size="sm"
                variant="primary"
                onClick={handleTest}
                disabled={testing || !form.script}
                className="mr-1"
              >
                {testing ? (
                  <><Spinner animation="border" size="sm" className="mr-1" /> Testing...</>
                ) : (
                  <><i className="fas fa-play mr-1"></i> Test</>
                )}
              </Button>
              {acl.update && (
                <Button
                  size="sm"
                  variant="success"
                  onClick={handleSave}
                  disabled={saving || !form.name}
                  className="mr-1"
                >
                  {saving ? (
                    <><Spinner animation="border" size="sm" className="mr-1" /> Saving...</>
                  ) : (
                    <><i className="fas fa-save mr-1"></i> Save</>
                  )}
                </Button>
              )}
              {acl.delete && selectedScript && (
                <Button
                  size="sm"
                  variant="outline-danger"
                  onClick={() => setDeleteConfirm(selectedScript)}
                >
                  <i className="fas fa-trash-alt mr-1"></i> Delete
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
              <i className="fas fa-exclamation-triangle mr-2"></i>{error}
            </Alert>
          </Col>
        </Row>
      )}

      {success && (
        <Row className="mb-2">
          <Col>
            <Alert variant="success" dismissible onClose={() => setSuccess(null)} className="mb-0 py-2 small">
              <i className="fas fa-check-circle mr-2"></i>{success}
            </Alert>
          </Col>
        </Row>
      )}

      <Row>
        {/* Left: Script editor */}
        <Col lg={8} className="mb-3 mb-lg-0">
          <Card className="border h-100">
            <Card.Header className="bg-warning text-dark py-2">
              <span className="font-weight-bold small">
                <i className="fas fa-code mr-2"></i>
                LLM Script (Prompt Template)
              </span>
            </Card.Header>
            <Card.Body className="p-0">
              <div
                ref={editorContainerRef}
                className="monaco-editor-container"
              />
            </Card.Body>
          </Card>
        </Col>

        {/* Right: Configuration */}
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
                  onChange={e => setForm(prev => ({ ...prev, name: e.target.value }))}
                  placeholder="Enter script name"
                />
              </Form.Group>

              <Form.Group className="mb-2">
                <Form.Check
                  type="checkbox"
                  label={<span className="small">Async (run via cron, not immediately)</span>}
                  checked={!!form.async}
                  onChange={e => setForm(prev => ({ ...prev, async: e.target.checked ? 1 : 0 }))}
                />
              </Form.Group>

              <Form.Group className="mb-2">
                <Form.Label className="small font-weight-bold mb-1">Model</Form.Label>
                <Form.Control
                  as="select"
                  size="sm"
                  value={form.model}
                  onChange={e => setForm(prev => ({ ...prev, model: e.target.value }))}
                >
                  <option value="">
                    {defaults ? `Default (${defaults.default_model})` : 'Default'}
                  </option>
                  {models.map(m => (
                    <option key={m.id} value={m.id}>{m.id}</option>
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
                  onChange={e => setForm(prev => ({ ...prev, temperature: e.target.value }))}
                  placeholder={defaults ? `Default: ${defaults.default_temperature}` : 'e.g. 0.7'}
                />
              </Form.Group>

              <Form.Group className="mb-2">
                <Form.Label className="small font-weight-bold mb-1">Max Tokens</Form.Label>
                <Form.Control
                  size="sm"
                  type="number"
                  value={form.max_tokens}
                  onChange={e => setForm(prev => ({ ...prev, max_tokens: e.target.value }))}
                  placeholder={defaults ? `Default: ${defaults.default_max_tokens}` : 'e.g. 2048'}
                />
              </Form.Group>

              {/* Refresh Sections Multi-Select */}
              <Form.Group className="mb-2">
                <Form.Label className="small font-weight-bold mb-1">Refresh Sections</Form.Label>
                <Dropdown>
                  <Dropdown.Toggle size="sm" variant="outline-secondary" className="w-100 text-left d-flex justify-content-between align-items-center">
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
                        onChange={e => setSectionSearch(e.target.value)}
                        onClick={e => e.stopPropagation()}
                      />
                    </div>
                    {filteredSections.length === 0 ? (
                      <Dropdown.ItemText className="text-muted small">No sections found</Dropdown.ItemText>
                    ) : (
                      filteredSections.map(s => (
                        <Dropdown.Item
                          key={s.id}
                          as="button"
                          className="small py-1"
                          active={selectedSectionIds.includes(Number(s.id))}
                          onClick={(e: any) => {
                            e.preventDefault();
                            e.stopPropagation();
                            toggleSection(Number(s.id));
                          }}
                        >
                          <Form.Check
                            type="checkbox"
                            checked={selectedSectionIds.includes(Number(s.id))}
                            onChange={() => {}}
                            label={<span>{s.name} <small className="text-muted">({s.id})</small></span>}
                            className="mb-0"
                          />
                        </Dropdown.Item>
                      ))
                    )}
                  </Dropdown.Menu>
                </Dropdown>
                {selectedSectionIds.length > 0 && (
                  <div className="mt-1">
                    {selectedSectionIds.map(id => {
                      const sec = sections.find(s => Number(s.id) === id);
                      return (
                        <Badge
                          key={id}
                          variant="info"
                          className="mr-1 mb-1 cursor-pointer"
                          onClick={() => toggleSection(id)}
                        >
                          {sec?.name || id} <i className="fas fa-times ml-1"></i>
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

          {/* Data Config */}
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
                <i className="fas fa-wrench mr-1"></i> {getDataConfigLabel()}
              </Button>
              {form.data_config && (
                <pre className="mt-2 mb-0 small p-2 bg-light border rounded" style={{ maxHeight: '120px', overflow: 'auto', fontSize: '0.7rem' }}>
                  {(() => {
                    try { return JSON.stringify(JSON.parse(form.data_config), null, 2); } catch { return form.data_config; }
                  })()}
                </pre>
              )}
            </Card.Body>
          </Card>

          {/* Test Variables (Monaco JSON) */}
          <Card className="border">
            <Card.Header className="bg-light py-2">
              <span className="font-weight-bold small">
                <i className="fas fa-flask mr-2"></i>
                Test Variables (JSON)
              </span>
            </Card.Header>
            <Card.Body className="p-0">
              <div
                ref={testVarsContainerRef}
                className="monaco-editor-container-small"
              />
            </Card.Body>
          </Card>
        </Col>
      </Row>

      {/* Test Result */}
      {testResult && (
        <Row className="mt-3">
          <Col>
            <Card className={`border ${(testResult as any).result ? 'border-success' : 'border-danger'}`}>
              <Card.Header className={`py-2 ${(testResult as any).result ? 'bg-success' : 'bg-danger'} text-white d-flex justify-content-between align-items-center`}>
                <span className="font-weight-bold small">
                  <i className={`fas ${(testResult as any).result ? 'fa-check-circle' : 'fa-times-circle'} mr-2`}></i>
                  Test Result
                </span>
                <div>
                  <Button
                    size="sm"
                    variant="outline-light"
                    className="mr-2 py-0"
                    onClick={copyRawResponse}
                    title="Copy raw response"
                  >
                    <i className={`fas ${copiedType === 'raw' ? 'fa-check' : 'fa-copy'} mr-1`}></i>
                    {copiedType === 'raw' ? 'Copied Raw' : 'Copy Raw'}
                  </Button>
                  <Button
                    size="sm"
                    variant="outline-light"
                    className="mr-2 py-0"
                    onClick={copyPayload}
                    title="Copy payload"
                    disabled={!getRequestPayloadFromTestResult()}
                  >
                    <i className={`fas ${copiedType === 'payload' ? 'fa-check' : 'fa-copy'} mr-1`}></i>
                    {copiedType === 'payload' ? 'Copied Payload' : 'Copy Payload'}
                  </Button>
                  <Button
                    size="sm"
                    variant="link"
                    className="text-white p-0"
                    onClick={() => setTestResult(null)}
                  >
                    <i className="fas fa-times"></i>
                  </Button>
                </div>
              </Card.Header>
              <Card.Body className="py-2">
                {(testResult as any).result && (testResult as any).data ? (
                  <div>
                    <div className="mb-2">
                      <strong className="small">Content:</strong>
                      <div className="p-2 bg-light border rounded mt-1 small llm-scripts-test-markdown">
                        <MarkdownRenderer content={(testResult as any).data.content || ''} />
                      </div>
                    </div>
                    <Row>
                      <Col sm={4}>
                        <small className="text-muted">Model:</small>
                        <div className="small font-weight-bold">{(testResult as any).data.model || '-'}</div>
                      </Col>
                      <Col sm={4}>
                        <small className="text-muted">Tokens:</small>
                        <div className="small font-weight-bold">{(testResult as any).data.tokens_used || '-'}</div>
                      </Col>
                      <Col sm={4}>
                        <small className="text-muted">Time:</small>
                        <div className="small font-weight-bold">{(testResult as any).data.execution_time_ms ? `${(testResult as any).data.execution_time_ms}ms` : '-'}</div>
                      </Col>
                    </Row>
                  </div>
                ) : (
                  <pre className="mb-0 pre-wrap small" style={{ maxHeight: '300px', overflow: 'auto' }}>
                    {JSON.stringify(testResult, null, 2)}
                  </pre>
                )}
              </Card.Body>
            </Card>
          </Col>
        </Row>
      )}

      {/* Delete Confirmation Modal */}
      <Modal show={!!deleteConfirm} onHide={() => { setDeleteConfirm(null); setDeleteInput(''); }} centered size="sm">
        <Modal.Header closeButton className="bg-danger text-white py-2">
          <Modal.Title className="h6">
            <i className="fas fa-trash-alt mr-2"></i> Delete Script
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
            onChange={e => setDeleteInput(e.target.value)}
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
