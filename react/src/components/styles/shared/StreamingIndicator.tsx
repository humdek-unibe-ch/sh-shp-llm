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
    <div className="streaming-indicator-bar">
      <div className="streaming-dots">
        <span className="dot"></span>
        <span className="dot"></span>
        <span className="dot"></span>
      </div>
      <span>{text}</span>
    </div>
  );
};

export default StreamingIndicator;
