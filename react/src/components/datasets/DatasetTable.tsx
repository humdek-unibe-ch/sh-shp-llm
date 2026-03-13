import React from 'react';
import { Badge, Button, Table } from 'react-bootstrap';
import type { PromptDataset } from './datasetTypes';

interface DatasetTableProps {
  datasets: PromptDataset[];
  selectedDatasetId: number | null;
  onSelect: (datasetId: number) => void;
  onToggleLock?: (dataset: PromptDataset) => void;
  disabled?: boolean;
}

export const DatasetTable: React.FC<DatasetTableProps> = ({
  datasets,
  selectedDatasetId,
  onSelect,
  onToggleLock,
  disabled = false,
}) => (
  <div>
    <Table hover size="sm" className="mb-0 prompt-lab-table prompt-dataset-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Profile</th>
          <th>Cases</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        {datasets.length === 0 ? (
          <tr>
            <td colSpan={5} className="text-muted small">No datasets yet.</td>
          </tr>
        ) : datasets.map((dataset) => (
          <tr key={dataset.id} className={dataset.id === selectedDatasetId ? 'table-info' : ''}>
            <td>
              <div className="small font-weight-bold">{dataset.name}</div>
              {dataset.description && <div className="text-muted small">{dataset.description}</div>}
            </td>
            <td className="small">{dataset.execution_profile_code || '-'}</td>
            <td className="small">{dataset.cases_count ?? 0}</td>
            <td>
              <Badge variant={dataset.is_locked ? 'dark' : 'secondary'}>
                <i className={`fas ${dataset.is_locked ? 'fa-lock' : 'fa-check-circle'} mr-1`}></i>
                {dataset.is_locked ? 'Locked' : 'Ready'}
              </Badge>
            </td>
            <td>
              <div className="prompt-row-actions">
                <Button
                  size="sm"
                  className="prompt-compact-btn"
                  variant={dataset.id === selectedDatasetId ? 'primary' : 'outline-primary'}
                  onClick={() => onSelect(dataset.id)}
                  disabled={disabled}
                >
                  <i className={`fas ${dataset.id === selectedDatasetId ? 'fa-eye' : 'fa-arrow-right'} mr-1`}></i>
                  <span>{dataset.id === selectedDatasetId ? 'Viewing' : 'View'}</span>
                </Button>
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
              </div>
            </td>
          </tr>
        ))}
      </tbody>
    </Table>
  </div>
);
