import React from 'react';
import { AdminConversation } from '@/types';
import { ConversationRow } from './ConversationRow';

type ConversationListProps = {
  conversations: AdminConversation[];
  selectedId: string | null;
  emptyLabel: string;
  onSelect: (id: string) => void;
};

export const ConversationList: React.FC<ConversationListProps> = ({
  conversations,
  selectedId,
  emptyLabel,
  onSelect
}) => {
  if (!conversations.length) {
    return <div className="p-3 text-muted small">{emptyLabel}</div>;
  }

  return (
    <>
      {conversations.map((conv) => (
        <ConversationRow
          key={conv.id}
          conversation={conv}
          selected={selectedId === String(conv.id)}
          onSelect={onSelect}
        />
      ))}
    </>
  );
};


