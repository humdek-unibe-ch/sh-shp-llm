/**
 * Prompts hub conflict dialog: the prompt was changed by someone else while the user was
 * editing (or looking at the history). Nothing was saved. For a save the dialog sets what is
 * live now against the user's text and lets the user take the live text or knowingly keep
 * editing; a prompt is never overwritten silently.
 *
 * @module components/promptsHub/PromptConflictModal
 */
import React from 'react';
import { Alert, Button, Col, Modal, Row } from 'react-bootstrap';
import { PromptDiffViewer } from '../prompts/PromptDiffViewer';
import type { PromptLiveState } from './promptsHubTypes';
import { formatWhen, toLf } from './promptsHubUtils';

interface PromptConflictModalProps {
  /** What is live now */
  live: PromptLiveState;
  /** The text the user was about to save; null for a restore, which has no text of the user's */
  mine: string | null;
  /** Reloading is in progress */
  busy: boolean;
  /** Take what is live now and drop the user's text */
  onReload: () => void;
  /** Keep the user's text; the next save replaces what is live now (null where that makes no sense) */
  onKeepEditing: (() => void) | null;
  /** Close without choosing: nothing changes, a later save is checked against the old state again */
  onCancel: () => void;
}

/** "Jane Admin saved v8 at Nov 20, 2:03 PM" */
function whoSavedWhat(live: PromptLiveState): string {
  const who = live.updated_by ? live.updated_by : 'Someone';
  const what = live.version_no !== null && live.version_no > 0 ? `v${live.version_no}` : 'a new text';
  const when = live.updated_at ? ` at ${formatWhen(live.updated_at)}` : '';
  return `${who} saved ${what}${when}`;
}

/** The dialog. */
export const PromptConflictModal: React.FC<PromptConflictModalProps> = ({
  live,
  mine,
  busy,
  onReload,
  onKeepEditing,
  onCancel,
}) => {
  const comparing = mine !== null;

  return (
    <Modal
      show
      onHide={busy ? () => undefined : onCancel}
      centered
      dialogClassName={comparing ? 'prompt-modal-90 prompt-diff-modal' : undefined}
    >
      <Modal.Header closeButton className="py-2">
        <Modal.Title className="h6">
          <i className="fas fa-exclamation-triangle mr-2 text-warning" aria-hidden="true"></i>
          This prompt was changed by someone else
        </Modal.Title>
      </Modal.Header>
      <Modal.Body>
        <Alert variant="warning" className="py-2 small">
          <strong>{whoSavedWhat(live)}</strong> while you were working on it. Nothing was saved.
          {comparing
            ? ' Compare the live text with yours, then choose what happens to your text.'
            : ' Reload the live text, then restore the version again.'}
        </Alert>
        {comparing && (
          <>
            <Row className="small text-muted mb-2">
              <Col>Live now{live.version_no ? ` (v${live.version_no})` : ''}</Col>
              <Col className="text-right">Your text</Col>
            </Row>
            <PromptDiffViewer leftContent={toLf(live.content)} rightContent={toLf(mine)} className="prompt-diff-monaco flex-grow-1" />
          </>
        )}
      </Modal.Body>
      <Modal.Footer className="py-2">
        <Button variant="outline-secondary" size="sm" onClick={onReload} disabled={busy}>
          {comparing ? 'Reload theirs (drop my text)' : 'Reload theirs'}
        </Button>
        {onKeepEditing !== null && (
          <Button variant="primary" size="sm" onClick={onKeepEditing} disabled={busy}>
            Keep editing
          </Button>
        )}
        {onKeepEditing === null && (
          <Button variant="secondary" size="sm" onClick={onCancel} disabled={busy}>
            Close
          </Button>
        )}
      </Modal.Footer>
    </Modal>
  );
};
