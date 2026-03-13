import React from 'react';
import { Button, Modal } from 'react-bootstrap';
import type { PromptDatasetCase } from './datasetTypes';

function parseJsonSafe(value: unknown): unknown {
  if (typeof value !== 'string' || value.trim() === '') {
    return {};
  }
  try {
    return JSON.parse(value);
  } catch {
    return {};
  }
}

export const DatasetCasePreviewModal: React.FC<{
  datasetCase: PromptDatasetCase | null;
  onHide: () => void;
}> = ({ datasetCase, onHide }) => (
  <Modal show={!!datasetCase} onHide={onHide} centered dialogClassName="prompt-modal-90">
    <Modal.Header closeButton className="py-2">
      <Modal.Title className="h6">Case Preview</Modal.Title>
    </Modal.Header>
    <Modal.Body>
      {datasetCase && (
        <>
          <div className="small mb-2"><strong>Title:</strong> {datasetCase.title || datasetCase.case_key}</div>
          <div className="small mb-2"><strong>Type:</strong> {datasetCase.case_type_code || '-'}</div>
          <div className="small mb-2"><strong>Source:</strong> {datasetCase.source_type_code || '-'}</div>
          <div className="small text-muted mb-1">Input Payload</div>
          <pre className="small border rounded bg-light p-2 mb-2 prompt-json-preview">{JSON.stringify(parseJsonSafe(datasetCase.input_payload_json), null, 2)}</pre>
          <div className="small text-muted mb-1">Expected Labels</div>
          <pre className="small border rounded bg-light p-2 mb-0 prompt-json-preview">{JSON.stringify(parseJsonSafe(datasetCase.expected_labels_json), null, 2)}</pre>
        </>
      )}
    </Modal.Body>
    <Modal.Footer className="py-2">
      <Button size="sm" variant="secondary" onClick={onHide}>Close</Button>
    </Modal.Footer>
  </Modal>
);
