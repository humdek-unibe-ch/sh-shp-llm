import React from 'react';
import { Button, Form } from 'react-bootstrap';
import type { PromptDataset } from './datasetTypes';
import { DatasetTable } from './DatasetTable';

interface DatasetBrowserProps {
  datasets: PromptDataset[];
  selectedDatasetId: number | null;
  search: string;
  onSearchChange: (value: string) => void;
  newDatasetName: string;
  newDatasetType: string;
  onNewDatasetNameChange: (value: string) => void;
  onNewDatasetTypeChange: (value: string) => void;
  onSelect: (datasetId: number) => void;
  onCreateDataset: () => void;
  onToggleLock: (dataset: PromptDataset) => void;
  disabled?: boolean;
  loading?: boolean;
}

export const DatasetBrowser: React.FC<DatasetBrowserProps> = ({
  datasets,
  selectedDatasetId,
  search,
  onSearchChange,
  newDatasetName,
  newDatasetType,
  onNewDatasetNameChange,
  onNewDatasetTypeChange,
  onSelect,
  onCreateDataset,
  onToggleLock,
  disabled = false,
  loading = false,
}) => (
  <div className="border rounded p-3 bg-white h-100">
    <div className="small font-weight-bold text-muted mb-2">Datasets</div>
    <Form.Control
      size="sm"
      value={search}
      onChange={(event) => onSearchChange(event.target.value)}
      placeholder="Search datasets"
      className="mb-2"
    />
    <DatasetTable
      datasets={datasets}
      selectedDatasetId={selectedDatasetId}
      onSelect={onSelect}
      onToggleLock={onToggleLock}
      disabled={disabled || loading}
    />

    <div className="small font-weight-bold text-muted mt-3 mb-2">Create Dataset</div>
    <Form.Control
      size="sm"
      value={newDatasetName}
      onChange={(event) => onNewDatasetNameChange(event.target.value)}
      placeholder="e.g. Pilot Study Replay Set"
      className="mb-2"
    />
    <Form.Control
      as="select"
      size="sm"
      value={newDatasetType}
      onChange={(event) => onNewDatasetTypeChange(event.target.value)}
      className="mb-2"
    >
      <option value="golden_manual">golden_manual</option>
      <option value="production_replay">production_replay</option>
      <option value="pilot_study_replay">pilot_study_replay</option>
      <option value="conversation_replay">conversation_replay</option>
      <option value="form_submission_replay">form_submission_replay</option>
      <option value="script_fixture">script_fixture</option>
    </Form.Control>
    <Button size="sm" variant="outline-secondary" onClick={onCreateDataset} disabled={disabled || loading || newDatasetName.trim() === ''}>
      Create Dataset
    </Button>
  </div>
);
