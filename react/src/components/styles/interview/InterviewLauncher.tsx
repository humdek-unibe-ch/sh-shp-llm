/**
 * Interview Launcher
 * ==================
 *
 * The collapsed state of the interview: one call-to-action card.
 *
 *   - Unfinished draft (retentions that keep it): the primary button
 *     continues it, "start over" discards it.
 *   - Otherwise (nothing to continue, or a retention that begins a fresh
 *     chat on every open): the primary button carries the section's
 *     "new" label and starts a new draft.
 *
 * After a save the card also shows a short success note, so the
 * participant sees the result next to the refreshed list.
 */

import React from 'react';
import { Spinner } from 'react-bootstrap';
import type { InterviewConfig, InterviewUiLabels } from '../../../types/interview';
import { faClass } from '../shared/ChatPanelHeader';
import { MarkdownRenderer } from '../shared/MarkdownRenderer';
import { fillTemplate } from './interviewUtils';

interface InterviewLauncherProps {
  config: InterviewConfig;
  labels: InterviewUiLabels;
  /** Checking for an unfinished draft. */
  peeking: boolean;
  /** Progress of the unfinished draft, null when there is none to continue. */
  resume: { done: number; total: number } | null;
  /** The interview is opening. */
  busy: boolean;
  error: string | null;
  /** CMS danger_blocked_message (markdown/HTML) — render rich text, no dismiss. */
  errorMarkdown?: boolean;
  /** An entry was just saved. */
  saved: boolean;
  onDismissSaved: () => void;
  onDismissError: () => void;
  onStart: () => void;
  onRestart: () => void;
}

export const InterviewLauncher: React.FC<InterviewLauncherProps> = ({
  config,
  labels,
  peeking,
  resume,
  busy,
  error,
  errorMarkdown = false,
  saved,
  onDismissSaved,
  onDismissError,
  onStart,
  onRestart,
}) => {
  const text = resume
    ? fillTemplate(labels.resumeText, { done: resume.done, total: resume.total })
    : labels.launcherText;
  const primary = resume ? labels.resume : config.labels.start;

  return (
    <div className="card llm-iv-launcher">
      <div className="card-body">
        {saved ? (
          <div className="alert alert-success alert-dismissible llm-iv-saved" role="status">
            <strong>
              <i className="fas fa-check-circle mr-1" aria-hidden="true" />
              {labels.savedTitle}
            </strong>{' '}
            {labels.savedText}
            <button type="button" className="close" aria-label={labels.close} onClick={onDismissSaved}>
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
        ) : null}
        <div className="llm-iv-launcher-row">
          <span className="llm-iv-launcher-icon" aria-hidden="true">
            <i className={faClass(labels.launcherIcon || '', 'fas fa-flag')} />
          </span>
          <div className="llm-iv-launcher-text">
            <h5 className="card-title llm-iv-launcher-title mb-1">{labels.launcherTitle}</h5>
            <p className="card-text text-muted mb-0">{peeking ? '\u00a0' : text}</p>
          </div>
          <div className="llm-iv-launcher-actions">
            <button
              type="button"
              className="btn btn-primary llm-iv-launcher-start"
              onClick={onStart}
              disabled={peeking || busy}
            >
              {peeking || busy ? <Spinner animation="border" size="sm" /> : primary}
            </button>
            {resume && !busy ? (
              <button type="button" className="btn btn-link llm-iv-launcher-restart" onClick={onRestart}>
                {labels.restart}
              </button>
            ) : null}
          </div>
        </div>
        {error ? (
          <div
            className={`alert alert-danger mt-3 mb-0${errorMarkdown ? '' : ' alert-dismissible'}`}
            role="alert"
          >
            {errorMarkdown ? (
              <div className="llm-iv-notice-md">
                <MarkdownRenderer content={error} />
              </div>
            ) : (
              <span>{error}</span>
            )}
            {!errorMarkdown ? (
              <button type="button" className="close" aria-label={labels.close} onClick={onDismissError}>
                <span aria-hidden="true">&times;</span>
              </button>
            ) : null}
          </div>
        ) : null}
      </div>
    </div>
  );
};

export default InterviewLauncher;
