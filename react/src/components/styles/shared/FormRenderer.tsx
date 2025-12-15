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
import Select from 'react-select';
import type { FormDefinition, FormField, FormFieldOption, FormSection, FormContentSection } from '../../../types';
import { formatFormSelectionsAsText, isFormContentSection, isFormField } from '../../../types';
import { MarkdownRenderer } from './MarkdownRenderer';

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
 * Uses full-width clickable option buttons for better UX
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
      <div className="form-options-container">
        {field.options.map((option: FormFieldOption) => (
          <div
            key={option.value}
            className={`form-option-button ${value === option.value ? 'selected' : ''} ${disabled ? 'disabled' : ''}`}
            onClick={() => !disabled && onChange(option.value)}
            role="button"
            tabIndex={disabled ? -1 : 0}
            onKeyDown={(e) => {
              if (!disabled && (e.key === 'Enter' || e.key === ' ')) {
                e.preventDefault();
                onChange(option.value);
              }
            }}
          >
            <div className="form-option-radio">
              <div className={`form-option-radio-inner ${value === option.value ? 'checked' : ''}`} />
            </div>
            <span className="form-option-label">{option.label}</span>
          </div>
        ))}
      </div>
    </Form.Group>
  );
};

/**
 * Checkbox Field Component
 * Uses full-width clickable option buttons for better UX
 */
const CheckboxField: React.FC<{
  field: FormField;
  values: string[];
  onChange: (values: string[]) => void;
  disabled?: boolean;
}> = ({ field, values, onChange, disabled }) => {
  const handleToggle = useCallback((optionValue: string) => {
    if (values.includes(optionValue)) {
      onChange(values.filter(v => v !== optionValue));
    } else {
      onChange([...values, optionValue]);
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
      <div className="form-options-container">
        {field.options.map((option: FormFieldOption) => {
          const isChecked = values.includes(option.value);
          return (
            <div
              key={option.value}
              className={`form-option-button ${isChecked ? 'selected' : ''} ${disabled ? 'disabled' : ''}`}
              onClick={() => !disabled && handleToggle(option.value)}
              role="button"
              tabIndex={disabled ? -1 : 0}
              onKeyDown={(e) => {
                if (!disabled && (e.key === 'Enter' || e.key === ' ')) {
                  e.preventDefault();
                  handleToggle(option.value);
                }
              }}
            >
              <div className="form-option-checkbox">
                {isChecked && <i className="fas fa-check" />}
              </div>
              <span className="form-option-label">{option.label}</span>
            </div>
          );
        })}
      </div>
    </Form.Group>
  );
};

/**
 * Select/Dropdown Field Component
 * Uses react-select for better UX
 */
const SelectField: React.FC<{
  field: FormField;
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
}> = ({ field, value, onChange, disabled }) => {
  const options = field.options.map((option: FormFieldOption) => ({
    value: option.value,
    label: option.label
  }));

  const selectedOption = options.find(opt => opt.value === value) || null;

  return (
    <Form.Group className="mb-3">
      <Form.Label className="font-weight-bold mb-2">
        {field.label}
        {field.required && <span className="text-danger ml-1">*</span>}
      </Form.Label>
      {field.helpText && (
        <Form.Text className="text-muted d-block mb-2">{field.helpText}</Form.Text>
      )}
      <Select
        value={selectedOption}
        onChange={(option) => onChange(option?.value || '')}
        options={options}
        isDisabled={disabled}
        placeholder="-- Select an option --"
        isClearable={!field.required}
        classNamePrefix="react-select"
        styles={{
          control: (base, state) => ({
            ...base,
            borderColor: state.isFocused ? '#80bdff' : '#ced4da',
            boxShadow: state.isFocused ? '0 0 0 0.2rem rgba(0, 123, 255, 0.25)' : 'none',
            '&:hover': {
              borderColor: state.isFocused ? '#80bdff' : '#ced4da'
            }
          }),
          option: (base, state) => ({
            ...base,
            backgroundColor: state.isSelected ? '#007bff' : state.isFocused ? '#e9ecef' : 'white',
            color: state.isSelected ? 'white' : '#212529',
            '&:active': {
              backgroundColor: '#007bff'
            }
          })
        }}
      />
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
   * Check if any field has a value
   */
  const hasAnySelection = useCallback((): boolean => {
    for (const field of formDefinition.fields) {
      const value = formValues[field.id];
      if (value && (typeof value === 'string' ? value.length > 0 : value.length > 0)) {
        return true;
      }
    }
    return false;
  }, [formDefinition, formValues]);

  /**
   * Validate form before submission
   */
  const validateForm = useCallback((): boolean => {
    const errors: Record<string, string> = {};
    
    // Check required fields
    for (const field of formDefinition.fields) {
      if (field.required) {
        const value = formValues[field.id];
        const isEmpty = Array.isArray(value) ? value.length === 0 : !value;
        
        if (isEmpty) {
          errors[field.id] = `Please select an option for "${field.label}"`;
        }
      }
    }
    
    // If no required fields but no selections at all, show general error
    if (Object.keys(errors).length === 0 && !hasAnySelection()) {
      // Find first field to attach error to
      if (formDefinition.fields.length > 0) {
        errors[formDefinition.fields[0].id] = 'Please select at least one option';
      }
    }
    
    setValidationErrors(errors);
    return Object.keys(errors).length === 0;
  }, [formDefinition, formValues, hasAnySelection]);

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
    
    // Double-check we have something to send
    if (!readableText || readableText === 'Form submitted (no selections)') {
      setValidationErrors({
        [formDefinition.fields[0]?.id || 'general']: 'Please select at least one option'
      });
      return;
    }
    
    onSubmit(formValues, readableText);
  }, [formDefinition, formValues, validateForm, onSubmit]);

  const isDisabled = disabled || isSubmitting;

  /**
   * Render a form field
   */
  const renderField = (field: FormField) => {
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
  };

  /**
   * Render a content section with markdown
   */
  const renderContentSection = (content: string, index: number) => (
    <div key={`content-${index}`} className="form-content-section mb-4">
      <MarkdownRenderer content={content} isStreaming={false} />
    </div>
  );

  /**
   * Render sections (mixed fields and content)
   */
  const renderSections = () => {
    // If sections array is provided, use it for mixed content
    if (formDefinition.sections && formDefinition.sections.length > 0) {
      return formDefinition.sections.map((section, index) => {
        if (isFormContentSection(section)) {
          return renderContentSection(section.content, index);
        } else if (isFormField(section)) {
          return renderField(section);
        }
        return null;
      });
    }

    // Otherwise, render contentBefore, fields, contentAfter
    return (
      <>
        {formDefinition.contentBefore && renderContentSection(formDefinition.contentBefore, -1)}
        {formDefinition.fields.map(field => renderField(field))}
        {formDefinition.contentAfter && renderContentSection(formDefinition.contentAfter, 999)}
      </>
    );
  };

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
          {renderSections()}
          
          {/* Only show submit button if there are form fields */}
          {formDefinition.fields.length > 0 && (
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
          )}
        </Form>
      </Card.Body>
    </Card>
  );
};

export default FormRenderer;

