import React, { useEffect, useMemo, useState } from 'react';
import { Alert, Button, Form, Modal } from 'react-bootstrap';
import type { PromptDatasetCase } from './datasetTypes';

interface DatasetCaseEditModalProps {
  show: boolean;
  datasetCase: PromptDatasetCase | null;
  onHide: () => void;
  onSave: (payload: { title: string; notes: string; tags: string[] }) => Promise<void>;
}

export const DatasetCaseEditModal: React.FC<DatasetCaseEditModalProps> = ({
  show,
  datasetCase,
  onHide,
  onSave,
}) => {
  const [title, setTitle] = useState('');
  const [notes, setNotes] = useState('');
  const [tagsText, setTagsText] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!show || !datasetCase) {
      return;
    }
    let tags: string[] = [];
    try {
      tags = JSON.parse(datasetCase.tags_json || '[]') as string[];
    } catch {
      tags = [];
    }
    setTitle(datasetCase.title || datasetCase.case_key || '');
    setNotes(datasetCase.notes || '');
    setTagsText(tags.join(', '));
    setSaving(false);
    setError(null);
  }, [datasetCase, show]);

  const previewText = useMemo(() => {
    try {
      const parsed = JSON.parse(datasetCase?.input_payload_json || '{}') as Record<string, unknown>;
      const values = Object.values(parsed.variables as Record<string, unknown> || parsed.form_data as Record<string, unknown> || parsed || {});
      const first = values.find((value) => typeof value === 'string' && value.trim() !== '');
      return typeof first === 'string' ? first : '';
    } catch {
      return '';
    }
  }, [datasetCase]);

  return (
    <Modal show={show} onHide={onHide} centered>
      <Modal.Header closeButton className="py-2">
        <Modal.Title className="h6">Edit Case</Modal.Title>
      </Modal.Header>
      <Modal.Body>
        {error && <Alert variant="danger" className="py-2">{error}</Alert>}
        {previewText && (
          <div className="small text-muted border rounded bg-light p-2 mb-3">
            <strong>Input preview:</strong> {previewText}
          </div>
        )}
        <Form.Group>
          <Form.Label className="small font-weight-bold">Case Name</Form.Label>
          <Form.Control size="sm" value={title} onChange={(event) => setTitle(event.target.value)} />
        </Form.Group>
        <Form.Group>
          <Form.Label className="small font-weight-bold">Notes</Form.Label>
          <Form.Control as="textarea" rows={3} value={notes} onChange={(event) => setNotes(event.target.value)} />
        </Form.Group>
        <Form.Group className="mb-0">
          <Form.Label className="small font-weight-bold">Tags</Form.Label>
          <Form.Control size="sm" value={tagsText} onChange={(event) => setTagsText(event.target.value)} placeholder="comma,separated,tags" />
        </Form.Group>
      </Modal.Body>
      <Modal.Footer className="py-2">
        <Button size="sm" variant="secondary" onClick={onHide} disabled={saving}>Cancel</Button>
        <Button
          size="sm"
          variant="primary"
          disabled={saving || title.trim() === ''}
          onClick={async () => {
            setSaving(true);
            setError(null);
            try {
              await onSave({
                title: title.trim(),
                notes: notes.trim(),
                tags: tagsText.split(',').map((item) => item.trim()).filter(Boolean),
              });
              onHide();
            } catch (err) {
              setError(err instanceof Error ? err.message : 'Failed to update case');
            } finally {
              setSaving(false);
            }
          }}
        >
          {saving ? 'Saving...' : 'Save Changes'}
        </Button>
      </Modal.Footer>
    </Modal>
  );
};
