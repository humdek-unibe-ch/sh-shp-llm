/**
 * Answers Pane
 * ============
 *
 * The answers pane of the form interview: what the participant has said
 * so far, the controls they may edit by hand, validation messages and the
 * one primary action - Save.
 *
 * One list, one entry per visible question, in form order. A question is
 * answered either by the coach (its answer is shown as text) or in a
 * hosted native control, which sits in the slot of its own question. The
 * pane grows with its content - there is no inner scrollbar - and the
 * question the coach is asking right now is highlighted and scrolled into
 * view, so the participant always knows where the conversation is.
 *
 * `progressLayout` picks the presentation of the entries (stepper, cards,
 * list); `none` drops the progress marks and the collected answers and
 * keeps only the hand-editable fields.
 *
 * When a saved entry is being revised the pane says so, marks the answers
 * that differ from the saved record and shows what they were.
 */

import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Spinner } from 'react-bootstrap';
import type {
  InterviewConfig,
  InterviewFieldDef,
  InterviewState,
  InterviewUiLabels,
} from '../../../types/interview';
import { fillTemplate, valueText } from './interviewUtils';

interface AnswersPaneProps {
  config: InterviewConfig;
  labels: InterviewUiLabels;
  state: InterviewState | null;
  /** A request is in flight (disables Save). */
  working: boolean;
  /** The approval request itself is in flight. */
  approving: boolean;
  fieldErrors: Record<string, string> | null;
  formErrors: Record<string, string> | null;
  unsupportedFiles: string[];
  /** Fields edited through a hosted native control. */
  nativeNames: ReadonlySet<string>;
  /** Fields that just received a value from the coach (brief highlight). */
  flash: ReadonlySet<string>;
  onApprove: () => void;
  /** Hosted native controls; they move into the slots of their questions. */
  renderNative?: (scope: HTMLElement | null) => React.ReactNode;
}

type StepStatus = 'done' | 'active' | 'pending' | 'none';

interface Step {
  name: string;
  def: InterviewFieldDef;
  status: StepStatus;
  required: boolean;
  /** Position among the interview questions; null without progress. */
  number: number | null;
  edited: boolean;
  hosted: boolean;
}

const ErrorList: React.FC<{ rows: Record<string, string>; className: string }> = ({
  rows,
  className,
}) => (
  <div className={`alert alert-warning py-2 mb-2 ${className}`} role="alert">
    <ul className="mb-0 pl-3">
      {Object.entries(rows).map(([name, msg]) => (
        <li key={name}>
          <strong>{name}</strong>: {msg}
        </li>
      ))}
    </ul>
  </div>
);

