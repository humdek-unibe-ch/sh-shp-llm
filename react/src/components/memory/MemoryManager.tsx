import React, { useEffect, useMemo, useState } from 'react';
import { Alert, Button, Card, Col, Nav, Row, Spinner, Tab } from 'react-bootstrap';
import { MemoryAdminPanel } from '../admin/MemoryAdminPanel';
import { MemoryRulesEditorApp } from './MemoryRulesEditorApp';
import { memoryApi } from '../../utils/api';

export interface MemoryPageConfig {
  csrfToken?: string;
  promptLabEndpoint: string;
  adminConsoleUrl?: string;
  scriptsUrl?: string;
  pageUrl?: string;
  pageId?: number | null;
}

type MemoryTab = 'overview' | 'rules' | 'sources' | 'users';

function readUrlState() {
  const url = new URL(window.location.href);
  return {
    tab: (url.searchParams.get('tab') as MemoryTab) || 'overview',
    ruleId: url.searchParams.get('rule') ? Number(url.searchParams.get('rule')) : null,
    userId: url.searchParams.get('user') ? Number(url.searchParams.get('user')) : null,
    memoryKey: url.searchParams.get('memory_key') || undefined,
    edit: url.searchParams.get('edit') === '1',
    history: url.searchParams.get('history') === '1',
  };
}

function writeUrlState(next: { tab: MemoryTab; ruleId?: number | null; userId?: number | null; memoryKey?: string; edit?: boolean; history?: boolean }) {
  const url = new URL(window.location.href);
  url.searchParams.set('tab', next.tab);
  if (next.ruleId) url.searchParams.set('rule', String(next.ruleId)); else url.searchParams.delete('rule');
  if (next.userId) url.searchParams.set('user', String(next.userId)); else url.searchParams.delete('user');
  if (next.memoryKey) url.searchParams.set('memory_key', next.memoryKey); else url.searchParams.delete('memory_key');
  if (next.edit) url.searchParams.set('edit', '1'); else url.searchParams.delete('edit');
  if (next.history) url.searchParams.set('history', '1'); else url.searchParams.delete('history');
  window.history.replaceState({}, '', url.toString());
}

