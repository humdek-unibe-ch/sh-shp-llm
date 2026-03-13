import React, { useEffect, useState } from 'react';
import Select from 'react-select';
import { Button, Form, Modal, Spinner, Table } from 'react-bootstrap';
import type { PromptDescriptor, PromptExecutionProfile } from '../prompts/promptTypes';
import type { createDatasetApi } from './datasetApi';
import type { PromptImportCandidate, PromptImportSourceType } from './datasetTypes';

function candidatePreview(sourceType: PromptImportSourceType, candidate: PromptImportCandidate): string {
  if (sourceType === 'script_run') {
    return `${candidate.name || 'Script'} (${candidate.model || 'default'})`;
  }
  if (sourceType === 'playground_run') {
    return (candidate.request_content || candidate.response_content || '').slice(0, 120);
  }
  return (candidate.content || '').slice(0, 120);
}

function sourceExecutionProfile(sourceType: PromptImportSourceType, fallback: PromptExecutionProfile): string {
  if (sourceType === 'form_submission') return 'form_runtime';
  if (sourceType === 'conversation_message') return 'chat_runtime';
  if (sourceType === 'script_run') return 'script_runtime';
  return fallback;
}

interface DatasetImportModalProps {
  show: boolean;
  onHide: () => void;
  descriptor: PromptDescriptor;
  executionProfile: PromptExecutionProfile;
  datasetId: number | null;
  datasetApi: ReturnType<typeof createDatasetApi>;
  resolveRuntimeOverrides: () => Record<string, unknown>;
  onImported: (count: number) => void;
}

export const DatasetImportModal: React.FC<DatasetImportModalProps> = ({
  show,
  onHide,
  descriptor,
  executionProfile,
  datasetId,
  datasetApi,
  resolveRuntimeOverrides,
  onImported,
}) => {
  const [sourceType, setSourceType] = useState<PromptImportSourceType>('playground_run');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(false);
  const [candidates, setCandidates] = useState<PromptImportCandidate[]>([]);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const sourceOptions = [
    { value: 'playground_run', label: 'From playground runs' },
    { value: 'form_submission', label: 'From form submissions' },
    { value: 'conversation_message', label: 'From conversations' },
    { value: 'script_run', label: 'From scripts' },
  ];
  const selectedSourceOption = sourceOptions.find((option) => option.value === sourceType) || sourceOptions[0];

  const filteredCandidates = candidates.filter((candidate) => {
    const term = search.trim().toLowerCase();
    if (term === '') {
      return true;
    }
    const preview = candidatePreview(sourceType, candidate).toLowerCase();
    const idText = String(candidate.id);
    const name = String(candidate.name || '').toLowerCase();
    const model = String(candidate.model || '').toLowerCase();
    return preview.includes(term) || idText.includes(term) || name.includes(term) || model.includes(term);
  });

  useEffect(() => {
    if (!show) return;
    setLoading(true);
    setSelectedIds([]);
    datasetApi.getImportCandidates(descriptor, sourceType, 80)
      .then((rows) => setCandidates(rows || []))
      .finally(() => setLoading(false));
  }, [datasetApi, descriptor, show, sourceType]);

  const handleImport = async () => {
    if (!datasetId || selectedIds.length === 0) return;
    setLoading(true);
    try {
      const inserted = await datasetApi.addCasesFromSource({
        descriptor,
        datasetId,
        sourceType,
        sourceIds: selectedIds,
        executionProfile: sourceExecutionProfile(sourceType, executionProfile),
        runtimeOverrides: resolveRuntimeOverrides(),
      });
      onImported(inserted.length);
      onHide();
    } finally {
      setLoading(false);
    }
  };

  return (
    <Modal show={show} onHide={onHide} centered dialogClassName="prompt-modal-90">
      <Modal.Header closeButton className="py-2">
        <Modal.Title className="h6">Import Dataset Cases</Modal.Title>
      </Modal.Header>
      <Modal.Body>
        <Form.Group className="mb-2">
          <Form.Label className="small mb-1">Import Source</Form.Label>
          <Select
            className="prompt-select"
            classNamePrefix="react-select"
            isSearchable
            options={sourceOptions}
            value={selectedSourceOption}
            onChange={(option) => setSourceType((option?.value as PromptImportSourceType) || 'playground_run')}
          />
        </Form.Group>
        <Form.Control
          size="sm"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder="Search candidates"
          className="mb-2"
        />

        <div className="table-responsive">
          <Table hover size="sm" className="mb-0 prompt-lab-table">
            <thead>
              <tr>
                <th style={{ width: 40 }}></th>
                <th>ID</th>
                <th>Preview</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={4} className="text-muted small">Loading candidates...</td></tr>
              ) : filteredCandidates.length === 0 ? (
                <tr><td colSpan={4} className="text-muted small">No candidates found.</td></tr>
              ) : filteredCandidates.map((candidate) => (
                <tr key={candidate.id}>
                  <td>
                    <Form.Check
                      checked={selectedIds.includes(candidate.id)}
                      onChange={(event) => {
                        setSelectedIds((current) => (
                          event.target.checked
                            ? [...current, candidate.id]
                            : current.filter((value) => value !== candidate.id)
                        ));
                      }}
                    />
                  </td>
                  <td className="small">{candidate.id}</td>
                  <td className="small">{candidatePreview(sourceType, candidate)}</td>
                  <td className="small">{candidate.created_at || candidate.updated_at || '-'}</td>
                </tr>
              ))}
            </tbody>
          </Table>
        </div>
      </Modal.Body>
      <Modal.Footer className="py-2">
        <Button size="sm" variant="secondary" onClick={onHide}>Close</Button>
        <Button size="sm" variant="primary" onClick={handleImport} disabled={!datasetId || selectedIds.length === 0 || loading}>
          {loading ? <><Spinner animation="border" size="sm" className="mr-2" />Importing...</> : 'Import Selected'}
        </Button>
      </Modal.Footer>
    </Modal>
  );
};
