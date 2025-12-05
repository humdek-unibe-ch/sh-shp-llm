/**
 * Conversation Sidebar Component
 * ==============================
 * 
 * Displays the list of conversations with:
 * - New conversation button
 * - Conversation cards with title, date, and delete button
 * - Active conversation highlighting
 * - Empty state message
 * - Loading state
 * 
 * Matches the vanilla JS implementation styling.
 * 
 * @module components/ConversationSidebar
 */

import React, { useState, useCallback } from 'react';
import type { Conversation, LlmChatConfig } from '../types';
import { formatDate, escapeHtml } from '../utils/formatters';

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
 * Props for conversation card
 */
interface ConversationCardProps {
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
 * Individual conversation card component
 */
const ConversationCard: React.FC<ConversationCardProps> = ({
  conversation,
  isActive,
  onSelect,
  onDelete,
  config
}) => {
  const [isHovered, setIsHovered] = useState(false);
  
  /**
   * Handle delete click
   */
  const handleDeleteClick = useCallback((e: React.MouseEvent) => {
    e.stopPropagation();
    
    // Use native confirm or the CMS confirmation if available
    if (typeof (window as any).$.confirm === 'function') {
      // Use jQuery-confirm if available (SelfHelp CMS)
      (window as any).$.confirm({
        title: config.deleteConfirmationTitle,
        content: config.deleteConfirmationMessage,
        type: 'red',
        buttons: {
          confirm: () => {
            onDelete();
          },
          cancel: () => {}
        }
      });
    } else {
      // Fallback to native confirm
      if (window.confirm(config.deleteConfirmationMessage)) {
        onDelete();
      }
    }
  }, [onDelete, config]);
  
  return (
    <div
      className={`card mb-2 position-relative ${isActive ? 'border-primary bg-light' : ''}`}
      data-conversation-id={conversation.id}
      style={{ cursor: 'pointer' }}
      onClick={onSelect}
      onMouseEnter={() => setIsHovered(true)}
      onMouseLeave={() => setIsHovered(false)}
    >
      <div className="card-body py-2 px-3">
        <div className="font-weight-bold mb-1">
          {conversation.title}
        </div>
        <div className="small text-muted">
          {formatDate(conversation.updated_at)}
        </div>
        
        {/* Delete button - visible on hover */}
        <div
          className="position-absolute"
          style={{
            top: 8,
            right: 8,
            opacity: isHovered ? 1 : 0,
            transition: 'opacity 0.2s'
          }}
        >
          <button
            className="btn btn-sm btn-outline-danger"
            data-conversation-id={conversation.id}
            title="Delete conversation"
            onClick={handleDeleteClick}
          >
            <i className="fas fa-trash"></i>
          </button>
        </div>
      </div>
    </div>
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
  
  /**
   * Handle form submission
   */
  const handleSubmit = useCallback((e: React.FormEvent) => {
    e.preventDefault();
    onCreate(title.trim());
    setTitle('');
    onClose();
  }, [title, onCreate, onClose]);
  
  /**
   * Handle close
   */
  const handleClose = useCallback(() => {
    setTitle('');
    onClose();
  }, [onClose]);
  
  if (!isOpen) return null;
  
  return (
    <div className="modal fade show d-block" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
      <div className="modal-dialog">
        <div className="modal-content">
          <div className="modal-header">
            <h5 className="modal-title">{config.newConversationTitleLabel}</h5>
            <button
              type="button"
              className="btn-close"
              aria-label="Close"
              onClick={handleClose}
            >
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <form onSubmit={handleSubmit}>
            <div className="modal-body">
              <div className="mb-3">
                <label htmlFor="conversation-title" className="form-label">
                  {config.conversationTitleLabel}
                </label>
                <input
                  type="text"
                  className="form-control"
                  id="conversation-title"
                  placeholder="Enter conversation title (optional)"
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  autoFocus
                />
              </div>
            </div>
            <div className="modal-footer">
              <button
                type="button"
                className="btn btn-secondary"
                onClick={handleClose}
              >
                {config.cancelButtonLabel}
              </button>
              <button
                type="submit"
                className="btn btn-primary"
              >
                {config.createButtonLabel}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
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
  
  /**
   * Handle new conversation button click
   */
  const handleNewConversationClick = useCallback(() => {
    setShowModal(true);
  }, []);
  
  /**
   * Handle conversation creation from modal
   */
  const handleCreate = useCallback((title: string) => {
    onCreate(title);
    setShowModal(false);
  }, [onCreate]);
  
  return (
    <>
      <div className="card h-100">
        {/* Sidebar Header */}
        <div className="card-header bg-secondary text-white">
          <div className="d-flex justify-content-between align-items-center">
            <h6 className="mb-0">Conversations</h6>
            <button
              className="btn btn-sm btn-light"
              id="new-conversation-btn"
              onClick={handleNewConversationClick}
            >
              <i className="fas fa-plus"></i> New
            </button>
          </div>
        </div>
        
        {/* Conversations List */}
        <div
          className="card-body overflow-auto"
          id="conversations-list"
          style={{ maxHeight: 'calc(100vh - 200px)' }}
        >
          {isLoading ? (
            // Loading state
            <div className="text-center py-3">
              <div className="spinner-border spinner-border-sm text-primary" role="status">
                <span className="sr-only">Loading...</span>
              </div>
              <div className="mt-2 text-muted small">Loading conversations...</div>
            </div>
          ) : conversations.length === 0 ? (
            // Empty state
            <div className="text-center text-muted py-3">
              <small>{config.noConversationsMessage}</small>
            </div>
          ) : (
            // Conversation cards
            conversations.map((conversation) => (
              <ConversationCard
                key={conversation.id}
                conversation={conversation}
                isActive={currentConversation?.id === conversation.id}
                onSelect={() => onSelect(conversation)}
                onDelete={() => onDelete(conversation.id)}
                config={config}
              />
            ))
          )}
        </div>
      </div>
      
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
