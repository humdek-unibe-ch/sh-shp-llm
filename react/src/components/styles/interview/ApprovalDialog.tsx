/**
 * Approval Dialog
 * ===============
 *
 * The confirmation before an interview is written (CMS:
 * `llm_interview_confirm`). A new entry shows the record that will be
 * saved; a saved entry that is being revised shows exactly what changed,
 * each answer before and after, plus how many stayed as they were.
 *
 * Layout contract: never taller or wider than 90% of the viewport; the
 * header and the buttons stay in view and only the body scrolls.
 */

import React from 'react';
import { Modal, Spinner } from 'react-bootstrap';
import type { InterviewUiLabels } from '../../../types/interview';
import type { ReviewRow } from './interviewUtils';
import { fillTemplate } from './interviewUtils';

interface ApprovalDialogProps {
  show: boolean;
  title: string;
  /** Text above the record (core confirmation message); may be empty. */
  message: string;
  mode: 'new' | 'edit';
  rows: ReviewRow[];
  labels: InterviewUiLabels;
  confirmLabel: string;
  cancelLabel: string;
  /** The save request is running. */
  busy: boolean;
  /** Section theme variables, copied onto the body-level modal. */
  theme: React.CSSProperties;
  /** Section classes, copied onto the body-level modal for author CSS. */
  themeClass: string;
  sectionId: number;
  onCancel: () => void;
  onConfirm: () => void;
}

const Empty: React.FC<{ text: string }> = ({ text }) => (
  <em className="llm-iv-empty">{text}</em>
);

export const ApprovalDialog: React.FC<ApprovalDialogProps> = ({
  show,
  title,
  message,
  mode,
  rows,
  labels,
  confirmLabel,
  cancelLabel,
  busy,
  theme,
  themeClass,
  sectionId,
  onCancel,
  onConfirm,
}) => {
  const edit = mode === 'edit';
  const changed = rows.filter((r) => r.changed);
  const unchanged = rows.length - changed.length;

  return (
    <Modal
      show={show}
      onHide={busy ? () => undefined : onCancel}
      animation={false}
      centered
      scrollable
      backdrop={busy ? 'static' : true}
      className={`llm-iv-modal llm-iv-modal-nested ${themeClass}`.trim()}
      backdropClassName="llm-iv-backdrop-nested"
      dialogClassName="llm-iv-dialog"
      data-llm-iv-modal={sectionId}
      style={theme}
      aria-label={title}
    >
      <Modal.Header closeButton={!busy}>
        <Modal.Title>{title}</Modal.Title>
      </Modal.Header>
      <Modal.Body className="llm-slim-scroll">
        {message ? <p className="llm-iv-confirm-message">{message}</p> : null}

        {edit ? (
          <>
            <p className="llm-iv-review-intro">{labels.reviewChanges}</p>
            <div className="llm-iv-changes">
              {changed.map((row) => (
                <div className="llm-iv-change" key={row.name}>
                  <div className="llm-iv-change-label">{row.label}</div>
                  <div className="llm-iv-change-values">
                    <div className="llm-iv-change-before">
                      <span className="llm-iv-change-caption">{labels.valueBefore}</span>
                      <span className="llm-iv-change-text">
                        {row.before !== '' ? row.before : <Empty text={labels.valueEmpty} />}
                      </span>
                    </div>
                    <span className="llm-iv-change-arrow" aria-hidden="true">
                      <i className="fas fa-arrow-right" />
                    </span>
                    <div className="llm-iv-change-after">
                      <span className="llm-iv-change-caption">{labels.valueAfter}</span>
                      <span className="llm-iv-change-text">
                        {row.after !== '' ? row.after : <Empty text={labels.valueEmpty} />}
                      </span>
                    </div>
                  </div>
                </div>
              ))}
            </div>
            {unchanged > 0 ? (
              <p className="llm-iv-review-unchanged small text-muted mb-0">
                {fillTemplate(labels.reviewUnchanged, { count: unchanged })}
              </p>
            ) : null}
          </>
        ) : (
          <>
            <p className="llm-iv-review-intro">{labels.reviewValues}</p>
            <dl className="llm-iv-record">
              {rows.map((row) => (
                <div className="llm-iv-record-row" key={row.name}>
                  <dt>{row.label}</dt>
                  <dd>{row.after !== '' ? row.after : <Empty text={labels.valueEmpty} />}</dd>
                </div>
              ))}
            </dl>
          </>
        )}
      </Modal.Body>
      <Modal.Footer>
        <button type="button" className="btn btn-secondary" onClick={onCancel} disabled={busy}>
          {cancelLabel}
        </button>
        <button type="button" className="btn btn-primary" onClick={onConfirm} disabled={busy}>
          {busy ? <Spinner animation="border" size="sm" /> : confirmLabel}
        </button>
      </Modal.Footer>
    </Modal>
  );
};

export default ApprovalDialog;
