import React, { useEffect, useMemo, useState } from 'react';
import { Alert, Button, Col, Modal, Row, Table } from 'react-bootstrap';
import { createDatasetApi } from '../datasets/datasetApi';
import { DatasetBrowser } from '../datasets/DatasetBrowser';
import { DatasetCasePreviewModal } from '../datasets/DatasetCasePreviewModal';
import { DatasetCaseTable } from '../datasets/DatasetCaseTable';
import { DatasetImportModal } from '../datasets/DatasetImportModal';
import type { PromptDataset, PromptDatasetCase } from '../datasets/datasetTypes';
import { createEvaluationApi } from '../evaluations/evaluationApi';
import { EvaluationResultsView } from '../evaluations/EvaluationResultsView';
import { EvaluationRunnerModal } from '../evaluations/EvaluationRunnerModal';
import { HumanReviewPanel } from '../evaluations/HumanReviewPanel';
import type { PromptEvalDefinition, PromptEvalRunCase, PromptEvalRunResult } from '../evaluations/evaluationTypes';
import type { createPromptLabApi } from './promptApi';
import type { PromptDescriptor, PromptExecutionProfile, PromptMessage, PromptModel, PromptVersion } from './promptTypes';

interface PlaygroundCapture {
  variables: Record<string, unknown>;
  messageHistory: PromptMessage[];
  runtimeOverrides: Record<string, unknown>;
  runRef?: {
    id_llm_prompt_playground_runs?: number | null;
    id_llmConversations?: number | null;
    id_llmMessages_request?: number | null;
    id_llmMessages_response?: number | null;
  } | null;
}

interface PromptDatasetsModalProps {
  show: boolean;
  onHide: () => void;
  api: ReturnType<typeof createPromptLabApi>;
  descriptor: PromptDescriptor;
  versions: PromptVersion[];
  activeVersionId: number | null;
  models: PromptModel[];
  executionProfile: PromptExecutionProfile;
  promptValue: string;
  disabled?: boolean;
  defaultModel?: string | null;
  resolveRuntimeOverrides: () => Record<string, unknown>;
  lastPlaygroundCapture: PlaygroundCapture | null;
}

function parseJsonSafe<T>(value: unknown, fallback: T): T {
  if (typeof value !== 'string' || value.trim() === '') return fallback;
  try { return (JSON.parse(value) as T) ?? fallback; } catch { return fallback; }
}

