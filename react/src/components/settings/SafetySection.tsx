/**
 * Safety Section — global danger detection settings.
 *
 * Danger detection is always on for every LLM section. Here admins set the
 * global keywords, the alert recipients and the blocked message (one text per
 * system language). A section can replace each value in its own field.
 *
 * @module components/settings/SafetySection
 */
import React, { useState } from 'react';

interface LanguageDef {
  id: number;
  locale: string;
  language: string;
}

interface FieldDef {
  name: string;
  label: string;
  help: string;
  value: any;
  languages?: LanguageDef[];
}

interface Props {
  getField: (name: string) => FieldDef | undefined;
  getVal: (name: string) => string;
  /** Blocked message texts by language id (saved + unsaved). */
  messages: Record<string, string>;
  onChange: (name: string, value: string | Record<string, string>) => void;
  disabled?: boolean;
}

/** SafetySection component. */
export const SafetySection: React.FC<Props> = ({ getField, getVal, messages, onChange, disabled }) => {
  const messageField = getField('danger_blocked_message');
  const languages = messageField?.languages ?? [];
  const [languageId, setLanguageId] = useState<string>(languages.length > 0 ? String(languages[0].id) : '');

  const renderTextarea = (name: string, rows: number) => {
    const field = getField(name);
    if (!field) return null;
    return (
      <div className="form-group">
        <label className="small font-weight-bold" htmlFor={`llm-${name}`}>{field.label}</label>
        <textarea
          id={`llm-${name}`}
          className="form-control form-control-sm"
          rows={rows}
          value={getVal(name)}
          onChange={e => onChange(name, e.target.value)}
          disabled={disabled}
        />
        {field.help && <small className="form-text text-muted">{field.help}</small>}
      </div>
    );
  };

  const noEmails = getField('danger_notification_emails') !== undefined
    && getVal('danger_notification_emails').trim() === '';

  return (
    <div className="card mb-3">
      <div className="card-header">
        <h6 className="mb-0">
          <i className="fa fa-shield-alt mr-2 text-muted"></i>
          Safety
        </h6>
      </div>
      <div className="card-body">
        {renderTextarea('danger_keywords', 4)}
        {renderTextarea('danger_notification_emails', 3)}
        {noEmails && (
          <div className="alert alert-warning small py-2">
            No notification email set - detections are only written to the audit log.
          </div>
        )}

        {messageField && (
          <div className="form-group">
            <div className="d-flex align-items-center mb-1">
              <label className="small font-weight-bold mb-0 mr-2" htmlFor="llm-danger_blocked_message">
                {messageField.label}
              </label>
              <select
                className="form-control form-control-sm w-auto"
                aria-label="Language of the blocked message"
                value={languageId}
                onChange={e => setLanguageId(e.target.value)}
              >
                {languages.map(l => (
                  <option key={l.id} value={String(l.id)}>{l.language} ({l.locale})</option>
                ))}
              </select>
            </div>
            <textarea
              id="llm-danger_blocked_message"
              className="form-control form-control-sm"
              rows={6}
              value={messages[languageId] ?? ''}
              onChange={e => onChange('danger_blocked_message', { ...messages, [languageId]: e.target.value })}
              disabled={disabled || languageId === ''}
            />
            {messageField.help && <small className="form-text text-muted">{messageField.help}</small>}
          </div>
        )}
      </div>
    </div>
  );
};
