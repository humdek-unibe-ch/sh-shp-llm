import React from 'react';
import { Badge, Button, Table } from 'react-bootstrap';
import type { PromptEvalRunCase } from './evaluationTypes';

function scoreBadgeVariant(passed?: number | null): 'success' | 'danger' | 'secondary' {
  if (passed === 1) return 'success';
  if (passed === 0) return 'danger';
  return 'secondary';
}

function caseBadgeVariant(status?: string): 'success' | 'danger' | 'warning' {
  if (status === 'failed') return 'danger';
  if (status === 'pending_review') return 'warning';
  return 'success';
}

function caseStatusLabel(status?: string): string {
  if (status === 'failed') return 'Fail';
  if (status === 'pending_review') return 'Pending';
  return 'Pass';
}

export const EvaluationCaseTable: React.FC<{
  cases: PromptEvalRunCase[];
  onInspect: (runCase: PromptEvalRunCase) => void;
}> = ({ cases, onInspect }) => (
  <div className="table-responsive mt-3">
    <Table hover size="sm" className="mb-0 prompt-lab-table">
      <thead>
        <tr>
          <th>Case</th>
          <th>Model</th>
          <th>Status</th>
          <th>Output</th>
          <th>Scores</th>
          <th style={{ width: 140 }}>Actions</th>
        </tr>
      </thead>
      <tbody>
        {cases.length === 0 ? (
          <tr><td colSpan={6} className="text-muted small">No evaluation cases yet.</td></tr>
        ) : cases.map((item) => {
          const runCaseId = Number(item.run_case_id || item.id || 0);
          const status = item.status || (item.passed === false ? 'failed' : 'passed');
          const outputText = (item.display_content || String((item.normalized_output || {})['display_content'] || '')).slice(0, 140);
          return (
            <tr key={`${runCaseId}-${item.dataset_case_id || item.id}`}>
              <td className="small">{item.title || item.dataset_case_title || `Case ${item.dataset_case_id || item.id}`}</td>
              <td className="small">{item.model || '-'}</td>
              <td><Badge variant={caseBadgeVariant(status)}>{caseStatusLabel(status)}</Badge></td>
              <td className="small">{outputText}</td>
              <td className="small">
                {(item.scores || []).map((score, index) => (
                  <span key={`${runCaseId}-${index}`} className="mr-2">
                    <Badge variant={scoreBadgeVariant(score.passed)} className="mr-1">{score.eval_name || score.score_type}</Badge>
                    {score.score_value_label || score.score_value_numeric || '-'}
                  </span>
                ))}
              </td>
              <td>
                <div className="prompt-row-actions">
                  <Button size="sm" variant="outline-secondary" onClick={() => onInspect(item)}>Inspect</Button>
                </div>
              </td>
            </tr>
          );
        })}
      </tbody>
    </Table>
  </div>
);
