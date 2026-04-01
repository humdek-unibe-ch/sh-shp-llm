import React, { useEffect, useState, useCallback } from 'react';
import { Container, Row, Col, Card, Badge, Alert, Spinner, Button, Form, Modal, ListGroup, Tabs, Tab } from 'react-bootstrap';
import { memoryApi } from '../../utils/api';
import { formatDateTime } from '../../utils/formatters';
import { JsonInspector } from '../shared/JsonInspector';
import { JsonMonacoEditor } from '../shared/JsonMonacoEditor';

interface MemoryRule {
  key: string;
  label?: string;
  enabled: boolean;
  execution_mode: string;
  source_type?: string;
  memory_key?: string;
}

interface MemoryOverview {
  enabled: boolean;
  storage_mode: string;
  current_table: string;
  history_table: string;
  rules_count: number;
  enabled_rules: number;
  total_entries: number;
  unique_users: number;
  unique_keys: string[];
  rules: MemoryRule[];
}

interface MemoryUser {
  user_id: number;
  user_name?: string;
  user_email?: string;
  memory_count: number;
  last_updated: string | null;
  memory_keys: string[];
}

function tryParseJson(value: unknown): unknown {
  if (typeof value !== 'string') {
    return value;
  }
  try {
    return JSON.parse(value);
  } catch {
    return value;
  }
}

type MemoryDetailTab = 'current' | 'history' | 'rules';

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return !!value && typeof value === 'object' && !Array.isArray(value);
}

function flattenDiffObject(value: unknown, prefix = '', output: Record<string, string> = {}) {
  if (isPlainObject(value)) {
    Object.entries(value).forEach(([key, child]) => {
      const nextPrefix = prefix ? `${prefix}.${key}` : key;
      flattenDiffObject(child, nextPrefix, output);
    });
    return output;
  }

  output[prefix || '(root)'] = typeof value === 'string' ? value : JSON.stringify(value ?? null);
  return output;
}

function buildDiffRows(beforeValue: unknown, afterValue: unknown) {
  const beforeFlat = flattenDiffObject(beforeValue);
  const afterFlat = flattenDiffObject(afterValue);
  const keys = Array.from(new Set([...Object.keys(beforeFlat), ...Object.keys(afterFlat)])).sort();

  return keys
    .map((path) => {
      const before = beforeFlat[path];
      const after = afterFlat[path];
      if (before === after) {
        return null;
      }
      return {
        path,
        before: before ?? '',
        after: after ?? '',
        status: before === undefined ? 'added' : after === undefined ? 'removed' : 'changed',
      };
    })
    .filter((item): item is { path: string; before: string; after: string; status: string } => item !== null);
}

