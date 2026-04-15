/**
 * Dataset Metadata Modal — edit dataset name, description, type, and profile.
 *
 * Provides a form for updating dataset metadata fields and toggling
 * the locked/unlocked state.
 *
 * @module components/datasets/DatasetMetadataModal
 */
import React, { useEffect, useMemo, useState } from 'react';
import Select from 'react-select';
import { Alert, Button, Form, Modal } from 'react-bootstrap';
import type { PromptDataset } from './datasetTypes';
import { datasetTypeOptions, describeExecutionProfile, executionProfileOptions } from './datasetOptions';

interface DatasetMetadataModalProps {
  show: boolean;
  dataset: PromptDataset | null;
  onHide: () => void;
  onSave: (payload: {
    name: string;
    description: string;
    datasetType: string;
    executionProfile: string;
  }) => Promise<void>;
  disabled?: boolean;
}

/** Modal dialog for dataset metadata modal. */
export const DatasetMetadataModal: React.FC<DatasetMetadataModalProps> = ({
  show,
  dataset,
  onHide,
  onSave,
  disabled = false,
}) => {
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [datasetType, setDatasetType] = useState('golden_manual');
  const [executionProfile, setExecutionProfile] = useState('text_only');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!show || !dataset) {
      return;
    }
    setName(dataset.name || '');
    setDescription(dataset.description || '');
    setDatasetType(dataset.dataset_type_code || 'golden_manual');
    setExecutionProfile(dataset.execution_profile_code || 'text_only');
    setSaving(false);
    setError(null);
  }, [dataset, show]);

  const selectedType = useMemo(
    () => datasetTypeOptions.find((option) => option.value === datasetType) || datasetTypeOptions[0],
    [datasetType],
  );
  const selectedProfile = useMemo(
    () => executionProfileOptions.find((option) => option.value === executionProfile) || executionProfileOptions[0],
    [executionProfile],
  );
  const profileLocked = Number(dataset?.cases_count || 0) > 0;

  const handleSave = async () => {
    if (!dataset || saving || disabled) {
      return;
    }
    setSaving(true);
    setError(null);
    try {
      await onSave({
        name: name.trim(),
        description: description.trim(),
        datasetType,
        executionProfile,
      });
      onHide();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update dataset');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal show={show} onHide={onHide} centered>
      <Modal.Header closeButton className="py-2">
        <Modal.Title className="h6">Edit Dataset</Modal.Title>
      </Modal.Header>
      <Modal.Body>
        {error && <Alert variant="danger" className="py-2">{error}</Alert>}
        <Form.Group>
          <Form.Label className="small font-weight-bold">Name</Form.Label>
          <Form.Control
            size="sm"
            value={name}
            onChange={(event) => setName(event.target.value)}
            placeholder="Dataset name"
            disabled={disabled || saving}
          />
        </Form.Group>
        <Form.Group>
          <Form.Label className="small font-weight-bold">Description</Form.Label>
          <Form.Control
            as="textarea"
            rows={3}
            value={description}
            onChange={(event) => setDescription(event.target.value)}
            placeholder="Optional notes about this dataset"
            disabled={disabled || saving}
          />
        </Form.Group>
        <Form.Group>
          <Form.Label className="small font-weight-bold">Dataset Type</Form.Label>
          <Select
            classNamePrefix="react-select"
            options={datasetTypeOptions}
            value={selectedType}
            onChange={(option) => setDatasetType(option?.value || 'golden_manual')}
            isDisabled={disabled || saving}
          />
        </Form.Group>
        <Form.Group className="mb-0">
          <Form.Label className="small font-weight-bold">Profile</Form.Label>
          <Select
            classNamePrefix="react-select"
            options={executionProfileOptions}
            value={selectedProfile}
            onChange={(option) => setExecutionProfile(option?.value || 'text_only')}
            isDisabled={disabled || saving || profileLocked}
          />
          <Form.Text className="text-muted">
            {profileLocked
              ? 'Profile is read-only once the dataset contains linked cases.'
              : describeExecutionProfile(executionProfile)}
          </Form.Text>
        </Form.Group>
      </Modal.Body>
      <Modal.Footer className="py-2">
        <Button size="sm" variant="secondary" onClick={onHide} disabled={saving}>Cancel</Button>
        <Button size="sm" variant="primary" onClick={handleSave} disabled={disabled || saving || name.trim() === ''}>
          {saving ? 'Saving...' : 'Save Changes'}
        </Button>
      </Modal.Footer>
    </Modal>
  );
};
