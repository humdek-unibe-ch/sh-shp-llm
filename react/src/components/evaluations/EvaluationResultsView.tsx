import React, { useEffect, useMemo, useState } from 'react';
import { Button, Form } from 'react-bootstrap';
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
    combinedExecutionCount?: number | null;
  } | null;
  onInspectCase: (runCase: PromptEvalRunCase) => void;
  headerActions?: React.ReactNode;
  onDeleteCaseEvaluation?: (runCase: PromptEvalRunCase) => void;
  deletingRunId?: number | null;
}

export const EvaluationResultsView: React.FC<EvaluationResultsViewProps> = ({
  result,
  cases,
  baselineSummary,
  onInspectCase,
  headerActions,
  onDeleteCaseEvaluation,
  deletingRunId = null,
}) => {
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);

  const normalizedSearch = search.trim().toLowerCase();
  const filteredCases = useMemo(() => {
    if (!normalizedSearch) {
      return cases;
    }
    return cases.filter((item) => {
      const haystack = [
        item.title,
        item.dataset_case_title,
        item.model,
        item.input_preview,
        item.display_content,
        ...(item.input_fields || []).map((f) => `${f.key} ${f.value}`),
        ...(item.scores || []).map((s) => `${s.eval_name || ''} ${s.score_type || ''} ${s.score_value_label || ''} ${s.score_value_numeric ?? ''}`),
      ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();
      return haystack.includes(normalizedSearch);
    });
  }, [cases, normalizedSearch]);

  const totalPages = Math.max(1, Math.ceil(filteredCases.length / pageSize));
  const safePage = Math.min(page, totalPages);
  const pageStart = (safePage - 1) * pageSize;
  const pagedCases = filteredCases.slice(pageStart, pageStart + pageSize);

  useEffect(() => {
    setPage(1);
  }, [normalizedSearch, pageSize, cases.length]);

  if (!result?.run?.summary) {
    return null;
  }

  return (
    <div className="mt-3">
      <div className="d-flex justify-content-between align-items-center mb-2">
        <div className="small text-muted">Evaluation Summary</div>
        {headerActions ? <div className="d-flex align-items-center" style={{ gap: 8 }}>{headerActions}</div> : null}
      </div>
      <EvaluationSummaryCards summary={result.run.summary} baselineSummary={baselineSummary} />
      <div className="d-flex justify-content-between align-items-center flex-wrap mt-2" style={{ gap: 8 }}>
        <Form.Control
          size="sm"
          type="text"
          placeholder="Search evaluations..."
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          style={{ maxWidth: 320 }}
        />
        <div className="small text-muted">
          Showing {filteredCases.length === 0 ? 0 : pageStart + 1}-{Math.min(pageStart + pageSize, filteredCases.length)} of {filteredCases.length}
        </div>
      </div>
      <EvaluationCaseTable cases={pagedCases} onInspect={onInspectCase} onDeleteEvaluation={onDeleteCaseEvaluation} deletingRunId={deletingRunId} />
      <div className="d-flex justify-content-between align-items-center mt-2">
        <div className="d-flex align-items-center" style={{ gap: 8 }}>
          <span className="small text-muted">Rows:</span>
          <Form.Control
            as="select"
            size="sm"
            value={String(pageSize)}
            onChange={(event) => setPageSize(Number(event.target.value))}
            style={{ width: 90 }}
          >
            <option value="10">10</option>
            <option value="25">25</option>
            <option value="50">50</option>
          </Form.Control>
        </div>
        <div className="d-flex align-items-center" style={{ gap: 8 }}>
          <Button size="sm" variant="outline-secondary" disabled={safePage <= 1} onClick={() => setPage((current) => Math.max(1, current - 1))}>Prev</Button>
          <span className="small text-muted">Page {safePage} / {totalPages}</span>
          <Button size="sm" variant="outline-secondary" disabled={safePage >= totalPages} onClick={() => setPage((current) => Math.min(totalPages, current + 1))}>Next</Button>
        </div>
      </div>
    </div>
  );
};
