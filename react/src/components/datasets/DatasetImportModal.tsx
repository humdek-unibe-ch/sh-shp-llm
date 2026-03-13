import React, { useEffect, useState } from 'react';
import Select from 'react-select';
import { Button, Form, Modal, Spinner, Table } from 'react-bootstrap';
import type { PromptDescriptor, PromptExecutionProfile } from '../prompts/promptTypes';
import type { createDatasetApi } from './datasetApi';
import type { PromptImportCandidate, PromptImportSourceType } from './datasetTypes';

function shortText(value: string, max = 140): string {
  const normalized = value.replace(/\s+/g, ' ').trim();
  if (normalized.length <= max) {
    return normalized;
  }
  return `${normalized.slice(0, max)}...`;
}

function candidatePreview(sourceType: PromptImportSourceType, candidate: PromptImportCandidate): string {
  if (sourceType === 'script_run') {
    return shortText(`${candidate.name || 'Script'} (${candidate.model || 'default'})`, 110);
  }
  if (sourceType === 'playground_run') {
    return shortText(candidate.request_content || candidate.response_content || '', 140) || '(no request/response text)';
  }
  return shortText(candidate.content || '', 140) || '(empty message)';
}

function candidateMeta(sourceType: PromptImportSourceType, candidate: PromptImportCandidate): string {
  if (sourceType === 'script_run') {
    return `Script #${candidate.id} | model: ${candidate.model || 'default'}`;
  }

  const parts: string[] = [];
  if (candidate.id_llmConversations) {
    parts.push(`conversation #${candidate.id_llmConversations}`);
  }
  if (candidate.id_llm_scripts) {
    parts.push(`script #${candidate.id_llm_scripts}`);
  }
  if (candidate.id_dataRows) {
    parts.push(`data row #${candidate.id_dataRows}`);
  }
  if (candidate.id_llmMessages_request) {
    parts.push(`request #${candidate.id_llmMessages_request}`);
  }
  if (candidate.id_llmMessages_response) {
    parts.push(`response #${candidate.id_llmMessages_response}`);
  }
  if (candidate.role) {
    parts.push(`role: ${candidate.role}`);
  }

  return parts.length > 0 ? parts.join(' | ') : 'No linked IDs';
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
  const [error, setError] = useState<string | null>(null);
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
    const meta = candidateMeta(sourceType, candidate).toLowerCase();
    const idText = String(candidate.id);
    const name = String(candidate.name || '').toLowerCase();
    const model = String(candidate.model || '').toLowerCase();
    return preview.includes(term) || meta.includes(term) || idText.includes(term) || name.includes(term) || model.includes(term);
  });

  useEffect(() => {
    if (!show) return;
    setLoading(true);
    setError(null);
    setSelectedIds([]);
    datasetApi.getImportCandidates(descriptor, sourceType, 80)
      .then((rows) => setCandidates(rows || []))
      .catch((err) => setError(err instanceof Error ? err.message : 'Failed to load import candidates'))
      .finally(() => setLoading(false));
  }, [datasetApi, descriptor, show, sourceType]);

  const handleImport = async () => {
    if (!datasetId || selectedIds.length === 0) return;
    setLoading(true);
    setError(null);
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
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to import selected cases');
    } finally {
      setLoading(false);
    }
  };

  const toggleSelected = (candidateId: number) => {
    setSelectedIds((current) => (
      current.includes(candidateId)
        ? current.filter((value) => value !== candidateId)
        : [...current, candidateId]
    ));
  };

  return (
    <Modal show={show} onHide={onHide} centered dialogClassName="prompt-modal-90">
      <Modal.Header closeButton className="py-2">
        <Modal.Title className="h6">Import Dataset Cases</Modal.Title>
      </Modal.Header>
      <Modal.Body>
        {error && <div className="alert alert-danger py-2 small">{error}</div>}
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
        {sourceType === 'conversation_message' && executionProfile === 'script_runtime' && (
          <div className="alert alert-info py-2 small mb-2">
            Conversation imports for scripts auto-derive variables from the message text.
            Prefer structured messages (JSON or key:value lines) for best replay accuracy.
          </div>
        )}

        <div className="table-responsive">
          <Table hover size="sm" className="mb-0 prompt-lab-table">
            <thead>
              <tr>
                <th style={{ width: 40 }}></th>
                <th>ID</th>
                <th>Preview</th>
                <th>Details</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={5} className="text-muted small">Loading candidates...</td></tr>
              ) : filteredCandidates.length === 0 ? (
                <tr><td colSpan={5} className="text-muted small">No candidates found.</td></tr>
              ) : filteredCandidates.map((candidate) => {
                const candidateId = Number(candidate.id);
                const isSelected = selectedIds.includes(candidateId);
                return (
                <tr
                  key={candidate.id}
                  className={isSelected ? 'table-primary' : ''}
                  onClick={() => toggleSelected(candidateId)}
                  style={{ cursor: 'pointer' }}
                >
                  <td>
                    <Form.Check
                      checked={isSelected}
                      onChange={(event) => {
                        event.stopPropagation();
                        toggleSelected(candidateId);
                      }}
                      onClick={(event) => event.stopPropagation()}
                    />
                  </td>
                  <td className="small">{candidate.id}</td>
                  <td className="small">{candidatePreview(sourceType, candidate)}</td>
                  <td className="small text-muted">{candidateMeta(sourceType, candidate)}</td>
                  <td className="small">{candidate.created_at || candidate.updated_at || '-'}</td>
                </tr>
              )})}
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
