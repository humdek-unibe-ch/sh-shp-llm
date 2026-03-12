import React from 'react';
import { Form, Row } from 'react-bootstrap';
import { PromptEditor } from './PromptEditor';
import type { PromptVariableDefinition } from './promptTypes';

interface PromptVariableInputsProps {
  schema: PromptVariableDefinition[];
  values: Record<string, unknown>;
  onChange: (name: string, value: unknown) => void;
}

function normalizeValue(value: unknown, type?: string): string | number {
  if (value == null) {
    return '';
  }

  if (type === 'number' || type === 'integer') {
    return Number(value);
  }

  if (typeof value === 'object') {
    return JSON.stringify(value, null, 2);
  }

  return String(value);
}

export const PromptVariableInputs: React.FC<PromptVariableInputsProps> = ({
  schema,
  values,
  onChange,
}) => {
  if (!schema.length) {
    return <div className="small text-muted">No prompt variables detected.</div>;
  }

  return (
    <Row>
      {schema.map((variable) => {
        const type = variable.type || 'string';
        const isMultiline = type === 'string';
        const controlValue = normalizeValue(values[variable.name], type);

        return (
          <div key={variable.name} className="col-12 mb-2">
            <Form.Group className="mb-0">
              <Form.Label className="small font-weight-bold mb-1">
                {variable.name}
                {variable.required ? <span className="text-danger ml-1">*</span> : null}
              </Form.Label>
              {type === 'boolean' ? (
                <Form.Check
                  type="checkbox"
                  checked={String(values[variable.name] ?? '') === 'true' || values[variable.name] === true}
                  onChange={(event) => onChange(variable.name, event.target.checked)}
                  label={<span className="small">Enabled</span>}
                />
              ) : (
                <>
                  {isMultiline ? (
                    <PromptEditor
                      value={String(controlValue || '')}
                      onChange={(value) => onChange(variable.name, value)}
                      editorMode="textarea"
                      language="markdown"
                      rows={4}
                      className="prompt-variable-editor"
                      placeholder={variable.description || `${variable.name} value`}
                    />
                  ) : (
                    <Form.Control
                      size="sm"
                      type={type === 'number' || type === 'integer' ? 'number' : 'text'}
                      value={controlValue}
                      onChange={(event) => onChange(variable.name, event.target.value)}
                      placeholder={variable.description || `${variable.name} value`}
                    />
                  )}
                </>
              )}
              {variable.description && (
                <Form.Text className="text-muted">{variable.description}</Form.Text>
              )}
            </Form.Group>
          </div>
        );
      })}
    </Row>
  );
};
