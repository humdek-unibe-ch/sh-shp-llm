/**
 * Form Renderer Component
 * =======================
 * 
 * Renders JSON Schema-based forms returned by the LLM in form mode.
 * Supports radio buttons, checkboxes, and dropdowns with Bootstrap 4.6 styling.
 * 
 * @module components/FormRenderer
 */

import React, { useState, useCallback, useMemo } from 'react';
import { Form, Button, Card } from 'react-bootstrap';
import type { FormDefinition, FormField, FormFieldOption } from '../../types';
import { formatFormSelectionsAsText } from '../../types';

/**
 * Props for FormRenderer component
 */
interface FormRendererProps {
  /** Form definition from LLM response */
  formDefinition: FormDefinition;
  /** Callback when form is submitted */
  onSubmit: (values: Record<string, string | string[]>, readableText: string) => void;
  /** Whether form submission is in progress */
  isSubmitting?: boolean;
  /** Whether the form is disabled */
  disabled?: boolean;
}

/**
 * Radio Field Component
 */
const RadioField: React.FC<{
  field: FormField;
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
}> = ({ field, value, onChange, disabled }) => {
  return (
    <Form.Group className="mb-3">
      <Form.Label className="font-weight-bold mb-2">
        {field.label}
        {field.required && <span className="text-danger ml-1">*</span>}
      </Form.Label>
      {field.helpText && (
        <Form.Text className="text-muted d-block mb-2">{field.helpText}</Form.Text>
      )}
      <div className="pl-2">
        {field.options.map((option: FormFieldOption) => (
          <Form.Check
            key={option.value}
            type="radio"
            id={`${field.id}-${option.value}`}
            name={field.id}
            label={option.label}
            value={option.value}
            checked={value === option.value}
            onChange={(e) => onChange(e.target.value)}
            disabled={disabled}
            className="mb-2 form-radio-option"
            custom
          />
        ))}
      </div>
    </Form.Group>
  );
};

/**
 * Checkbox Field Component
 */
const CheckboxField: React.FC<{
  field: FormField;
  values: string[];
  onChange: (values: string[]) => void;
  disabled?: boolean;
}> = ({ field, values, onChange, disabled }) => {
  const handleChange = useCallback((optionValue: string, checked: boolean) => {
    if (checked) {
      onChange([...values, optionValue]);
    } else {
      onChange(values.filter(v => v !== optionValue));
    }
  }, [values, onChange]);

  return (
    <Form.Group className="mb-3">
      <Form.Label className="font-weight-bold mb-2">
        {field.label}
        {field.required && <span className="text-danger ml-1">*</span>}
      </Form.Label>
      {field.helpText && (
        <Form.Text className="text-muted d-block mb-2">{field.helpText}</Form.Text>
      )}
      <div className="pl-2">
        {field.options.map((option: FormFieldOption) => (
          <Form.Check
            key={option.value}
            type="checkbox"
            id={`${field.id}-${option.value}`}
            label={option.label}
            checked={values.includes(option.value)}
            onChange={(e) => handleChange(option.value, e.target.checked)}
            disabled={disabled}
            className="mb-2 form-checkbox-option"
            custom
          />
        ))}
      </div>
    </Form.Group>
  );
};

/**
 * Select/Dropdown Field Component
 */
