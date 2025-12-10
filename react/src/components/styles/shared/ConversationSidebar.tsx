/**
 * Conversation Sidebar Component
 * ==============================
 * 
 * Modern sidebar displaying conversation list with:
 * - New conversation button
 * - Conversation items with title, date, and delete button
 * - Active conversation highlighting
 * - Empty state message
 * - Loading state
 * 
 * @module components/ConversationSidebar
 */

import React, { useState, useCallback } from 'react';
import { Card, ListGroup, Button, Modal, Form, CloseButton } from 'react-bootstrap';
import type { Conversation, LlmChatConfig } from '../../../types';
import { formatDate } from '../../../utils/formatters';

/**
 * Props for ConversationSidebar component
 */
interface ConversationSidebarProps {
  /** List of conversations */
  conversations: Conversation[];
  /** Currently selected conversation */
  currentConversation: Conversation | null;
  /** Callback when conversation is selected */
  onSelect: (conversation: Conversation) => void;
  /** Callback when new conversation is created */
  onCreate: (title?: string) => void;
  /** Callback when conversation is deleted */
  onDelete: (conversationId: string) => void;
  /** Whether data is loading */
  isLoading: boolean;
  /** Component configuration */
  config: LlmChatConfig;
}

/**
 * Props for conversation item
 */
interface ConversationItemProps {
  /** Conversation data */
  conversation: Conversation;
  /** Whether this is the active conversation */
  isActive: boolean;
  /** Callback when selected */
  onSelect: () => void;
  /** Callback when deleted */
  onDelete: () => void;
  /** Component configuration */
  config: LlmChatConfig;
}

/**
 * Individual conversation item component
 */
const ConversationItem: React.FC<ConversationItemProps> = ({
  conversation,
  isActive,
  onSelect,
  onDelete,
  config
}) => {
  /**
   * Handle delete click
   */
  const handleDeleteClick = useCallback((e: React.MouseEvent) => {
    e.stopPropagation();
    
    // Use native confirm or the CMS confirmation if available
    if (typeof (window as any).$.confirm === 'function') {
      (window as any).$.confirm({
        title: config.deleteConfirmationTitle,
        content: config.deleteConfirmationMessage,
        type: 'red',
        buttons: {
          confirm: () => onDelete(),
          cancel: () => {}
        }
      });
    } else {
      if (window.confirm(config.deleteConfirmationMessage)) {
        onDelete();
      }
    }
  }, [onDelete, config]);
  
  return (
    <ListGroup.Item
      action
      active={isActive}
      onClick={onSelect}
      className="d-flex align-items-center justify-content-between conversation-row"
    >
      <div className="d-flex align-items-center flex-grow-1">
        <div className="conversation-icon mr-3">
          <i className="fas fa-comment-dots"></i>
        </div>
        <div className="conversation-content">
          <div className="conversation-title font-weight-medium">{conversation.title}</div>
          <small className="text-muted">{formatDate(conversation.updated_at)}</small>
        </div>
      </div>
      <Button
        variant="link"
        size="sm"
        className="text-danger p-1 conversation-delete-btn"
        title="Delete conversation"
        onClick={handleDeleteClick}
      >
        <i className="fas fa-trash-alt"></i>
      </Button>
    </ListGroup.Item>
  );
};

/**
 * New conversation modal component
 */
interface NewConversationModalProps {
  isOpen: boolean;
  onClose: () => void;
  onCreate: (title: string) => void;
  config: LlmChatConfig;
}

const NewConversationModal: React.FC<NewConversationModalProps> = ({
  isOpen,
  onClose,
  onCreate,
  config
}) => {
  const [title, setTitle] = useState('');
  
  const handleSubmit = useCallback((e: React.FormEvent) => {
    e.preventDefault();
    onCreate(title.trim());
    setTitle('');
    onClose();
  }, [title, onCreate, onClose]);
  
  const handleClose = useCallback(() => {
    setTitle('');
    onClose();
  }, [onClose]);
  
  return (
    <Modal show={isOpen} onHide={handleClose} centered>
      <Modal.Header closeButton>
        <Modal.Title>{config.newConversationTitleLabel}</Modal.Title>
      </Modal.Header>
      <Form onSubmit={handleSubmit}>
        <Modal.Body>
          <Form.Group>
            <Form.Label htmlFor="conversation-title">
              {config.conversationTitleLabel}
            </Form.Label>
            <Form.Control
              type="text"
              id="conversation-title"
              placeholder="Enter conversation title (optional)"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              autoFocus
            />
          </Form.Group>
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={handleClose}>
            {config.cancelButtonLabel}
          </Button>
          <Button variant="primary" type="submit">
            <i className="fas fa-plus mr-1"></i>
            {config.createButtonLabel}
          </Button>
        </Modal.Footer>
      </Form>
    </Modal>
  );
};

/**
 * Conversation Sidebar Component
 * 
 * Main sidebar component displaying conversation list
 */
export const ConversationSidebar: React.FC<ConversationSidebarProps> = ({
  conversations,
  currentConversation,
  onSelect,
  onCreate,
  onDelete,
  isLoading,
  config
}) => {
  const [showModal, setShowModal] = useState(false);
  
  const handleNewConversationClick = useCallback(() => {
    setShowModal(true);
  }, []);
  
  const handleCreate = useCallback((title: string) => {
    onCreate(title);
    setShowModal(false);
  }, [onCreate]);
  
  return (
    <>
      <Card className="h-100 border-0 shadow-sm conversation-sidebar-card d-flex flex-column">
        {/* Sidebar Header */}
        <Card.Header className="bg-white border-0 conversation-sidebar-header">
          <div className="d-flex justify-content-between align-items-center">
            <h6 className="mb-0">Conversations</h6>
            <Button
              variant="primary"
              size="sm"
              onClick={handleNewConversationClick}
              className="conversation-new-btn"
            >
              <i className="fas fa-plus mr-1"></i> New
            </Button>
          </div>
        </Card.Header>

        {/* Conversations List */}
        <Card.Body className="p-0 flex-grow-1 overflow-auto conversation-sidebar-body">
          <ListGroup variant="flush" className="conversation-list">
            {isLoading ? (
              <ListGroup.Item className="text-center py-4">
                <div className="spinner-border spinner-border-sm text-primary" role="status">
                  <span className="sr-only">Loading...</span>
                </div>
                <p className="mt-2 mb-0">Loading...</p>
              </ListGroup.Item>
            ) : conversations.length === 0 ? (
              <ListGroup.Item className="text-center py-4">
                <i className="fas fa-inbox fa-2x text-muted mb-3"></i>
                <p className="text-muted mb-0">{config.noConversationsMessage}</p>
              </ListGroup.Item>
            ) : (
              conversations.map((conversation) => (
                <ConversationItem
                  key={conversation.id}
                  conversation={conversation}
                  isActive={currentConversation ? String(currentConversation.id) === String(conversation.id) : false}
                  onSelect={() => onSelect(conversation)}
                  onDelete={() => onDelete(String(conversation.id))}
                  config={config}
                />
              ))
            )}
          </ListGroup>
        </Card.Body>
      </Card>
      
      {/* New Conversation Modal */}
      <NewConversationModal
        isOpen={showModal}
        onClose={() => setShowModal(false)}
        onCreate={handleCreate}
        config={config}
      />
    </>
  );
};

export default ConversationSidebar;
