/**
 * Dataset Case Table — renders individual test cases within a dataset.
 *
 * Shows input/output summaries, expected labels, selection checkboxes,
 * and per-case actions (edit, preview, history, delete, move).
 *
 * @module components/datasets/DatasetCaseTable
 */
import React from 'react';
import { Badge, Button, Table } from 'react-bootstrap';
import type { PromptDatasetCase } from './datasetTypes';

/** parseInputPreview utility. */
function parseInputPreview(inputPayloadJson?: string): string {
  if (!inputPayloadJson) return '';
  try {
    const parsed = JSON.parse(inputPayloadJson) as Record<string, unknown>;
    const variables = (parsed.variables && typeof parsed.variables === 'object' && !Array.isArray(parsed.variables))
      ? parsed.variables as Record<string, unknown>
      : (parsed.form_data && typeof parsed.form_data === 'object' && !Array.isArray(parsed.form_data))
        ? parsed.form_data as Record<string, unknown>
        : parsed;
    const firstValue = Object.values(variables).find((value) => typeof value === 'string' && value.trim() !== '');
    if (typeof firstValue === 'string') {
      return firstValue.length > 120 ? `${firstValue.slice(0, 120)}...` : firstValue;
    }
  } catch {
    return '';
  }
  return '';
}

/** parseSafetyLabel utility. */
function parseSafetyLabel(expectedLabelsJson?: string | null): string {
  if (!expectedLabelsJson) return 'safe';
  try {
    const parsed = JSON.parse(expectedLabelsJson) as Record<string, unknown>;
    const safety = parsed.safety && typeof parsed.safety === 'object' && !Array.isArray(parsed.safety)
      ? parsed.safety as Record<string, unknown>
      : null;
    const dangerLevel = typeof safety?.danger_level === 'string' ? safety.danger_level : null;
    return dangerLevel || 'safe';
  } catch {
    return 'safe';
  }
}

interface DatasetCaseTableProps {
  cases: PromptDatasetCase[];
  loading?: boolean;
  canDelete?: boolean;
  selectedCaseIds?: number[];
  onToggleSelection?: (caseId: number, checked: boolean) => void;
  onToggleAllSelection?: (checked: boolean) => void;
  onPreview: (datasetCase: PromptDatasetCase) => void;
  onEdit?: (datasetCase: PromptDatasetCase) => void;
  onViewHistory?: (datasetCase: PromptDatasetCase) => void;
  onDelete: (datasetCase: PromptDatasetCase) => void;
}

/** Table component for dataset case table. */
export const DatasetCaseTable: React.FC<DatasetCaseTableProps> = ({
  cases,
  loading = false,
  canDelete = true,
  selectedCaseIds = [],
  onToggleSelection,
  onToggleAllSelection,
  onPreview,
  onEdit,
  onViewHistory,
  onDelete,
}) => {
  const selectableCases = cases.filter((datasetCase) => Number(datasetCase.id) > 0);
  const allSelected = selectableCases.length > 0 && selectableCases.every((datasetCase) => selectedCaseIds.includes(datasetCase.id));

  return (
    <div className="table-responsive">
      <Table hover size="sm" className="mb-0 prompt-lab-table">
      <thead>
        <tr>
          <th style={{ width: 44 }}>
            <input
              type="checkbox"
              checked={allSelected}
              onChange={(event) => onToggleAllSelection?.(event.target.checked)}
              disabled={!onToggleAllSelection || loading || selectableCases.length === 0}
            />
          </th>
          <th>Case</th>
          <th>Type</th>
          <th>Source</th>
          <th>Tags</th>
          <th style={{ width: 220 }}>Actions</th>
        </tr>
      </thead>
      <tbody>
        {loading ? (
          <tr><td colSpan={6} className="text-muted small">Loading cases...</td></tr>
        ) : cases.length === 0 ? (
          <tr><td colSpan={6} className="text-muted small">No dataset cases yet.</td></tr>
        ) : cases.map((datasetCase) => {
          let tags: string[] = [];
          try {
            tags = JSON.parse(datasetCase.tags_json || '[]') as string[];
          } catch {
            tags = [];
          }
          return (
            <tr key={datasetCase.id}>
              <td className="small align-middle">
                <input
                  type="checkbox"
                  checked={selectedCaseIds.includes(datasetCase.id)}
                  onChange={(event) => onToggleSelection?.(datasetCase.id, event.target.checked)}
                  disabled={!onToggleSelection}
                />
              </td>
              <td className="small">
                <div className="font-weight-bold">{datasetCase.title || datasetCase.case_key}</div>
                <div className="text-muted">{parseInputPreview(datasetCase.input_payload_json) || datasetCase.case_key}</div>
                <div className="mt-1">
                  <Badge variant={parseSafetyLabel(datasetCase.expected_labels_json) === 'safe' ? 'success' : 'warning'}>
                    safety: {parseSafetyLabel(datasetCase.expected_labels_json)}
                  </Badge>
                </div>
              </td>
              <td className="small">{datasetCase.case_type_code || '-'}</td>
              <td className="small">{datasetCase.source_type_code || '-'}</td>
              <td className="small">
                {tags.length === 0 ? '-' : tags.map((tag) => (
                  <Badge key={`${datasetCase.id}:${tag}`} variant="light" className="mr-1">{tag}</Badge>
                ))}
              </td>
              <td>
                <div className="prompt-row-actions">
                  <Button size="sm" variant="outline-secondary" onClick={() => onPreview(datasetCase)}>Preview</Button>
                  {onEdit && <Button size="sm" variant="outline-info" onClick={() => onEdit(datasetCase)}>Edit</Button>}
                  {onViewHistory && <Button size="sm" variant="outline-info" onClick={() => onViewHistory(datasetCase)}>History</Button>}
                  {canDelete && <Button size="sm" variant="outline-danger" onClick={() => onDelete(datasetCase)}>Remove</Button>}
                </div>
              </td>
            </tr>
          );
        })}
      </tbody>
      </Table>
    </div>
  );
};