export const AnswersPane: React.FC<AnswersPaneProps> = ({
  config,
  labels,
  state,
  working,
  approving,
  fieldErrors,
  formErrors,
  unsupportedFiles,
  nativeNames,
  flash,
  onApprove,
  renderNative,
}) => {
  const edit = state?.mode === 'edit';
  const layout = config.progressLayout;
  const plain = layout === 'none';
  const progress = state?.progress ?? null;
  const baseline = state?.baseline ?? {};
  const changed = useMemo(() => new Set(state?.changed ?? []), [state?.changed]);
  const [scopeEl, setScopeEl] = useState<HTMLElement | null>(null);

  const total = progress?.total ?? 0;
  const done = progress?.done ?? 0;
  const percent = total > 0 ? Math.round((done / total) * 100) : 0;
  const showCount = !plain && !edit && total > 0;
  const canSave = !!state?.can_approve && !working;
  // The coach's current question; before its first answer the first open one.
  const asking = state?.asking ?? progress?.active ?? null;

  const steps = useMemo<Step[]>(() => {
    if (!state) return [];
    let counter = 0;
    const out: Step[] = [];
    for (const [name, def] of Object.entries(state.fields ?? {})) {
      if (def.hidden) continue;
      const hosted = nativeNames.has(name);
      if (plain && !hosted) continue;
      const pf = progress?.fields?.[name];
      out.push({
        name,
        def,
        status: pf ? pf.status : 'none',
        required: pf ? pf.required : !!def.required,
        number: pf ? ++counter : null,
        edited: edit && changed.has(name),
        hosted,
      });
    }
    return out;
  }, [state, nativeNames, plain, progress, edit, changed]);

  // Bring the question the coach moved to into view; the cursor stays in
  // the chat box. Minimal movement, none when it is already visible.
  const shownRef = useRef<string | null>(null);
  useEffect(() => {
    if (!scopeEl || asking === shownRef.current) return;
    shownRef.current = asking;
    if (!asking) return;
    const target = scopeEl.querySelector<HTMLElement>(
      `[data-field="${CSS.escape(asking)}"]`
    );
    if (!target) return;
    const calm = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    target.scrollIntoView({ block: 'nearest', behavior: calm ? 'auto' : 'smooth' });
  }, [asking, scopeEl, steps.length]);

  const anyDone = steps.some((s) => s.status === 'done');
  const showBar = showCount;

  return (
    <section className="card border-0 shadow-sm llm-chat-panel llm-iv-panel llm-iv-collected">
      <div className="card-header bg-white llm-iv-pane-head">
        <span className="llm-iv-pane-title">{labels.collectedTitle}</span>
        {edit ? (
          changed.size > 0 ? (
            <span className="badge badge-pill llm-iv-edited-badge" aria-live="polite">
              {changed.size} {labels.editedBadge}
            </span>
          ) : null
        ) : showCount ? (
          <span className="badge badge-pill badge-light llm-iv-count" aria-live="polite">
            {done}/{total}
          </span>
        ) : null}
      </div>
      {showBar ? (
        <div
          className="progress llm-iv-progress"
          role="progressbar"
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={percent}
          aria-label={labels.progressLabel}
        >
          <div className="progress-bar" style={{ width: `${percent}%` }} />
        </div>
      ) : null}

      <div className="llm-iv-pane-body" ref={setScopeEl}>
        {edit ? <p className="llm-iv-hint small text-muted mb-3">{labels.editingHint}</p> : null}
        {fieldErrors ? <ErrorList rows={fieldErrors} className="llm-iv-field-errors" /> : null}
        {formErrors ? <ErrorList rows={formErrors} className="llm-iv-form-errors" /> : null}
        {unsupportedFiles.length > 0 ? (
          <div className="alert alert-info py-2 mb-2 llm-iv-unsupported" role="alert">
            {fillTemplate(labels.unsupportedFiles, { fields: unsupportedFiles.join(', ') })}
          </div>
        ) : null}

        {!state ? (
          <div className="llm-iv-skeleton" aria-hidden="true">
            <span />
            <span />
            <span />
          </div>
        ) : null}

        {steps.length > 0 ? (
          <ol className={`llm-iv-steps llm-iv-steps-${plain ? 'plain' : layout}`}>
            {steps.map((step) => {
              const { name, def } = step;
              const isAsked = name === asking;
              const answer = !step.hosted ? valueText(def, state?.draft?.[name]) : '';
              const before = step.edited ? valueText(def, baseline[name]) : '';
              const stateText =
                step.status === 'done'
                  ? labels.stepDone
                  : step.status === 'none'
                    ? ''
                    : isAsked || step.status === 'active'
                      ? labels.stepActive
                      : labels.stepPending;
              const cls = [
                'llm-iv-step',
                `is-${step.status}`,
                step.hosted ? 'is-hosted' : '',
                isAsked ? 'is-asking' : '',
                step.edited ? 'is-edited' : '',
                flash.has(name) ? 'is-flash' : '',
              ]
                .filter(Boolean)
                .join(' ');
              return (
                <li
                  key={name}
                  className={cls}
                  data-field={name}
                  aria-current={isAsked ? 'step' : undefined}
                >
                  <span className="llm-iv-step-mark" aria-hidden="true">
                    {step.edited ? (
                      <i className="fas fa-pen" />
                    ) : step.status === 'done' ? (
                      <i className="fas fa-check" />
                    ) : step.number !== null ? (
                      step.number
                    ) : (
                      <i className="fas fa-circle" />
                    )}
                  </span>
                  <div className="llm-iv-step-main">
                    <div className="llm-iv-step-head">
                      <span className="llm-iv-step-label">
                        {def.label || name}
                        {step.required ? (
                          <span className="llm-iv-req" title={labels.required}>
                            *
                          </span>
                        ) : null}
                        {stateText ? <span className="sr-only"> ({stateText})</span> : null}
                      </span>
                      {step.edited ? (
                        <span className="badge badge-pill llm-iv-edited-badge">
                          {labels.editedBadge}
                        </span>
                      ) : isAsked && !plain && layout !== 'stepper' ? (
                        <span className="llm-iv-asking-tag">{labels.stepActive}</span>
                      ) : null}
                    </div>
                    {step.hosted ? (
                      <div className="llm-iv-step-slot" data-llm-iv-slot={name} />
                    ) : answer !== '' ? (
                      <div className="llm-iv-step-answer">{answer}</div>
                    ) : isAsked || plain ? (
                      <div className="llm-iv-step-empty">{labels.notAnswered}</div>
                    ) : null}
                    {step.edited ? (
                      <div className="llm-iv-step-before">
                        <span className="llm-iv-step-before-label">{labels.valueBefore}</span>
                        {before !== '' ? before : labels.valueEmpty}
                      </div>
                    ) : null}
                  </div>
                </li>
              );
            })}
          </ol>
        ) : null}

        {!edit && state && !plain && steps.length > 0 && !anyDone ? (
          <p className="llm-iv-hint text-muted small mt-3 mb-0">{labels.collectedHint}</p>
        ) : null}

        {renderNative ? renderNative(scopeEl) : null}
      </div>

      <div className="card-footer bg-white llm-iv-pane-foot">
        <button
          type="button"
          className="btn btn-primary btn-block llm-iv-save"
          onClick={onApprove}
          disabled={!canSave}
        >
          {approving ? (
            <Spinner animation="border" size="sm" />
          ) : (
            <>
              <i className="fas fa-check mr-2" aria-hidden="true" />
              {config.labels.approve}
            </>
          )}
        </button>
        {state && !state.can_approve ? (
          <div className="llm-iv-save-hint small text-muted mt-1">
            {edit && changed.size === 0 ? labels.noChanges : labels.approveHint}
          </div>
        ) : null}
      </div>
    </section>
  );
};

export default AnswersPane;
