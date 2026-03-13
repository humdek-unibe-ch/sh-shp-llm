import React, { useState } from 'react';
import Select from 'react-select';
import { Button, Form, Modal, Row, Col, Spinner } from 'react-bootstrap';
import type { PromptVersion } from '../prompts/promptTypes';
import type { PromptEvalDefinition } from './evaluationTypes';

interface EvaluationRunnerModalProps {
  show: boolean;
  onHide: () => void;
  versions: PromptVersion[];
  activeVersionId: number | null;
  defaultModel?: string | null;
  evalDefinitions: PromptEvalDefinition[];
  disabled?: boolean;
  onRun: (config: {
    targetType: 'draft' | 'active_version' | 'version';
    targetVersionId?: number;
    selectedModels: string[];
    evalDefinitionIds: number[];
    baselineEnabled: boolean;
    baselineTargetType: 'active_version' | 'version';
    baselineTargetVersionId?: number;
  }) => Promise<void>;
}

export const EvaluationRunnerModal: React.FC<EvaluationRunnerModalProps> = ({
  show,
  onHide,
  versions,
  activeVersionId,
  defaultModel,
  evalDefinitions,
  disabled = false,
  onRun,
}) => {
  const [targetType, setTargetType] = useState<'draft' | 'active_version' | 'version'>('draft');
  const [targetVersionId, setTargetVersionId] = useState<number | null>(activeVersionId ?? null);
  const [selectedModels, setSelectedModels] = useState<string[]>(defaultModel ? [defaultModel] : []);
  const [selectedEvalDefIds, setSelectedEvalDefIds] = useState<number[]>(
    evalDefinitions.filter((item) => item.eval_type_code === 'programmatic').map((item) => item.id),
  );
  const [baselineEnabled, setBaselineEnabled] = useState(false);
  const [baselineTargetType, setBaselineTargetType] = useState<'active_version' | 'version'>('active_version');
  const [baselineTargetVersionId, setBaselineTargetVersionId] = useState<number | null>(activeVersionId ?? null);
  const [running, setRunning] = useState(false);
  const targetTypeOptions = [
    { value: 'draft', label: 'Current draft' },
    { value: 'active_version', label: 'Active version' },
    { value: 'version', label: 'Specific version' },
  ];
  const versionOptions = versions.map((version) => ({
    value: String(version.id),
    label: `v${version.version_no}${version.id === activeVersionId ? ' (active)' : ''}`,
  }));
  const baselineTypeOptions = [
    { value: 'active_version', label: 'Active version' },
    { value: 'version', label: 'Specific version' },
  ];
  const selectedTargetType = targetTypeOptions.find((option) => option.value === targetType) || targetTypeOptions[0];
  const selectedVersionOption = versionOptions.find((option) => Number(option.value) === (targetVersionId ?? 0)) || null;
  const selectedBaselineType = baselineTypeOptions.find((option) => option.value === baselineTargetType) || baselineTypeOptions[0];
  const selectedBaselineVersion = versionOptions.find((option) => Number(option.value) === (baselineTargetVersionId ?? 0)) || null;

  const handleRun = async () => {
    setRunning(true);
    try {
      await onRun({
        targetType,
        targetVersionId: targetType === 'version' ? targetVersionId || undefined : undefined,
        selectedModels,
        evalDefinitionIds: selectedEvalDefIds,
        baselineEnabled,
        baselineTargetType,
        baselineTargetVersionId: baselineTargetType === 'version' ? baselineTargetVersionId || undefined : undefined,
      });
      onHide();
    } finally {
      setRunning(false);
    }
  };

  return (
    <Modal show={show} onHide={onHide} centered dialogClassName="prompt-modal-90">
      <Modal.Header closeButton className="py-2">
        <Modal.Title className="h6">Run Dataset Evaluation</Modal.Title>
      </Modal.Header>
      <Modal.Body>
        <Row>
          <Col md={4}>
            <Form.Group>
              <Form.Label className="small mb-1">Target</Form.Label>
              <Select
                className="prompt-select"
                classNamePrefix="react-select"
                isSearchable={false}
                options={targetTypeOptions}
                value={selectedTargetType}
                onChange={(option) => setTargetType((option?.value as 'draft' | 'active_version' | 'version') || 'draft')}
              />
            </Form.Group>
          </Col>
          <Col md={4}>
            <Form.Group>
              <Form.Label className="small mb-1">Version</Form.Label>
              <Select
                className="prompt-select"
                classNamePrefix="react-select"
                isSearchable
                isDisabled={targetType !== 'version'}
                options={versionOptions}
                value={selectedVersionOption}
                onChange={(option) => setTargetVersionId(option?.value ? Number(option.value) : null)}
                placeholder="Choose version"
              />
            </Form.Group>
          </Col>
          <Col md={4}>
            <Form.Group>
              <Form.Label className="small mb-1">Models (optional)</Form.Label>
              <Form.Control
                size="sm"
                value={selectedModels.join(', ')}
                onChange={(event) => setSelectedModels(event.target.value.split(',').map((item) => item.trim()).filter(Boolean).slice(0, 3))}
                placeholder={defaultModel || 'model-a, model-b'}
              />
            </Form.Group>
          </Col>
        </Row>

        <Form.Check
          id="baseline-enable"
          type="checkbox"
          className="small mb-3"
          label="Compare against baseline target"
          checked={baselineEnabled}
          onChange={(event) => setBaselineEnabled(event.target.checked)}
        />

        {baselineEnabled && (
          <Row>
            <Col md={6}>
              <Form.Group>
                <Form.Label className="small mb-1">Baseline Target</Form.Label>
                <Select
                  className="prompt-select"
                  classNamePrefix="react-select"
                  isSearchable={false}
                  options={baselineTypeOptions}
                  value={selectedBaselineType}
                  onChange={(option) => setBaselineTargetType((option?.value as 'active_version' | 'version') || 'active_version')}
                />
              </Form.Group>
            </Col>
            <Col md={6}>
              <Form.Group>
                <Form.Label className="small mb-1">Baseline Version</Form.Label>
                <Select
                  className="prompt-select"
                  classNamePrefix="react-select"
                  isSearchable
                  isDisabled={baselineTargetType !== 'version'}
                  options={versionOptions}
                  value={selectedBaselineVersion}
                  onChange={(option) => setBaselineTargetVersionId(option?.value ? Number(option.value) : null)}
                  placeholder="Choose version"
                />
              </Form.Group>
            </Col>
          </Row>
        )}

        <Form.Group>
          <Form.Label className="small mb-1">Evaluators</Form.Label>
          <div className="border rounded p-2 prompt-eval-def-list">
            {evalDefinitions.map((definition) => (
              <Form.Check
                key={definition.id}
                id={`eval-def-${definition.id}`}
                type="checkbox"
                className="small mb-1"
                label={`${definition.name} (${definition.eval_type_code || 'unknown'})`}
                checked={selectedEvalDefIds.includes(definition.id)}
                onChange={(event) => {
                  setSelectedEvalDefIds((current) => (
                    event.target.checked
                      ? [...current, definition.id]
                      : current.filter((id) => id !== definition.id)
                  ));
                }}
              />
            ))}
          </div>
        </Form.Group>
      </Modal.Body>
      <Modal.Footer className="py-2">
        <Button size="sm" variant="secondary" onClick={onHide}>Close</Button>
        <Button size="sm" variant="primary" onClick={handleRun} disabled={disabled || running || selectedEvalDefIds.length === 0}>
          {running ? <><Spinner animation="border" size="sm" className="mr-2" />Running...</> : 'Run Evaluation'}
        </Button>
      </Modal.Footer>
    </Modal>
  );
};
