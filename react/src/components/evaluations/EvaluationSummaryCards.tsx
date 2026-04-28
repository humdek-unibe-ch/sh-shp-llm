/**
 * Evaluation Summary Cards — high-level metrics for an evaluation run.
 *
 * Displays pass/fail counts, average scores, per-definition breakdowns,
 * and optional baseline delta badges in a responsive card grid.
 *
 * @module components/evaluations/EvaluationSummaryCards
 */
import React from 'react';
import { Badge } from 'react-bootstrap';
import type { PromptEvalRunResult } from './evaluationTypes';

interface EvaluationSummaryCardsProps {
  summary: NonNullable<PromptEvalRunResult['run']['summary']>;
  baselineSummary?: {
    baselinePassRate: number | null;
    baselineAvgScore: number | null;
    passRateDelta: number | null;
    avgScoreDelta: number | null;
    combinedExecutionCount?: number | null;
  } | null;
}

/** EvaluationSummaryCards component. */
export const EvaluationSummaryCards: React.FC<EvaluationSummaryCardsProps> = ({ summary, baselineSummary }) => (
  <div className="d-flex flex-wrap">
    <Badge variant="secondary" className="mr-2 mb-2">Dataset Cases: {summary.dataset_case_count ?? summary.total_cases ?? 0}</Badge>
    <Badge variant="secondary" className="mr-2 mb-2">{baselineSummary ? 'Target Executions' : 'Executions'}: {summary.execution_count ?? summary.total_cases ?? 0}</Badge>
    {baselineSummary?.combinedExecutionCount != null && (
      <Badge variant="primary" className="mr-2 mb-2">Compared Executions: {baselineSummary.combinedExecutionCount}</Badge>
    )}
    <Badge variant="success" className="mr-2 mb-2">Pass: {summary.pass_count ?? 0}</Badge>
    <Badge variant="danger" className="mr-2 mb-2">Fail: {summary.fail_count ?? 0}</Badge>
    <Badge variant="warning" className="mr-2 mb-2">Pending: {summary.pending_review_count ?? 0}</Badge>
    <Badge variant="info" className="mr-2 mb-2">Pass Rate: {summary.pass_rate ?? 0}%</Badge>
    <Badge variant="dark" className="mr-2 mb-2">Avg Score: {summary.avg_score ?? 'n/a'}</Badge>
    {baselineSummary?.baselinePassRate != null && <Badge variant="secondary" className="mr-2 mb-2">Baseline Pass: {baselineSummary.baselinePassRate}%</Badge>}
    {baselineSummary?.baselineAvgScore != null && <Badge variant="secondary" className="mr-2 mb-2">Baseline Avg: {baselineSummary.baselineAvgScore}</Badge>}
    {baselineSummary?.passRateDelta != null && <Badge variant={baselineSummary.passRateDelta >= 0 ? 'success' : 'danger'} className="mr-2 mb-2">Pass Delta: {baselineSummary.passRateDelta >= 0 ? '+' : ''}{baselineSummary.passRateDelta}%</Badge>}
    {baselineSummary?.avgScoreDelta != null && <Badge variant={baselineSummary.avgScoreDelta >= 0 ? 'success' : 'danger'} className="mr-2 mb-2">Score Delta: {baselineSummary.avgScoreDelta >= 0 ? '+' : ''}{baselineSummary.avgScoreDelta}</Badge>}
  </div>
);
