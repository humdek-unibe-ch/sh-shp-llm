import type { createPromptLabApi } from '../prompts/promptApi';

export function createEvaluationApi(api: ReturnType<typeof createPromptLabApi>) {
  return {
    listEvalDefinitions: api.listEvalDefinitions,
    runDatasetEval: api.runDatasetEval,
    getEvalRun: api.getEvalRun,
    listEvalRunCases: api.listEvalRunCases,
    saveHumanScore: api.saveHumanScore,
  };
}
