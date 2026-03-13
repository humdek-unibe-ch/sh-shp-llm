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

export const HumanReviewPanel: React.FC<HumanReviewPanelProps> = ({
  draft,
  disabled = false,
  onDraftChange,
  onSave,
}) => (
  <div className="prompt-human-review-grid">
    <Form.Control
      size="sm"
      type="number"
      min={1}
      max={5}
      value={draft.numeric}
      onChange={(event) => onDraftChange({ ...draft, numeric: event.target.value })}
      placeholder="1-5"
    />
    <Form.Control
      size="sm"
      value={draft.label}
      onChange={(event) => onDraftChange({ ...draft, label: event.target.value })}
      placeholder="label"
    />
    <Form.Control
      as="select"
      size="sm"
      value={draft.passed}
      onChange={(event) => onDraftChange({ ...draft, passed: event.target.value })}
    >
      <option value="">Pending</option>
      <option value="1">Pass</option>
      <option value="0">Fail</option>
    </Form.Control>
    <Form.Control
      size="sm"
      value={draft.reason}
      onChange={(event) => onDraftChange({ ...draft, reason: event.target.value })}
      placeholder="reason"
    />
    <Button size="sm" variant="outline-primary" disabled={disabled} onClick={onSave}>
      {disabled ? 'Saving...' : 'Save'}
    </Button>
  </div>
);