export const MemoryManager: React.FC<{ config: MemoryPageConfig }> = ({ config }) => {
  const initialState = useMemo(() => readUrlState(), []);
  const [activeTab, setActiveTab] = useState<MemoryTab>(initialState.tab);
  const [selectedRuleId, setSelectedRuleId] = useState<number | null>(initialState.ruleId);
  const [selectedUserId, setSelectedUserId] = useState<number | null>(initialState.userId);
  const [selectedMemoryKey, setSelectedMemoryKey] = useState<string | undefined>(initialState.memoryKey);
  const [editRequested, setEditRequested] = useState<boolean>(initialState.edit);
  const [historyRequested, setHistoryRequested] = useState<boolean>(initialState.history);
  const [overview, setOverview] = useState<Record<string, unknown> | null>(null);
  const [sources, setSources] = useState<Array<Record<string, unknown>>>([]);
  const [loadingOverview, setLoadingOverview] = useState(false);
  const [loadingSources, setLoadingSources] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    writeUrlState({
      tab: activeTab,
      ruleId: selectedRuleId,
      userId: selectedUserId,
      memoryKey: selectedMemoryKey,
      edit: activeTab === 'users' ? editRequested : false,
      history: activeTab === 'users' ? historyRequested : false,
    });
  }, [activeTab, selectedRuleId, selectedUserId, selectedMemoryKey, editRequested, historyRequested]);

  useEffect(() => {
    setLoadingOverview(true);
    memoryApi.getOverview()
      .then((response) => {
        if (response.error) throw new Error(response.error);
        setOverview(response as unknown as Record<string, unknown>);
      })
      .catch((err) => setError(err instanceof Error ? err.message : 'Failed to load overview'))
      .finally(() => setLoadingOverview(false));
  }, []);

  useEffect(() => {
    if (activeTab !== 'sources') return;
    setLoadingSources(true);
    memoryApi.getSources()
      .then((response) => {
        if (response.error) throw new Error(response.error);
        setSources(response.sources || []);
      })
      .catch((err) => setError(err instanceof Error ? err.message : 'Failed to load sources'))
      .finally(() => setLoadingSources(false));
  }, [activeTab]);

  return (
    <div className="container-fluid py-3">
      {error && <Alert variant="danger" dismissible onClose={() => setError(null)}>{error}</Alert>}
      <div className="d-flex justify-content-between align-items-center flex-wrap mb-3">
        <div>
          <h2 className="mb-1">LLM Memory</h2>
          <div className="text-muted">Dedicated global memory manager with sharable URL state.</div>
        </div>
        <div className="mt-2 mt-md-0">
          {config.adminConsoleUrl && <Button variant="outline-secondary" className="mr-2" href={config.adminConsoleUrl}>Conversations</Button>}
          {config.scriptsUrl && <Button variant="outline-secondary" href={config.scriptsUrl}>Scripts</Button>}
        </div>
      </div>

      <Tab.Container activeKey={activeTab} onSelect={(key) => setActiveTab((key as MemoryTab) || 'overview')}>
        <Card>
          <Card.Header>
            <Nav variant="tabs" className="card-header-tabs">
              <Nav.Item><Nav.Link eventKey="overview">Overview</Nav.Link></Nav.Item>
              <Nav.Item><Nav.Link eventKey="rules">Rules</Nav.Link></Nav.Item>
              <Nav.Item><Nav.Link eventKey="sources">Sources</Nav.Link></Nav.Item>
              <Nav.Item><Nav.Link eventKey="users">Users</Nav.Link></Nav.Item>
            </Nav>
          </Card.Header>
          <Card.Body>
            <Tab.Content>
              <Tab.Pane eventKey="overview">
                {loadingOverview ? <div className="text-center py-5"><Spinner animation="border" size="sm" /></div> : (
                  <Row>
                    <Col lg={3} md={6} className="mb-3"><Card className="border h-100"><Card.Body><div className="text-muted small">Enabled</div><div className="h5 mb-0">{overview?.enabled ? 'Yes' : 'No'}</div></Card.Body></Card></Col>
                    <Col lg={3} md={6} className="mb-3"><Card className="border h-100"><Card.Body><div className="text-muted small">Storage Mode</div><div className="h5 mb-0">{String(overview?.storage_mode || '-')}</div></Card.Body></Card></Col>
                    <Col lg={3} md={6} className="mb-3"><Card className="border h-100"><Card.Body><div className="text-muted small">Rules</div><div className="h5 mb-0">{String(overview?.enabled_rules || 0)}/{String(overview?.rules_count || 0)}</div></Card.Body></Card></Col>
                    <Col lg={3} md={6} className="mb-3"><Card className="border h-100"><Card.Body><div className="text-muted small">Users With Memory</div><div className="h5 mb-0">{String(overview?.unique_users || 0)}</div></Card.Body></Card></Col>
                    <Col lg={6} className="mb-3"><Card className="border h-100"><Card.Header>Tables</Card.Header><Card.Body><div><strong>Current:</strong> {String(overview?.current_table || '-')}</div><div><strong>History:</strong> {String(overview?.history_table || '-')}</div><div><strong>Memory Keys:</strong> {Array.isArray(overview?.unique_keys) ? (overview?.unique_keys as string[]).join(', ') : '-'}</div></Card.Body></Card></Col>
                    <Col lg={6} className="mb-3"><Card className="border h-100"><Card.Header>Quick Links</Card.Header><Card.Body><Button variant="outline-primary" className="mr-2 mb-2" onClick={() => setActiveTab('rules')}>Manage Rules</Button><Button variant="outline-primary" className="mr-2 mb-2" onClick={() => setActiveTab('sources')}>View Sources</Button><Button variant="outline-primary" className="mb-2" onClick={() => setActiveTab('users')}>Browse User Memory</Button></Card.Body></Card></Col>
                    <Col lg={12} className="mb-3">
                      <Card className="border">
                        <Card.Header>Recent Activity</Card.Header>
                        <Card.Body>
                          {Array.isArray(overview?.recent_activity) && overview.recent_activity.length > 0 ? (
                            <div className="table-responsive">
                              <table className="table table-sm mb-0">
                                <thead>
                                  <tr>
                                    <th>When</th>
                                    <th>User</th>
                                    <th>Memory Key</th>
                                    <th>Rule</th>
                                    <th>Status</th>
                                    <th>Summary</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  {(overview.recent_activity as Array<Record<string, unknown>>).map((item, index) => (
                                    <tr key={`${String(item.record_id || index)}`}>
                                      <td>{String(item.event_at || item.created_at || '')}</td>
                                      <td>{String(item.user_name || `User #${String(item.id_users || '')}`)}</td>
                                      <td>{String(item.memory_key || '-')}</td>
                                      <td>{String(item.rule_key || '-')}</td>
                                      <td>{String(item.update_status || '-')}</td>
                                      <td>{String(item.change_summary || '-')}</td>
                                    </tr>
                                  ))}
                                </tbody>
                              </table>
                            </div>
                          ) : (
                            <div className="text-muted small">No recent activity yet.</div>
                          )}
                        </Card.Body>
                      </Card>
                    </Col>
                  </Row>
                )}
              </Tab.Pane>
              <Tab.Pane eventKey="rules">
                <MemoryRulesEditorApp config={{ promptLabEndpoint: config.promptLabEndpoint, csrfToken: config.csrfToken, pageId: config.pageId ?? null, selectedRuleId, onRuleSelected: (ruleId) => setSelectedRuleId(ruleId) }} />
              </Tab.Pane>
              <Tab.Pane eventKey="sources">
                {loadingSources ? <div className="text-center py-5"><Spinner animation="border" size="sm" /></div> : (
                  <div>
                    {sources.length === 0 ? <Alert variant="info">No write sources found.</Alert> : sources.map((source, index) => (
                      <Card key={`${String(source.source_category || '')}-${String(source.target_id || index)}`} className="mb-3 border">
                        <Card.Body>
                          <div className="d-flex justify-content-between align-items-start flex-wrap">
                            <div>
                              <div className="font-weight-bold">{String(source.target_label || 'Source')}</div>
                              <div className="text-muted small">{String(source.source_category || '')} | {String(source.source_type || '')} {source.trigger_type ? `| ${String(source.trigger_type)}` : ''}</div>
                              {source.target_secondary ? <div className="small text-muted">{String(source.target_secondary)}</div> : null}
                            </div>
                            <div>{Array.isArray(source.rule_keys) && (source.rule_keys as string[]).map((key) => <span key={key} className="badge badge-secondary ml-1">{key}</span>)}</div>
                          </div>
                          {source.target_url ? <div className="mt-2"><a href={String(source.target_url)}>Open source</a></div> : null}
                        </Card.Body>
                      </Card>
                    ))}
                  </div>
                )}
              </Tab.Pane>
              <Tab.Pane eventKey="users">
                <MemoryAdminPanel
                  initialUserId={selectedUserId ?? undefined}
                  initialMemoryKey={selectedMemoryKey}
                  initialActiveTab={historyRequested ? 'history' : 'current'}
                  initialEditOpen={editRequested}
                  onStateChange={({ userId, memoryKey, history, edit }) => {
                    setSelectedUserId(userId ?? null);
                    setSelectedMemoryKey(memoryKey);
                    setHistoryRequested(!!history);
                    setEditRequested(!!edit);
                  }}
                />
              </Tab.Pane>
            </Tab.Content>
          </Card.Body>
        </Card>
      </Tab.Container>
    </div>
  );
};
