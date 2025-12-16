import React, { useEffect, useState, useRef, useMemo } from 'react';
import { Container, Row, Col, Card, Form, Button, Badge, Alert, Spinner, Pagination } from 'react-bootstrap';
import Select from 'react-select';
import { adminApi } from '../../utils/api';
import { MarkdownRenderer } from '../shared/MarkdownRenderer';
import { FormDisplay } from '../styles/shared/FormDisplay';
import type { AdminConfig, AdminConversation, Message, FormDefinition } from '../../types';
import { parseFormDefinition, parseFormSubmissionMetadata } from '../../types';
import './AdminConsole.css';

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

// Context Popup Component - Using Bootstrap 4.6 classes
interface ContextPopupProps {
  message: Message;
  show: boolean;
  onHide: () => void;
}

const ContextPopup: React.FC<ContextPopupProps> = ({ message, show, onHide }) => {
  const [copied, setCopied] = useState(false);
  const [copyType, setCopyType] = useState<'raw' | 'formatted'>('formatted');
  const backdropRef = useRef<HTMLDivElement>(null);

  // Handle ESC key and click outside to close
  useEffect(() => {
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && show) {
        onHide();
      }
    };

    const handleClickOutside = (event: MouseEvent) => {
      if (backdropRef.current && event.target === backdropRef.current) {
        onHide();
      }
    };

    if (show) {
      document.addEventListener('keydown', handleKeyDown);
      document.addEventListener('mousedown', handleClickOutside);
      document.body.style.overflow = 'hidden';
      return () => {
        document.removeEventListener('keydown', handleKeyDown);
        document.removeEventListener('mousedown', handleClickOutside);
        document.body.style.overflow = '';
      };
    }
  }, [show, onHide]);

  // Safety check for null message
  if (!message || !message.sent_context || typeof message.sent_context !== 'string' || message.sent_context.trim() === '') {
    return null;
  }

  if (!show) return null;

  const handleCopy = async (type: 'raw' | 'formatted') => {
    if (message.sent_context) {
      try {
        let textToCopy = message.sent_context;
        
        if (type === 'formatted') {
          // Try to extract readable content from JSON, strip HTML tags for plain text
          try {
            const parsed = JSON.parse(message.sent_context);
            if (Array.isArray(parsed)) {
              textToCopy = parsed
                .filter((item) => item && typeof item === 'object' && item.content)
                .map((item) => {
                  // Strip HTML tags for plain text copy
                  const plainText = item.content.replace(/<[^>]*>/g, '');
                  return `[${item.role?.toUpperCase() || 'SYSTEM'}]\n${plainText}`;
                })
                .join('\n\n---\n\n');
            } else if (parsed && typeof parsed === 'object' && parsed.content) {
              const plainText = parsed.content.replace(/<[^>]*>/g, '');
              textToCopy = `[${parsed.role?.toUpperCase() || 'SYSTEM'}]\n${plainText}`;
            }
          } catch {
            // Keep original if not JSON
          }
        }
        
        await navigator.clipboard.writeText(textToCopy);
        setCopied(true);
        setCopyType(type);
        setTimeout(() => setCopied(false), 2000);
      } catch (err) {
        console.error('Failed to copy context:', err);
      }
    }
  };

  // Render content - handles both HTML and plain text
  const renderContent = (content: string) => {
    // Check if content contains HTML tags
    const hasHtml = /<[^>]+>/.test(content);
    
    if (hasHtml) {
      // Render HTML content directly
      return <div className="context-content-body" dangerouslySetInnerHTML={{ __html: content }} />;
    } else {
      // Use MarkdownRenderer for plain text/markdown
      return (
        <div className="context-content-body">
          <MarkdownRenderer content={content} />
        </div>
      );
    }
  };

  const parseContext = (context: string) => {
    try {
      // Try to parse as JSON first (for structured context)
      const parsed = JSON.parse(context);
      if (Array.isArray(parsed)) {
        // Handle array of messages format
        const validMessages = parsed
          .filter((item) => item && typeof item === 'object' && item.content)
          .map((item, index) => (
            <div key={index} className="card mb-3">
              <div className="card-header bg-light py-2 d-flex align-items-center">
                <i className={`fas ${item.role === 'system' ? 'fa-cogs' : item.role === 'user' ? 'fa-user' : 'fa-robot'} mr-2 text-info`}></i>
                <span className="font-weight-bold text-uppercase small">
                  {item.role?.charAt(0).toUpperCase() + item.role?.slice(1) || 'System'}
                </span>
              </div>
              <div className="card-body py-3">
                {renderContent(item.content)}
              </div>
            </div>
          ));

        if (validMessages.length > 0) {
          return validMessages;
        }
      } else if (parsed && typeof parsed === 'object' && parsed.content) {
        // Handle single message format
        return (
          <div className="card">
            <div className="card-header bg-light py-2 d-flex align-items-center">
              <i className={`fas ${parsed.role === 'system' ? 'fa-cogs' : parsed.role === 'user' ? 'fa-user' : 'fa-robot'} mr-2 text-info`}></i>
              <span className="font-weight-bold text-uppercase small">
                {parsed.role?.charAt(0).toUpperCase() + parsed.role?.slice(1) || 'System'}
              </span>
            </div>
            <div className="card-body py-3">
              {renderContent(parsed.content)}
            </div>
          </div>
        );
      }
    } catch {
      // Not JSON, treat as plain text/markdown/HTML
    }

    // Default: treat as plain content (could be HTML or markdown)
    return (
      <div className="card">
        <div className="card-header bg-light py-2 d-flex align-items-center">
          <i className="fas fa-cogs mr-2 text-info"></i>
          <span className="font-weight-bold text-uppercase small">System Context</span>
        </div>
        <div className="card-body py-3">
          {renderContent(context)}
        </div>
      </div>
    );
  };

  return (
    <div ref={backdropRef} className="context-modal-backdrop">
      <div className="context-modal bg-white rounded shadow-lg overflow-hidden">
        {/* Modal Header */}
        <div className="d-flex align-items-center justify-content-between p-3 bg-light border-bottom">
          <div className="d-flex align-items-center">
            <div className="bg-info text-white rounded d-flex align-items-center justify-content-center mr-3" style={{ width: '40px', height: '40px' }}>
              <i className="fas fa-layer-group"></i>
            </div>
            <div>
              <h5 className="mb-0 font-weight-bold">Context Sent to AI</h5>
              <small className="text-muted">System instructions provided with this message</small>
            </div>
          </div>
          <button 
            className="btn btn-outline-secondary btn-sm" 
            onClick={onHide} 
            title="Close (Esc)"
          >
            <i className="fas fa-times"></i>
          </button>
        </div>
        
        {/* Modal Body */}
        <div className="context-modal-body p-3">
          {parseContext(message.sent_context)}
        </div>
        
        {/* Modal Footer */}
        <div className="d-flex align-items-center justify-content-between p-3 bg-light border-top">
          <small className="text-muted">
            <i className="fas fa-info-circle mr-1"></i>
            This context was sent to the AI model along with the conversation history
          </small>
          <div className="btn-group">
            <Button
              variant="outline-secondary"
              size="sm"
              onClick={() => handleCopy('raw')}
              title="Copy raw JSON data"
            >
              <i className={`fas ${copied && copyType === 'raw' ? 'fa-check text-success' : 'fa-code'} mr-1`}></i>
              {copied && copyType === 'raw' ? 'Copied!' : 'Copy Raw'}
            </Button>
            <Button
              variant="info"
              size="sm"
              onClick={() => handleCopy('formatted')}
              title="Copy formatted text"
            >
              <i className={`fas ${copied && copyType === 'formatted' ? 'fa-check' : 'fa-copy'} mr-1`}></i>
              {copied && copyType === 'formatted' ? 'Copied!' : 'Copy Text'}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
};

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

