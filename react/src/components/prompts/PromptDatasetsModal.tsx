/**
 * Prompt Datasets Modal — manage evaluation datasets linked to a prompt.
 *
 * Allows creating, browsing, and selecting datasets. From here admins
 * can launch AI import, manual case entry, and evaluation runs.
 *
 * @module components/prompts/PromptDatasetsModal
 */
import React, { useEffect, useMemo, useState } from 'react';
import { Alert, Button, Col, Modal, Row, Table } from 'react-bootstrap';
import { createDatasetApi } from '../datasets/datasetApi';
import { DatasetBrowser } from '../datasets/DatasetBrowser';
import { DatasetCaseHistoryModal } from '../datasets/DatasetCaseHistoryModal';
import { DatasetCaseEditModal } from '../datasets/DatasetCaseEditModal';
import { DatasetCasePreviewModal } from '../datasets/DatasetCasePreviewModal';
import { DatasetCaseTable } from '../datasets/DatasetCaseTable';
import { DatasetImportModal } from '../datasets/DatasetImportModal';
import { DatasetAiImportModal } from '../datasets/DatasetAiImportModal';
import { DatasetMoveCasesModal } from '../datasets/DatasetMoveCasesModal';
import { JsonInspector } from '../shared/JsonInspector';
import type { PromptDataset, PromptDatasetCase, PromptExpectedLabels } from '../datasets/datasetTypes';
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