export const MemoryAdminPanel: React.FC<{
  initialUserId?: number;
  initialMemoryKey?: string;
  initialActiveTab?: MemoryDetailTab;
  initialEditOpen?: boolean;
  onSelectionChange?: (userId?: number, memoryKey?: string) => void;
  onStateChange?: (state: { userId?: number; memoryKey?: string; history?: boolean; edit?: boolean }) => void;
}> = ({ initialUserId, initialMemoryKey, initialActiveTab = 'current', initialEditOpen = false, onSelectionChange, onStateChange }) => {
  const [overview, setOverview] = useState<MemoryOverview | null>(null);
  const [users, setUsers] = useState<MemoryUser[]>([]);
  const [totalUsers, setTotalUsers] = useState(0);
  const [currentPage, setCurrentPage] = useState(1);
  const [search, setSearch] = useState('');
  const [selectedUser, setSelectedUser] = useState<MemoryUser | null>(null);
  const [userMemory, setUserMemory] = useState<Record<string, unknown> | null>(null);
  const [userMemoryKeys, setUserMemoryKeys] = useState<string[]>([]);
  const [selectedMemoryKey, setSelectedMemoryKey] = useState<string>('');
  const [history, setHistory] = useState<Record<string, unknown>[]>([]);
  const [activeDetailTab, setActiveDetailTab] = useState<MemoryDetailTab>(initialActiveTab);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [actionLoading, setActionLoading] = useState(false);

  const [editModal, setEditModal] = useState(false);
  const [editText, setEditText] = useState('');
  const [editJson, setEditJson] = useState('');

  const loadOverview = useCallback(async () => {
    try {
      const data = await memoryApi.getOverview();
      if (data.error) throw new Error(data.error);
      setOverview(data);
    } catch (err) {
      setError((err as Error).message);
    }
  }, []);

  const loadUsers = useCallback(async (page = 1) => {
    setLoading(true);
    try {
      const data = await memoryApi.getUsers({ page, per_page: 25, q: search || undefined });
      if (data.error) throw new Error(data.error);
      setUsers(data.items || []);
      setTotalUsers(data.total || 0);
      setCurrentPage(data.page || page);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setLoading(false);
    }
  }, [search]);

  const loadUserDetail = useCallback(async (userId: number, memoryKey?: string) => {
    setLoading(true);
    try {
      const data = await memoryApi.getUserDetail(String(userId), memoryKey);
      if (data.error) throw new Error(data.error);
      setUserMemory(data.memory);
      setUserMemoryKeys(data.memory_keys || []);
      if (!memoryKey && data.memory_keys?.length > 0) {
        setSelectedMemoryKey(data.memory_keys[0]);
      }
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setLoading(false);
    }
  }, []);

  const loadHistory = useCallback(async (userId: number, memoryKey?: string) => {
    try {
      const data = await memoryApi.getUserHistory(String(userId), memoryKey, 50);
      if (data.error) throw new Error(data.error);
      setHistory(data.history || []);
    } catch (err) {
      setError((err as Error).message);
    }
  }, []);

  useEffect(() => {
    loadOverview();
    loadUsers();
  }, []);

  useEffect(() => {
    if (!selectedUser && initialUserId && users.length > 0) {
      const match = users.find((user) => user.user_id === initialUserId);
      if (match) {
        handleSelectUser(match);
      }
    }
  }, [initialUserId, users]);

  useEffect(() => {
    const timer = setTimeout(() => loadUsers(1), 300);
    return () => clearTimeout(timer);
  }, [search]);

  useEffect(() => {
    if (selectedUser && selectedMemoryKey) {
      loadUserDetail(selectedUser.user_id, selectedMemoryKey);
      loadHistory(selectedUser.user_id, selectedMemoryKey);
      onSelectionChange?.(selectedUser.user_id, selectedMemoryKey);
    }
  }, [selectedMemoryKey]);

  useEffect(() => {
    onStateChange?.({
      userId: selectedUser?.user_id,
      memoryKey: selectedMemoryKey || undefined,
      history: activeDetailTab === 'history',
      edit: editModal,
    });
  }, [selectedUser, selectedMemoryKey, activeDetailTab, editModal, onStateChange]);

  useEffect(() => {
    setActiveDetailTab(initialActiveTab);
  }, [initialActiveTab]);

  useEffect(() => {
    if (initialEditOpen && selectedUser && userMemory && !editModal) {
      handleEdit();
    }
  }, [initialEditOpen, selectedUser, userMemory, editModal]);

  const handleSelectUser = async (user: MemoryUser) => {
    setSelectedUser(user);
    setSelectedMemoryKey(initialMemoryKey || user.memory_keys[0] || 'global');
    onSelectionChange?.(user.user_id, initialMemoryKey || user.memory_keys[0] || 'global');
    await loadUserDetail(user.user_id);
    await loadHistory(user.user_id, initialMemoryKey || user.memory_keys[0] || 'global');
  };

  const handleRerunRule = async (ruleKey: string) => {
    if (!selectedUser) return;
    setActionLoading(true);
    try {
      const res = await memoryApi.rerunRule(String(selectedUser.user_id), ruleKey);
      if (res.error) throw new Error(res.error);
      await loadUserDetail(selectedUser.user_id, selectedMemoryKey);
      await loadHistory(selectedUser.user_id, selectedMemoryKey);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setActionLoading(false);
    }
  };

  const handleRebuild = async () => {
    if (!selectedUser) return;
    setActionLoading(true);
    try {
      const res = await memoryApi.rebuild(String(selectedUser.user_id));
      if (res.error) throw new Error(res.error);
      await loadUserDetail(selectedUser.user_id, selectedMemoryKey);
      await loadHistory(selectedUser.user_id, selectedMemoryKey);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setActionLoading(false);
    }
  };

  const handleEdit = () => {
    if (!userMemory) return;
    setEditText(String(userMemory['memory_text'] || ''));
    const jsonObj = userMemory['memory_json'] || userMemory['memory_object'] || {};
    setEditJson(typeof jsonObj === 'string' ? jsonObj : JSON.stringify(jsonObj, null, 2));
    setEditModal(true);
  };

  const handleSaveEdit = async () => {
    if (!selectedUser) return;
    setActionLoading(true);
    try {
      const res = await memoryApi.edit(
        String(selectedUser.user_id),
        selectedMemoryKey,
        editText,
        editJson
      );
      if (res.error) throw new Error(res.error);
      setEditModal(false);
      await loadUserDetail(selectedUser.user_id, selectedMemoryKey);
      await loadHistory(selectedUser.user_id, selectedMemoryKey);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setActionLoading(false);
    }
  };

  const handleDelete = async () => {
    if (!selectedUser || !confirm('Delete this user\'s memory? A history entry will be kept.')) return;
    setActionLoading(true);
    try {
      const res = await memoryApi.delete(String(selectedUser.user_id), selectedMemoryKey);
      if (res.error) throw new Error(res.error);
      await loadUserDetail(selectedUser.user_id, selectedMemoryKey);
      await loadHistory(selectedUser.user_id, selectedMemoryKey);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setActionLoading(false);
    }
  };

  if (!overview) {
    return (
      <div className="text-center py-4">
        <Spinner animation="border" size="sm" />
        <span className="ml-2">Loading memory overview...</span>
      </div>
    );
  }

  if (!overview.enabled) {
    return (
      <Alert variant="info" className="m-3">
        <i className="fas fa-info-circle mr-2"></i>
        Global User Memory is not enabled. Enable it in the LLM module settings.
      </Alert>
    );
  }

  return (
    <Container fluid className="py-2">
      {error && (
        <Alert variant="danger" dismissible onClose={() => setError(null)} className="mb-3">
          <i className="fas fa-exclamation-triangle mr-2"></i>{error}
        </Alert>
      )}

      {/* Overview Stats */}
      <Row className="mb-3">
        <Col sm={3}>
          <Card className="text-center border">
            <Card.Body className="py-2">
              <div className="text-muted small">Users</div>
              <div className="h5 mb-0">{overview.unique_users}</div>
            </Card.Body>
          </Card>
        </Col>
        <Col sm={3}>
          <Card className="text-center border">
            <Card.Body className="py-2">
              <div className="text-muted small">Entries</div>
              <div className="h5 mb-0">{overview.total_entries}</div>
            </Card.Body>
          </Card>
        </Col>
        <Col sm={3}>
          <Card className="text-center border">
            <Card.Body className="py-2">
              <div className="text-muted small">Rules</div>
              <div className="h5 mb-0">{overview.enabled_rules}/{overview.rules_count}</div>
            </Card.Body>
          </Card>
        </Col>
        <Col sm={3}>
          <Card className="text-center border">
            <Card.Body className="py-2">
              <div className="text-muted small">Mode</div>
              <div className="h5 mb-0">{overview.storage_mode}</div>
            </Card.Body>
          </Card>
        </Col>
      </Row>

      <Row>
        {/* Users List */}
        <Col lg={4} className="mb-3">
          <Card className="border h-100">
            <Card.Header className="bg-light py-2 d-flex justify-content-between align-items-center">
              <span className="font-weight-bold small">
                <i className="fas fa-users mr-1"></i>Users ({totalUsers})
              </span>
            </Card.Header>
            <Card.Body className="p-2">
              <Form.Control
                type="text"
                size="sm"
                placeholder="Search users..."
                value={search}
                onChange={e => setSearch(e.target.value)}
                className="mb-2"
              />
              {loading && !selectedUser ? (
                <div className="text-center py-3"><Spinner size="sm" animation="border" /></div>
              ) : users.length === 0 ? (
                <div className="text-muted text-center small py-3">No memory users found</div>
              ) : (
                <ListGroup variant="flush" className="memory-user-list" style={{ maxHeight: '400px', overflowY: 'auto' }}>
                  {users.map(user => (
                    <ListGroup.Item
                      key={user.user_id}
                      action
                      active={selectedUser?.user_id === user.user_id}
                      onClick={() => handleSelectUser(user)}
                      className="py-2 px-2"
                    >
                      <div className="d-flex justify-content-between align-items-start">
                        <div>
                          <div className="font-weight-bold small">{user.user_name || `User #${user.user_id}`}</div>
                          {user.user_email && <div className="text-muted" style={{ fontSize: '0.75rem' }}>{user.user_email}</div>}
                        </div>
                        <Badge variant="secondary" className="ml-1">{user.memory_count}</Badge>
                      </div>
                      {user.last_updated && (
                        <div className="text-muted" style={{ fontSize: '0.7rem' }}>
                          Updated: {formatDateTime(user.last_updated)}
                        </div>
                      )}
                    </ListGroup.Item>
                  ))}
                </ListGroup>
              )}
            </Card.Body>
          </Card>
        </Col>

        {/* Detail Panel */}
        <Col lg={8}>
          {!selectedUser ? (
            <Card className="border h-100">
              <Card.Body className="text-center py-5 text-muted">
                <i className="fas fa-brain fa-3x mb-3"></i>
                <h5>Select a user to view their memory</h5>
              </Card.Body>
            </Card>
          ) : (
            <Card className="border">
              <Card.Header className="bg-light py-2">
                <div className="d-flex justify-content-between align-items-center flex-wrap">
                  <span className="font-weight-bold">
                    <i className="fas fa-brain mr-1"></i>
                    {selectedUser.user_name || `User #${selectedUser.user_id}`}
                  </span>
                  <div>
                    {userMemoryKeys.length > 1 && (
                      <Form.Control
                        as="select"
                        size="sm"
                        value={selectedMemoryKey}
                        onChange={e => setSelectedMemoryKey(e.target.value)}
                        className="d-inline-block mr-2"
                        style={{ width: 'auto' }}
                      >
                        {userMemoryKeys.map(k => (
                          <option key={k} value={k}>{k}</option>
                        ))}
                      </Form.Control>
                    )}
                    <Button size="sm" variant="outline-primary" onClick={handleEdit} disabled={actionLoading} className="mr-1">
                      <i className="fas fa-edit"></i>
                    </Button>
                    <Button size="sm" variant="outline-warning" onClick={handleRebuild} disabled={actionLoading} className="mr-1">
                      <i className="fas fa-sync-alt"></i>
                    </Button>
                    <Button size="sm" variant="outline-danger" onClick={handleDelete} disabled={actionLoading}>
                      <i className="fas fa-trash"></i>
                    </Button>
                  </div>
                </div>
              </Card.Header>
              <Card.Body className="p-0">
                <Tabs activeKey={activeDetailTab} onSelect={(key) => setActiveDetailTab((key as MemoryDetailTab) || 'current')} className="px-2 pt-2">
                  <Tab eventKey="current" title="Current Memory">
                    <div className="p-3">
                      {loading ? (
                        <div className="text-center py-3"><Spinner size="sm" animation="border" /></div>
                      ) : !userMemory ? (
                        <div className="text-muted text-center py-3">No memory data</div>
                      ) : (
                        <>
                          <h6 className="text-muted">Memory Text</h6>
                          <pre className="bg-light p-2 rounded small mb-3" style={{ whiteSpace: 'pre-wrap', maxHeight: '160px', overflow: 'auto' }}>
                            {String(userMemory['memory_text'] || '(empty)')}
                          </pre>

                          <h6 className="text-muted">Memory JSON</h6>
                          <div className="border rounded bg-light p-2 mb-3">
                            <JsonInspector value={userMemory['memory_json_decoded'] || tryParseJson(userMemory['memory_json'] || userMemory['memory_object'] || {})} className="small" />
                          </div>

                          <h6 className="text-muted">Flattened Fields</h6>
                          <div className="border rounded bg-light p-2 mb-3">
                            <JsonInspector value={userMemory['flat_fields'] || {}} className="small" emptyLabel="No flattened fields." />
                          </div>

                          <h6 className="text-muted">Source Metadata</h6>
                          <div className="border rounded bg-light p-2">
                            <JsonInspector value={{
                              last_rule_key: userMemory['last_rule_key'],
                              last_source_type: userMemory['last_source_type'],
                              last_source_ref: userMemory['last_source_ref_decoded'] || tryParseJson(userMemory['last_source_ref']),
                              last_trigger_type: userMemory['last_trigger_type'],
                              last_payload_json: userMemory['last_payload_json_decoded'] || tryParseJson(userMemory['last_payload_json']),
                              last_event_at: userMemory['last_event_at'],
                              last_updated_at: userMemory['last_updated_at'],
                            }} className="small" />
                          </div>
                        </>
                      )}
                    </div>
                  </Tab>
                  <Tab eventKey="history" title={`History (${history.length})`}>
                    <div className="p-3" style={{ maxHeight: '500px', overflow: 'auto' }}>
                      {history.length === 0 ? (
                        <div className="text-muted text-center py-3">No history entries</div>
                      ) : (
                        history.map((entry, idx) => {
                          const diffRows = buildDiffRows(
                            entry['prev_memory_json_decoded'] || tryParseJson(entry['prev_memory_json'] || {}),
                            entry['memory_json_decoded'] || tryParseJson(entry['memory_json'] || {})
                          );

                          return (
                          <Card key={idx} className="mb-2 border">
                            <Card.Body className="py-2 px-3">
                              <div className="d-flex justify-content-between align-items-start mb-1">
                                <Badge variant={
                                  entry['update_status'] === 'applied' ? 'success' :
                                  entry['update_status'] === 'failed' ? 'danger' : 'secondary'
                                } className="small">
                                  {String(entry['update_status'] || 'unknown')}
                                </Badge>
                                <span className="text-muted" style={{ fontSize: '0.75rem' }}>
                                  {entry['event_at'] ? formatDateTime(String(entry['event_at'])) : ''}
                                </span>
                              </div>
                              <div className="small">
                                <strong>Rule:</strong> {String(entry['rule_key'] || '-')} |{' '}
                                <strong>Source:</strong> {String(entry['source_type'] || '-')}
                              </div>
                              {Boolean(entry['change_summary']) && (
                                <div className="text-muted small mt-1">{String(entry['change_summary'])}</div>
                              )}
                              <Row className="mt-2">
                                <Col md={6} className="mb-2">
                                  <div className="small font-weight-bold text-muted mb-1">Before</div>
                                  <div className="border rounded bg-light p-2">
                                    <JsonInspector value={entry['prev_memory_json_decoded'] || tryParseJson(entry['prev_memory_json'] || {})} className="small" emptyLabel="No previous memory." />
                                  </div>
                                </Col>
                                <Col md={6} className="mb-2">
                                  <div className="small font-weight-bold text-muted mb-1">After</div>
                                  <div className="border rounded bg-light p-2">
                                    <JsonInspector value={entry['memory_json_decoded'] || tryParseJson(entry['memory_json'] || {})} className="small" emptyLabel="No new memory." />
                                  </div>
                                </Col>
                              </Row>
                              <div className="small font-weight-bold text-muted mb-1">Diff</div>
                              <div className="border rounded bg-light p-2 mb-2">
                                {diffRows.length === 0 ? (
                                  <div className="small text-muted">No field-level changes detected.</div>
                                ) : (
                                  <div className="table-responsive">
                                    <table className="table table-sm mb-0 small">
                                      <thead>
                                        <tr>
                                          <th>Path</th>
                                          <th>Status</th>
                                          <th>Before</th>
                                          <th>After</th>
                                        </tr>
                                      </thead>
                                      <tbody>
                                        {diffRows.map((row) => (
                                          <tr key={row.path}>
                                            <td><code>{row.path}</code></td>
                                            <td>
                                              <Badge variant={row.status === 'added' ? 'success' : row.status === 'removed' ? 'danger' : 'warning'}>
                                                {row.status}
                                              </Badge>
                                            </td>
                                            <td><code>{row.before || '-'}</code></td>
                                            <td><code>{row.after || '-'}</code></td>
                                          </tr>
                                        ))}
                                      </tbody>
                                    </table>
                                  </div>
                                )}
                              </div>
                              <Row>
                                <Col md={6} className="mb-2">
                                  <div className="small font-weight-bold text-muted mb-1">Source Ref</div>
                                  <div className="border rounded bg-light p-2">
                                    <JsonInspector value={entry['source_ref_decoded'] || tryParseJson(entry['source_ref'] || {})} className="small" />
                                  </div>
                                </Col>
                                <Col md={6} className="mb-2">
                                  <div className="small font-weight-bold text-muted mb-1">Payload</div>
                                  <div className="border rounded bg-light p-2">
                                    <JsonInspector value={entry['payload_json_decoded'] || tryParseJson(entry['payload_json'] || {})} className="small" />
                                  </div>
                                </Col>
                              </Row>
                            </Card.Body>
                          </Card>
                        )})
                      )}
                    </div>
                  </Tab>
                  <Tab eventKey="rules" title="Rules">
                    <div className="p-3">
                      {overview.rules.length === 0 ? (
                        <div className="text-muted text-center py-3">No memory rules configured</div>
                      ) : (
                        overview.rules.map(rule => (
                          <Card key={rule.key} className="mb-2 border">
                            <Card.Body className="py-2 px-3 d-flex justify-content-between align-items-center">
                              <div>
                                <Badge variant={rule.enabled ? 'success' : 'secondary'} className="mr-2">
                                  {rule.enabled ? 'ON' : 'OFF'}
                                </Badge>
                                <strong className="small">{rule.label || rule.key}</strong>
                                <span className="text-muted ml-2 small">
                                  {rule.execution_mode} | {rule.source_type || 'any'}
                                </span>
                              </div>
                              <Button
                                size="sm"
                                variant="outline-primary"
                                onClick={() => handleRerunRule(rule.key)}
                                disabled={actionLoading || !rule.enabled}
                                title="Re-run this rule for selected user"
                              >
                                <i className="fas fa-play"></i>
                              </Button>
                            </Card.Body>
                          </Card>
                        ))
                      )}
                    </div>
                  </Tab>
                </Tabs>
              </Card.Body>
            </Card>
          )}
        </Col>
      </Row>

      {/* Edit Modal */}
      <Modal show={editModal} onHide={() => setEditModal(false)} size="lg">
        <Modal.Header closeButton>
          <Modal.Title>Edit Memory</Modal.Title>
        </Modal.Header>
        <Modal.Body>
          <Form.Group className="mb-3">
            <Form.Label>Memory Text</Form.Label>
            <Form.Control
              as="textarea"
              rows={4}
              value={editText}
              onChange={e => setEditText(e.target.value)}
            />
          </Form.Group>
          <Form.Group>
            <Form.Label>Memory JSON</Form.Label>
            <JsonMonacoEditor
              value={editJson}
              onChange={setEditJson}
              expectObject
              minHeight={260}
            />
          </Form.Group>
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={() => setEditModal(false)}>Cancel</Button>
          <Button variant="primary" onClick={handleSaveEdit} disabled={actionLoading}>
            {actionLoading ? <Spinner size="sm" animation="border" /> : 'Save'}
          </Button>
        </Modal.Footer>
      </Modal>
    </Container>
  );
};
