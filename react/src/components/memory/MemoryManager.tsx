import React, { useEffect, useMemo, useState } from 'react';
import { Alert, Badge, Button, Card, Col, Nav, Row, Spinner, Tab } from 'react-bootstrap';
import { MemoryAdminPanel } from './MemoryAdminPanel';
import { MemoryRulesEditorApp } from './MemoryRulesEditorApp';
import { memoryApi } from '../../utils/api';
import { MemoryConfigSection, type FieldDef } from '../settings/MemoryConfigSection';

export interface MemoryPageConfig {
  csrfToken?: string;
  promptLabEndpoint: string;
  pageUrl?: string;
  pageId?: number | null;
}

type MemoryTab = 'general' | 'rules' | 'sources' | 'users';

interface MemoryConfigGroup {
  label: string;
  fields: FieldDef[];
}

function readUrlState() {
  const url = new URL(window.location.href);
  return {
    tab: (url.searchParams.get('tab') as MemoryTab) || 'general',
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
  const [memoryConfig, setMemoryConfig] = useState<MemoryConfigGroup | null>(null);
  const [memoryConfigDirty, setMemoryConfigDirty] = useState<Record<string, string>>({});
  const [canUpdateConfig, setCanUpdateConfig] = useState(false);
  const [sources, setSources] = useState<Array<Record<string, unknown>>>([]);
  const [loadingOverview, setLoadingOverview] = useState(false);
  const [loadingConfig, setLoadingConfig] = useState(false);
  const [savingConfig, setSavingConfig] = useState(false);
  const [loadingSources, setLoadingSources] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

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
    setLoadingConfig(true);
    memoryApi.getConfig()
      .then((response) => {
        if (response.error) throw new Error(response.error);
        setMemoryConfig(response.settings);
        setCanUpdateConfig(!!response.acl?.update);
      })
      .catch((err) => setError(err instanceof Error ? err.message : 'Failed to load memory config'))
      .finally(() => setLoadingConfig(false));
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

  const getConfigField = (name: string): FieldDef | undefined =>
    memoryConfig?.fields.find((field) => field.name === name);

  const getConfigValue = (name: string): string => {
    if (memoryConfigDirty[name] !== undefined) {
      return memoryConfigDirty[name];
    }
    return getConfigField(name)?.value ?? '';
  };

  const handleConfigChange = (name: string, value: string) => {
    setMemoryConfigDirty((current) => ({ ...current, [name]: value }));
    setSuccess(null);
  };

  const handleConfigSave = async () => {
    if (Object.keys(memoryConfigDirty).length === 0) {
      return;
    }

    setSavingConfig(true);
    setError(null);
    setSuccess(null);
    try {
      const response = await memoryApi.saveConfig(memoryConfigDirty);
      if (response.error) {
        throw new Error(response.error);
      }

      const [overviewResponse, configResponse] = await Promise.all([
        memoryApi.getOverview(),
        memoryApi.getConfig(),
      ]);

      if (overviewResponse.error) {
        throw new Error(overviewResponse.error);
      }
      if (configResponse.error) {
        throw new Error(configResponse.error);
      }

      setOverview(overviewResponse as unknown as Record<string, unknown>);
      setMemoryConfig(configResponse.settings);
      setCanUpdateConfig(!!configResponse.acl?.update);
      setMemoryConfigDirty({});
      setSuccess(`Saved ${response.saved?.length || 0} memory setting(s).`);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save memory config');
    } finally {
      setSavingConfig(false);
    }
  };

  return (
    <div className="container-fluid py-3">
      {error && <Alert variant="danger" dismissible onClose={() => setError(null)}>{error}</Alert>}
      {success && <Alert variant="success" dismissible onClose={() => setSuccess(null)}>{success}</Alert>}
      <div className="d-flex justify-content-between align-items-center flex-wrap mb-3">
        <div>
          <h2 className="mb-1">LLM Memory</h2>
          <div className="text-muted">Dedicated global memory manager with sharable URL state.</div>
        </div>
      </div>

      <Tab.Container activeKey={activeTab} onSelect={(key) => setActiveTab((key as MemoryTab) || 'general')}>
        <Card>
          <Card.Header>
            <Nav variant="tabs" className="card-header-tabs">
              <Nav.Item><Nav.Link eventKey="general">General</Nav.Link></Nav.Item>
              <Nav.Item><Nav.Link eventKey="rules">Rules</Nav.Link></Nav.Item>
              <Nav.Item><Nav.Link eventKey="sources">Sources</Nav.Link></Nav.Item>
              <Nav.Item><Nav.Link eventKey="users">Users</Nav.Link></Nav.Item>
            </Nav>
          </Card.Header>
          <Card.Body>
            <Tab.Content>
              <Tab.Pane eventKey="general">
                {(loadingOverview || loadingConfig) ? <div className="text-center py-5"><Spinner animation="border" size="sm" /></div> : (
                  <Row>
                    <Col lg={7} className="mb-3">
                      {memoryConfig ? (
                        <>
                          <MemoryConfigSection
                            title="Memory System"
                            iconClass="fa fa-database"
                            getField={getConfigField}
                            getVal={getConfigValue}
                            onChange={handleConfigChange}
                            disabled={!canUpdateConfig || savingConfig}
                            hideDetailsWhenDisabled={false}
                          />
                          {canUpdateConfig && (
                            <div className="d-flex align-items-center mb-3">
                              <Button
                                size="sm"
                                variant="primary"
                                onClick={handleConfigSave}
                                disabled={savingConfig || Object.keys(memoryConfigDirty).length === 0}
                              >
                                {savingConfig ? 'Saving...' : 'Save Memory Settings'}
                              </Button>
                              {Object.keys(memoryConfigDirty).length > 0 && (
                                <span className="ml-3 text-muted small">
                                  {Object.keys(memoryConfigDirty).length} unsaved change(s)
                                </span>
                              )}
                            </div>
                          )}
                        </>
                      ) : null}
                    </Col>
                    <Col lg={5} className="mb-3">
                      <Card className="border mb-3">
                        <Card.Header>System Status</Card.Header>
                        <Card.Body>
                          <div className="mb-2 d-flex flex-wrap">
                            <Badge variant={overview?.enabled ? 'success' : 'secondary'} className="mr-2 mb-2">{overview?.enabled ? 'Enabled' : 'Disabled'}</Badge>
                            <Badge variant={(Number(overview?.rules_count || 0) > 0 && Number(overview?.sources_count || 0) > 0 && overview?.enabled) ? 'success' : 'warning'} className="mr-2 mb-2">
                              {(Number(overview?.rules_count || 0) > 0 && Number(overview?.sources_count || 0) > 0 && overview?.enabled) ? 'Configured' : 'Needs setup'}
                            </Badge>
                            {Number(overview?.rules_count || 0) === 0 ? <Badge variant="warning" className="mr-2 mb-2">No rules yet</Badge> : null}
                            {Number(overview?.sources_count || 0) === 0 ? <Badge variant="warning" className="mr-2 mb-2">No sources connected</Badge> : null}
                          </div>
                          <div className="small mb-2"><strong>Storage mode:</strong> {String(overview?.storage_mode || '-')}</div>
                          <div className="small mb-2"><strong>Rules:</strong> {String(overview?.enabled_rules || 0)}/{String(overview?.rules_count || 0)} enabled</div>
                          <div className="small mb-2"><strong>Users with memory:</strong> {String(overview?.unique_users || 0)}</div>
                          <div className="small mb-2"><strong>Write sources:</strong> {String(overview?.sources_count || 0)}</div>
                          <div className="small mb-0"><strong>Latest activity:</strong> {String(overview?.latest_activity_at || 'No activity yet')}</div>
                        </Card.Body>
                      </Card>
                      <Card className="border">
                        <Card.Header>How It Works</Card.Header>
                        <Card.Body>
                          <div className="small mb-2"><strong>Rules</strong> decide when memory should update and how values are derived.</div>
                          <div className="small mb-2"><strong>Sources</strong> show where those updates come from, such as forms, chat fallback, or system hooks.</div>
                          <div className="small mb-0"><strong>Users</strong> lets you inspect current memory, review history, and fix issues manually.</div>
                        </Card.Body>
                      </Card>
                    </Col>
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
                                    <th>Rule</th>
                                    <th>Status</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  {(overview.recent_activity as Array<Record<string, unknown>>).map((item, index) => (
                                    <tr key={`${String(item.record_id || index)}`}>
                                      <td>{String(item.event_at || item.created_at || '')}</td>
                                      <td>{String(item.user_name || `User #${String(item.id_users || '')}`)}</td>
                                      <td>{String(item.rule_key || '-')}</td>
                                      <td>{String(item.update_status || '-')}</td>
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
                    {sources.length === 0 ? <Alert variant="info">No configured write sources found yet. This tab only shows enabled sources that currently point to memory rules.</Alert> : sources.map((source, index) => (
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
