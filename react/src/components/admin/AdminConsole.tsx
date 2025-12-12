import React, { useEffect, useState } from 'react';
import { Container, Row, Col, Card, Form, Button, Badge, Alert, Spinner, Pagination } from 'react-bootstrap';
import Select from 'react-select';
import { adminApi } from '../../utils/api';
import { MarkdownRenderer } from '../shared/MarkdownRenderer';
import type { AdminConfig, AdminConversation, Message } from '../../types';

interface AdminFilters {
  userId: string;
  sectionId: string;
  query: string;
  dateFrom: string;
  dateTo: string;
}

interface FilterOption {
  id: number;
  name: string;
  email?: string;
  user_validation_code?: string | null;
}

// Helper function to get today's date in YYYY-MM-DD format
const getTodayDate = (): string => {
  const today = new Date();
  return today.toISOString().split('T')[0];
};

// Helper function to format date for display
const formatDate = (dateString: string): string => {
  const date = new Date(dateString);
  return date.toLocaleDateString(undefined, { 
    year: 'numeric', 
    month: 'short', 
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
};

// Helper function to format date badge
const formatDateBadge = (dateString: string): string => {
  const date = new Date(dateString);
  return date.toLocaleDateString(undefined, { 
    month: 'short', 
    day: 'numeric'
  });
};

export const AdminConsole: React.FC<{ config: AdminConfig }> = ({ config }) => {
  const [filters, setFilters] = useState<AdminFilters>({ 
    userId: '', 
    sectionId: '', 
    query: '',
    dateFrom: getTodayDate(),
    dateTo: getTodayDate()
  });
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
        q: filters.query || undefined,
        date_from: filters.dateFrom || undefined,
        date_to: filters.dateTo || undefined
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
    setFilters({ 
      userId: '', 
      sectionId: '', 
      query: '',
      dateFrom: getTodayDate(),
      dateTo: getTodayDate()
    });
    setSelectedConversation(null);
    setMessages([]);
    setCurrentPage(1);
  };

  const getUserDisplayName = (user: FilterOption) => {
    const nameParts = [];
    if (user.name) nameParts.push(user.name);
    if (user.email) nameParts.push(`(${user.email})`);
    if (user.user_validation_code) nameParts.push(`[ ${user.user_validation_code}]`);

    return nameParts.length > 0 ? nameParts.join(' ') : `User ${user.id}`;
  };

  const hasActiveFilters = filters.dateFrom || filters.dateTo || filters.userId || filters.sectionId || filters.query;

  // Prepare options for react-select
  const userOptions = [
    { value: '', label: 'All users' },
    ...filterOptions.users.map(user => ({
      value: user.id.toString(),
      label: getUserDisplayName(user)
    }))
  ];

  const sectionOptions = [
    { value: '', label: 'All sections' },
    ...filterOptions.sections.map(section => ({
      value: section.id.toString(),
      label: section.name
    }))
  ];

  return (
    <Container fluid className="llm-admin-console py-3">
      {/* Header Section */}
      <Row className="mb-3">
        <Col>
          <div className="d-flex justify-content-between align-items-center">
            <div className="d-flex align-items-center">
              <h4 className="text-dark mb-0 font-weight-bold">
                <i className="fas fa-comments mr-2 text-secondary"></i>
                {config.labels.heading}
              </h4>
              <Badge variant="secondary" className="ml-3">
                {totalConversations.toLocaleString()} conversations
              </Badge>
              {hasActiveFilters && (
                <Badge variant="info" className="ml-2">
                  {conversations.length} filtered
                </Badge>
              )}
            </div>
            <div className="d-flex button-group">
              <Button
                variant={showFilters ? 'secondary' : 'outline-secondary'}
                size="sm"
                onClick={() => setShowFilters(!showFilters)}
              >
                <i className={`fas fa-filter mr-2`}></i>
                {showFilters ? 'Hide Filters' : 'Show Filters'}
              </Button>
              <Button
                variant="primary"
                size="sm"
                onClick={() => loadConversations(currentPage)}
                disabled={loading}
              >
                <i className={`fas fa-sync-alt mr-2 ${loading ? 'fa-spin' : ''}`}></i>
                {config.labels.refreshLabel}
              </Button>
              <Button
                variant="outline-danger"
                size="sm"
                onClick={clearFilters}
                disabled={!hasActiveFilters}
                className="filter-clear-btn"
              >
                <i className="fas fa-times mr-1"></i>
                Clear
              </Button>
            </div>
          </div>
        </Col>
      </Row>

      {/* Error Alert */}
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

      {/* Filters Row - Collapsible */}
      {showFilters && (
        <Row className="mb-3">
          <Col>
            <Card className="border">
              <Card.Body className="py-3">
                <div className="filter-row">
                  {/* Date Range Filter */}
                  <div className="filter-col filter-date-range">
                    <Form.Label className="small text-muted mb-1">
                      <i className="fas fa-calendar-alt mr-1"></i>
                      Date Range
                    </Form.Label>
                    <div className="d-flex filter-date-range">
                      <Form.Control
                        type="date"
                        value={filters.dateFrom}
                        onChange={(e) => handleFilterChange('dateFrom', e.target.value)}
                        className="filter-input mr-1"
                      />
                      <Form.Control
                        type="date"
                        value={filters.dateTo}
                        onChange={(e) => handleFilterChange('dateTo', e.target.value)}
                        className="filter-input"
                      />
                    </div>
                  </div>

                  {/* User Filter */}
                  <div className="filter-col filter-user">
                    <Form.Label className="small text-muted mb-1">
                      <i className="fas fa-user mr-1"></i>
                      {config.labels.userFilterLabel}
                    </Form.Label>
                    <Select
                      value={userOptions.find(option => option.value === filters.userId)}
                      onChange={(selectedOption) => handleFilterChange('userId', selectedOption?.value || '')}
                      options={userOptions}
                      isSearchable={true}
                      isClearable={false}
                      placeholder="All users..."
                      className="react-select-container filter-select"
                      classNamePrefix="react-select"
                      styles={{
                        control: (provided) => ({
                          ...provided,
                          minHeight: '38px',
                          height: '38px',
                          fontSize: '0.875rem'
                        }),
                        valueContainer: (provided) => ({
                          ...provided,
                          height: '38px',
                          padding: '0 8px'
                        }),
                        input: (provided) => ({
                          ...provided,
                          margin: '0',
                          padding: '0'
                        }),
                        indicatorsContainer: (provided) => ({
                          ...provided,
                          height: '38px'
                        }),
                        option: (provided) => ({
                          ...provided,
                          fontSize: '0.875rem'
                        }),
                        singleValue: (provided) => ({
                          ...provided,
                          fontSize: '0.875rem'
                        })
                      }}
                    />
                  </div>

                  {/* Section Filter */}
                  <div className="filter-col filter-section">
                    <Form.Label className="small text-muted mb-1">
                      <i className="fas fa-folder mr-1"></i>
                      {config.labels.sectionFilterLabel}
                    </Form.Label>
                    <Select
                      value={sectionOptions.find(option => option.value === filters.sectionId)}
                      onChange={(selectedOption) => handleFilterChange('sectionId', selectedOption?.value || '')}
                      options={sectionOptions}
                      isSearchable={true}
                      isClearable={false}
                      placeholder="All sections..."
                      className="react-select-container filter-select"
                      classNamePrefix="react-select"
                      styles={{
                        control: (provided) => ({
                          ...provided,
                          minHeight: '38px',
                          height: '38px',
                          fontSize: '0.875rem'
                        }),
                        valueContainer: (provided) => ({
                          ...provided,
                          height: '38px',
                          padding: '0 8px'
                        }),
                        input: (provided) => ({
                          ...provided,
                          margin: '0',
                          padding: '0'
                        }),
                        indicatorsContainer: (provided) => ({
                          ...provided,
                          height: '38px'
                        }),
                        option: (provided) => ({
                          ...provided,
                          fontSize: '0.875rem'
                        }),
                        singleValue: (provided) => ({
                          ...provided,
                          fontSize: '0.875rem'
                          })
                      }}
                    />
                  </div>

                  {/* Search Filter */}
                  <div className="filter-col filter-search">
                    <Form.Label className="small text-muted mb-1">
                      <i className="fas fa-search mr-1"></i>
                      Search
                    </Form.Label>
                    <Form.Control
                      type="text"
                      placeholder={config.labels.searchPlaceholder}
                      value={filters.query}
                      onChange={(e) => handleFilterChange('query', e.target.value)}
                      className="filter-input"
                    />
                  </div>

                </div>
              </Card.Body>
            </Card>
          </Col>
        </Row>
      )}

      {/* Main Content: Conversations and Messages */}
      <Row>
        {/* Conversations List */}
        <Col lg={5} xl={4} className="mb-3 mb-lg-0">
          <Card className="border conversations-panel h-100">
            <Card.Header className="bg-secondary text-white py-2">
              <div className="d-flex justify-content-between align-items-center">
                <span className="font-weight-bold">
                  <i className="fas fa-list mr-2"></i>
                  Conversations
                </span>
                <Badge variant="light">
                  {conversations.length}
                </Badge>
              </div>
            </Card.Header>
            <div className="conversations-list">
              {loading && conversations.length === 0 ? (
                <div className="text-center py-5">
                  <Spinner animation="border" variant="secondary" className="mb-3" />
                  <div className="text-muted">{config.labels.loadingLabel}</div>
                </div>
              ) : conversations.length === 0 ? (
                <div className="text-center py-5 px-3">
                  <i className="fas fa-inbox fa-3x text-muted mb-3"></i>
                  <h6 className="text-muted">{config.labels.conversationsEmpty}</h6>
                  {hasActiveFilters && (
                    <p className="text-muted small mb-0">Try adjusting your filters</p>
                  )}
                </div>
              ) : (
                conversations.map(conversation => (
                  <div
                    key={conversation.id}
                    className={`conversation-item p-3 border-bottom ${
                      selectedConversation?.id === conversation.id
                        ? 'active'
                        : ''
                    }`}
                    onClick={() => selectConversation(conversation)}
                  >
                    <div className="d-flex justify-content-between align-items-start mb-1">
                      <h6 className="font-weight-bold mb-0 conversation-title">
                        {conversation.title || 'Untitled Conversation'}
                      </h6>
                      <Badge variant="secondary" className="ml-2 flex-shrink-0">
                        {formatDateBadge(conversation.updated_at)}
                      </Badge>
                    </div>
                    <div className="small text-muted mb-1">
                      <i className="fas fa-user mr-1"></i>
                      {conversation.user_name || 'Unknown user'}
                      {conversation.user_email && (
                        <span className="ml-1">({conversation.user_email})</span>
                      )}
                    </div>
                    <div className="small text-muted">
                      {conversation.section_name && (
                        <>
                          <i className="fas fa-folder mr-1"></i>
                          {conversation.section_name}
                          <span className="mx-1">•</span>
                        </>
                      )}
                      <i className="fas fa-brain mr-1"></i>
                      {conversation.model}
                      <span className="mx-1">•</span>
                      <i className="fas fa-comment-dots mr-1"></i>
                      {conversation.message_count || 0}
                    </div>
                  </div>
                ))
              )}
            </div>

            {/* Pagination */}
            {totalPages > 1 && (
              <Card.Footer className="bg-light py-2">
                <div className="d-flex justify-content-between align-items-center flex-wrap">
                  <small className="text-muted">
                    Page {currentPage} of {totalPages}
                  </small>
                  <Pagination size="sm" className="mb-0">
                    <Pagination.First
                      disabled={currentPage <= 1 || loading}
                      onClick={() => loadConversations(1)}
                    />
                    <Pagination.Prev
                      disabled={currentPage <= 1 || loading}
                      onClick={() => loadConversations(currentPage - 1)}
                    />
                    <Pagination.Item active>{currentPage}</Pagination.Item>
                    <Pagination.Next
                      disabled={currentPage >= totalPages || loading}
                      onClick={() => loadConversations(currentPage + 1)}
                    />
                    <Pagination.Last
                      disabled={currentPage >= totalPages || loading}
                      onClick={() => loadConversations(totalPages)}
                    />
                  </Pagination>
                </div>
              </Card.Footer>
            )}
          </Card>
        </Col>

        {/* Messages Panel */}
        <Col lg={7} xl={8}>
          <Card className="border messages-panel h-100">
            {loading && selectedConversation ? (
              <Card.Body className="text-center py-5">
                <Spinner animation="border" variant="secondary" size="sm" className="mb-3" />
                <div className="text-muted">{config.labels.loadingLabel}</div>
              </Card.Body>
            ) : !selectedConversation ? (
              <Card.Body className="text-center py-5 d-flex flex-column justify-content-center">
                <i className="fas fa-hand-pointer fa-3x text-muted mb-3"></i>
                <h5 className="text-muted mb-2">{config.labels.messagesEmpty}</h5>
                <p className="text-muted small mb-0">Select a conversation to view its messages</p>
              </Card.Body>
            ) : (
              <>
                {/* Conversation Header */}
                <Card.Header className="bg-light py-2">
                  <div className="d-flex justify-content-between align-items-start">
                    <div className="flex-grow-1">
                      <h5 className="text-dark mb-1 font-weight-bold">
                        {selectedConversation.title || 'Untitled Conversation'}
                      </h5>
                      <div className="small text-muted">
                        <i className="fas fa-user mr-1"></i>
                        {selectedConversation.user_name || 'Unknown'}
                        {selectedConversation.user_email && ` (${selectedConversation.user_email})`}
                        {selectedConversation.section_name && (
                          <>
                            <span className="mx-2">•</span>
                            <i className="fas fa-folder mr-1"></i>
                            {selectedConversation.section_name}
                          </>
                        )}
                        <span className="mx-2">•</span>
                        <i className="fas fa-brain mr-1"></i>
                        {selectedConversation.model}
                        <span className="mx-2">•</span>
                        <i className="fas fa-clock mr-1"></i>
                        {formatDate(selectedConversation.updated_at)}
                      </div>
                    </div>
                    <Badge variant="info" className="px-2 py-1">
                      <i className="fas fa-comment-dots mr-1"></i>
                      {selectedConversation.message_count || 0}
                    </Badge>
                  </div>
                </Card.Header>

                {/* Messages Container */}
                <Card.Body className="messages-container p-3 bg-light">
                  {messages.length === 0 ? (
                    <div className="text-center py-5">
                      <i className="fas fa-comment-slash fa-2x text-muted mb-3"></i>
                      <div className="text-muted">No messages in this conversation</div>
                    </div>
                  ) : (
                    messages.map(message => (
                      <div
                        key={message.id}
                        className={`message-bubble mb-3 p-3 ${
                          message.role === 'user'
                            ? 'user-message'
                            : 'assistant-message'
                        }`}
                      >
                        <div className="message-header small font-weight-bold mb-2 text-uppercase">
                          {message.role === 'user' ? (
                            <><i className="fas fa-user mr-2"></i>User</>
                          ) : (
                            <><i className="fas fa-robot mr-2"></i>Assistant</>
                          )}
                        </div>
                        <div className="message-content">
                          {message.role === 'user' ? (
                            <div style={{ whiteSpace: 'pre-wrap', wordWrap: 'break-word' }}>
                              {message.content}
                            </div>
                          ) : (
                            <MarkdownRenderer content={message.content} />
                          )}
                        </div>
                        {message.attachments && (
                          <div className="message-attachments mt-2 small text-muted">
                            <i className="fas fa-paperclip mr-2"></i>
                            {JSON.parse(message.attachments).length} attachment{JSON.parse(message.attachments).length !== 1 ? 's' : ''}
                          </div>
                        )}
                        <div className="message-timestamp small mt-2 text-right text-muted">
                          <i className="fas fa-clock mr-1"></i>
                          {formatDate(message.timestamp)}
                          {message.tokens_used && (
                            <>
                              <i className="fas fa-microchip ml-2 mr-1"></i>
                              {message.tokens_used.toLocaleString()} tokens
                            </>
                          )}
                        </div>
                      </div>
                    ))
                  )}
                </Card.Body>
              </>
            )}
          </Card>
        </Col>
      </Row>
    </Container>
  );
};
