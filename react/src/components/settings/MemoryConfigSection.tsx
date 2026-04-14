import React from 'react';
import { SearchableSelect } from './SearchableSelect';

export interface FieldDef {
  name: string;
  type: string;
  label: string;
  help: string;
  value: string;
  options?: { value: string; label: string }[];
}

interface Props {
  getField: (name: string) => FieldDef | undefined;
  getVal: (name: string) => string;
  onChange: (name: string, value: string) => void;
  disabled?: boolean;
  title?: string;
  iconClass?: string;
  hideDetailsWhenDisabled?: boolean;
  footer?: React.ReactNode;
}

export const MemoryConfigSection: React.FC<Props> = ({
  getField,
  getVal,
  onChange,
  disabled,
  title = 'Memory Configuration',
  iconClass = 'fa fa-brain',
  hideDetailsWhenDisabled = true,
  footer,
}) => {
  const memoryEnabled = getVal('llm_memory_enabled') === '1';

  const renderField = (name: string) => {
    const field = getField(name);
    if (!field) return null;
    const val = getVal(name);

    if (name === 'llm_memory_enabled') {
      return (
        <div className="form-group" key={name}>
          <div className="custom-control custom-switch">
            <input
              type="checkbox"
              className="custom-control-input"
              id="llm-memory-enabled"
              checked={val === '1'}
              onChange={e => onChange(name, e.target.checked ? '1' : '0')}
              disabled={disabled}
            />
            <label className="custom-control-label" htmlFor="llm-memory-enabled">
              {field.label}
            </label>
          </div>
          {field.help && <small className="form-text text-muted">{field.help}</small>}
        </div>
      );
    }

    if (name === 'llm_memory_storage_mode' && field.options) {
      return (
        <div className="form-group" key={name}>
          <label className="small font-weight-bold">{field.label}</label>
          <SearchableSelect
            options={field.options}
            value={val}
            onChange={v => onChange(name, v)}
            placeholder="-- Select Storage Mode --"
            disabled={disabled || !memoryEnabled}
          />
          {field.help && <small className="form-text text-muted">{field.help}</small>}
        </div>
      );
    }

    return (
      <div className="form-group" key={name}>
        <label className="small font-weight-bold">{field.label}</label>
        <input
          type="text"
          className="form-control form-control-sm"
          value={val}
          onChange={e => onChange(name, e.target.value)}
          disabled={disabled || !memoryEnabled}
        />
        {field.help && <small className="form-text text-muted">{field.help}</small>}
      </div>
    );
  };

  return (
    <div className="card mb-3">
      <div className="card-header">
        <h6 className="mb-0">
          <i className={`${iconClass} mr-2 text-muted`}></i>
          {title}
        </h6>
      </div>
      <div className="card-body">
        {renderField('llm_memory_enabled')}
        {(memoryEnabled || !hideDetailsWhenDisabled) && (
          <>
            {renderField('llm_memory_storage_mode')}
          </>
        )}
        {!memoryEnabled && (
          <p className="text-muted small mb-0 mt-2">
            Enable the memory system to configure memory settings.
          </p>
        )}
      </div>
      {footer && <div className="card-footer">{footer}</div>}
    </div>
  );
};
