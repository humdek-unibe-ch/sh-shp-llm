/**
 * Dataset Move Cases Modal — move selected test cases to another dataset.
 *
 * Presents a dataset selector and confirmation before bulk-moving
 * cases via the API.
 *
 * @module components/datasets/DatasetMoveCasesModal
 */
import React, { useEffect, useMemo, useState } from 'react';
import Select from 'react-select';
import { Alert, Button, Form, Modal } from 'react-bootstrap';
import type { PromptDataset, PromptDatasetCase } from './datasetTypes';

interface DatasetMoveCasesModalProps {
  show: boolean;
  sourceDataset: PromptDataset | null;
  targetDatasets: PromptDataset[];
  selectedCases: PromptDatasetCase[];
  onHide: () => void;
  onSubmit: (payload: { targetDatasetId: number; removeSource: boolean }) => Promise<void>;
  disabled?: boolean;
}

/** Modal dialog for dataset move cases modal. */
export const DatasetMoveCasesModal: React.FC<DatasetMoveCasesModalProps> = ({
  show,
  sourceDataset,
  targetDatasets,
  selectedCases,
  onHide,
  onSubmit,
  disabled = false,
}) => {
  const [targetDatasetId, setTargetDatasetId] = useState<number | null>(null);
  const [removeSource, setRemoveSource] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!show) {
      return;
    }
    setTargetDatasetId(targetDatasets[0]?.id ?? null);
    setRemoveSource(false);
    setSaving(false);
    setError(null);
  }, [show, targetDatasets]);

  const targetOptions = useMemo(
    () => targetDatasets.map((dataset) => ({ value: dataset.id, label: `${dataset.name} (${dataset.dataset_type_code || 'dataset'})` })),
    [targetDatasets],
  );

  const handleSubmit = async () => {
    if (!targetDatasetId || saving || disabled) {
      return;
    }
    setSaving(true);
    setError(null);
    try {
      await onSubmit({ targetDatasetId, removeSource });
      onHide();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to move cases');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal show={show} onHide={onHide} centered>
      <Modal.Header closeButton className="py-2">
        <Modal.Title className="h6">Move Or Promote Cases</Modal.Title>
      </Modal.Header>
      <Modal.Body>
        {error && <Alert variant="danger" className="py-2">{error}</Alert>}
        <div className="small mb-3">
          <strong>{selectedCases.length}</strong> case(s) selected from <strong>{sourceDataset?.name || 'dataset'}</strong>.
        </div>
        <Form.Group>
          <Form.Label className="small font-weight-bold">Target Dataset</Form.Label>
          <Select
            classNamePrefix="react-select"
            options={targetOptions}
            value={targetOptions.find((option) => option.value === targetDatasetId) || null}
            onChange={(option) => setTargetDatasetId(option?.value || null)}
            isDisabled={disabled || saving}
            placeholder="Choose a compatible dataset"
          />
          <Form.Text className="text-muted">
            Only datasets with the same execution profile are offered here.
          </Form.Text>
        </Form.Group>
        <Form.Group className="mb-0">
          <Form.Check
            id="dataset-move-remove-source"
            type="checkbox"
            checked={removeSource}
            onChange={(event) => setRemoveSource(event.target.checked)}
            disabled={disabled || saving}
            label="Remove cases from the current dataset too (full move)"
          />
          <Form.Text className="text-muted">
            Leave this off to promote/copy the selected cases into the target dataset while keeping them here as well.
          </Form.Text>
        </Form.Group>
      </Modal.Body>
      <Modal.Footer className="py-2">
        <Button size="sm" variant="secondary" onClick={onHide} disabled={saving}>Cancel</Button>
        <Button size="sm" variant="primary" onClick={handleSubmit} disabled={disabled || saving || !targetDatasetId || selectedCases.length === 0}>
          {saving ? 'Saving...' : removeSource ? 'Move Cases' : 'Promote Cases'}
        </Button>
      </Modal.Footer>
    </Modal>
  );
};
