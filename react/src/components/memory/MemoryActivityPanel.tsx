/**
 * Memory Activity — filterable, paginated feed of memory updates.
 *
 * Every row answers: which participant (name, code, email), which rule and memory key,
 * what triggered the update (form / survey, login, admin action, ...), who caused it
 * and what the result was. Filters are applied on the server.
 *
 * @module components/memory/MemoryActivityPanel
 */
import React, { useEffect, useRef, useState } from 'react';
import { Alert, Badge, Button, Card, Col, Form, Spinner } from 'react-bootstrap';
import { memoryApi } from '../../utils/api';

type ActivityData = Awaited<ReturnType<typeof memoryApi.getMemoryActivity>>;
type ActivityItem = ActivityData['items'][number];

interface ActivityFilters {
  q: string;
  rule_id: string;
  memory_key: string;
  status: string;
  source_type: string;
  date_from: string;
  date_to: string;
}

const EMPTY_FILTERS: ActivityFilters = {
  q: '',
  rule_id: '',
  memory_key: '',
  status: '',
  source_type: '',
  date_from: '',
  date_to: '',
};

const PAGE_SIZES = [25, 50, 100];
const SEARCH_DEBOUNCE_MS = 350;

const STATUS_VARIANTS: Record<string, string> = {
  applied: 'success',
  ignored_duplicate: 'secondary',
  ignored_stale: 'warning',
};

interface MemoryActivityPanelProps {
  /** Called when the admin clicks a rule name, to open it in the Rules tab. */
  onOpenRule?: (ruleId: number) => void;
}

