import React from 'react';
import { Badge } from 'react-bootstrap';
import type { AdminConversation } from '../../types';
import { formatDateTime } from '../../utils/formatters';
import type { ConversationTypeMeta } from './conversationTypes';

interface AdminConversationDetailHeaderProps {
  conversation: AdminConversation;
  conversationMeta: ConversationTypeMeta;
  onBlockConversation: (conversationId: string) => void;
  onUnblockConversation: (conversationId: string) => void;
  onDeleteConversation: (conversationId: string) => void;
}

export const AdminConversationDetailHeader: React.FC<AdminConversationDetailHeaderProps> = ({
  conversation,
  conversationMeta,
  onBlockConversation,
  onUnblockConversation,
  onDeleteConversation,
}) => {
  return (
    <div className="d-flex justify-content-between align-items-start">
      <div className="flex-grow-1">
        <div className="d-flex align-items-center mb-1 flex-wrap">
          <span className={`${conversationMeta.badgeClass} mr-2`}>
            <i className={`fas ${conversationMeta.icon} mr-1`}></i>
            {conversationMeta.label}
          </span>
          <h5 className="text-dark mb-0 font-weight-bold">
            {conversationMeta.displayTitle || conversation.title || 'Untitled Conversation'}
          </h5>
          {conversation.blocked ? (
            <Badge variant="warning" className="ml-2">
              <i className="fas fa-ban mr-1"></i>Blocked
            </Badge>
          ) : null}
          {conversation.deleted ? (
            <Badge variant="danger" className="ml-2">
              <i className="fas fa-trash-alt mr-1"></i>Deleted
            </Badge>
          ) : null}
        </div>

        <div className="small text-muted">
          <i className="fas fa-user mr-1"></i>
          {conversation.user_name || 'Unknown'}
          {conversation.section_name && (
            <>
              <span className="mx-2">|</span>
              <i className="fas fa-folder mr-1"></i>
              {conversation.section_name}
            </>
          )}
          {conversation.script_name && (
            <>
              <span className="mx-2">|</span>
              <i className="fas fa-code mr-1"></i>
              {conversation.script_name}
            </>
          )}
          <span className="mx-2">|</span>
          <i className="fas fa-brain mr-1"></i>
          {conversation.model}
          <span className="mx-2">|</span>
          <i className="fas fa-clock mr-1"></i>
          {formatDateTime(conversation.updated_at)}
        </div>

        {conversation.blocked_reason && (
          <div className="small text-danger mt-1">
            <i className="fas fa-exclamation-triangle mr-1"></i>
            Block reason: {conversation.blocked_reason}
          </div>
        )}
      </div>

      <div className="d-flex align-items-center">
        <Badge variant="info" className="px-2 py-1 mr-2">
          <i className="fas fa-comment-dots mr-1"></i>
          {conversation.message_count || 0}
        </Badge>
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
            {conversation.blocked ? (
              <button
                className="dropdown-item text-success"
                onClick={() => onUnblockConversation(conversation.id.toString())}
              >
                <i className="fas fa-check-circle mr-2"></i>
                Unblock Conversation
              </button>
            ) : (
              <button
                className="dropdown-item text-warning"
                onClick={() => onBlockConversation(conversation.id.toString())}
              >
                <i className="fas fa-ban mr-2"></i>
                Block Conversation
              </button>
            )}
            <div className="dropdown-divider"></div>
            <button
              className="dropdown-item text-danger"
              onClick={() => onDeleteConversation(conversation.id.toString())}
            >
              <i className="fas fa-trash-alt mr-2"></i>
              Delete Conversation
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};
