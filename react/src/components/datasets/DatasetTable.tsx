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
  <div className="table-responsive">
    <Table hover size="sm" className="mb-0">
      <thead>
        <tr>
          <th>Name</th>
          <th>Profile</th>
          <th>Cases</th>
          <th>Status</th>
          <th style={{ width: 140 }}>Actions</th>
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
                {dataset.is_locked ? 'Locked' : 'Open'}
              </Badge>
            </td>
            <td>
              <Button size="sm" variant={dataset.id === selectedDatasetId ? 'primary' : 'outline-primary'} className="mr-1" onClick={() => onSelect(dataset.id)} disabled={disabled}>
                {dataset.id === selectedDatasetId ? 'Selected' : 'Open'}
              </Button>
              {onToggleLock && (
                <Button size="sm" variant="outline-secondary" onClick={() => onToggleLock(dataset)} disabled={disabled}>
                  {dataset.is_locked ? 'Unlock' : 'Lock'}
                </Button>
              )}
            </td>
          </tr>
        ))}
      </tbody>
    </Table>
  </div>
);
