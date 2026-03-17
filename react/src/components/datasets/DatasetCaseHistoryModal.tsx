import React from 'react';
import { Badge, Button, Modal, Table } from 'react-bootstrap';
import { JsonInspector } from '../shared/JsonInspector';
import type { PromptEvalRunCase } from '../evaluations/evaluationTypes';
import type { PromptDatasetCase } from './datasetTypes';

interface DatasetCaseHistoryModalProps {
  show: boolean;
  datasetCase: PromptDatasetCase | null;
  history: PromptEvalRunCase[];
  loading?: boolean;
  onHide: () => void;
  onInspect?: (runCase: PromptEvalRunCase) => void;
}

function statusVariant(status?: string): 'success' | 'danger' | 'warning' | 'secondary' {
  if (status === 'failed') return 'danger';
  if (status === 'pending_review') return 'warning';
  if (status === 'passed') return 'success';
  return 'secondary';
}

export const DatasetCaseHistoryModal: React.FC<DatasetCaseHistoryModalProps> = ({
  show,
  datasetCase,
  history,
  loading = false,
  onHide,
  onInspect,
}) => (
  <Modal show={show} onHide={onHide} centered dialogClassName="prompt-modal-90">
    <Modal.Header closeButton className="py-2">
      <Modal.Title className="h6">Case Evaluation History</Modal.Title>
    </Modal.Header>
    <Modal.Body>
      <div className="small mb-3">
        <strong>{datasetCase?.title || datasetCase?.case_key || 'Case'}</strong>
      </div>
      <div className="table-responsive">
        <Table hover size="sm" className="prompt-lab-table">
          <thead>
            <tr>
              <th>Run</th>
              <th>Dataset</th>
              <th>Model</th>
              <th>Status</th>
              <th>Output</th>
              <th>Scores</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan={7} className="text-muted small">Loading history...</td></tr>
            ) : history.length === 0 ? (
              <tr><td colSpan={7} className="text-muted small">No evaluation history yet for this case.</td></tr>
            ) : history.map((entry) => (
              <tr key={`${entry.run_case_id || entry.id || 0}-${entry.id_llm_eval_runs || 0}`}>
                <td className="small">
                  <div>#{entry.id_llm_eval_runs || '-'}</div>
                  <div className="text-muted">{entry.run_created_at || ''}</div>
                </td>
                <td className="small">{entry.dataset_name || '-'}</td>
                <td className="small">{entry.model || '-'}</td>
                <td className="small"><Badge variant={statusVariant(entry.status)}>{entry.status || 'unknown'}</Badge></td>
                <td className="small">{String(entry.display_content || '').slice(0, 140) || '-'}</td>
                <td className="small">
                  {(entry.scores || []).length === 0 ? '-' : (
                    <JsonInspector value={entry.scores} className="small" />
                  )}
                </td>
                <td>
                  {onInspect ? (
                    <Button size="sm" variant="outline-secondary" onClick={() => onInspect(entry)}>Inspect</Button>
                  ) : null}
                </td>
              </tr>
            ))}
          </tbody>
        </Table>
      </div>
    </Modal.Body>
    <Modal.Footer className="py-2">
      <Button size="sm" variant="secondary" onClick={onHide}>Close</Button>
    </Modal.Footer>
  </Modal>
);
