import React from 'react';
import { AdminConversation } from '@/types';

type ConversationRowProps = {
  conversation: AdminConversation;
  selected: boolean;
  onSelect: (id: string) => void;
};

export const ConversationRow: React.FC<ConversationRowProps> = ({ conversation, selected, onSelect }) => {
  return (
    <button
      type="button"
      className={`list-group-item list-group-item-action ${selected ? 'active' : ''}`}
      onClick={() => onSelect(String(conversation.id))}
    >
      <div className="d-flex justify-content-between align-items-center">
        <div className="text-left">
          <div className="font-weight-bold text-truncate">{conversation.title || 'Untitled conversation'}</div>
          <div className="small text-muted text-truncate">
            {conversation.user_name || 'Unknown user'}
            {conversation.section_name ? ` · ${conversation.section_name}` : ''}
          </div>
          <div className="small text-muted">
            {conversation.model} · {conversation.message_count || 0} messages
          </div>
        </div>
        <div className="text-right small text-muted">
          <div>{new Date(conversation.updated_at).toLocaleString()}</div>
        </div>
      </div>
    </button>
  );
};


