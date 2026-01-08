/**
 * Loading Indicator Component
 * ===========================
 * 
 * Simple loading indicator shown during API requests.
 * 
 * @module components/shared/LoadingIndicator
 */

import React from 'react';

interface LoadingIndicatorProps {
  text?: string;
}

export const LoadingIndicator: React.FC<LoadingIndicatorProps> = ({ text = 'Loading...' }) => {
  return (
    <div className="llm-loading-indicator d-flex align-items-center justify-content-center p-2 bg-light border-top">
      <div className="spinner-border spinner-border-sm text-primary mr-2" role="status">
        <span className="sr-only">Loading...</span>
      </div>
      <span className="text-muted small">{text}</span>
    </div>
  );
};

export default LoadingIndicator;
