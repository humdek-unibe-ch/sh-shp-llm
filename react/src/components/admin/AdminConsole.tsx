import React, { useEffect, useState } from 'react';
import { adminApi } from '../../utils/api';
import type { AdminConfig, AdminConversation, Message } from '../../types';
import { FilterDropdown, type DropdownOption } from './shared/FilterDropdown';
import { ConversationList } from './shared/ConversationList';
import { MessageList } from './shared/MessageList';
import './LlmAdmin.css';

type FilterState = {
  userId: string;
  sectionId: string;
  query: string;
};

export const AdminConsole: React.FC<{ config: AdminConfig }> = ({ config }) => {
  const [filters, setFilters] = useState<FilterState>({ userId: '', sectionId: '', query: '' });
  const [options, setOptions] = useState<{ users: DropdownOption[]; sections: DropdownOption[] }>({
    users: [{ value: '', label: 'All users' }],
    sections: [{ value: '', label: 'All sections' }]
  });
  const [conversations, setConversations] = useState<AdminConversation[]>([]);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [conversationDetail, setConversationDetail] = useState<AdminConversation | null>(null);

  useEffect(() => {
    loadFilters();
  }, []);

  useEffect(() => {
    loadConversations(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters.userId, filters.sectionId, filters.query]);

  const loadFilters = async () => {
    try {
      const res = await adminApi.getFilters();
      const userOptions: DropdownOption[] = [
        { value: '', label: 'All users' },
        ...res.filters.users.map((u) => ({
          value: String(u.id),
          label: u.name || u.email || `User ${u.id}`,
          subtitle: u.email
        }))
      ];
      const sectionOptions: DropdownOption[] = [
        { value: '', label: 'All sections' },
        ...res.filters.sections.map((s) => ({
          value: String(s.id),
          label: s.name || `Section ${s.id}`
        }))
      ];
      setOptions({ users: userOptions, sections: sectionOptions });
    } catch (e) {
      setError((e as Error).message);
    }
  };

  const loadConversations = async (nextPage?: number) => {
    const targetPage = nextPage || page;
    setLoading(true);
    setError(null);
    try {
      const res = await adminApi.getConversations({
        page: targetPage,
        per_page: config.pageSize,
        user_id: filters.userId || undefined,
        section_id: filters.sectionId || undefined,
        q: filters.query || undefined
      });
      if (res.error) {
        throw new Error(res.error);
      }
      setConversations(res.items || []);
      setTotal(res.total || 0);
      setPage(res.page || targetPage);

      if (!selectedId && res.items && res.items.length > 0) {
        selectConversation(String(res.items[0].id));
      }
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setLoading(false);
    }
  };

  const selectConversation = async (id: string) => {
    setSelectedId(id);
    setLoading(true);
    setError(null);
    try {
      const res = await adminApi.getMessages(id);
      if (res.error) {
        throw new Error(res.error);
      }
      setConversationDetail(res.conversation || null);
      setMessages(res.messages || []);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setLoading(false);
    }
  };

  const totalPages = Math.max(1, Math.ceil(total / config.pageSize));

  return (
    <div className="container-fluid llm-admin-container">
      <div className="d-flex justify-content-between align-items-center mb-3">
        <div>
          <h5 className="mb-0">{config.labels.heading}</h5>
          <div className="text-muted small">{total} conversations</div>
        </div>
        <div>
          <button className="btn btn-outline-secondary btn-sm mr-2" onClick={() => loadConversations(page)}>
            <span className="mr-1">&#8635;</span>
            {config.labels.refreshLabel}
          </button>
        </div>
      </div>

      {error && (
        <div className="alert alert-danger" role="alert">
          {error}
        </div>
      )}

      <div className="row">
        <div className="col-lg-4 mb-4">
          <div className="card shadow-sm h-100">
            <div className="card-body">
              <h6 className="card-title text-uppercase text-muted small mb-3">{config.labels.filtersTitle}</h6>

              <FilterDropdown
                label={config.labels.userFilterLabel}
                options={options.users}
                value={filters.userId}
                onChange={(val) => {
                  setFilters((prev) => ({ ...prev, userId: val }));
                  setSelectedId(null);
                  setMessages([]);
                }}
              />

              <FilterDropdown
                label={config.labels.sectionFilterLabel}
                options={options.sections}
                value={filters.sectionId}
                onChange={(val) => {
                  setFilters((prev) => ({ ...prev, sectionId: val }));
                  setSelectedId(null);
                  setMessages([]);
                }}
              />

              <div className="form-group">
                <label className="font-weight-bold small text-muted mb-1">Search</label>
                <input
                  type="text"
                  className="form-control"
                  placeholder={config.labels.searchPlaceholder}
                  value={filters.query}
                  onChange={(e) => setFilters((prev) => ({ ...prev, query: e.target.value }))}
                />
              </div>
            </div>

            <div className="list-group list-group-flush conversation-list">
              <ConversationList
                conversations={conversations}
                selectedId={selectedId}
                emptyLabel={config.labels.conversationsEmpty}
                onSelect={selectConversation}
              />
            </div>

            <div className="card-footer d-flex justify-content-between align-items-center">
              <button
                type="button"
                className="btn btn-light btn-sm"
                disabled={page <= 1}
                onClick={() => loadConversations(page - 1)}
              >
                &laquo; Prev
              </button>
              <div className="small text-muted">
                Page {page} / {totalPages}
              </div>
              <button
                type="button"
                className="btn btn-light btn-sm"
                disabled={page >= totalPages}
                onClick={() => loadConversations(page + 1)}
              >
                Next &raquo;
              </button>
            </div>
          </div>
        </div>

        <div className="col-lg-8 mb-4">
          <div className="card shadow-sm h-100">
            <div className="card-body">
              {loading && (
                <div className="text-center text-muted mb-3 small">
                  {config.labels.loadingLabel}
                </div>
              )}
              {!selectedId && <div className="text-muted text-center py-5">{config.labels.messagesEmpty}</div>}
              {selectedId && conversationDetail && (
                <>
                  <div className="d-flex justify-content-between align-items-center mb-3">
                    <div>
                      <div className="h6 mb-1">{conversationDetail.title || 'Conversation'}</div>
                      <div className="small text-muted">
                        {conversationDetail.user_name || 'Unknown user'}
                        {conversationDetail.section_name ? ` · ${conversationDetail.section_name}` : ''}
                      </div>
                      <div className="small text-muted">
                        Model: {conversationDetail.model} · Updated{' '}
                        {new Date(conversationDetail.updated_at).toLocaleString()}
                      </div>
                    </div>
                  </div>

                  <div className="llm-admin-messages">
                    <MessageList messages={messages} emptyLabel={config.labels.messagesEmpty} />
                  </div>
                </>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};
