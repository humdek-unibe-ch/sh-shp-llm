import React from 'react';
import { Button, Form } from 'react-bootstrap';
import Select from 'react-select';
import type { PromptDataset } from './datasetTypes';
import { datasetTypeOptions, describeExecutionProfile } from './datasetOptions';
import { DatasetTable } from './DatasetTable';

interface DatasetBrowserProps {
  datasets: PromptDataset[];
  selectedDatasetId: number | null;
  search: string;
  onSearchChange: (value: string) => void;
  datasetName: string;
  datasetDescription: string;
  datasetType: string;
  executionProfile: string;
  formMode: 'create' | 'edit' | null;
  editingDataset?: PromptDataset | null;
  onDatasetNameChange: (value: string) => void;
  onDatasetDescriptionChange: (value: string) => void;
  onDatasetTypeChange: (value: string) => void;
  onSelect: (datasetId: number) => void;
  onOpenCreateForm: () => void;
  onOpenEditForm: (dataset: PromptDataset) => void;
  onSaveForm: () => Promise<boolean> | boolean;
  onCancelForm: () => void;
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
  datasetName,
  datasetDescription,
  datasetType,
  executionProfile,
  formMode,
  editingDataset = null,
  onDatasetNameChange,
  onDatasetDescriptionChange,
  onDatasetTypeChange,
  onSelect,
  onOpenCreateForm,
  onOpenEditForm,
  onSaveForm,
  onCancelForm,
  onToggleLock,
  onDeleteDataset,
  disabled = false,
  loading = false,
}) => {
  const selectedType = datasetTypeOptions.find((option) => option.value === datasetType) || datasetTypeOptions[0];
  const isEditing = formMode === 'edit';

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
        onEdit={onOpenEditForm}
        onToggleLock={onToggleLock}
        onDelete={onDeleteDataset}
        disabled={disabled || loading}
      />

      <div className="small font-weight-bold text-muted mt-3 mb-2">
        {isEditing ? `Edit Dataset${editingDataset?.name ? `: ${editingDataset.name}` : ''}` : 'Create Dataset'}
      </div>
      {formMode == null ? (
        <Button
          size="sm"
          variant="outline-secondary"
          onClick={onOpenCreateForm}
          disabled={disabled || loading}
        >
          <i className="fas fa-plus mr-1"></i>
          Create Dataset
        </Button>
      ) : (
        <div className="border rounded p-3 bg-light">
          <Form.Group>
            <Form.Label className="small mb-1">Dataset Name</Form.Label>
            <Form.Control
              size="sm"
              value={datasetName}
              onChange={(event) => onDatasetNameChange(event.target.value)}
              placeholder="e.g. Pilot Study Replay Set"
              autoFocus
            />
          </Form.Group>
          <Form.Group>
            <Form.Label className="small mb-1">Description</Form.Label>
            <Form.Control
              as="textarea"
              rows={3}
              value={datasetDescription}
              onChange={(event) => onDatasetDescriptionChange(event.target.value)}
              placeholder="Optional notes about this dataset"
            />
          </Form.Group>
          <Form.Group>
            <Form.Label className="small mb-1">Dataset Type</Form.Label>
            <Select
              className="prompt-select"
              classNamePrefix="react-select"
              isSearchable
              options={datasetTypeOptions}
              value={selectedType}
              onChange={(option) => onDatasetTypeChange(option?.value || 'golden_manual')}
            />
          </Form.Group>
          <Form.Group className="mb-2">
            <Form.Label className="small mb-1">Profile</Form.Label>
            <Form.Control
              size="sm"
              value={executionProfile}
              readOnly
              plaintext={false}
            />
            <Form.Text className="text-muted">
              {describeExecutionProfile(executionProfile)} This is linked to the current owner runtime and cannot be changed here.
            </Form.Text>
          </Form.Group>
          <div className="d-flex align-items-center" style={{ gap: 8 }}>
            <Button
              size="sm"
              variant="primary"
              onClick={async () => {
                await onSaveForm();
              }}
              disabled={disabled || loading || datasetName.trim() === ''}
            >
              <i className={`fas ${isEditing ? 'fa-save' : 'fa-plus'} mr-1`}></i>
              {isEditing ? 'Save Changes' : 'Save Dataset'}
            </Button>
            <Button
              size="sm"
              variant="outline-secondary"
              onClick={onCancelForm}
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
