import React from 'react';
import { Badge, Button, Form, OverlayTrigger, Tooltip } from 'react-bootstrap';
import type { PromptVersion } from './promptTypes';

interface PromptToolbarProps {
  activeVersion?: PromptVersion | null;
  dirty?: boolean;
  disabled?: boolean;
  changeNote: string;
  onChangeNote: (value: string) => void;
  onOpenVersions: () => void;
  onOpenCompare: () => void;
  onOpenPlayground: () => void;
  onOpenDatasets: () => void;
  onOpenBuilder: () => void;
  showBuilder?: boolean;
  showDatasets?: boolean;
}

export const PromptToolbar: React.FC<PromptToolbarProps> = ({
  activeVersion,
  dirty = false,
  disabled = false,
  changeNote,
  onChangeNote,
  onOpenVersions,
  onOpenCompare,
  onOpenPlayground,
  onOpenDatasets,
  onOpenBuilder,
  showBuilder = true,
  showDatasets = true,
}) => {
  return (
    <div className="prompt-toolbar border rounded bg-light px-3 py-2 mb-3">
      <div className="d-flex justify-content-between align-items-center flex-wrap">
        <div className="mb-2 mb-lg-0">
          <span className="small font-weight-bold text-dark mr-2">Prompt</span>
          {activeVersion ? (
            <Badge variant="secondary" className="mr-2">
              v{activeVersion.version_no}
            </Badge>
          ) : (
            <Badge variant="light" className="mr-2 border">
              No version
            </Badge>
          )}
          {dirty && <Badge variant="warning">Draft</Badge>}
          {activeVersion?.created_user_name && (
            <span className="small text-muted ml-2">
              {activeVersion.created_user_name}
            </span>
          )}
        </div>

        <div className="btn-group btn-group-sm">
          <Button variant="outline-secondary" onClick={onOpenVersions} disabled={disabled}>
            <i className="fas fa-history mr-1"></i>
            Versions
          </Button>
          <Button variant="outline-secondary" onClick={onOpenCompare} disabled={disabled || !activeVersion}>
            <i className="fas fa-code-branch mr-1"></i>
            Compare
          </Button>
          <Button variant="outline-primary" onClick={onOpenPlayground} disabled={disabled}>
            <i className="fas fa-flask mr-1"></i>
            Playground
          </Button>
          {showDatasets && (
            <Button variant="outline-info" onClick={onOpenDatasets} disabled={disabled}>
              <i className="fas fa-layer-group mr-1"></i>
              Datasets
            </Button>
          )}
          {showBuilder && (
            <Button variant="outline-success" onClick={onOpenBuilder} disabled={disabled}>
              <i className="fas fa-magic mr-1"></i>
              Build With AI
            </Button>
          )}
        </div>
      </div>

      <Form.Group className="mb-0 mt-2">
        <Form.Label className="small text-muted mb-1">
          Version Comment
          <OverlayTrigger
            placement="top"
            overlay={(
              <Tooltip id="prompt-version-comment-hint">
                Saved only on normal Save. If prompt text or config changed, this note is stored on the new version.
              </Tooltip>
            )}
          >
            <i className="fas fa-info-circle ml-2 text-secondary"></i>
          </OverlayTrigger>
        </Form.Label>
        <Form.Control
          size="sm"
          type="text"
          value={changeNote}
          disabled={disabled}
          onChange={(event) => onChangeNote(event.target.value)}
          placeholder="Optional note saved with the next version"
        />
        <Form.Text className="text-muted">
          Applies to the next created version when you click the page Save button.
        </Form.Text>
      </Form.Group>
    </div>
  );
};