/** Activity tab of the memory manager. */
export const MemoryActivityPanel: React.FC<MemoryActivityPanelProps> = ({ onOpenRule }) => {
  const [filters, setFilters] = useState<ActivityFilters>(EMPTY_FILTERS);
  /** Debounced copy of filters.q, so typing does not send a request per keystroke. */
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [data, setData] = useState<ActivityData | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [reloadToken, setReloadToken] = useState(0);
  const latestRequest = useRef(0);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setQuery(filters.q.trim());
      setPage(1);
    }, SEARCH_DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
  }, [filters.q]);

  useEffect(() => {
    const request = ++latestRequest.current;
    setLoading(true);
    memoryApi.getMemoryActivity({
      q: query,
      rule_id: filters.rule_id,
      memory_key: filters.memory_key,
      status: filters.status,
      source_type: filters.source_type,
      date_from: filters.date_from,
      date_to: filters.date_to,
      page,
      per_page: perPage,
    })
      .then((response) => {
        if (request !== latestRequest.current) return; // a newer filter change superseded this response
        if (response.error) throw new Error(response.error);
        setData(response);
        setError(null);
      })
      .catch((err) => {
        if (request !== latestRequest.current) return;
        setError(err instanceof Error ? err.message : 'Failed to load activity');
      })
      .finally(() => {
        if (request === latestRequest.current) setLoading(false);
      });
  }, [query, filters.rule_id, filters.memory_key, filters.status, filters.source_type, filters.date_from, filters.date_to, page, perPage, reloadToken]);

  const setFilter = (name: keyof ActivityFilters, value: string) => {
    setFilters((current) => ({ ...current, [name]: value }));
    if (name !== 'q') setPage(1);
  };

  const resetFilters = () => {
    setFilters(EMPTY_FILTERS);
    setQuery('');
    setPage(1);
  };

  const items: ActivityItem[] = data?.items || [];
  const options = data?.filter_options;
  const total = data?.total || 0;
  const totalPages = Math.max(1, Math.ceil(total / perPage));
  const firstShown = total === 0 ? 0 : (page - 1) * perPage + 1;
  const lastShown = Math.min(total, page * perPage);
  const hasFilters = Object.values(filters).some((value) => value !== '');
  const statusLabels = new Map<string, string>((options?.statuses || []).map((status): [string, string] => [status.value, status.label]));

  const renderParticipant = (item: ActivityItem) => (
    <>
      <div className="font-weight-bold">{item.user_name || item.user_code || `User #${item.id_users}`}</div>
      {item.user_email ? <div className="small text-muted">{item.user_email}</div> : null}
      <div className="small text-muted">
        {item.user_code ? <span className="mr-2">Code: <code>{item.user_code}</code></span> : null}
        <span>ID {item.id_users}</span>
      </div>
    </>
  );

  const renderRule = (item: ActivityItem) => (
    <>
      {item.rule_id > 0 ? (
        <button
          type="button"
          className="btn btn-link btn-sm p-0 text-left"
          onClick={() => onOpenRule?.(item.rule_id)}
          title="Open this rule"
        >
          {item.rule_label || 'Deleted rule'}
        </button>
      ) : (
        <span className="text-muted">{item.rule_label}</span>
      )}
      {item.rule_id > 0 ? <div className="small text-muted">Rule #{item.rule_id}</div> : null}
      <Badge variant="light" className="border mt-1" title={item.memory_key_label}>
        <code>{item.memory_key}</code>
      </Badge>
    </>
  );

  const renderTrigger = (item: ActivityItem) => (
    <>
      <Badge variant="light" className="border">{item.source_label}</Badge>
      {item.source_detail ? <div className="small text-muted">{item.source_detail}</div> : null}
      <div className="small text-muted">
        {item.triggered_by.type === 'admin'
          ? `by admin${item.triggered_by.name ? `: ${item.triggered_by.name}` : ''}${item.triggered_by.email ? ` (${item.triggered_by.email})` : ''}`
          : 'by participant'}
      </div>
    </>
  );

  return (
    <Card className="border">
      <Card.Header className="d-flex justify-content-between align-items-center flex-wrap">
        <span>Memory Activity</span>
        <span className="d-flex align-items-center">
          {loading && data ? <Spinner animation="border" size="sm" className="mr-2" /> : null}
          <Button size="sm" variant="outline-secondary" onClick={() => setReloadToken((token) => token + 1)} disabled={loading}>
            <i className="fas fa-sync-alt mr-1"></i>Refresh
          </Button>
        </span>
      </Card.Header>
      <Card.Body>
        {error ? <Alert variant="danger" dismissible onClose={() => setError(null)}>{error}</Alert> : null}

        <Form.Row className="memory-activity-filters">
          <Form.Group as={Col} lg={4} md={12} className="mb-2">
            <Form.Label className="small text-muted mb-0">Participant</Form.Label>
            <Form.Control
              size="sm"
              type="text"
              placeholder="Name, email, code or user id"
              value={filters.q}
              onChange={(event) => setFilter('q', event.target.value)}
            />
          </Form.Group>
          <Form.Group as={Col} lg={4} md={6} className="mb-2">
            <Form.Label className="small text-muted mb-0">Rule</Form.Label>
            <Form.Control as="select" size="sm" value={filters.rule_id} onChange={(event) => setFilter('rule_id', event.target.value)}>
              <option value="">All rules</option>
              {(options?.rules || []).map((rule) => (
                <option key={rule.id} value={String(rule.id)}>{rule.label}</option>
              ))}
            </Form.Control>
          </Form.Group>
          <Form.Group as={Col} lg={4} md={6} className="mb-2">
            <Form.Label className="small text-muted mb-0">Memory key</Form.Label>
            <Form.Control as="select" size="sm" value={filters.memory_key} onChange={(event) => setFilter('memory_key', event.target.value)}>
              <option value="">All keys</option>
              {(options?.memory_keys || []).map((key) => (
                <option key={key.code} value={key.code}>{key.label} ({key.code})</option>
              ))}
            </Form.Control>
          </Form.Group>
          <Form.Group as={Col} lg={3} md={6} className="mb-2">
            <Form.Label className="small text-muted mb-0">Triggered by</Form.Label>
            <Form.Control as="select" size="sm" value={filters.source_type} onChange={(event) => setFilter('source_type', event.target.value)}>
              <option value="">All sources</option>
              {(options?.source_types || []).map((source) => (
                <option key={source.value} value={source.value}>{source.label}</option>
              ))}
            </Form.Control>
          </Form.Group>
          <Form.Group as={Col} lg={3} md={6} className="mb-2">
            <Form.Label className="small text-muted mb-0">Status</Form.Label>
            <Form.Control as="select" size="sm" value={filters.status} onChange={(event) => setFilter('status', event.target.value)}>
              <option value="">All statuses</option>
              {(options?.statuses || []).map((status) => (
                <option key={status.value} value={status.value}>{status.label}</option>
              ))}
            </Form.Control>
          </Form.Group>
          <Form.Group as={Col} lg={2} md={6} xs={6} className="mb-2">
            <Form.Label className="small text-muted mb-0">From</Form.Label>
            <Form.Control size="sm" type="date" value={filters.date_from} max={filters.date_to || undefined} onChange={(event) => setFilter('date_from', event.target.value)} />
          </Form.Group>
          <Form.Group as={Col} lg={2} md={6} xs={6} className="mb-2">
            <Form.Label className="small text-muted mb-0">To</Form.Label>
            <Form.Control size="sm" type="date" value={filters.date_to} min={filters.date_from || undefined} onChange={(event) => setFilter('date_to', event.target.value)} />
          </Form.Group>
          <Form.Group as={Col} lg={2} md={12} className="mb-2 d-flex align-items-end">
            <Button size="sm" variant="outline-secondary" block onClick={resetFilters} disabled={!hasFilters}>
              Reset filters
            </Button>
          </Form.Group>
        </Form.Row>

        {!data && loading ? (
          <div className="text-center py-5"><Spinner animation="border" size="sm" /></div>
        ) : items.length === 0 ? (
          <div className="text-muted small py-3">
            {hasFilters ? 'No activity matches the current filters.' : 'No activity yet.'}
          </div>
        ) : (
          <div className={`table-responsive${loading ? ' memory-activity-loading' : ''}`}>
            <table className="table table-sm table-hover memory-activity-table mb-0">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Participant</th>
                  <th>Rule / key</th>
                  <th>Triggered by</th>
                  <th>Result</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={item.record_id}>
                    <td className="text-nowrap">
                      <div>{item.event_at || '-'}</div>
                      {item.saved_at && item.saved_at !== item.event_at ? (
                        <div className="small text-muted" title="When the memory update was saved">saved {item.saved_at}</div>
                      ) : null}
                    </td>
                    <td>{renderParticipant(item)}</td>
                    <td>{renderRule(item)}</td>
                    <td>{renderTrigger(item)}</td>
                    <td>
                      <Badge variant={STATUS_VARIANTS[item.update_status] || 'secondary'}>
                        {statusLabels.get(item.update_status) || item.update_status || '-'}
                      </Badge>
                      {item.change_summary ? (
                        <div className="small text-muted memory-activity-summary" title={item.change_summary}>{item.change_summary}</div>
                      ) : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div className="d-flex justify-content-between align-items-center flex-wrap mt-3">
          <div className="small text-muted">
            {total === 0 ? '0 events' : `Showing ${firstShown}-${lastShown} of ${total} events`}
          </div>
          <div className="d-flex align-items-center">
            <Form.Control
              as="select"
              size="sm"
              className="mr-2 memory-activity-page-size"
              value={perPage}
              onChange={(event) => { setPerPage(Number(event.target.value)); setPage(1); }}
            >
              {PAGE_SIZES.map((size) => (
                <option key={size} value={size}>{size} / page</option>
              ))}
            </Form.Control>
            <Button size="sm" variant="outline-secondary" disabled={page <= 1 || loading} onClick={() => setPage(page - 1)}>Previous</Button>
            <span className="mx-2 small">Page {page} of {totalPages}</span>
            <Button size="sm" variant="outline-secondary" disabled={page >= totalPages || loading} onClick={() => setPage(page + 1)}>Next</Button>
          </div>
        </div>
      </Card.Body>
    </Card>
  );
};
