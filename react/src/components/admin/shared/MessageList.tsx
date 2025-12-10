import React from 'react';
import { Message } from '@/types';
import { MessageRow } from './MessageRow';

type MessageListProps = {
  messages: Message[];
  emptyLabel: string;
};

export const MessageList: React.FC<MessageListProps> = ({ messages, emptyLabel }) => {
  if (!messages.length) {
    return <div className="text-muted text-center py-4">{emptyLabel}</div>;
  }

  return (
    <>
      {messages.map((msg) => (
        <MessageRow key={msg.id} message={msg} />
      ))}
    </>
  );
};


