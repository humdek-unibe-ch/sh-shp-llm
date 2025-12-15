/**
 * Form Display Component
 * ======================
 * 
 * Displays completed form submissions in a read-only format.
 * Shows the form structure with the user's selections highlighted.
 * Used in both chat history and admin console.
 * 
 * @module components/FormDisplay
 */

import React from 'react';
import { Card, Badge } from 'react-bootstrap';
import type { FormDefinition, FormField, FormFieldOption } from '../../../types';

/**
 * Props for FormDisplay component
 */
interface FormDisplayProps {
  /** Form definition from LLM response */
  formDefinition: FormDefinition;
  /** User's submitted values */
  submittedValues?: Record<string, string | string[]>;
  /** Whether to show in compact mode */
  compact?: boolean;
}

/**
 * Display a single field with the user's selection
 */
const FieldDisplay: React.FC<{
  field: FormField;
  value?: string | string[];
  compact?: boolean;
}> = ({ field, value, compact }) => {
  const hasValue = value && (Array.isArray(value) ? value.length > 0 : value.length > 0);
  
  // Get selected labels for selection fields
  const getSelectedLabels = (): string[] => {
    if (!value || !field.options) return [];
    const values = Array.isArray(value) ? value : [value];
    return values.map(v => {
      const option = field.options?.find(opt => opt.value === v);
      return option?.label || v;
    });
  };

  // For text/number fields, just show the value
  if (field.type === 'text' || field.type === 'textarea' || field.type === 'number') {
    return (
      <div className={`mb-${compact ? '2' : '3'}`}>
        <div className="d-flex align-items-start">
          <div className="flex-grow-1">
            <div className={`${compact ? 'small' : ''} text-muted mb-1`}>
              {field.label}
            </div>
            {hasValue ? (
              <div className={`${compact ? 'small' : ''} font-weight-bold text-dark`}>
                {value as string}
              </div>
            ) : (
              <div className={`${compact ? 'small' : ''} text-muted font-italic`}>
                Not answered
              </div>
            )}
          </div>
        </div>
      </div>
    );
  }

  // For selection fields, show options with selected ones highlighted
  const selectedLabels = getSelectedLabels();

  if (compact) {
    return (
      <div className="mb-2">
        <div className="small text-muted mb-1">{field.label}</div>
        {hasValue ? (
          <div className="small font-weight-bold text-dark">
            {selectedLabels.join(', ')}
          </div>
        ) : (
          <div className="small text-muted font-italic">Not answered</div>
        )}
      </div>
    );
  }

  return (
    <div className="mb-3">
      <div className="text-muted mb-2">{field.label}</div>
      <div className="d-flex flex-wrap" style={{ gap: '8px' }}>
        {(field.options || []).map((option: FormFieldOption) => {
          const isSelected = Array.isArray(value) 
            ? value.includes(option.value) 
            : value === option.value;
          
          return (
            <div
              key={option.value}
              className={`px-3 py-2 rounded border ${
                isSelected 
                  ? 'bg-primary text-white border-primary' 
                  : 'bg-light text-muted border-light'
              }`}
              style={{ 
                fontSize: '0.875rem',
                opacity: isSelected ? 1 : 0.6
              }}
            >
              {isSelected && (
                <i className="fas fa-check mr-2"></i>
              )}
              {option.label}
            </div>
          );
        })}
      </div>
    </div>
  );
};

/**
 * Form Display Component
 * 
 * Renders a completed form with the user's selections
 */
export const FormDisplay: React.FC<FormDisplayProps> = ({
  formDefinition,
  submittedValues = {},
  compact = false
}) => {
  return (
    <Card className={`border-0 ${compact ? 'shadow-none' : 'shadow-sm'} mb-3`}>
      <Card.Header className={`bg-light border-bottom ${compact ? 'py-2 px-3' : ''}`}>
        <div className="d-flex align-items-center justify-content-between">
          <div className="d-flex align-items-center">
            <i className="fas fa-check-circle text-success mr-2"></i>
            <span className={`${compact ? 'small' : ''} font-weight-bold text-dark`}>
              {formDefinition.title || 'Form Response'}
            </span>
          </div>
          <Badge variant="success" className={compact ? 'small' : ''}>
            Submitted
          </Badge>
        </div>
        {formDefinition.description && !compact && (
          <p className="mb-0 mt-1 text-muted small">{formDefinition.description}</p>
        )}
      </Card.Header>
      <Card.Body className={compact ? 'py-2 px-3' : ''}>
        {formDefinition.fields.map(field => (
          <FieldDisplay
            key={field.id}
            field={field}
            value={submittedValues[field.id]}
            compact={compact}
          />
        ))}
      </Card.Body>
    </Card>
  );
};

/**
 * Compact inline form summary (for message lists)
 */
export const FormSummaryInline: React.FC<{
  formDefinition: FormDefinition;
  submittedValues?: Record<string, string | string[]>;
}> = ({ formDefinition, submittedValues = {} }) => {
  // Get all answered fields with their values
  const answeredFields = formDefinition.fields.filter(field => {
    const value = submittedValues[field.id];
    return value && (Array.isArray(value) ? value.length > 0 : value.length > 0);
  });

  const getDisplayValue = (field: FormField): string => {
    const value = submittedValues[field.id];
    if (!value) return '';
    
    if (field.type === 'text' || field.type === 'textarea' || field.type === 'number') {
      return value as string;
    }
    
    const values = Array.isArray(value) ? value : [value];
    return values.map(v => {
      const option = field.options?.find(opt => opt.value === v);
      return option?.label || v;
    }).join(', ');
  };

  return (
    <div className="form-summary-inline bg-light rounded p-3 border">
      <div className="d-flex align-items-center mb-2">
        <i className="fas fa-check-circle text-success mr-2"></i>
        <strong className="text-dark">{formDefinition.title || 'Form Response'}</strong>
        <Badge variant="success" className="ml-2 small">Submitted</Badge>
      </div>
      
      {answeredFields.length > 0 ? (
        <div className="small">
          {answeredFields.map((field, index) => (
            <div key={field.id} className={index < answeredFields.length - 1 ? 'mb-1' : ''}>
              <span className="text-muted">{field.label}:</span>{' '}
              <span className="font-weight-bold text-dark">{getDisplayValue(field)}</span>
            </div>
          ))}
        </div>
      ) : (
        <div className="small text-muted font-italic">No selections made</div>
      )}
    </div>
  );
};

export default FormDisplay;

