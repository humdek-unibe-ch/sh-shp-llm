import type { createPromptLabApi } from '../prompts/promptApi';

export function createEvaluationApi(api: ReturnType<typeof createPromptLabApi>) {
  return {
    listEvalDefinitions: api.listEvalDefinitions,
    runDatasetEval: api.runDatasetEval,
    getEvalRun: api.getEvalRun,
    listEvalRuns: api.listEvalRuns,
    listEvalRunCases: api.listEvalRunCases,
    linkEvalRunBaseline: api.linkEvalRunBaseline,
    saveHumanScore: api.saveHumanScore,
  };
}