const SelectField: React.FC<{
  field: FormField;
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
}> = ({ field, value, onChange, disabled }) => {
  return (
    <Form.Group className="mb-3">
      <Form.Label className="font-weight-bold mb-2">
        {field.label}
        {field.required && <span className="text-danger ml-1">*</span>}
      </Form.Label>
      {field.helpText && (
        <Form.Text className="text-muted d-block mb-2">{field.helpText}</Form.Text>
      )}
      <Form.Control
        as="select"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        disabled={disabled}
        className="form-select-field"
        custom
      >
        <option value="">-- Select an option --</option>
        {field.options.map((option: FormFieldOption) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </Form.Control>
    </Form.Group>
  );
};

/**
 * Form Renderer Component
 * 
 * Renders a complete form based on JSON Schema definition
 */
export const FormRenderer: React.FC<FormRendererProps> = ({
  formDefinition,
  onSubmit,
  isSubmitting = false,
  disabled = false
}) => {
  // Initialize form values state
  const initialValues = useMemo(() => {
    const values: Record<string, string | string[]> = {};
    for (const field of formDefinition.fields) {
      if (field.type === 'checkbox') {
        values[field.id] = [];
      } else {
        values[field.id] = '';
      }
    }
    return values;
  }, [formDefinition]);

  const [formValues, setFormValues] = useState<Record<string, string | string[]>>(initialValues);
  const [validationErrors, setValidationErrors] = useState<Record<string, string>>({});

  /**
   * Update a field value
   */
  const updateFieldValue = useCallback((fieldId: string, value: string | string[]) => {
    setFormValues(prev => ({
      ...prev,
      [fieldId]: value
    }));
    // Clear validation error when user makes a selection
    if (validationErrors[fieldId]) {
      setValidationErrors(prev => {
        const updated = { ...prev };
        delete updated[fieldId];
        return updated;
      });
    }
  }, [validationErrors]);

  /**
   * Validate form before submission
   */
  const validateForm = useCallback((): boolean => {
    const errors: Record<string, string> = {};
    
    for (const field of formDefinition.fields) {
      if (field.required) {
        const value = formValues[field.id];
        const isEmpty = Array.isArray(value) ? value.length === 0 : !value;
        
        if (isEmpty) {
          errors[field.id] = `Please select an option for "${field.label}"`;
        }
      }
    }
    
    setValidationErrors(errors);
    return Object.keys(errors).length === 0;
  }, [formDefinition, formValues]);

  /**
   * Handle form submission
   */
  const handleSubmit = useCallback((e: React.FormEvent) => {
    e.preventDefault();
    
    if (!validateForm()) {
      return;
    }
    
    // Generate readable text from selections
    const readableText = formatFormSelectionsAsText(formDefinition, formValues);
    
    onSubmit(formValues, readableText);
  }, [formDefinition, formValues, validateForm, onSubmit]);

  const isDisabled = disabled || isSubmitting;

  return (
    <Card className="llm-form-card border-0 shadow-sm mb-3">
      {(formDefinition.title || formDefinition.description) && (
        <Card.Header className="bg-light border-bottom">
          {formDefinition.title && (
            <h5 className="mb-1 text-primary">
              <i className="fas fa-list-ul mr-2"></i>
              {formDefinition.title}
            </h5>
          )}
          {formDefinition.description && (
            <p className="mb-0 text-muted small">{formDefinition.description}</p>
          )}
        </Card.Header>
      )}
      <Card.Body>
        <Form onSubmit={handleSubmit}>
          {formDefinition.fields.map((field: FormField) => {
            const error = validationErrors[field.id];
            
            return (
              <div key={field.id} className={error ? 'has-validation-error' : ''}>
                {field.type === 'radio' && (
                  <RadioField
                    field={field}
                    value={formValues[field.id] as string}
                    onChange={(value) => updateFieldValue(field.id, value)}
                    disabled={isDisabled}
                  />
                )}
                {field.type === 'checkbox' && (
                  <CheckboxField
                    field={field}
                    values={formValues[field.id] as string[]}
                    onChange={(values) => updateFieldValue(field.id, values)}
                    disabled={isDisabled}
                  />
                )}
                {field.type === 'select' && (
                  <SelectField
                    field={field}
                    value={formValues[field.id] as string}
                    onChange={(value) => updateFieldValue(field.id, value)}
                    disabled={isDisabled}
                  />
                )}
                {error && (
                  <div className="text-danger small mb-2 mt-n2">
                    <i className="fas fa-exclamation-circle mr-1"></i>
                    {error}
                  </div>
                )}
              </div>
            );
          })}
          
          <div className="d-flex justify-content-end mt-4 pt-3 border-top">
            <Button
              type="submit"
              variant="primary"
              disabled={isDisabled}
              className="px-4"
            >
              {isSubmitting ? (
                <>
                  <span className="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span>
                  Sending...
                </>
              ) : (
                <>
                  <i className="fas fa-paper-plane mr-2"></i>
                  {formDefinition.submitLabel || 'Submit'}
                </>
              )}
            </Button>
          </div>
        </Form>
      </Card.Body>
    </Card>
  );
};

export default FormRenderer;

