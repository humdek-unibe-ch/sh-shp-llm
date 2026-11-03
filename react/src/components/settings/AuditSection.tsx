/**
 * Audit Section — request trace storage.
 *
 * Every LLM call is stored as a hash-verified request trace. While the switch
 * is on, the full context and request payload are stored as well (dual write),
 * so the trace verifier can compare both before payloads are switched off.
 *
 * @module components/settings/AuditSection
 */
import React from 'react';

interface FieldDef {
  name: string;
  label: string;
  help: string;
  value: any;
}

interface Props {
  getField: (name: string) => FieldDef | undefined;
  getVal: (name: string) => string;
  onChange: (name: string, value: string) => void;
  disabled?: boolean;
}

/** AuditSection component. */
export const AuditSection: React.FC<Props> = ({ getField, getVal, onChange, disabled }) => {
  const field = getField('llm_trace_store_blobs');
  if (!field) return null;

  return (
    <div className="card mb-3">
      <div className="card-header">
        <h6 className="mb-0">
          <i className="fa fa-clipboard-check mr-2 text-muted"></i>
          Request Audit
        </h6>
      </div>
      <div className="card-body">
        <div className="form-group mb-0">
          <div className="custom-control custom-switch">
            <input
              type="checkbox"
              className="custom-control-input"
              id="llm-trace-store-blobs"
              checked={getVal('llm_trace_store_blobs') === '1'}
              onChange={e => onChange('llm_trace_store_blobs', e.target.checked ? '1' : '0')}
              disabled={disabled}
            />
            <label className="custom-control-label small font-weight-bold" htmlFor="llm-trace-store-blobs">
              {field.label}
            </label>
          </div>
          {field.help && <small className="form-text text-muted">{field.help}</small>}
        </div>
      </div>
    </div>
  );
};
