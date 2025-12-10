import React from 'react';
import { Message } from '@/types';
import { MarkdownRenderer } from '../../shared/MarkdownRenderer';

type MessageRowProps = {
  message: Message;
};

export const MessageRow: React.FC<MessageRowProps> = ({ message }) => {
  return (
    <div className="card mb-2">
      <div className="card-body p-3">
        <div className="d-flex justify-content-between">
          <div className="badge badge-light text-uppercase">{message.role}</div>
          <div className="small text-muted">{new Date(message.timestamp).toLocaleString()}</div>
        </div>
        <div className="mt-2 message-content">
          <MarkdownRenderer content={message.content} />
        </div>
        {message.tokens_used !== undefined && (
          <div className="small text-muted mt-2">Tokens: {message.tokens_used}</div>
        )}
      </div>
    </div>
  );
};