/** Modal dialog for prompt datasets modal. */
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
  const [datasetFormMode, setDatasetFormMode] = useState<'create' | 'edit' | null>(null);
  const [datasetFormName, setDatasetFormName] = useState('');
  const [datasetFormDescription, setDatasetFormDescription] = useState('');
  const [datasetFormType, setDatasetFormType] = useState('golden_manual');
  const [cases, setCases] = useState<PromptDatasetCase[]>([]);
  const [selectedCaseIds, setSelectedCaseIds] = useState<number[]>([]);
  const [loadingCases, setLoadingCases] = useState(false);
  const [showImportModal, setShowImportModal] = useState(false);
  const [showAiImportModal, setShowAiImportModal] = useState(false);
  const [showRunnerModal, setShowRunnerModal] = useState(false);
  const [editingDataset, setEditingDataset] = useState<PromptDataset | null>(null);
  const [showMoveModal, setShowMoveModal] = useState(false);
  const [compatibleDatasets, setCompatibleDatasets] = useState<PromptDataset[]>([]);
  const [editingCase, setEditingCase] = useState<PromptDatasetCase | null>(null);
  const [casePreview, setCasePreview] = useState<PromptDatasetCase | null>(null);
  const [caseHistoryCase, setCaseHistoryCase] = useState<PromptDatasetCase | null>(null);
  const [caseHistoryEntries, setCaseHistoryEntries] = useState<PromptEvalRunCase[]>([]);
  const [loadingCaseHistory, setLoadingCaseHistory] = useState(false);
  const [evalDefs, setEvalDefs] = useState<PromptEvalDefinition[]>([]);
  const [evalResult, setEvalResult] = useState<PromptEvalRunResult | null>(null);
  const [evalRunCases, setEvalRunCases] = useState<PromptEvalRunCase[]>([]);
  const [baselineSummary, setBaselineSummary] = useState<{ baselinePassRate: number | null; baselineAvgScore: number | null; passRateDelta: number | null; avgScoreDelta: number | null; combinedExecutionCount?: number | null } | null>(null);
  const [evalRunSources, setEvalRunSources] = useState<Array<{ runId: number; label: 'Target' | 'Baseline' }>>([]);
  const [selectedEvalCase, setSelectedEvalCase] = useState<PromptEvalRunCase | null>(null);
  const [savingHumanKey, setSavingHumanKey] = useState<string | null>(null);
  const [deletingEvalRunId, setDeletingEvalRunId] = useState<number | null>(null);
  const [deletingEvalRunCaseId, setDeletingEvalRunCaseId] = useState<number | null>(null);
  const [deletingAllEvals, setDeletingAllEvals] = useState(false);
  const [hasAnyEvaluations, setHasAnyEvaluations] = useState(false);
  const [humanDrafts, setHumanDrafts] = useState<Record<string, { numeric: string; label: string; passed: string; reason: string }>>({});
  const selectedDataset = useMemo(() => datasets.find((item) => item.id === selectedDatasetId) || null, [datasets, selectedDatasetId]);
  const selectedCases = useMemo(
    () => cases.filter((item) => selectedCaseIds.includes(item.id)),
    [cases, selectedCaseIds],
  );
  const isSelectedDatasetLocked = !!selectedDataset?.is_locked;
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

  const loadEvalRuns = async (datasetId: number, limit = 20) => (await evaluationApi.listEvalRuns(descriptor, datasetId, limit)) || [];

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

  useEffect(() => {
    setSelectedCaseIds((current) => current.filter((caseId) => cases.some((item) => item.id === caseId)));
  }, [cases]);

  useEffect(() => {
    if (!show || !selectedDatasetId) {
      return;
    }
    refreshLatestEvaluation().catch(() => undefined);
  }, [show, selectedDatasetId, descriptor.ownerId, descriptor.ownerType]);

  const handleCreateDataset = async (): Promise<boolean> => {
    setLoading(true); setError(null); setSuccess(null);
    try {
      const created = await datasetApi.createDataset(
        descriptor,
        datasetFormName.trim(),
        executionProfile,
        datasetFormDescription.trim(),
        datasetFormType,
      );
      setSelectedDatasetId(created.id);
      setDatasetFormMode(null);
      setDatasetFormName('');
      setDatasetFormDescription('');
      setDatasetFormType('golden_manual');
      await loadDatasets();
      setSuccess(`Dataset "${created.name}" created.`);
      return true;
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create dataset');
      return false;
    } finally { setLoading(false); }
  };

  const handleStartCreateDataset = () => {
    setEditingDataset(null);
    setDatasetFormMode('create');
    setDatasetFormName('');
    setDatasetFormDescription('');
    setDatasetFormType('golden_manual');
  };

  const handleStartEditDataset = (dataset: PromptDataset) => {
    setEditingDataset(dataset);
    setDatasetFormMode('edit');
    setDatasetFormName(dataset.name || '');
    setDatasetFormDescription(dataset.description || '');
    setDatasetFormType(dataset.dataset_type_code || 'golden_manual');
  };

  const handleCancelDatasetForm = () => {
    setDatasetFormMode(null);
    setEditingDataset(null);
    setDatasetFormName('');
    setDatasetFormDescription('');
    setDatasetFormType('golden_manual');
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
    const performDelete = async () => {
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
          setEvalRunSources([]);
          setBaselineSummary(null);
        }
        setSuccess(`Dataset "${dataset.name}" deleted.`);
        setError(null);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to delete dataset');
      }
    };

    const jquery = (window as any).$ || (window as any).jQuery;
    if (typeof jquery?.confirm === 'function') {
      jquery.confirm({
        title: 'Delete dataset?',
        content: `Delete dataset "${dataset.name}" and all related cases/evaluation runs? This cannot be undone.`,
        type: 'red',
        buttons: {
          confirm: {
            text: 'Delete',
            btnClass: 'btn-danger',
            action: () => {
              void performDelete();
            },
          },
          cancel: {
            text: 'Cancel',
            action: () => {},
          },
        },
      });
      return;
    }

    if (window.confirm(`Delete dataset "${dataset.name}" and all related cases/evaluation runs? This cannot be undone.`)) {
      await performDelete();
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
    if (!selectedDatasetId) {
      return;
    }
    const performDelete = async () => {
      try {
        await datasetApi.deleteDatasetCase(descriptor, selectedDatasetId, datasetCase.id);
        setSelectedCaseIds((current) => current.filter((caseId) => caseId !== datasetCase.id));
        await refreshSelectedDataset();
        await refreshLatestEvaluation();
        setSuccess(`Removed case "${datasetCase.title || datasetCase.case_key}". Related evaluation records for this case were cleaned up by cascade.`);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to delete dataset case');
      }
    };

    const title = datasetCase.title || datasetCase.case_key;
    const message = `Delete case "${title}"? Related evaluation run-case/score records for this case will be removed as well.`;
    const jquery = (window as any).$ || (window as any).jQuery;
    if (typeof jquery?.confirm === 'function') {
      jquery.confirm({
        title: 'Delete dataset case?',
        content: message,
        type: 'orange',
        buttons: {
          confirm: { text: 'Delete', btnClass: 'btn-danger', action: () => { void performDelete(); } },
          cancel: { text: 'Cancel', action: () => {} },
        },
      });
      return;
    }

    if (window.confirm(message)) {
      await performDelete();
    }
  };

  const handleSaveDatasetMetadata = async (): Promise<boolean> => {
    if (!editingDataset) {
      return false;
    }
    setLoading(true);
    setError(null);
    try {
      await datasetApi.updateDataset(descriptor, editingDataset.id, {
        name: datasetFormName.trim(),
        description: datasetFormDescription.trim(),
        datasetType: datasetFormType,
      });
      await loadDatasets();
      if (selectedDatasetId === editingDataset.id) {
        await refreshSelectedDataset();
      }
      setSuccess(`Dataset "${datasetFormName.trim()}" updated.`);
      handleCancelDatasetForm();
      return true;
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update dataset');
      return false;
    } finally {
      setLoading(false);
    }
  };

  const handleOpenMoveCases = async () => {
    if (!selectedDatasetId || selectedCaseIds.length === 0) {
      setError('Select at least one case to move or promote.');
      return;
    }
    try {
      const targets = await datasetApi.listCompatibleDatasets(descriptor, selectedDatasetId);
      setCompatibleDatasets((targets || []).filter((item) => item.id !== selectedDatasetId));
      setShowMoveModal(true);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load compatible datasets');
    }
  };

  const handleMoveCases = async (payload: { targetDatasetId: number; removeSource: boolean }) => {
    if (!selectedDatasetId || selectedCaseIds.length === 0) {
      return;
    }
    const response = await datasetApi.moveDatasetCases({
      descriptor,
      sourceDatasetId: selectedDatasetId,
      targetDatasetId: payload.targetDatasetId,
      caseIds: selectedCaseIds,
      removeSource: payload.removeSource,
    });
    setSelectedCaseIds([]);
    setShowMoveModal(false);
    await refreshSelectedDataset();
    setSuccess(
      payload.removeSource
        ? `Moved ${Number(response?.moved_count || 0)} case(s) to the target dataset.`
        : `Promoted ${Number(response?.linked_count || response?.moved_count || 0)} case(s) to the target dataset.`,
    );
  };

  const handleOpenCaseHistory = async (datasetCase: PromptDatasetCase) => {
    setCaseHistoryCase(datasetCase);
    setLoadingCaseHistory(true);
    try {
      const rows = await datasetApi.listCaseEvaluationHistory(descriptor, datasetCase.id, 40);
      setCaseHistoryEntries(rows || []);
    } catch (err) {
      setCaseHistoryEntries([]);
      setError(err instanceof Error ? err.message : 'Failed to load case history');
    } finally {
      setLoadingCaseHistory(false);
    }
  };

  const handleSaveCaseMetadata = async (payload: { title: string; notes: string; tags: string[]; expectedLabels: PromptExpectedLabels }) => {
    if (!selectedDatasetId || !editingCase) {
      return;
    }
    await datasetApi.updateDatasetCase(descriptor, selectedDatasetId, editingCase.id, payload);
    await refreshSelectedDataset();
    setSuccess(`Case "${payload.title}" updated.`);
    setEditingCase(null);
  };

  const fetchEvalRunCases = async (runId: number, label: 'Target' | 'Baseline') => {
    const rows = await evaluationApi.listEvalRunCases(descriptor, runId);
    return (rows || []).map((row) => ({
      ...row,
      comparison_label: label,
      normalized_output: row.normalized_output || parseJsonSafe(row.normalized_output_json || null, null),
      input_payload: row.input_payload || parseJsonSafe(row.input_payload_json || null, null),
      scores: (row.scores || []).map((score) => ({ ...score, details: score.details || parseJsonSafe(score.details_json || null, null) })),
    }));
  };

  const refreshEvalRunCases = async (sources: Array<{ runId: number; label: 'Target' | 'Baseline' }>) => {
    if (!sources.length) {
      setEvalRunCases([]);
      return;
    }
    const all = await Promise.all(sources.map((source) => fetchEvalRunCases(source.runId, source.label)));
    const merged = ([] as PromptEvalRunCase[]).concat(...all);
    merged.sort((a, b) => {
      const titleA = String(a.title || a.dataset_case_title || '');
      const titleB = String(b.title || b.dataset_case_title || '');
      if (titleA !== titleB) return titleA.localeCompare(titleB);
      const modelA = String(a.model || '');
      const modelB = String(b.model || '');
      if (modelA !== modelB) return modelA.localeCompare(modelB);
      const labelA = String(a.comparison_label || 'Target');
      const labelB = String(b.comparison_label || 'Target');
      return labelA.localeCompare(labelB);
    });
    setEvalRunCases(merged);
  };

  const handleRunEvaluation = async (config: { targetType: 'draft' | 'active_version' | 'version'; targetVersionId?: number; selectedModels: string[]; evalDefinitionIds: number[]; baselineEnabled: boolean; baselineTargetType: 'active_version' | 'version'; baselineTargetVersionId?: number }) => {
    if (!selectedDatasetId) return;
    setError(null); setSuccess(null); setEvalRunCases([]); setEvalResult(null); setBaselineSummary(null); setEvalRunSources([]); setHumanDrafts({});
    const result = await evaluationApi.runDatasetEval({ descriptor, datasetId: selectedDatasetId, targetType: config.targetType, targetVersionId: config.targetVersionId, draftPrompt: promptValue, runtimeOverrides: resolveRuntimeOverrides(), selectedModels: config.selectedModels, evalDefinitionIds: config.evalDefinitionIds });
    const sources: Array<{ runId: number; label: 'Target' | 'Baseline' }> = [];
    setEvalResult(result);
    if (result?.run?.id) {
      sources.push({ runId: Number(result.run.id), label: 'Target' });
    }
    if (config.baselineEnabled) {
      const baselineResult = await evaluationApi.runDatasetEval({ descriptor, datasetId: selectedDatasetId, targetType: config.baselineTargetType, targetVersionId: config.baselineTargetVersionId, draftPrompt: promptValue, runtimeOverrides: resolveRuntimeOverrides(), selectedModels: config.selectedModels, evalDefinitionIds: config.evalDefinitionIds });
      if (baselineResult?.run?.id) {
        sources.push({ runId: Number(baselineResult.run.id), label: 'Baseline' });
      }
      const mainSummary = result?.run?.summary || {}; const baseSummary = baselineResult?.run?.summary || {};
      const mainPassRate = typeof mainSummary.pass_rate === 'number' ? mainSummary.pass_rate : null;
      const baselinePassRate = typeof baseSummary.pass_rate === 'number' ? baseSummary.pass_rate : null;
      const mainAvgScore = typeof mainSummary.avg_score === 'number' ? mainSummary.avg_score : null;
      const baselineAvgScore = typeof baseSummary.avg_score === 'number' ? baseSummary.avg_score : null;
      const executionCountMain = typeof mainSummary.execution_count === 'number' ? mainSummary.execution_count : null;
      const executionCountBase = typeof baseSummary.execution_count === 'number' ? baseSummary.execution_count : null;
      setBaselineSummary({
        baselinePassRate,
        baselineAvgScore,
        passRateDelta: (mainPassRate != null && baselinePassRate != null) ? Number((mainPassRate - baselinePassRate).toFixed(2)) : null,
        avgScoreDelta: (mainAvgScore != null && baselineAvgScore != null) ? Number((mainAvgScore - baselineAvgScore).toFixed(4)) : null,
        combinedExecutionCount: (executionCountMain != null && executionCountBase != null) ? executionCountMain + executionCountBase : null,
      });

      if (result?.run?.id && baselineResult?.run?.id) {
        await evaluationApi.linkEvalRunBaseline({
          descriptor,
          runId: Number(result.run.id),
          baselineRunId: Number(baselineResult.run.id),
          baselineSummary: {
            baseline_pass_rate: baselinePassRate,
            baseline_avg_score: baselineAvgScore,
            pass_rate_delta: (mainPassRate != null && baselinePassRate != null) ? Number((mainPassRate - baselinePassRate).toFixed(2)) : null,
            avg_score_delta: (mainAvgScore != null && baselineAvgScore != null) ? Number((mainAvgScore - baselineAvgScore).toFixed(4)) : null,
            combined_execution_count: (executionCountMain != null && executionCountBase != null) ? executionCountMain + executionCountBase : null,
          },
        });
      }
    }
    setEvalRunSources(sources);
    await refreshEvalRunCases(sources);
    setSuccess('Dataset evaluation completed.');
  };

  const refreshLatestEvaluation = async () => {
    if (!selectedDatasetId) {
      setEvalResult(null);
      setEvalRunCases([]);
      setEvalRunSources([]);
      setBaselineSummary(null);
      setHasAnyEvaluations(false);
      return;
    }

    const runs = await loadEvalRuns(selectedDatasetId, 30);
    setHasAnyEvaluations((runs || []).length > 0);
    const latest = (runs || []).find((row: any) => String((row as any).status_code || '') === 'completed') as any;
    if (!latest?.id) {
      setEvalResult(null);
      setEvalRunCases([]);
      setEvalRunSources([]);
      setBaselineSummary(null);
      return;
    }

    const targetRunId = Number(latest.id);
    const targetRef = latest.target_ref && typeof latest.target_ref === 'object' ? latest.target_ref : {};
    const summary = latest.summary && typeof latest.summary === 'object' ? latest.summary : {};
    const baselineRunId = Number((targetRef as any).baseline_run_id || summary.baseline_run_id || 0);
    const sources: Array<{ runId: number; label: 'Target' | 'Baseline' }> = [{ runId: targetRunId, label: 'Target' }];
    if (baselineRunId > 0) {
      sources.push({ runId: baselineRunId, label: 'Baseline' });
    }
    setEvalResult({ run: latest, cases: [] });
    setEvalRunSources(sources);
    await refreshEvalRunCases(sources);

    if (baselineRunId > 0) {
      setBaselineSummary({
        baselinePassRate: typeof summary.baseline_pass_rate === 'number' ? summary.baseline_pass_rate : null,
        baselineAvgScore: typeof summary.baseline_avg_score === 'number' ? summary.baseline_avg_score : null,
        passRateDelta: typeof summary.pass_rate_delta === 'number' ? summary.pass_rate_delta : null,
        avgScoreDelta: typeof summary.avg_score_delta === 'number' ? summary.avg_score_delta : null,
        combinedExecutionCount: typeof summary.combined_execution_count === 'number' ? summary.combined_execution_count : null,
      });
    } else {
      setBaselineSummary(null);
    }
  };

  const handleDeleteEvaluationRun = async (runId: number) => {
    if (!selectedDatasetId || runId <= 0) return;
    const performDelete = async () => {
      setDeletingEvalRunId(runId);
      setError(null);
      try {
        await evaluationApi.deleteEvalRun(descriptor, selectedDatasetId, runId);
        await refreshLatestEvaluation();
        setSuccess(`Deleted evaluation run #${runId}.`);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to delete evaluation run');
      } finally {
        setDeletingEvalRunId(null);
      }
    };

    const message = `Delete evaluation run #${runId}? This permanently removes its run-cases and scores.`;
    const jquery = (window as any).$ || (window as any).jQuery;
    if (typeof jquery?.confirm === 'function') {
      jquery.confirm({
        title: 'Delete evaluation run?',
        content: message,
        type: 'red',
        buttons: {
          confirm: { text: 'Delete', btnClass: 'btn-danger', action: () => { void performDelete(); } },
          cancel: { text: 'Cancel', action: () => {} },
        },
      });
      return;
    }
    if (window.confirm(message)) {
      await performDelete();
    }
  };

  const handleDeleteEvaluationRunCase = async (runCaseId: number) => {
    if (!selectedDatasetId || runCaseId <= 0) return;
    const performDelete = async () => {
      setDeletingEvalRunCaseId(runCaseId);
      setError(null);
      try {
        const response = await evaluationApi.deleteEvalRunCase(descriptor, selectedDatasetId, runCaseId);
        await refreshLatestEvaluation();
        if (response?.run_deleted) {
          setSuccess(`Deleted evaluation case #${runCaseId} (last case — parent run #${response.run_id} removed).`);
        } else {
          setSuccess(`Deleted evaluation case #${runCaseId}.`);
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to delete evaluation case');
      } finally {
        setDeletingEvalRunCaseId(null);
      }
    };

    const message = `Delete this evaluation case (#${runCaseId})? Only this single case and its scores are removed — other cases in the same run stay intact.`;
    const jquery = (window as any).$ || (window as any).jQuery;
    if (typeof jquery?.confirm === 'function') {
      jquery.confirm({
        title: 'Delete evaluation case?',
        content: message,
        type: 'red',
        buttons: {
          confirm: { text: 'Delete', btnClass: 'btn-danger', action: () => { void performDelete(); } },
          cancel: { text: 'Cancel', action: () => {} },
        },
      });
      return;
    }
    if (window.confirm(message)) {
      await performDelete();
    }
  };

  const handleDeleteAllEvaluations = async () => {
    if (!selectedDatasetId) return;
    const performDeleteAll = async () => {
      setDeletingAllEvals(true);
      setError(null);
      try {
        const response = await evaluationApi.deleteEvalRunsBulk(descriptor, selectedDatasetId);
        await refreshLatestEvaluation();
        setSuccess(`Deleted ${Number(response?.deleted_count || 0)} evaluation run(s) for this dataset.`);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to delete evaluation runs');
      } finally {
        setDeletingAllEvals(false);
      }
    };

    const message = `Delete ALL evaluation runs for dataset "${selectedDataset?.name || selectedDatasetId}"? This permanently removes all related run-cases and scores.`;
    const jquery = (window as any).$ || (window as any).jQuery;
    if (typeof jquery?.confirm === 'function') {
      jquery.confirm({
        title: 'Delete all evaluation runs?',
        content: message,
        type: 'red',
        buttons: {
          confirm: { text: 'Delete All Evaluations', btnClass: 'btn-danger', action: () => { void performDeleteAll(); } },
          cancel: { text: 'Cancel', action: () => {} },
        },
      });
      return;
    }
    if (window.confirm(message)) {
      await performDeleteAll();
    }
  };

  const handleSaveHumanReview = async (runCaseId: number, definitionId: number) => {
    const key = `${runCaseId}:${definitionId}`; const draft = humanDrafts[key] || { numeric: '', label: '', passed: '', reason: '' };
    setSavingHumanKey(key);
    try {
      await evaluationApi.saveHumanScore({ descriptor, runCaseId, definitionId, scoreValueNumeric: draft.numeric.trim() === '' ? null : Number(draft.numeric), scoreValueLabel: draft.label || null, passed: draft.passed === '' ? null : Number(draft.passed), details: { reason: draft.reason || '' } });
      if (evalRunSources.length > 0) {
        await refreshEvalRunCases(evalRunSources);
      } else if (evalResult?.run?.id) {
        await refreshEvalRunCases([{ runId: Number(evalResult.run.id), label: 'Target' }]);
      }
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
                datasetName={datasetFormName}
                datasetDescription={datasetFormDescription}
                datasetType={datasetFormType}
                executionProfile={executionProfile}
                formMode={datasetFormMode}
                editingDataset={editingDataset}
                onDatasetNameChange={setDatasetFormName}
                onDatasetDescriptionChange={setDatasetFormDescription}
                onDatasetTypeChange={setDatasetFormType}
                onSelect={setSelectedDatasetId}
                onOpenCreateForm={handleStartCreateDataset}
                onOpenEditForm={handleStartEditDataset}
                onSaveForm={() => (datasetFormMode === 'edit' ? handleSaveDatasetMetadata() : handleCreateDataset())}
                onCancelForm={handleCancelDatasetForm}
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
                    <Button size="sm" variant="outline-secondary" onClick={handleAddCurrentCase} disabled={disabled || !selectedDatasetId || isSelectedDatasetLocked || !lastPlaygroundCapture || promptValue.trim() === ''}>Add Latest Playground</Button>
                    <Button size="sm" variant="outline-info" onClick={() => setShowImportModal(true)} disabled={disabled || !selectedDatasetId || isSelectedDatasetLocked}>Import Cases</Button>
                    <Button size="sm" variant="outline-primary" onClick={() => setShowAiImportModal(true)} disabled={disabled || !selectedDatasetId || isSelectedDatasetLocked}>Import With AI</Button>
                    <Button size="sm" variant="primary" onClick={() => setShowRunnerModal(true)} disabled={disabled || !selectedDatasetId || promptValue.trim() === ''}>Run Evaluation</Button>
                  </div>
                </div>
                {selectedCases.length > 0 && (
                  <div className="d-flex justify-content-between align-items-center flex-wrap border rounded bg-light p-2 mb-3" style={{ gap: 8 }}>
                    <div className="small">
                      <strong>{selectedCases.length}</strong> case(s) selected for move/promotion.
                    </div>
                    <div className="d-flex align-items-center" style={{ gap: 8 }}>
                      <Button size="sm" variant="outline-primary" onClick={() => { void handleOpenMoveCases(); }} disabled={disabled || isSelectedDatasetLocked}>
                        Move / Promote
                      </Button>
                      <Button size="sm" variant="outline-secondary" onClick={() => setSelectedCaseIds([])}>
                        Clear Selection
                      </Button>
                    </div>
                  </div>
                )}
                {isSelectedDatasetLocked && (
                  <div className="small text-warning mb-2">
                    <i className="fas fa-lock mr-1"></i>
                    This dataset is locked. Unlock it to add, import, or remove cases.
                  </div>
                )}
                <DatasetCaseTable
                  cases={cases}
                  loading={loadingCases}
                  canDelete={!selectedDataset?.is_locked}
                  selectedCaseIds={selectedCaseIds}
                  onToggleSelection={(caseId, checked) => setSelectedCaseIds((current) => (
                    checked ? Array.from(new Set([...current, caseId])) : current.filter((item) => item !== caseId)
                  ))}
                  onToggleAllSelection={(checked) => setSelectedCaseIds(checked ? cases.map((item) => item.id) : [])}
                  onPreview={setCasePreview}
                  onEdit={setEditingCase}
                  onViewHistory={(datasetCase) => { void handleOpenCaseHistory(datasetCase); }}
                  onDelete={handleDeleteCase}
                />
                <EvaluationResultsView
                  result={evalResult}
                  cases={evaluationCases}
                  baselineSummary={baselineSummary}
                  onInspectCase={setSelectedEvalCase}
                  onDeleteCaseEvaluation={(runCase) => {
                    const runCaseId = Number(runCase.run_case_id || runCase.id || 0);
                    if (runCaseId > 0) {
                      void handleDeleteEvaluationRunCase(runCaseId);
                    }
                  }}
                  deletingRunCaseId={deletingEvalRunCaseId}
                  headerActions={
                    <>
                      {hasAnyEvaluations && (
                        <Button
                          size="sm"
                          variant="danger"
                          disabled={disabled || !selectedDatasetId || deletingAllEvals}
                          onClick={() => { void handleDeleteAllEvaluations(); }}
                        >
                          {deletingAllEvals ? 'Deleting...' : 'Delete All Evaluations'}
                        </Button>
                      )}
                    </>
                  }
                />
              </div>
            </Col>
          </Row>
        </Modal.Body>
        <Modal.Footer className="py-2"><Button size="sm" variant="secondary" onClick={onHide}>Close</Button></Modal.Footer>
      </Modal>

      <DatasetImportModal show={showImportModal} onHide={() => setShowImportModal(false)} descriptor={descriptor} executionProfile={executionProfile} datasetId={selectedDatasetId} datasetApi={datasetApi} resolveRuntimeOverrides={resolveRuntimeOverrides} onImported={(count) => { refreshSelectedDataset().catch(() => undefined); setSuccess(`Imported ${count} case(s).`); }} />
      <DatasetAiImportModal
        show={showAiImportModal}
        onHide={() => setShowAiImportModal(false)}
        descriptor={descriptor}
        executionProfile={executionProfile}
        promptTemplate={promptValue}
        datasetId={selectedDatasetId}
        datasetApi={datasetApi}
        resolveRuntimeOverrides={resolveRuntimeOverrides}
        models={models}
        defaultModel={defaultModel}
        onImported={(count) => {
          refreshSelectedDataset().catch(() => undefined);
          setSuccess(`Imported ${count} case(s).`);
        }}
      />
      <DatasetMoveCasesModal
        show={showMoveModal}
        sourceDataset={selectedDataset}
        targetDatasets={compatibleDatasets}
        selectedCases={selectedCases}
        onHide={() => setShowMoveModal(false)}
        onSubmit={handleMoveCases}
        disabled={disabled || isSelectedDatasetLocked}
      />
      <DatasetCaseEditModal
        show={!!editingCase}
        datasetCase={editingCase}
        onHide={() => setEditingCase(null)}
        onSave={handleSaveCaseMetadata}
      />
      <DatasetCaseHistoryModal
        show={!!caseHistoryCase}
        datasetCase={caseHistoryCase}
        history={caseHistoryEntries}
        loading={loadingCaseHistory}
        onHide={() => setCaseHistoryCase(null)}
        onInspect={(runCase) => {
          setSelectedEvalCase(runCase);
          setCaseHistoryCase(null);
        }}
      />
      <EvaluationRunnerModal show={showRunnerModal} onHide={() => setShowRunnerModal(false)} versions={versions} activeVersionId={activeVersionId} draftPromptPreview={promptValue} models={models} defaultModel={defaultModel} evalDefinitions={evalDefs} disabled={disabled} onRun={handleRunEvaluation} />
      <DatasetCasePreviewModal datasetCase={casePreview} promptTemplate={promptValue} onHide={() => setCasePreview(null)} />

      <Modal show={!!selectedEvalCase} onHide={() => setSelectedEvalCase(null)} centered dialogClassName="prompt-modal-90 prompt-eval-detail-modal">
        <Modal.Header closeButton className="py-2"><Modal.Title className="h6">Evaluation Case Detail</Modal.Title></Modal.Header>
        <Modal.Body>
          {selectedEvalCase && (
            <>
              <div className="small mb-2"><strong>Case:</strong> {selectedEvalCase.title || selectedEvalCase.dataset_case_title || '-'}</div>
              <div className="small mb-2"><strong>Target:</strong> {selectedEvalCase.comparison_label || 'Target'} {selectedEvalCase.model ? `| ${selectedEvalCase.model}` : ''}</div>
              <div className="small text-muted mb-1">Input Snapshot</div>
              <div className="small border rounded bg-light p-2 mb-3 prompt-json-preview">
                <JsonInspector value={selectedEvalCase.input_preview || selectedEvalCase.input_payload || parseJsonSafe(selectedEvalCase.input_payload_json, {})} />
              </div>
              <div className="small text-muted mb-1">LLM Result (display_content)</div>
              <div className="small border rounded bg-light p-2 mb-3 prompt-wrap-text">
                {String(
                  selectedEvalCase.display_content
                    || ((selectedEvalCase.normalized_output || parseJsonSafe(selectedEvalCase.normalized_output_json, {})) as any)?.display_content
                    || '-',
                )}
              </div>
              <div className="small text-muted mb-1">Normalized Output</div>
              <div className="small border rounded bg-light p-2 mb-3 prompt-json-preview">
                <JsonInspector value={selectedEvalCase.normalized_output || parseJsonSafe(selectedEvalCase.normalized_output_json, {})} />
              </div>
              <div className="small text-muted mb-1">Scores</div>
              <Table size="sm" className="prompt-lab-table">
                <colgroup>
                  <col style={{ width: '20%' }} />
                  <col style={{ width: '12%' }} />
                  <col style={{ width: '12%' }} />
                  <col style={{ width: '56%' }} />
                </colgroup>
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
                            <JsonInspector value={score.details || parseJsonSafe(score.details_json, {})} className="small" />
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
