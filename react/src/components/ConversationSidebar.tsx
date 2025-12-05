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
import type { Conversation, LlmChatConfig } from '../types';
import { formatDate } from '../utils/formatters';

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
    <div
      className={`conversation-item ${isActive ? 'active' : ''}`}
      onClick={onSelect}
    >
      <div className="conversation-icon">
        <i className="fas fa-comment-dots"></i>
      </div>
      
      <div className="conversation-content">
        <div className="conversation-title">{conversation.title}</div>
        <div className="conversation-meta">{formatDate(conversation.updated_at)}</div>
      </div>
      
      <button
        className="delete-btn"
        title="Delete conversation"
        onClick={handleDeleteClick}
      >
        <i className="fas fa-trash-alt"></i>
      </button>
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
  
  if (!isOpen) return null;
  
  return (
    <div className="modal fade show d-block" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
      <div className="modal-dialog modal-dialog-centered">
        <div className="modal-content">
          <div className="modal-header">
            <h5 className="modal-title">{config.newConversationTitleLabel}</h5>
            <button
              type="button"
              className="close"
              aria-label="Close"
              onClick={handleClose}
            >
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <form onSubmit={handleSubmit}>
            <div className="modal-body">
              <div className="form-group">
                <label htmlFor="conversation-title">
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
                <i className="fas fa-plus mr-1"></i>
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
  
  const handleNewConversationClick = useCallback(() => {
    setShowModal(true);
  }, []);
  
  const handleCreate = useCallback((title: string) => {
    onCreate(title);
    setShowModal(false);
  }, [onCreate]);
  
  return (
    <>
      <div className="conversation-sidebar">
        {/* Sidebar Header */}
        <div className="sidebar-header">
          <div className="d-flex justify-content-between align-items-center">
            <h6>Conversations</h6>
            <button
              className="btn btn-primary btn-sm"
              onClick={handleNewConversationClick}
            >
              <i className="fas fa-plus mr-1"></i> New
            </button>
          </div>
        </div>
        
        {/* Conversations List */}
        <div className="conversations-list">
          {isLoading ? (
            <div className="sidebar-empty">
              <div className="spinner-border spinner-border-sm text-primary" role="status">
                <span className="sr-only">Loading...</span>
              </div>
              <p className="mt-2">Loading...</p>
            </div>
          ) : conversations.length === 0 ? (
            <div className="sidebar-empty">
              <i className="fas fa-inbox"></i>
              <p>{config.noConversationsMessage}</p>
            </div>
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
