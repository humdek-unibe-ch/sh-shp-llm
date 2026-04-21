/**
 * Evaluation API client factory.
 *
 * Thin wrapper that exposes evaluation-specific endpoints from the
 * Prompt Lab API (run creation, scoring, baseline linking, human review).
 *
 * @module components/evaluations/evaluationApi
 */
import type { createPromptLabApi } from '../prompts/promptApi';

/** Creates a scoped API for evaluation CRUD and run management. */
export function createEvaluationApi(api: ReturnType<typeof createPromptLabApi>) {
  return {
    listEvalDefinitions: api.listEvalDefinitions,
    runDatasetEval: api.runDatasetEval,
    getEvalRun: api.getEvalRun,
    listEvalRuns: api.listEvalRuns,
    deleteEvalRun: api.deleteEvalRun,
    deleteEvalRunCase: api.deleteEvalRunCase,
    deleteEvalRunsBulk: api.deleteEvalRunsBulk,
    listEvalRunCases: api.listEvalRunCases,
    linkEvalRunBaseline: api.linkEvalRunBaseline,
    saveHumanScore: api.saveHumanScore,
  };
}
