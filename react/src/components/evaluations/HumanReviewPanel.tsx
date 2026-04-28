/**
 * Human Review Panel — manual scoring interface for evaluation cases.
 *
 * Displays the case output and provides score inputs, notes, and
 * submit/skip buttons for cases that require human judgment.
 *
 * @module components/evaluations/HumanReviewPanel
 */
import React from 'react';
import { Button, Form } from 'react-bootstrap';

interface HumanReviewDraft {
  numeric: string;
  label: string;
  passed: string;
  reason: string;
}

interface HumanReviewPanelProps {
  draft: HumanReviewDraft;
  disabled?: boolean;
  onDraftChange: (nextDraft: HumanReviewDraft) => void;
  onSave: () => void;
}

/** Panel component for human review panel. */
export const HumanReviewPanel: React.FC<HumanReviewPanelProps> = ({
  draft,
  disabled = false,
  onDraftChange,
  onSave,
}) => (
  <div>
    <div className="small text-muted mb-1">
      Human review:
    </div>
    <div className="prompt-human-review-grid">
      <Form.Control
        size="sm"
        type="number"
        min={1}
        max={5}
        value={draft.numeric}
        onChange={(event) => onDraftChange({ ...draft, numeric: event.target.value })}
        placeholder="Score (1-5)"
        title="Numeric quality score from 1 to 5"
      />
      <Form.Control
        size="sm"
        value={draft.label}
        onChange={(event) => onDraftChange({ ...draft, label: event.target.value })}
        placeholder="Label (optional)"
        title="Optional short label, e.g. helpful / unclear"
      />
      <Form.Control
        as="select"
        size="sm"
        value={draft.passed}
        onChange={(event) => onDraftChange({ ...draft, passed: event.target.value })}
        title="Final decision for this evaluator"
      >
        <option value="">Decision: Pending</option>
        <option value="1">Decision: Pass</option>
        <option value="0">Decision: Fail</option>
      </Form.Control>
      <Form.Control
        size="sm"
        value={draft.reason}
        onChange={(event) => onDraftChange({ ...draft, reason: event.target.value })}
        placeholder="Reason / notes"
        title="Why this score was chosen"
      />
      <Button size="sm" variant="outline-primary" disabled={disabled} onClick={onSave}>
        {disabled ? 'Saving...' : 'Save'}
      </Button>
    </div>
  </div>
);
