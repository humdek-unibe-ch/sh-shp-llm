import React from 'react';
import { SearchableSelect } from './SearchableSelect';

interface FieldDef {
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
  models: { id: string; name?: string }[];
  disabled?: boolean;
}

export const ModelDefaultsSection: React.FC<Props> = ({ getField, getVal, onChange, models, disabled }) => {
  const renderField = (name: string) => {
    const field = getField(name);
    if (!field) return null;
    const val = getVal(name);

    if (name === 'llm_default_model') {
      const modelOptions = models.map(m => ({ value: m.id, label: m.name || m.id }));
      return (
        <div className="form-group" key={name}>
          <label className="small font-weight-bold">{field.label}</label>
          <SearchableSelect
            options={modelOptions}
            value={val}
            onChange={v => onChange(name, v)}
            placeholder="-- Select Model --"
            disabled={disabled}
          />
          {field.help && <small className="form-text text-muted">{field.help}</small>}
        </div>
      );
    }

    if (name === 'llm_temperature') {
      const numVal = parseFloat(val) || 0;
      return (
        <div className="form-group" key={name}>
          <label className="small font-weight-bold">
            {field.label}: <span className="text-primary">{numVal.toFixed(1)}</span>
          </label>
          <input
            type="range"
            className="custom-range"
            min="0"
            max="2"
            step="0.1"
            value={numVal}
            onChange={e => onChange(name, e.target.value)}
            disabled={disabled}
          />
          {field.help && <small className="form-text text-muted">{field.help}</small>}
        </div>
      );
    }

    return (
      <div className="form-group" key={name}>
        <label className="small font-weight-bold">{field.label}</label>
        <input
          type={field.type === 'number' ? 'number' : 'text'}
          className="form-control form-control-sm"
          value={val}
          onChange={e => onChange(name, e.target.value)}
          disabled={disabled}
        />
        {field.help && <small className="form-text text-muted">{field.help}</small>}
      </div>
    );
  };

  return (
    <div className="card mb-3">
      <div className="card-header">
        <h6 className="mb-0">
          <i className="fa fa-sliders-h mr-2 text-muted"></i>
          Model Defaults
        </h6>
      </div>
      <div className="card-body">
        {renderField('llm_default_model')}
        {renderField('llm_temperature')}
        {renderField('llm_max_tokens')}
        {renderField('llm_timeout')}
      </div>
    </div>
  );
};
