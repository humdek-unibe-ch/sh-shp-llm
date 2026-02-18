import React, { useEffect, useState, useRef } from 'react';
import { Container, Row, Col, Card, Form, Button, Badge, Alert, Spinner, Pagination, Modal } from 'react-bootstrap';
import Select from 'react-select';
import { adminApi } from '../../utils/api';
import { ContextPopup } from './ContextPopup';
import { PayloadPopup } from './PayloadPopup';
import { AdminMessageList } from './AdminMessageList';
import { compactSelectStyles } from './selectStyles';
import type { AdminConfig, AdminConversation, Message } from '../../types';
import './AdminConsole.css';

interface AdminFilters {
  userId: string;
  sectionId: string;
  scriptId: string;
  query: string;
  dateFrom: string;
  dateTo: string;
}

// Confirmation modal state interface
interface ConfirmationModal {
  show: boolean;
  title: string;
  message: string;
  confirmText: string;
  confirmVariant: 'danger' | 'warning' | 'success' | 'primary';
  onConfirm: () => void;
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
    scriptId: '',
    query: '',
    dateFrom: getTodayDate(),
    dateTo: getTodayDate()
  });
  const [filterOptions, setFilterOptions] = useState<{
    users: FilterOption[];
    sections: { id: number; name: string }[];
    scripts: { id: number; name: string }[];
  }>({ users: [], sections: [], scripts: [] });
  const [conversations, setConversations] = useState<AdminConversation[]>([]);
  const [selectedConversation, setSelectedConversation] = useState<AdminConversation | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalConversations, setTotalConversations] = useState(0);
  const [showFilters, setShowFilters] = useState(true);
  const [contextPopup, setContextPopup] = useState<{
    show: boolean;
    message: Message | null;
    target: HTMLElement | null;
  }>({ show: false, message: null, target: null });

  // Payload popup state (for viewing API request payload)
  const [payloadPopup, setPayloadPopup] = useState<{
    show: boolean;
    message: Message | null;
  }>({ show: false, message: null });

  // Confirmation modal state
  const [confirmModal, setConfirmModal] = useState<ConfirmationModal>({
    show: false,
    title: '',
    message: '',
    confirmText: 'Confirm',
    confirmVariant: 'primary',
    onConfirm: () => {}
  });

  // Block reason input state
  const [blockReason, setBlockReason] = useState('');

  // Scroll position preservation
  const messagesContainerRef = useRef<HTMLDivElement>(null);
  const [preservedScrollTop, setPreservedScrollTop] = useState<number | null>(null);

  useEffect(() => {
    loadFilterOptions();
  }, []);

  useEffect(() => {
    loadConversations(1);
  }, [filters]);

  // Handle conversation ID from URL on initial load
  useEffect(() => {
    const url = new URL(window.location.href);
    const conversationId = url.searchParams.get('conversation');
    if (conversationId && conversations.length > 0) {
      // Find the conversation in the list
      const conversation = conversations.find(c => c.id.toString() === conversationId);
      if (conversation && (!selectedConversation || selectedConversation.id.toString() !== conversationId)) {
        selectConversation(conversation);
      }
    }
  }, [conversations]); // Re-run when conversations are loaded

  // Handle browser back/forward navigation
  useEffect(() => {
    const handlePopState = (event: PopStateEvent) => {
      const url = new URL(window.location.href);
      const conversationId = url.searchParams.get('conversation');
      
      if (conversationId) {
        const conversation = conversations.find(c => c.id.toString() === conversationId);
        if (conversation) {
          // Don't update URL again since we're responding to URL change
          setSelectedConversation(conversation);
          setLoading(true);
          adminApi.getMessages(conversationId)
            .then(response => {
              setMessages(response.messages || []);
            })
            .catch(err => {
              setError((err as Error).message);
            })
            .finally(() => {
              setLoading(false);
            });
        }
      } else {
        // No conversation in URL, clear selection
        setSelectedConversation(null);
        setMessages([]);
      }
    };

    window.addEventListener('popstate', handlePopState);
    return () => window.removeEventListener('popstate', handlePopState);
  }, [conversations]);

  // Restore scroll position after messages are loaded
  useEffect(() => {
    if (preservedScrollTop !== null && messagesContainerRef.current) {
      messagesContainerRef.current.scrollTop = preservedScrollTop;
      setPreservedScrollTop(null);
    }
  }, [messages, preservedScrollTop]);

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
        script_id: filters.scriptId || undefined,
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

  const selectConversation = async (conversation: AdminConversation, preserveScroll: boolean = false) => {
    setSelectedConversation(conversation);

    // Update URL with conversation ID (without full page reload)
    const url = new URL(window.location.href);
    url.searchParams.set('conversation', conversation.id.toString());
    window.history.pushState({ conversationId: conversation.id }, '', url.toString());

    // Save scroll position if preserving
    if (preserveScroll && messagesContainerRef.current) {
      setPreservedScrollTop(messagesContainerRef.current.scrollTop);
    }

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
      scriptId: '',
      query: '',
      dateFrom: getTodayDate(),
      dateTo: getTodayDate()
    });
    setSelectedConversation(null);
    setMessages([]);
    setCurrentPage(1);
  };

  // ========== Admin Action Handlers ==========

  // Helper to show confirmation modal
  const showConfirmation = (
    title: string,
    message: string,
    confirmText: string,
    confirmVariant: 'danger' | 'warning' | 'success' | 'primary',
    onConfirm: () => void
  ) => {
    setConfirmModal({
      show: true,
      title,
      message,
      confirmText,
      confirmVariant,
      onConfirm
    });
  };

  // Helper to hide confirmation modal
  const hideConfirmation = () => {
    setConfirmModal(prev => ({ ...prev, show: false }));
    setBlockReason('');
  };

  const handleDeleteConversation = (conversationId: string) => {
    showConfirmation(
      'Delete Conversation',
      'Are you sure you want to delete this conversation? The conversation will be hidden from the user but kept in the database for audit purposes.',
      'Delete',
      'danger',
      async () => {
        hideConfirmation();
        setLoading(true);
        try {
          const response = await adminApi.deleteConversation(conversationId);
          if (response.error) {
            throw new Error(response.error);
          }
          
          // Clear selection and refresh list
          setSelectedConversation(null);
          setMessages([]);
          await loadConversations(currentPage);
        } catch (err) {
          setError((err as Error).message);
        } finally {
          setLoading(false);
        }
      }
    );
  };

  const handleBlockConversation = (conversationId: string) => {
    // Show block modal with reason input
    setConfirmModal({
      show: true,
      title: 'Block Conversation',
      message: 'The user will not be able to continue this conversation. Optionally enter a reason for blocking:',
      confirmText: 'Block',
      confirmVariant: 'warning',
      onConfirm: async () => {
        hideConfirmation();
        setLoading(true);
        try {
          const response = await adminApi.blockConversation(conversationId, blockReason || undefined);
          if (response.error) {
            throw new Error(response.error);
          }
          
          // Update the selected conversation's blocked status
          if (selectedConversation && selectedConversation.id.toString() === conversationId) {
            setSelectedConversation({
              ...selectedConversation,
              blocked: true,
              blocked_reason: blockReason || 'Manually blocked by administrator'
            });
          }
          
          // Refresh conversation list
          await loadConversations(currentPage);
        } catch (err) {
          setError((err as Error).message);
        } finally {
          setLoading(false);
        }
      }
    });
  };

  const handleUnblockConversation = (conversationId: string) => {
    showConfirmation(
      'Unblock Conversation',
      'Are you sure you want to unblock this conversation? The user will be able to continue chatting.',
      'Unblock',
      'success',
      async () => {
        hideConfirmation();
        setLoading(true);
        try {
          const response = await adminApi.unblockConversation(conversationId);
          if (response.error) {
            throw new Error(response.error);
          }
          
          // Update the selected conversation's blocked status
          if (selectedConversation && selectedConversation.id.toString() === conversationId) {
            setSelectedConversation({
              ...selectedConversation,
              blocked: false,
              blocked_reason: undefined
            });
          }
          
          // Refresh conversation list
          await loadConversations(currentPage);
        } catch (err) {
          setError((err as Error).message);
        } finally {
          setLoading(false);
        }
      }
    );
  };

  // ========== End Admin Action Handlers ==========

  const getUserDisplayName = (user: FilterOption) => {
    const nameParts = [];
    if (user.name) nameParts.push(user.name);
    if (user.email) nameParts.push(`(${user.email})`);
    if (user.user_validation_code) nameParts.push(`[ ${user.user_validation_code}]`);

    return nameParts.length > 0 ? nameParts.join(' ') : `User ${user.id}`;
  };

  const hasActiveFilters = filters.dateFrom || filters.dateTo || filters.userId || filters.sectionId || filters.scriptId || filters.query;

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

  const scriptOptions = [
    { value: '', label: 'All scripts' },
    ...(filterOptions.scripts || []).map(script => ({
      value: script.id.toString(),
      label: script.name
    }))
  ];

  return (
    <Container fluid className="llm-admin-console py-3">
      {/* Header Section */}
      <Row className="mb-3">
        <Col>
          <div className="d-flex justify-content-between align-items-center flex-wrap admin-header">
            <div className="d-flex align-items-center flex-wrap admin-header-title">
              <h4 className="text-dark mb-0 font-weight-bold">
                <i className="fas fa-comments mr-2 text-secondary"></i>
                {config.labels.heading}
              </h4>
              <Badge variant="secondary" className="ml-2">
                {totalConversations.toLocaleString()} conversations
              </Badge>
              {hasActiveFilters && (
                <Badge variant="info" className="ml-2">
                  {conversations.length} filtered
                </Badge>
              )}
            </div>
            <div className="admin-header-buttons">
              <Button
                variant={showFilters ? 'secondary' : 'outline-secondary'}
                onClick={() => setShowFilters(!showFilters)}
              >
                <i className="fas fa-filter"></i>
                <span className="d-none d-sm-inline">{showFilters ? 'Hide' : 'Show'}</span>
                <span className="d-sm-none">{showFilters ? 'Hide' : 'Filters'}</span>
              </Button>
              <Button
                variant="primary"
                onClick={() => {
                  loadConversations(currentPage);
                  // Also refresh messages for selected conversation while preserving scroll position
                  if (selectedConversation) {
                    selectConversation(selectedConversation, true);
                  }
                }}
                disabled={loading}
              >
                <i className={`fas fa-sync-alt ${loading ? 'fa-spin' : ''}`}></i>
                <span className="d-none d-sm-inline">Refresh</span>
              </Button>
              <Button
                variant="outline-danger"
                onClick={clearFilters}
                disabled={!hasActiveFilters}
              >
                <i className="fas fa-times"></i>
                <span className="d-none d-sm-inline">Clear</span>
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
                <div className="filter-grid">
                  {/* Date Range Filter */}
                  <div className="filter-col filter-col-date">
                    <Form.Label className="small text-muted mb-1">
                      <i className="fas fa-calendar-alt mr-1"></i>
                      Date Range
                    </Form.Label>
                    <div className="date-range-inputs">
                      <Form.Control
                        type="date"
                        value={filters.dateFrom}
                        onChange={(e) => handleFilterChange('dateFrom', e.target.value)}
                        className="filter-input"
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
                  <div className="filter-col filter-col-half">
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
                      menuPortalTarget={document.body}
                      styles={compactSelectStyles}
                    />
                  </div>

                  {/* Section Filter */}
                  <div className="filter-col filter-col-half">
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
                      menuPortalTarget={document.body}
                      styles={compactSelectStyles}
                    />
                  </div>

                  {/* Script Filter */}
                  {scriptOptions.length > 1 && (
                    <div className="filter-col filter-col-half">
                      <Form.Label className="small text-muted mb-1">
                        <i className="fas fa-code mr-1"></i>
                        Script
                      </Form.Label>
                      <Select
                        value={scriptOptions.find(option => option.value === filters.scriptId)}
                        onChange={(selectedOption) => handleFilterChange('scriptId', selectedOption?.value || '')}
                        options={scriptOptions}
                        isSearchable={true}
                        isClearable={false}
                        placeholder="All scripts..."
                        className="react-select-container filter-select"
                        classNamePrefix="react-select"
                        menuPortalTarget={document.body}
                        styles={compactSelectStyles}
                      />
                    </div>
                  )}

                  {/* Search Filter */}
                  <div className="filter-col filter-col-half">
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
                      {conversation.script_name && (
                        <>
                          <i className="fas fa-code mr-1"></i>
                          {conversation.script_name}
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
                      <div className="d-flex align-items-center mb-1">
                        <h5 className="text-dark mb-0 font-weight-bold">
                          {selectedConversation.title || 'Untitled Conversation'}
                        </h5>
                        {selectedConversation.blocked ? (
                          <Badge variant="warning" className="ml-2">
                            <i className="fas fa-ban mr-1"></i>Blocked
                          </Badge>
                        ) : null}
                        {selectedConversation.deleted ? (
                          <Badge variant="danger" className="ml-2">
                            <i className="fas fa-trash-alt mr-1"></i>Deleted
                          </Badge>
                        ) : null}
                      </div>
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
                        {selectedConversation.script_name && (
                          <>
                            <span className="mx-2">•</span>
                            <i className="fas fa-code mr-1"></i>
                            {selectedConversation.script_name}
                          </>
                        )}
                        <span className="mx-2">•</span>
                        <i className="fas fa-brain mr-1"></i>
                        {selectedConversation.model}
                        <span className="mx-2">•</span>
                        <i className="fas fa-clock mr-1"></i>
                        {formatDate(selectedConversation.updated_at)}
                      </div>
                      {selectedConversation.blocked_reason && (
                        <div className="small text-danger mt-1">
                          <i className="fas fa-exclamation-triangle mr-1"></i>
                          Block reason: {selectedConversation.blocked_reason}
                        </div>
                      )}
                    </div>
                    <div className="d-flex align-items-center">
                      <Badge variant="info" className="px-2 py-1 mr-2">
                        <i className="fas fa-comment-dots mr-1"></i>
                        {selectedConversation.message_count || 0}
                      </Badge>
                      {/* Action Buttons Dropdown */}
                      <div className="dropdown">
                        <button 
                          className="btn btn-outline-secondary btn-sm dropdown-toggle" 
                          type="button" 
                          data-toggle="dropdown" 
                          aria-haspopup="true" 
                          aria-expanded="false"
                        >
                          <i className="fas fa-cog mr-1"></i>
                          Actions
                        </button>
                        <div className="dropdown-menu dropdown-menu-right">
                          {selectedConversation.blocked ? (
                            <button 
                              className="dropdown-item text-success"
                              onClick={() => handleUnblockConversation(selectedConversation.id.toString())}
                            >
                              <i className="fas fa-check-circle mr-2"></i>
                              Unblock Conversation
                            </button>
                          ) : (
                            <button 
                              className="dropdown-item text-warning"
                              onClick={() => handleBlockConversation(selectedConversation.id.toString())}
                            >
                              <i className="fas fa-ban mr-2"></i>
                              Block Conversation
                            </button>
                          )}
                          <div className="dropdown-divider"></div>
                          <button 
                            className="dropdown-item text-danger"
                            onClick={() => handleDeleteConversation(selectedConversation.id.toString())}
                          >
                            <i className="fas fa-trash-alt mr-2"></i>
                            Delete Conversation
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                </Card.Header>

                {/* Messages Container */}
                <Card.Body ref={messagesContainerRef} className="messages-container p-3" style={{ overflowY: 'auto', maxHeight: '600px' }}>
                  {messages.length === 0 ? (
                    <div className="text-center py-5">
                      <i className="fas fa-comment-slash fa-2x text-muted mb-3"></i>
                      <div className="text-muted">No messages in this conversation</div>
                    </div>
                  ) : (
                    <AdminMessageList messages={messages} formatDate={formatDate} setContextPopup={setContextPopup} setPayloadPopup={setPayloadPopup} />
                  )}
                </Card.Body>
              </>
            )}
          </Card>
        </Col>
      </Row>

      {/* Context Popup Modal */}
      {contextPopup.show && contextPopup.message && (
        <ContextPopup
          message={contextPopup.message}
          show={contextPopup.show}
          onHide={() => setContextPopup({ show: false, message: null, target: null })}
        />
      )}

      {/* Payload Popup Modal */}
      {payloadPopup.show && payloadPopup.message && (
        <PayloadPopup
          message={payloadPopup.message}
          show={payloadPopup.show}
          onHide={() => setPayloadPopup({ show: false, message: null })}
        />
      )}

      {/* Confirmation Modal */}
      <Modal show={confirmModal.show} onHide={hideConfirmation} centered>
        <Modal.Header closeButton>
          <Modal.Title>
            <i className={`fas ${confirmModal.confirmVariant === 'danger' ? 'fa-trash-alt' : confirmModal.confirmVariant === 'warning' ? 'fa-ban' : 'fa-check-circle'} mr-2 text-${confirmModal.confirmVariant}`}></i>
            {confirmModal.title}
          </Modal.Title>
        </Modal.Header>
        <Modal.Body>
          <p>{confirmModal.message}</p>
          {/* Show reason input for block action */}
          {confirmModal.title === 'Block Conversation' && (
            <Form.Group>
              <Form.Label className="small text-muted">Reason (optional)</Form.Label>
              <Form.Control
                type="text"
                placeholder="Enter reason for blocking..."
                value={blockReason}
                onChange={(e) => setBlockReason(e.target.value)}
              />
            </Form.Group>
          )}
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={hideConfirmation}>
            Cancel
          </Button>
          <Button variant={confirmModal.confirmVariant} onClick={confirmModal.onConfirm}>
            {confirmModal.confirmText}
          </Button>
        </Modal.Footer>
      </Modal>
    </Container>
  );
};
