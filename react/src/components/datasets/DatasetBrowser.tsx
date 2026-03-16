import React, { useState } from 'react';
import { Button, Form } from 'react-bootstrap';
import Select from 'react-select';
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
  onCreateDataset: () => Promise<boolean> | boolean;
  onToggleLock: (dataset: PromptDataset) => void;
  onDeleteDataset: (dataset: PromptDataset) => void;
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
  onDeleteDataset,
  disabled = false,
  loading = false,
}) => {
  const [createExpanded, setCreateExpanded] = useState(false);
  const datasetTypeOptions = [
    { value: 'golden_manual', label: 'golden_manual' },
    { value: 'production_replay', label: 'production_replay' },
    { value: 'pilot_study_replay', label: 'pilot_study_replay' },
    { value: 'conversation_replay', label: 'conversation_replay' },
    { value: 'form_submission_replay', label: 'form_submission_replay' },
    { value: 'script_fixture', label: 'script_fixture' },
  ];
  const selectedType = datasetTypeOptions.find((option) => option.value === newDatasetType) || datasetTypeOptions[0];

  return (
  <div className="border rounded p-3 bg-white h-100 prompt-dataset-browser">
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
      onDelete={onDeleteDataset}
      disabled={disabled || loading}
    />

    <div className="small font-weight-bold text-muted mt-3 mb-2">Create Dataset</div>
    {!createExpanded ? (
      <Button
        size="sm"
        variant="outline-secondary"
        onClick={() => setCreateExpanded(true)}
        disabled={disabled || loading}
      >
        <i className="fas fa-plus mr-1"></i>
        Create Dataset
      </Button>
    ) : (
      <div className="border rounded p-2 bg-light">
        <Form.Label className="small mb-1">Dataset Name</Form.Label>
        <Form.Control
          size="sm"
          value={newDatasetName}
          onChange={(event) => onNewDatasetNameChange(event.target.value)}
          placeholder="e.g. Pilot Study Replay Set"
          className="mb-2"
          autoFocus
        />
        <Form.Label className="small mb-1">Dataset Type</Form.Label>
        <Select
          className="prompt-select mb-2"
          classNamePrefix="react-select"
          isSearchable
          options={datasetTypeOptions}
          value={selectedType}
          onChange={(option) => onNewDatasetTypeChange(option?.value || 'golden_manual')}
        />
        <div className="d-flex">
          <Button
            size="sm"
            variant="primary"
            onClick={async () => {
              const created = await onCreateDataset();
              if (created) {
                setCreateExpanded(false);
              }
            }}
            disabled={disabled || loading || newDatasetName.trim() === ''}
          >
            <i className="fas fa-save mr-1"></i>
            Save Dataset
          </Button>
          <Button
            size="sm"
            variant="outline-secondary"
            className="ml-2"
            onClick={() => setCreateExpanded(false)}
            disabled={loading}
          >
            Cancel
          </Button>
        </div>
      </div>
    )}
  </div>
);
};
