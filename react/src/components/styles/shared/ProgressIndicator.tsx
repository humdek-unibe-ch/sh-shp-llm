/**
 * Progress Indicator Component
 * ============================
 * 
 * Displays progress tracking for context coverage in LLM conversations.
 * Shows a progress bar with percentage and optionally a list of topics.
 * 
 * Features:
 * - Visual progress bar with percentage
 * - Optional topic list showing coverage status
 * - Completion message when 100% is reached
 * - Collapsible topic details
 * - Compact mode for floating chat panels
 * 
 * @module components/ProgressIndicator
 */

import React, { useState } from 'react';
import type { ProgressData, TopicCoverage } from '../../../types';

/**
 * Props for ProgressIndicator component
 */
interface ProgressIndicatorProps {
  /** Progress data from the API */
  progress: ProgressData;
  /** Label for the progress bar */
  barLabel?: string;
  /** Message shown when progress is complete */
  completeMessage?: string;
  /** Whether to show the topic list */
  showTopics?: boolean;
  /** Optional CSS class for the container */
  className?: string;
  /** Whether to use compact mode (for floating panels) */
  compact?: boolean;
}

/**
 * Get progress bar color based on percentage
 * Uses Bootstrap 4.6 colors
 */
const getProgressColor = (percentage: number): string => {
  if (percentage >= 100) return '#28a745'; // success
  if (percentage >= 75) return '#17a2b8';  // info
  if (percentage >= 50) return '#007bff';  // primary
  if (percentage >= 25) return '#ffc107';  // warning
  return '#6c757d'; // secondary
};

/**
 * Progress Indicator Component
 * 
 * Shows context coverage progress with an optional topic breakdown
 */