/**
 * Admin Message List Component
 * Renders messages with proper form detection and display
 */
const AdminMessageList: React.FC<{
  messages: Message[];
  formatDate: (date: string) => string;
  setContextPopup: (popup: { show: boolean; message: Message | null; target: HTMLElement | null }) => void;
}> = ({ messages, formatDate, setContextPopup }) => {
  // Pre-compute form definitions for each assistant message
  const formDefinitionsMap = useMemo(() => {
    const map = new Map<number, FormDefinition>();
    messages.forEach((message, index) => {
      if (message.role === 'assistant') {
        const formDef = parseFormDefinition(message.content);
        if (formDef) {
          map.set(index, formDef);
        }
      }
    });
    return map;
  }, [messages]);

  // Find the previous assistant's form definition for a given index
  const findPreviousAssistantFormDefinition = (currentIndex: number): FormDefinition | undefined => {
    for (let i = currentIndex - 1; i >= 0; i--) {
      if (messages[i].role === 'assistant') {
        return formDefinitionsMap.get(i);
      }
    }
    return undefined;
  };

  // Get attachment info
  const getAttachmentInfo = (attachments?: string): { count: number; isFormSubmission: boolean } => {
    if (!attachments) return { count: 0, isFormSubmission: false };
    try {
      const parsed = JSON.parse(attachments);
      if (parsed && parsed.type === 'form_submission') {
        return { count: 0, isFormSubmission: true };
      }
      return { count: Array.isArray(parsed) ? parsed.length : 1, isFormSubmission: false };
    } catch {
      return { count: 0, isFormSubmission: false };
    }
  };

  return (
    <div className="message-stack">
      {messages.map((message, index) => {
        const isUser = message.role === 'user';
        const formDefinition = !isUser ? parseFormDefinition(message.content) : null;
        const attachmentInfo = getAttachmentInfo(message.attachments);
        
        // Get user's submitted values for historical forms
        let userSubmittedValues: Record<string, string | string[]> | undefined;
        if (formDefinition && index < messages.length - 1) {
          const nextMessage = messages[index + 1];
          if (nextMessage && nextMessage.role === 'user') {
            const submissionMeta = parseFormSubmissionMetadata(nextMessage.attachments);
            if (submissionMeta) {
              userSubmittedValues = submissionMeta.values;
            }
          }
        }
        
        // Check if user message is a form submission
        let isUserFormSubmission = false;
        let userFormDefinition: FormDefinition | undefined;
        let userFormValues: Record<string, string | string[]> | undefined;
        
        if (isUser) {
          const submissionMeta = parseFormSubmissionMetadata(message.attachments);
          if (submissionMeta) {
            isUserFormSubmission = true;
            userFormValues = submissionMeta.values;
            userFormDefinition = findPreviousAssistantFormDefinition(index);
          }
        }

        return (
          <div
            key={message.id}
            className={`message-wrapper ${isUser ? 'user' : 'assistant'}`}
          >
            {/* Avatar */}
            <div className="message-avatar">
              <i className={`fas ${isUser ? 'fa-user' : 'fa-robot'}`}></i>
            </div>
            
            {/* Message Bubble */}
            <div className={`message-bubble ${isUser ? 'user-message' : 'assistant-message'}`}>
              {/* Context button for assistant messages */}
              {message.sent_context && (
                <div className="d-flex justify-content-end mb-2">
                  <button
                    className="btn btn-outline-info btn-sm py-0 px-2"
                    style={{ fontSize: '0.7rem' }}
                    onClick={() => {
                      setContextPopup({
                        show: true,
                        message,
                        target: null
                      });
                    }}
                    title="View context sent to AI"
                  >
                    <i className="fas fa-layer-group mr-1"></i>
                    Context
                  </button>
                </div>
              )}
              
              {/* Message Content */}
              <div className="message-content">
                {isUser ? (
                  // User messages
                  isUserFormSubmission && userFormDefinition && userFormValues ? (
                    // Form submission with selections
                    <FormDisplay
                      formDefinition={userFormDefinition}
                      submittedValues={userFormValues}
                      compact={false}
                    />
                  ) : (
                    // Regular text message
                    <div style={{ whiteSpace: 'pre-wrap', wordWrap: 'break-word' }}>
                      {message.content}
                    </div>
                  )
                ) : formDefinition ? (
                  // Assistant form response - show with user's selections
                  <FormDisplay
                    formDefinition={formDefinition}
                    submittedValues={userSubmittedValues}
                    compact={false}
                  />
                ) : (
                  // Regular assistant message
                  <MarkdownRenderer content={message.content} />
                )}
              </div>
              
              {/* Attachments (only show for non-form submissions) */}
              {attachmentInfo.count > 0 && !attachmentInfo.isFormSubmission && (
                <div className="mt-2 small text-muted">
                  <i className="fas fa-paperclip mr-1"></i>
                  {attachmentInfo.count} attachment{attachmentInfo.count !== 1 ? 's' : ''}
                </div>
              )}
              
              {/* Message Meta */}
              <div className="message-meta mt-2">
                <span>
                  <i className="fas fa-clock mr-1"></i>
                  {formatDate(message.timestamp)}
                </span>
                {message.tokens_used && (
                  <span className="tokens">
                    <i className="fas fa-microchip"></i>
                    {message.tokens_used.toLocaleString()} tokens
                  </span>
                )}
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
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
  const [contextPopup, setContextPopup] = useState<{
    show: boolean;
    message: Message | null;
    target: HTMLElement | null;
  }>({ show: false, message: null, target: null });

  // Scroll position preservation
  const messagesContainerRef = useRef<HTMLDivElement>(null);
  const [preservedScrollTop, setPreservedScrollTop] = useState<number | null>(null);

  useEffect(() => {
    loadFilterOptions();
  }, []);

  useEffect(() => {
    loadConversations(1);
  }, [filters]);

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
                        }),
                        menuPortal: (base) => ({
                          ...base,
                          zIndex: 9999
                        })
                      }}
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
                        }),
                        menuPortal: (base) => ({
                          ...base,
                          zIndex: 9999
                        })
                      }}
                    />
                  </div>

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
                <Card.Body ref={messagesContainerRef} className="messages-container p-3" style={{ overflowY: 'auto', maxHeight: '600px' }}>
                  {messages.length === 0 ? (
                    <div className="text-center py-5">
                      <i className="fas fa-comment-slash fa-2x text-muted mb-3"></i>
                      <div className="text-muted">No messages in this conversation</div>
                    </div>
                  ) : (
                    <AdminMessageList messages={messages} formatDate={formatDate} setContextPopup={setContextPopup} />
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
    </Container>
  );
};
