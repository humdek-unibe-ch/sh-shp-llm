/**
 * Prompt Versions Modal — browse and restore prompt version history.
 *
 * Displays a table of all saved versions with timestamps, labels,
 * published status, and restore/diff actions.
 *
 * @module components/prompts/PromptVersionsModal
 */
import React from 'react';
import { Badge, Button, Modal, Table } from 'react-bootstrap';
import { formatDateTime } from '../../utils/formatters';
import type { PromptVersion } from './promptTypes';

/** What the table shows of a version (the Prompt Lab passes full versions, the Prompts hub passes just these). */
export type PromptVersionRow = Pick<PromptVersion, 'id' | 'version_no' | 'created_at' | 'created_user_name' | 'change_note'>;

interface PromptVersionsModalProps<V extends PromptVersionRow> {
  show: boolean;
  onHide: () => void;
  versions: V[];
  activeVersionId?: number | null;
  disabled?: boolean;
  /** Disables only the "use" button, e.g. where a version can be read but not restored */
  useDisabled?: boolean;
  /** Text of the "use" button */
  useLabel?: string;
  onUseVersion: (version: V) => void;
  onCompareVersion: (version: V) => void;
}

/** Modal dialog for prompt versions modal. */
export function PromptVersionsModal<V extends PromptVersionRow = PromptVersion>({
  show,
  onHide,
  versions,
  activeVersionId,
  disabled = false,
  useDisabled = false,
  useLabel = 'Use',
  onUseVersion,
  onCompareVersion,
}: PromptVersionsModalProps<V>): React.ReactElement {
  return (
    <Modal show={show} onHide={onHide} centered dialogClassName="prompt-modal-90 prompt-versions-modal">
      <Modal.Header closeButton className="py-2">
        <Modal.Title className="h6">
          <i className="fas fa-history mr-2"></i>
          Prompt Versions
        </Modal.Title>
      </Modal.Header>
      <Modal.Body className="p-0">
        {versions.length === 0 ? (
          <div className="p-4 text-center text-muted small">No saved versions yet.</div>
        ) : (
          <Table hover responsive size="sm" className="mb-0 prompt-versions-table">
            <thead>
              <tr>
                <th>Version</th>
                <th>When</th>
                <th>Who</th>
                <th>Comment</th>
                <th className="text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {versions.map((version) => {
                const isActive = version.id === activeVersionId;
                return (
                  <tr key={version.id}>
                    <td>
                      <span className="font-weight-bold">v{version.version_no}</span>
                      {isActive && (
                        <Badge variant="success" className="ml-2">
                          Active
                        </Badge>
                      )}
                    </td>
                    <td className="small text-muted">{formatDateTime(version.created_at)}</td>
                    <td className="small">{version.created_user_name || '-'}</td>
                    <td className="small">{version.change_note || <span className="text-muted">-</span>}</td>
                    <td className="text-right">
                      <Button
                        size="sm"
                        variant="outline-secondary"
                        className="mr-2"
                        disabled={disabled}
                        onClick={() => onCompareVersion(version)}
                      >
                        Compare
                      </Button>
                      <Button
                        size="sm"
                        variant="primary"
                        disabled={disabled || useDisabled}
                        onClick={() => onUseVersion(version)}
                      >
                        {useLabel}
                      </Button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </Table>
        )}
      </Modal.Body>
      <Modal.Footer className="py-2">
        <Button size="sm" variant="secondary" onClick={onHide}>
          Close
        </Button>
      </Modal.Footer>
    </Modal>
  );
}
