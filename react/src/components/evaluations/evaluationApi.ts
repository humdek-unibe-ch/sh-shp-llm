import type { createPromptLabApi } from '../prompts/promptApi';

export function createEvaluationApi(api: ReturnType<typeof createPromptLabApi>) {
  return {
    listEvalDefinitions: api.listEvalDefinitions,
    runDatasetEval: api.runDatasetEval,
    getEvalRun: api.getEvalRun,
    listEvalRuns: api.listEvalRuns,
    deleteEvalRun: api.deleteEvalRun,
    deleteEvalRunsBulk: api.deleteEvalRunsBulk,
    listEvalRunCases: api.listEvalRunCases,
    linkEvalRunBaseline: api.linkEvalRunBaseline,
    saveHumanScore: api.saveHumanScore,
  };
}
