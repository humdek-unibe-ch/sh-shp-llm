/**
 * Dataset Table — renders the list of datasets as a sortable table.
 *
 * Shows name, type, execution profile, case count, lock status, and
 * action buttons (view, edit metadata, delete).
 *
 * @module components/datasets/DatasetTable
 */
import React from 'react';
import { Badge, Button, Table } from 'react-bootstrap';
import type { PromptDataset } from './datasetTypes';

interface DatasetTableProps {
  datasets: PromptDataset[];
  selectedDatasetId: number | null;
  onSelect: (datasetId: number) => void;
  onEdit?: (dataset: PromptDataset) => void;
  onToggleLock?: (dataset: PromptDataset) => void;
  onDelete?: (dataset: PromptDataset) => void;
  disabled?: boolean;
}

/** Table component for dataset table. */
export const DatasetTable: React.FC<DatasetTableProps> = ({
  datasets,
  selectedDatasetId,
  onSelect,
  onEdit,
  onToggleLock,
  onDelete,
  disabled = false,
}) => (
  <div className="table-responsive">
    <Table hover size="sm" className="mb-0 prompt-lab-table prompt-dataset-table">
      <thead>
        <tr>
          <th>Name</th>
          <th style={{ width: 170 }}>Type</th>
          <th style={{ width: 150 }}>Profile</th>
          <th style={{ width: 70 }}>Cases</th>
          <th style={{ width: 90 }}>Status</th>
          <th style={{ width: 240 }}>Actions</th>
        </tr>
      </thead>
      <tbody>
        {datasets.length === 0 ? (
          <tr>
            <td colSpan={6} className="text-muted small">No datasets yet.</td>
          </tr>
        ) : datasets.map((dataset) => (
          <tr key={dataset.id} className={dataset.id === selectedDatasetId ? 'table-info' : ''}>
            <td>
              <div className="small font-weight-bold">{dataset.name}</div>
              {dataset.description && <div className="text-muted small">{dataset.description}</div>}
            </td>
            <td className="small text-nowrap">{dataset.dataset_type_code || '-'}</td>
            <td className="small text-nowrap">{dataset.execution_profile_code || '-'}</td>
            <td className="small">{dataset.cases_count ?? 0}</td>
            <td>
              <Badge variant={dataset.is_locked ? 'dark' : 'secondary'} className="px-2 py-1 font-weight-normal">
                {dataset.is_locked ? 'Locked' : 'Ready'}
              </Badge>
            </td>
            <td>
              <div className="d-flex justify-content-end flex-wrap" style={{ gap: 4 }}>
                <Button
                  size="sm"
                  className="prompt-compact-btn"
                  variant={dataset.id === selectedDatasetId ? 'primary' : 'outline-primary'}
                  onClick={() => onSelect(dataset.id)}
                  disabled={disabled}
                >
                  <i className="fas fa-eye mr-1"></i>
                  <span>View</span>
                </Button>
                {onEdit && (
                  <Button
                    size="sm"
                    className="prompt-compact-btn"
                    variant="outline-info"
                    onClick={() => onEdit(dataset)}
                    disabled={disabled}
                  >
                    <i className="fas fa-pen mr-1"></i>
                    <span>Edit</span>
                  </Button>
                )}
                {onToggleLock && (
                  <Button
                    size="sm"
                    className="prompt-compact-btn"
                    variant="outline-secondary"
                    onClick={() => onToggleLock(dataset)}
                    disabled={disabled}
                  >
                    <i className={`fas ${dataset.is_locked ? 'fa-unlock' : 'fa-lock'} mr-1`}></i>
                    <span>{dataset.is_locked ? 'Unlock' : 'Lock'}</span>
                  </Button>
                )}
                {onDelete && (
                  <Button
                    size="sm"
                    className="prompt-compact-btn"
                    variant="outline-danger"
                    onClick={() => onDelete(dataset)}
                    disabled={disabled || !!dataset.is_locked}
                    title={dataset.is_locked ? 'Unlock dataset before deleting' : 'Delete dataset'}
                  >
                    <i className="fas fa-trash-alt mr-1"></i>
                    <span>Delete</span>
                  </Button>
                )}
              </div>
            </td>
          </tr>
        ))}
      </tbody>
    </Table>
  </div>
);
