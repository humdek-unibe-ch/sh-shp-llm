import React from 'react';
import type { PromptEvalRunCase, PromptEvalRunResult } from './evaluationTypes';
import { EvaluationCaseTable } from './EvaluationCaseTable';
import { EvaluationSummaryCards } from './EvaluationSummaryCards';

interface EvaluationResultsViewProps {
  result: PromptEvalRunResult | null;
  cases: PromptEvalRunCase[];
  baselineSummary?: {
    baselinePassRate: number | null;
    baselineAvgScore: number | null;
    passRateDelta: number | null;
    avgScoreDelta: number | null;
  } | null;
  onInspectCase: (runCase: PromptEvalRunCase) => void;
}

export const EvaluationResultsView: React.FC<EvaluationResultsViewProps> = ({
  result,
  cases,
  baselineSummary,
  onInspectCase,
}) => {
  if (!result?.run?.summary) {
    return null;
  }

  return (
    <div className="mt-3">
      <div className="small text-muted mb-2">Evaluation Summary</div>
      <EvaluationSummaryCards summary={result.run.summary} baselineSummary={baselineSummary} />
      <EvaluationCaseTable cases={cases} onInspect={onInspectCase} />
    </div>
  );
};
