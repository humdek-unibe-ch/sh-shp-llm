import React, { useEffect, useState } from 'react';
import { Container, Row, Col, Card, Form, Button, Badge, Alert, Spinner, Pagination, Modal } from 'react-bootstrap';
import { adminApi } from '../../utils/api';
import type { AdminConfig, AdminConversation, Message } from '../../types';

interface AdminFilters {
  userId: string;
  sectionId: string;
  query: string;
}

interface FilterOption {
  id: number;
  name: string;
  email?: string;
  code?: string;
}

export const AdminConsole: React.FC<{ config: AdminConfig }> = ({ config }) => {
  const [filters, setFilters] = useState<AdminFilters>({ userId: '', sectionId: '', query: '' });
  const [filterOptions, setFilterOptions] = useState<{
    users: FilterOption[];
    sections: { id: number; name: string }[];
  }>({ users: [], sections: [] });
  const [conversations, setConversations] = useState<AdminConversation[]>([]);
  const [selectedConversation, setSelectedConversation] = useState<AdminConversation | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalConversations, setTotalConversations] = useState(0);
  const [showFilters, setShowFilters] = useState(true);

  useEffect(() => {
    loadFilterOptions();
  }, []);

  useEffect(() => {
    loadConversations(1);
  }, [filters]);

  const loadFilterOptions = async () => {
    try {
      const response = await adminApi.getFilters();
      setFilterOptions(response.filters);
    } catch (err) {
      setError((err as Error).message);
    }
  };

  const loadConversations = async (page: number = currentPage) => {
    setLoading(true);
    setError(null);

    try {
      const response = await adminApi.getConversations({
        page,
        per_page: config.pageSize,
        user_id: filters.userId || undefined,
        section_id: filters.sectionId || undefined,
        q: filters.query || undefined
      });

      setConversations(response.items || []);
      setCurrentPage(response.page || page);
      setTotalPages(Math.ceil((response.total || 0) / config.pageSize));
      setTotalConversations(response.total || 0);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setLoading(false);
    }
  };

  const selectConversation = async (conversation: AdminConversation) => {
    setSelectedConversation(conversation);
    setLoading(true);

    try {
      const response = await adminApi.getMessages(conversation.id.toString());
      setMessages(response.messages || []);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setLoading(false);
    }
  };

  const handleFilterChange = (filterType: keyof AdminFilters, value: string) => {
    setFilters(prev => ({ ...prev, [filterType]: value }));
    setSelectedConversation(null);
    setMessages([]);
    setCurrentPage(1);
  };

  const clearFilters = () => {
    setFilters({ userId: '', sectionId: '', query: '' });
    setSelectedConversation(null);
    setMessages([]);
    setCurrentPage(1);
  };

  const getUserDisplayName = (user: FilterOption) => {
    if (user.name && user.email) {
      return `${user.name} (${user.email})`;
    }
    return user.name || user.email || `User ${user.id}`;
  };

  return (
    <Container fluid className="py-4">
      <Row className="mb-4">
        <Col>
          <div className="d-flex justify-content-between align-items-center">
            <div>
              <h2 className="mb-0 text-primary">{config.labels.heading}</h2>
              <p className="text-muted mt-1 mb-0">
                {totalConversations.toLocaleString()} total conversations
                {(filters.userId || filters.sectionId || filters.query) && (
                  <span className="ms-2">
                    • {conversations.length} matching current filters
                  </span>
                )}
              </p>
            </div>
            <div className="d-flex gap-2">
              <Button
                variant="outline-secondary"
                size="sm"
                onClick={() => setShowFilters(!showFilters)}
              >
                <i className={`fas fa-filter me-2`}></i>
                {showFilters ? 'Hide' : 'Show'} Filters
              </Button>
              <Button
                variant="outline-primary"
                size="sm"
                onClick={() => loadConversations(currentPage)}
                disabled={loading}
              >
                <i className="fas fa-sync-alt me-2"></i>
                {config.labels.refreshLabel}
              </Button>
            </div>
          </div>
        </Col>
      </Row>

      {error && (
        <Row className="mb-4">
          <Col>
            <Alert variant="danger" dismissible onClose={() => setError(null)}>
              <i className="fas fa-exclamation-triangle me-2"></i>
              {error}
            </Alert>
          </Col>
        </Row>
      )}

      <Row>
        {/* Filters Sidebar */}
        {showFilters && (
          <Col lg={4} className="mb-4">
            <Card className="shadow-sm border-0">
              <Card.Header className="bg-light">
                <div className="d-flex justify-content-between align-items-center">
                  <h6 className="mb-0 fw-bold">{config.labels.filtersTitle}</h6>
                  {(filters.userId || filters.sectionId || filters.query) && (
                    <Button
                      variant="link"
                      size="sm"
                      onClick={clearFilters}
                      className="text-decoration-none p-0"
                    >
                      Clear all
                    </Button>
                  )}
                </div>
              </Card.Header>
              <Card.Body>
                <Form.Group className="mb-3">
                  <Form.Label className="fw-bold small text-muted mb-2">{config.labels.userFilterLabel}</Form.Label>
                  <select
                    value={filters.userId}
                    onChange={(e: React.ChangeEvent<HTMLSelectElement>) => handleFilterChange('userId', e.target.value)}
                    className="form-control form-control-sm"
                  >
                    <option value="">All users</option>
                    {filterOptions.users.map(user => (
                      <option key={user.id} value={user.id}>
                        {getUserDisplayName(user)}
                      </option>
                    ))}
                  </select>
                </Form.Group>

                <Form.Group className="mb-3">
                  <Form.Label className="fw-bold small text-muted mb-2">{config.labels.sectionFilterLabel}</Form.Label>
                  <select
                    value={filters.sectionId}
                    onChange={(e: React.ChangeEvent<HTMLSelectElement>) => handleFilterChange('sectionId', e.target.value)}
                    className="form-control form-control-sm"
                  >
                    <option value="">All sections</option>
                    {filterOptions.sections.map(section => (
                      <option key={section.id} value={section.id}>
                        {section.name}
                      </option>
                    ))}
                  </select>
                </Form.Group>

                <Form.Group className="mb-3">
                  <Form.Label className="fw-bold small text-muted mb-2">Search</Form.Label>
                  <Form.Control
                    type="text"
                    placeholder={config.labels.searchPlaceholder}
                    value={filters.query}
                    onChange={(e) => handleFilterChange('query', e.target.value)}
                    size="sm"
                  />
                  <Form.Text className="text-muted small">
                    Search by title, user name, email, or validation code
                  </Form.Text>
                </Form.Group>
              </Card.Body>
            </Card>
          </Col>
        )}

        {/* Conversations List */}
        <Col lg={showFilters ? 4 : 6} className="mb-4">
          <Card className="shadow-sm border-0 h-100">
            <Card.Header className="bg-light">
              <h6 className="mb-0 fw-bold">Conversations</h6>
            </Card.Header>
            <div className="conversations-list" style={{ maxHeight: '600px', overflowY: 'auto' }}>
              {loading && conversations.length === 0 ? (
                <div className="text-center py-5">
                  <Spinner animation="border" size="sm" className="mb-3" />
                  <div className="text-muted small">{config.labels.loadingLabel}</div>
                </div>
              ) : conversations.length === 0 ? (
                <div className="text-center py-5 text-muted">
                  <i className="fas fa-comments fa-3x mb-3 opacity-50"></i>
                  <h6>{config.labels.conversationsEmpty}</h6>
                  {(filters.userId || filters.sectionId || filters.query) && (
                    <p className="mb-0 small">Try adjusting your filters</p>
                  )}
                </div>
              ) : (
                conversations.map(conversation => (
                  <div
                    key={conversation.id}
                    className={`conversation-item p-3 border-bottom cursor-pointer transition-all ${
                      selectedConversation?.id === conversation.id
                        ? 'bg-primary bg-opacity-10 border-primary'
                        : 'hover-bg-light'
                    }`}
                    onClick={() => selectConversation(conversation)}
                    style={{ cursor: 'pointer' }}
                  >
                    <div className="d-flex justify-content-between align-items-start mb-2">
                      <div className="flex-grow-1 me-2">
                        <div className="fw-bold text-truncate mb-1">
                          {conversation.title || 'Untitled Conversation'}
                        </div>
                        <div className="small text-muted mb-1">
                          {conversation.user_name || 'Unknown user'}
                          {conversation.user_email && (
                            <span className="ms-1">({conversation.user_email})</span>
                          )}
                        </div>
                        {conversation.user_validation_code && (
                          <div className="small text-muted mb-1">
                            <i className="fas fa-key me-1"></i>
                            {conversation.user_validation_code}
                          </div>
                        )}
                        <div className="small text-muted">
                          {conversation.section_name && `${conversation.section_name} • `}
                          {conversation.model} • {conversation.message_count || 0} messages
                        </div>
                      </div>
                      <Badge variant="secondary" className="flex-shrink-0 ms-2">
                        {new Date(conversation.updated_at).toLocaleDateString()}
                      </Badge>
                    </div>
                  </div>
                ))
              )}
            </div>

            {/* Pagination */}
            {totalPages > 1 && (
              <Card.Footer className="bg-white border-top">
                <div className="d-flex justify-content-between align-items-center">
                  <small className="text-muted">
                    Page {currentPage} of {totalPages}
                  </small>
                  <Pagination className="mb-0" size="sm">
                    <Pagination.Prev
                      disabled={currentPage <= 1 || loading}
                      onClick={() => loadConversations(currentPage - 1)}
                    />
                    <Pagination.Item active>{currentPage}</Pagination.Item>
                    <Pagination.Next
                      disabled={currentPage >= totalPages || loading}
                      onClick={() => loadConversations(currentPage + 1)}
                    />
                  </Pagination>
                </div>
              </Card.Footer>
            )}
          </Card>
        </Col>

        {/* Messages Panel */}
        <Col lg={showFilters ? 4 : 6}>
          <Card className="shadow-sm border-0 h-100">
            <Card.Body className="d-flex flex-column">
              {loading && selectedConversation ? (
                <div className="text-center py-5 flex-grow-1">
                  <Spinner animation="border" className="mb-3" />
                  <div className="text-muted">{config.labels.loadingLabel}</div>
                </div>
              ) : !selectedConversation ? (
                <div className="text-center py-5 text-muted flex-grow-1">
                  <i className="fas fa-comments fa-3x mb-3 opacity-50"></i>
                  <h6>{config.labels.messagesEmpty}</h6>
                  <p className="mb-0 small">Select a conversation to view its messages</p>
                </div>
              ) : (
                <>
                  <div className="conversation-header mb-4 pb-3 border-bottom">
                    <div className="d-flex justify-content-between align-items-start mb-2">
                      <div className="flex-grow-1">
                        <h5 className="mb-1 text-primary">{selectedConversation.title || 'Untitled Conversation'}</h5>
                        <div className="text-muted small mb-1">
                          <strong>User:</strong> {selectedConversation.user_name || 'Unknown'}
                          {selectedConversation.user_email && ` (${selectedConversation.user_email})`}
                        </div>
                        {selectedConversation.user_validation_code && (
                          <div className="text-muted small mb-1">
                            <i className="fas fa-key me-1"></i>
                            <strong>Validation:</strong> {selectedConversation.user_validation_code}
                          </div>
                        )}
                        <div className="text-muted small">
                          {selectedConversation.section_name && (
                            <><strong>Section:</strong> {selectedConversation.section_name} • </>
                          )}
                          <strong>Model:</strong> {selectedConversation.model} •
                          <strong>Created:</strong> {new Date(selectedConversation.created_at).toLocaleString()} •
                          <strong>Updated:</strong> {new Date(selectedConversation.updated_at).toLocaleString()}
                        </div>
                      </div>
                      <Badge variant="info" className="flex-shrink-0">
                        {selectedConversation.message_count || 0} messages
                      </Badge>
                    </div>
                  </div>

                  <div className="messages-container flex-grow-1" style={{ maxHeight: '500px', overflowY: 'auto' }}>
                    {messages.length === 0 ? (
                      <div className="text-center py-4 text-muted">
                        <i className="fas fa-inbox fa-2x mb-2 opacity-50"></i>
                        <div>No messages in this conversation</div>
                      </div>
                    ) : (
                      messages.map(message => (
                        <div
                          key={message.id}
                          className={`message-item mb-3 p-3 rounded shadow-sm ${
                            message.role === 'user'
                              ? 'bg-primary text-white ms-auto border'
                              : 'bg-light border'
                          }`}
                          style={{
                            maxWidth: '85%',
                            marginLeft: message.role === 'user' ? 'auto' : '0',
                            borderRadius: message.role === 'user' ? '18px 18px 4px 18px' : '18px 18px 18px 4px'
                          }}
                        >
                          <div className="message-role small fw-bold mb-1 text-uppercase opacity-75">
                            {message.role === 'user' ? (
                              <><i className="fas fa-user me-1"></i>User</>
                            ) : (
                              <><i className="fas fa-robot me-1"></i>Assistant</>
                            )}
                          </div>
                          <div className="message-content">
                            {message.formatted_content ? (
                              <div dangerouslySetInnerHTML={{ __html: message.formatted_content }} />
                            ) : (
                              <div style={{ whiteSpace: 'pre-wrap', wordWrap: 'break-word' }}>
                                {message.content}
                              </div>
                            )}
                          </div>
                          {message.attachments && (
                            <div className="message-attachments mt-2 small opacity-75">
                              <i className="fas fa-paperclip me-1"></i>
                              {JSON.parse(message.attachments).length} attachment{JSON.parse(message.attachments).length !== 1 ? 's' : ''}
                            </div>
                          )}
                          <div className="message-timestamp small mt-2 opacity-75 text-end">
                            {new Date(message.timestamp).toLocaleString()}
                            {message.tokens_used && (
                              <> • {message.tokens_used.toLocaleString()} tokens</>
                            )}
                          </div>
                        </div>
                      ))
                    )}
                  </div>
                </>
              )}
            </Card.Body>
          </Card>
        </Col>
      </Row>
    </Container>
  );
};
