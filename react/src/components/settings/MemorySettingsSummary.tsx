/**
 * Memory Settings Summary — read-only overview of memory configuration.
 *
 * Displays the current memory-related settings (models, rule count,
 * storage stats) as a compact summary card.
 *
 * @module components/settings/MemorySettingsSummary
 */
import React from 'react';

interface Props {
  enabled: boolean;
  memoryPageUrl?: string;
}

/** MemorySettingsSummary component. */
export const MemorySettingsSummary: React.FC<Props> = ({ enabled, memoryPageUrl }) => {
  return (
    <div className="card mb-3">
      <div className="card-header">
        <h6 className="mb-0">
          <i className="fa fa-database mr-2 text-muted"></i>
          Memory
        </h6>
      </div>
      <div className="card-body">
        <div className="d-flex justify-content-between align-items-center flex-wrap">
          <div className="mb-2 mb-md-0">
            <div className="font-weight-bold">Global memory is managed in the dedicated memory area.</div>
            <div className="text-muted small">Configure the memory system, inspect sources, edit rules, and browse user memory there.</div>
          </div>
          <div className="d-flex align-items-center">
            <span className={`badge badge-${enabled ? 'success' : 'secondary'} mr-2`}>
              {enabled ? 'Enabled' : 'Disabled'}
            </span>
            {memoryPageUrl ? (
              <a className="btn btn-sm btn-outline-primary" href={memoryPageUrl}>
                Open Memory Manager
              </a>
            ) : null}
          </div>
        </div>
      </div>
    </div>
  );
};
