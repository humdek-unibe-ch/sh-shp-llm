/**
 * Streaming Indicator Component
 * =============================
 * 
 * Displays a visual indicator that the AI is currently streaming a response.
 * Shows in the footer area of the chat while streaming is active.
 * 
 * @module components/StreamingIndicator
 */

import React from 'react';

/**
 * Props for StreamingIndicator component
 */
interface StreamingIndicatorProps {
  /** Text to display (e.g., "AI is thinking...") */
  text?: string;
}

/**
 * Streaming Indicator Component
 * 
 * Shows a pulsing indicator while the AI is generating a response
 */
export const StreamingIndicator: React.FC<StreamingIndicatorProps> = ({
  text = 'AI is thinking...'
}) => {
  return (
    <div
      id="streaming-indicator"
      className="streaming-indicator px-3 py-2 border-top bg-light"
    >
      <div className="d-flex align-items-center">
        <div className="streaming-dots mr-2">
          <span className="dot"></span>
          <span className="dot"></span>
          <span className="dot"></span>
        </div>
        <small className="text-muted">{text}</small>
      </div>
    </div>
  );
};

export default StreamingIndicator;