export const PromptDatasetsModal: React.FC<PromptDatasetsModalProps> = ({
  show,
  onHide,
  api,
  descriptor,
  versions,
  activeVersionId,
  models,
  executionProfile,
  promptValue,
  disabled = false,
  defaultModel,
  resolveRuntimeOverrides,
  lastPlaygroundCapture,
}) => {
  const datasetApi = useMemo(() => createDatasetApi(api), [api]);
  const evaluationApi = useMemo(() => createEvaluationApi(api), [api]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [datasets, setDatasets] = useState<PromptDataset[]>([]);
  const [datasetSearch, setDatasetSearch] = useState('');
  const [selectedDatasetId, setSelectedDatasetId] = useState<number | null>(null);
  const [newDatasetName, setNewDatasetName] = useState('');
  const [newDatasetType, setNewDatasetType] = useState('golden_manual');
  const [cases, setCases] = useState<PromptDatasetCase[]>([]);
  const [loadingCases, setLoadingCases] = useState(false);
  const [showImportModal, setShowImportModal] = useState(false);
  const [showRunnerModal, setShowRunnerModal] = useState(false);
  const [casePreview, setCasePreview] = useState<PromptDatasetCase | null>(null);
  const [evalDefs, setEvalDefs] = useState<PromptEvalDefinition[]>([]);
  const [evalResult, setEvalResult] = useState<PromptEvalRunResult | null>(null);
  const [evalRunCases, setEvalRunCases] = useState<PromptEvalRunCase[]>([]);
  const [baselineSummary, setBaselineSummary] = useState<{ baselinePassRate: number | null; baselineAvgScore: number | null; passRateDelta: number | null; avgScoreDelta: number | null } | null>(null);
  const [selectedEvalCase, setSelectedEvalCase] = useState<PromptEvalRunCase | null>(null);
  const [savingHumanKey, setSavingHumanKey] = useState<string | null>(null);
  const [humanDrafts, setHumanDrafts] = useState<Record<string, { numeric: string; label: string; passed: string; reason: string }>>({});
  const selectedDataset = useMemo(() => datasets.find((item) => item.id === selectedDatasetId) || null, [datasets, selectedDatasetId]);
  const evaluationCases = evalRunCases.length > 0 ? evalRunCases : (evalResult?.cases || []);

  const loadDatasets = async (searchText = datasetSearch) => {
    const rows = await datasetApi.listDatasets(descriptor, executionProfile, searchText);
    setDatasets(rows || []);
    setSelectedDatasetId((current) => current ?? rows?.[0]?.id ?? null);
  };

  const refreshSelectedDataset = async () => {
    if (!selectedDatasetId) { setCases([]); return; }
    setLoadingCases(true);
    try {
      const response = await datasetApi.getDataset(descriptor, selectedDatasetId);
      setCases(response.cases || []);
      await loadDatasets();
    } finally {
      setLoadingCases(false);
    }
  };

  useEffect(() => {
    if (!show) return;
    setLoading(true); setError(null); setSuccess(null);
    Promise.all([loadDatasets(''), evaluationApi.listEvalDefinitions(descriptor)])
      .then(([, definitions]) => setEvalDefs(definitions || []))
      .catch((err) => setError(err instanceof Error ? err.message : 'Failed to load datasets'))
      .finally(() => setLoading(false));
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [show, descriptor.ownerId, descriptor.ownerType, executionProfile]);

  useEffect(() => { if (show && selectedDatasetId) refreshSelectedDataset().catch(() => undefined); }, [selectedDatasetId, show]);

  const handleCreateDataset = async () => {
    setLoading(true); setError(null); setSuccess(null);
    try {
      const created = await datasetApi.createDataset(descriptor, newDatasetName.trim(), executionProfile, '', newDatasetType);
      setSelectedDatasetId(created.id); setNewDatasetName(''); await loadDatasets(); setSuccess(`Dataset "${created.name}" created.`);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create dataset');
    } finally { setLoading(false); }
  };

  const handleToggleLock = async (dataset: PromptDataset) => {
    try {
      await datasetApi.updateDataset(descriptor, dataset.id, { isLocked: !dataset.is_locked });
      await loadDatasets();
      setSuccess(`Dataset "${dataset.name}" ${dataset.is_locked ? 'unlocked' : 'locked'}.`);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update dataset');
    }
  };

  const handleDeleteDataset = async (dataset: PromptDataset) => {
    if (dataset.is_locked) {
      setError('Unlock dataset before deleting it.');
      return;
    }
    if (!window.confirm(`Delete dataset "${dataset.name}" and all related cases/evaluation runs? This cannot be undone.`)) {
      return;
    }

    try {
      await datasetApi.deleteDataset(descriptor, dataset.id);
      const rows = await datasetApi.listDatasets(descriptor, executionProfile, datasetSearch);
      setDatasets(rows || []);
      const fallbackId = rows?.[0]?.id ?? null;
      setSelectedDatasetId((current) => (current === dataset.id ? fallbackId : current));
      if (selectedDatasetId === dataset.id) {
        setCases([]);
        setEvalResult(null);
        setEvalRunCases([]);
        setBaselineSummary(null);
      }
      setSuccess(`Dataset "${dataset.name}" deleted.`);
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to delete dataset');
    }
  };

  const handleAddCurrentCase = async () => {
    if (!selectedDatasetId || !lastPlaygroundCapture) return;
    setLoading(true); setError(null); setSuccess(null);
    try {
      await datasetApi.addCaseFromPlaygroundRun({
        descriptor,
        datasetId: selectedDatasetId,
        executionProfile,
        title: `Prompt replay ${new Date().toISOString()}`,
        runtimeOverrides: lastPlaygroundCapture.runtimeOverrides,
        variables: lastPlaygroundCapture.variables,
        messageHistory: lastPlaygroundCapture.messageHistory,
        runRef: lastPlaygroundCapture.runRef || undefined,
      });
      await refreshSelectedDataset();
      setSuccess('Case added to dataset from latest playground run.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to add dataset case');
    } finally { setLoading(false); }
  };

  const handleDeleteCase = async (datasetCase: PromptDatasetCase) => {
    try {
      await datasetApi.deleteDatasetCase(descriptor, datasetCase.id);
      await refreshSelectedDataset();
      setSuccess(`Removed case "${datasetCase.title || datasetCase.case_key}".`);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to delete dataset case');
    }
  };

  const refreshEvalRunCases = async (runId: number) => {
    const rows = await evaluationApi.listEvalRunCases(descriptor, runId);
    setEvalRunCases((rows || []).map((row) => ({ ...row, normalized_output: row.normalized_output || parseJsonSafe(row.normalized_output_json || null, null), scores: (row.scores || []).map((score) => ({ ...score, details: score.details || parseJsonSafe(score.details_json || null, null) })) })));
  };

  const handleRunEvaluation = async (config: { targetType: 'draft' | 'active_version' | 'version'; targetVersionId?: number; selectedModels: string[]; evalDefinitionIds: number[]; baselineEnabled: boolean; baselineTargetType: 'active_version' | 'version'; baselineTargetVersionId?: number }) => {
    if (!selectedDatasetId) return;
    setError(null); setSuccess(null); setEvalRunCases([]); setEvalResult(null); setBaselineSummary(null); setHumanDrafts({});
    const result = await evaluationApi.runDatasetEval({ descriptor, datasetId: selectedDatasetId, targetType: config.targetType, targetVersionId: config.targetVersionId, draftPrompt: promptValue, runtimeOverrides: resolveRuntimeOverrides(), selectedModels: config.selectedModels, evalDefinitionIds: config.evalDefinitionIds });
    setEvalResult(result); if (result?.run?.id) await refreshEvalRunCases(Number(result.run.id));
    if (config.baselineEnabled) {
      const baselineResult = await evaluationApi.runDatasetEval({ descriptor, datasetId: selectedDatasetId, targetType: config.baselineTargetType, targetVersionId: config.baselineTargetVersionId, draftPrompt: promptValue, runtimeOverrides: resolveRuntimeOverrides(), selectedModels: config.selectedModels, evalDefinitionIds: config.evalDefinitionIds });
      const mainSummary = result?.run?.summary || {}; const baseSummary = baselineResult?.run?.summary || {};
      const mainPassRate = typeof mainSummary.pass_rate === 'number' ? mainSummary.pass_rate : null;
      const baselinePassRate = typeof baseSummary.pass_rate === 'number' ? baseSummary.pass_rate : null;
      const mainAvgScore = typeof mainSummary.avg_score === 'number' ? mainSummary.avg_score : null;
      const baselineAvgScore = typeof baseSummary.avg_score === 'number' ? baseSummary.avg_score : null;
      setBaselineSummary({ baselinePassRate, baselineAvgScore, passRateDelta: (mainPassRate != null && baselinePassRate != null) ? Number((mainPassRate - baselinePassRate).toFixed(2)) : null, avgScoreDelta: (mainAvgScore != null && baselineAvgScore != null) ? Number((mainAvgScore - baselineAvgScore).toFixed(4)) : null });
    }
    setSuccess('Dataset evaluation completed.');
  };

  const handleSaveHumanReview = async (runCaseId: number, definitionId: number) => {
    const key = `${runCaseId}:${definitionId}`; const draft = humanDrafts[key] || { numeric: '', label: '', passed: '', reason: '' };
    setSavingHumanKey(key);
    try {
      await evaluationApi.saveHumanScore({ descriptor, runCaseId, definitionId, scoreValueNumeric: draft.numeric.trim() === '' ? null : Number(draft.numeric), scoreValueLabel: draft.label || null, passed: draft.passed === '' ? null : Number(draft.passed), details: { reason: draft.reason || '' } });
      if (evalResult?.run?.id) await refreshEvalRunCases(Number(evalResult.run.id));
      setSuccess('Human review score saved.');
    } catch (err) { setError(err instanceof Error ? err.message : 'Failed to save human score'); } finally { setSavingHumanKey(null); }
  };

  return (
    <>
      <Modal show={show} onHide={onHide} centered dialogClassName="prompt-modal-90 prompt-datasets-modal">
        <Modal.Header closeButton className="py-2"><Modal.Title className="h6"><i className="fas fa-layer-group mr-2"></i>Datasets And Evaluations</Modal.Title></Modal.Header>
        <Modal.Body>
          {error && <Alert variant="danger" className="py-2">{error}</Alert>}
          {success && <Alert variant="success" className="py-2">{success}</Alert>}
          <Row className="mb-3">
            <Col lg={12}>
              <div className="border rounded p-3 bg-white">
              <DatasetBrowser
                datasets={datasets}
                selectedDatasetId={selectedDatasetId}
                search={datasetSearch}
                onSearchChange={(value) => { setDatasetSearch(value); loadDatasets(value).catch(() => undefined); }}
                newDatasetName={newDatasetName}
                newDatasetType={newDatasetType}
                onNewDatasetNameChange={setNewDatasetName}
                onNewDatasetTypeChange={setNewDatasetType}
                onSelect={setSelectedDatasetId}
                onCreateDataset={handleCreateDataset}
                onToggleLock={handleToggleLock}
                onDeleteDataset={handleDeleteDataset}
                disabled={disabled}
                loading={loading}
              />
              </div>
            </Col>
          </Row>
          <Row>
            <Col lg={12}>
              <div className="border rounded p-3 bg-white">
                <div className="d-flex justify-content-between align-items-center flex-wrap mb-3">
                  <div>
                    <div className="small font-weight-bold">{selectedDataset?.name || 'Select a dataset'}</div>
                    <div className="small text-muted">{selectedDataset ? `${selectedDataset.dataset_type_code || 'dataset'} / ${selectedDataset.execution_profile_code || 'profile'}` : 'Create or choose a dataset to continue.'}</div>
                  </div>
                  <div className="mt-2 mt-md-0 prompt-header-actions">
                    <Button size="sm" variant="outline-secondary" onClick={handleAddCurrentCase} disabled={disabled || !selectedDatasetId || !lastPlaygroundCapture || promptValue.trim() === ''}>Add Latest Playground</Button>
                    <Button size="sm" variant="outline-info" onClick={() => setShowImportModal(true)} disabled={disabled || !selectedDatasetId}>Import Cases</Button>
                    <Button size="sm" variant="primary" onClick={() => setShowRunnerModal(true)} disabled={disabled || !selectedDatasetId || promptValue.trim() === ''}>Run Evaluation</Button>
                  </div>
                </div>
                <DatasetCaseTable cases={cases} loading={loadingCases} canDelete={!selectedDataset?.is_locked} onPreview={setCasePreview} onDelete={handleDeleteCase} />
                <EvaluationResultsView result={evalResult} cases={evaluationCases} baselineSummary={baselineSummary} onInspectCase={setSelectedEvalCase} />
              </div>
            </Col>
          </Row>
        </Modal.Body>
        <Modal.Footer className="py-2"><Button size="sm" variant="secondary" onClick={onHide}>Close</Button></Modal.Footer>
      </Modal>

      <DatasetImportModal show={showImportModal} onHide={() => setShowImportModal(false)} descriptor={descriptor} executionProfile={executionProfile} datasetId={selectedDatasetId} datasetApi={datasetApi} resolveRuntimeOverrides={resolveRuntimeOverrides} onImported={(count) => { refreshSelectedDataset().catch(() => undefined); setSuccess(`Imported ${count} case(s).`); }} />
      <EvaluationRunnerModal show={showRunnerModal} onHide={() => setShowRunnerModal(false)} versions={versions} activeVersionId={activeVersionId} models={models} defaultModel={defaultModel} evalDefinitions={evalDefs} disabled={disabled} onRun={handleRunEvaluation} />
      <DatasetCasePreviewModal datasetCase={casePreview} onHide={() => setCasePreview(null)} />

      <Modal show={!!selectedEvalCase} onHide={() => setSelectedEvalCase(null)} centered dialogClassName="prompt-modal-90">
        <Modal.Header closeButton className="py-2"><Modal.Title className="h6">Evaluation Case Detail</Modal.Title></Modal.Header>
        <Modal.Body>
          {selectedEvalCase && (
            <>
              <div className="small mb-2"><strong>Case:</strong> {selectedEvalCase.title || selectedEvalCase.dataset_case_title || '-'}</div>
              <div className="small text-muted mb-1">Normalized Output</div>
              <pre className="small border rounded bg-light p-2 mb-3 prompt-json-preview">{JSON.stringify(selectedEvalCase.normalized_output || parseJsonSafe(selectedEvalCase.normalized_output_json, {}), null, 2)}</pre>
              <div className="small text-muted mb-1">Scores</div>
              <Table size="sm" className="prompt-lab-table">
                <thead><tr><th>Evaluator</th><th>Score</th><th>Status</th><th>Details</th></tr></thead>
                <tbody>
                  {(selectedEvalCase.scores || []).map((score, index) => {
                    const runCaseId = Number(selectedEvalCase.run_case_id || selectedEvalCase.id || 0);
                    const definitionId = Number(score.id_llm_eval_definitions || 0);
                    const key = `${runCaseId}:${definitionId}`;
                    const draft = humanDrafts[key] || { numeric: '', label: '', passed: '', reason: '' };
                    const isHuman = score.score_type === 'human_review';
                    return (
                      <tr key={`${key}:${index}`}>
                        <td className="small">{score.eval_name || score.score_type}</td>
                        <td className="small">{score.score_value_numeric ?? score.score_value_label ?? '-'}</td>
                        <td className="small">{score.passed === 1 ? 'Pass' : score.passed === 0 ? 'Fail' : 'Pending'}</td>
                        <td className="small">
                          {isHuman && runCaseId > 0 && definitionId > 0 ? (
                            <HumanReviewPanel
                              draft={draft}
                              disabled={savingHumanKey === key}
                              onDraftChange={(nextDraft) => setHumanDrafts((current) => ({ ...current, [key]: nextDraft }))}
                              onSave={() => handleSaveHumanReview(runCaseId, definitionId)}
                            />
                          ) : (
                            <pre className="mb-0 small">{JSON.stringify(score.details || parseJsonSafe(score.details_json, {}), null, 2)}</pre>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </Table>
            </>
          )}
        </Modal.Body>
        <Modal.Footer className="py-2"><Button size="sm" variant="secondary" onClick={() => setSelectedEvalCase(null)}>Close</Button></Modal.Footer>
      </Modal>
    </>
  );
};