export const ProgressIndicator: React.FC<ProgressIndicatorProps> = ({
  progress,
  barLabel = 'Progress',
  completeMessage = 'Great job! You have covered all topics.',
  showTopics = false,
  className = '',
  compact = false
}) => {
  const [isExpanded, setIsExpanded] = useState(false);
  
  const { percentage, topics_total, topics_covered, topic_coverage, is_complete, debug } = progress;
  
  // Convert topic_coverage object to array for rendering
  const topicList = Object.values(topic_coverage || {}) as TopicCoverage[];
  
  // Check if no topics were found (configuration issue)
  const hasNoTopics = topics_total === 0;
  
  // Compact mode rendering - ultra-compact single line with percentage inside progress bar
  if (compact) {
    return (
      <div className={`progress-indicator-compact ${className}`}>
        <div className="d-flex align-items-center">
          {/* Progress bar with percentage inside */}
          <div 
            className="progress flex-grow-1 position-relative" 
            style={{ 
              height: '18px', 
              backgroundColor: '#e9ecef',
              borderRadius: '9px',
              overflow: 'hidden'
            }}
          >
            <div 
              className="progress-bar" 
              role="progressbar" 
              style={{ 
                width: `${Math.min(percentage, 100)}%`,
                backgroundColor: getProgressColor(percentage),
                transition: 'width 0.5s ease-in-out'
              }}
              aria-valuenow={percentage} 
              aria-valuemin={0} 
              aria-valuemax={100}
            />
            {/* Percentage text overlay */}
            <span 
              className="position-absolute w-100 text-center font-weight-bold"
              style={{ 
                top: '50%', 
                left: 0,
                transform: 'translateY(-50%)',
                fontSize: '10px',
                color: percentage > 50 ? '#ffffff' : '#343a40',
                textShadow: percentage > 50 ? '0 0 2px rgba(0,0,0,0.3)' : 'none',
                lineHeight: 1
              }}
            >
              {percentage.toFixed(0)}% · {topics_covered}/{topics_total}
            </span>
          </div>
          
          {/* Expand button */}
          {showTopics && topicList.length > 0 && (
            <button 
              className="btn btn-link btn-sm p-0 ml-2 text-decoration-none"
              onClick={() => setIsExpanded(!isExpanded)}
              type="button"
              title={isExpanded ? 'Hide topics' : 'Show topics'}
              style={{ lineHeight: 1 }}
            >
              <i className={`fas fa-chevron-${isExpanded ? 'up' : 'down'} text-muted`} style={{ fontSize: '10px' }}></i>
            </button>
          )}
        </div>
        
        {/* Expandable topic list in compact mode */}
        {showTopics && isExpanded && topicList.length > 0 && (
          <div className="topic-list-compact mt-2 pt-2 border-top" style={{ maxHeight: '120px', overflowY: 'auto' }}>
            {topicList.map((topic) => (
              <div key={topic.id} className="d-flex align-items-center py-1" style={{ fontSize: '10px' }}>
                <i className={`fas ${topic.is_covered ? 'fa-check-circle text-success' : 'fa-circle text-muted'} mr-1`} style={{ fontSize: '8px' }}></i>
                <span className={`flex-grow-1 ${topic.is_covered ? '' : 'text-muted'}`} style={{ lineHeight: 1.2 }}>{topic.title}</span>
              </div>
            ))}
          </div>
        )}
      </div>
    );
  }

  // Standard mode rendering
  return (
    <div className={`progress-indicator-container ${className}`}>
      {/* Warning when no topics found */}
      {hasNoTopics && (
        <div className="alert alert-warning mb-3 py-2">
          <div className="d-flex align-items-start">
            <i className="fas fa-exclamation-triangle mr-2 mt-1"></i>
            <div>
              <strong>No trackable topics found</strong>
              <p className="mb-1 small">
                Progress tracking requires topics to be defined in the conversation context.
              </p>
              <details className="small">
                <summary className="text-primary" style={{ cursor: 'pointer' }}>How to fix this</summary>
                <div className="mt-2 p-2 bg-light rounded">
                  <p className="mb-1">Add a <code>## TRACKABLE_TOPICS</code> section to your context:</p>
                  <pre className="bg-dark text-light p-2 rounded small mb-0" style={{ whiteSpace: 'pre-wrap' }}>
{`## TRACKABLE_TOPICS

- name: Topic Name
  keywords: keyword1, keyword2, keyword3`}
                  </pre>
                </div>
              </details>
              {debug && (
                <details className="small mt-2">
                  <summary className="text-secondary" style={{ cursor: 'pointer' }}>Debug info</summary>
                  <div className="mt-1 p-2 bg-light rounded small">
                    <div>Context length: {debug.context_length} chars</div>
                    <div>Has TRACKABLE_TOPICS section: {debug.has_trackable_topics_section ? 'Yes' : 'No'}</div>
                    <div>Has [TOPIC:] markers: {debug.has_topic_markers ? 'Yes' : 'No'}</div>
                    <div>User messages: {debug.user_messages_count}</div>
                    {debug.context_preview && (
                      <div className="mt-1">
                        <strong>Context preview:</strong>
                        <pre className="bg-dark text-light p-1 rounded small mb-0" style={{ whiteSpace: 'pre-wrap', maxHeight: '100px', overflow: 'auto' }}>
                          {debug.context_preview}
                        </pre>
                      </div>
                    )}
                  </div>
                </details>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Progress Header */}
      <div className="progress-header d-flex justify-content-between align-items-center mb-2">
        <span className="progress-label font-weight-medium">
          <i className="fas fa-chart-line mr-2 text-primary"></i>
          {barLabel}
        </span>
        <span className="progress-percentage font-weight-bold" style={{ color: getProgressColor(percentage) }}>
          {percentage.toFixed(1)}%
        </span>
      </div>
      
      {/* Progress Bar */}
      <div className="progress mb-2" style={{ height: '8px', backgroundColor: '#e9ecef' }}>
        <div 
          className="progress-bar" 
          role="progressbar" 
          style={{ 
            width: `${Math.min(percentage, 100)}%`,
            backgroundColor: getProgressColor(percentage),
            transition: 'width 0.5s ease-in-out, background-color 0.3s ease'
          }}
          aria-valuenow={percentage} 
          aria-valuemin={0} 
          aria-valuemax={100}
        />
      </div>
      
      {/* Topics Summary */}
      {topics_total > 0 && (
        <div className="progress-summary d-flex justify-content-between align-items-center">
          <small className="text-muted">
            {topics_covered} of {topics_total} topics covered
          </small>
          {showTopics && topicList.length > 0 && (
            <button 
              className="btn btn-link btn-sm p-0 text-decoration-none"
              onClick={() => setIsExpanded(!isExpanded)}
              type="button"
            >
              <small>
                {isExpanded ? 'Hide details' : 'Show details'}
                <i className={`fas fa-chevron-${isExpanded ? 'up' : 'down'} ml-1`}></i>
              </small>
            </button>
          )}
        </div>
      )}
      
      {/* Completion Message */}
      {is_complete && (
        <div className="alert alert-success mt-3 mb-0 py-2 d-flex align-items-center">
          <i className="fas fa-check-circle mr-2"></i>
          <span>{completeMessage}</span>
        </div>
      )}
      
      {/* Topic List (Expandable) */}
      {showTopics && isExpanded && topicList.length > 0 && (
        <div className="topic-list mt-3">
          <div className="list-group list-group-flush">
            {topicList.map((topic) => (
              <TopicItem key={topic.id} topic={topic} />
            ))}
          </div>
        </div>
      )}
    </div>
  );
};

/**
 * Topic Item Component
 * 
 * Displays a single topic with its coverage status
 */
interface TopicItemProps {
  topic: TopicCoverage;
}

const TopicItem: React.FC<TopicItemProps> = ({ topic }) => {
  const { title, coverage, is_covered } = topic;
  
  return (
    <div className="list-group-item px-0 py-2 border-0 d-flex align-items-center">
      <span className={`topic-status mr-2 ${is_covered ? 'text-success' : 'text-secondary'}`}>
        <i className={`fas ${is_covered ? 'fa-check-circle' : 'fa-circle'}`}></i>
      </span>
      <span className={`topic-title flex-grow-1 ${is_covered ? '' : 'text-muted'}`}>
        {title}
      </span>
      {is_covered && (
        <span className="topic-coverage badge badge-success badge-pill">
          {coverage.toFixed(0)}%
        </span>
      )}
    </div>
  );
};

/**
 * Compact Progress Indicator
 * 
 * A smaller version for use in headers or tight spaces
 */
interface CompactProgressProps {
  percentage: number;
  label?: string;
}

export const CompactProgress: React.FC<CompactProgressProps> = ({
  percentage,
  label
}) => {
  const getColor = (): string => {
    if (percentage >= 100) return '#28a745';
    if (percentage >= 50) return '#007bff';
    return '#6c757d';
  };

  return (
    <div className="compact-progress d-flex align-items-center">
      {label && <small className="text-muted mr-2">{label}</small>}
      <div 
        className="progress flex-grow-1" 
        style={{ height: '4px', width: '60px', backgroundColor: '#e9ecef' }}
      >
        <div 
          className="progress-bar" 
          style={{ 
            width: `${Math.min(percentage, 100)}%`,
            backgroundColor: getColor()
          }}
        />
      </div>
      <small className="ml-2 font-weight-bold" style={{ color: getColor(), minWidth: '40px' }}>
        {percentage.toFixed(0)}%
      </small>
    </div>
  );
};

export default ProgressIndicator;

