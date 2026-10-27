/**
 * LLM Form Interview
 * ==================
 *
 * The llmFormLogList interview shell. Two presentations of one flow:
 *
 *  - Launcher: a single call-to-action card (start / continue).
 *  - Workspace: the answers pane (with the one Save action) next to the
 *    coach chat - the llmChat panel with interview behaviour. The chat
 *    position is configurable; with progress layout "none" and no hand
 *    editable fields the workspace is the chat alone, which then carries
 *    the Save button.
 *
 * The workspace opens like the core form does - the URL decides:
 * `?record=new&sid=<section>` starts a new entry, `?record=<id>&sid=…`
 * revises a saved one, a plain URL shows the list (plus the launcher).
 * Starting pushes that URL, so reload and back/forward land on the same
 * screen and the address can be shared.
 *
 *  - Page mode: the workspace takes the place of the list.
 *  - Modal mode (`show_form_in_modal`): a real modal window with header
 *    and close button above the list. Closing it (x, Esc, the Minimize
 *    action) keeps an unfinished draft; clicking outside never closes.
 *
 * Saving closes the workspace, refreshes the list and leaves a short
 * success note on the launcher. All writes go through the interview JSON
 * contract - nothing is persisted to dataRows before approval.
 *
 * Draft model: exactly one canonical server draft (stateRef is updated
 * synchronously in adoptState) plus per-field pending edits carrying a
 * version. Mutations run through one serialized queue; each request
 * reads the latest state inside its queue slot (never a stale capture),
 * and a success only acknowledges the pending values it actually sent -
 * edits typed while a request is in flight keep their newer versions
 * and stay queued. Background work never disables the composer, so the
 * cursor stays where the participant put it.
 */

import React, {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import { flushSync } from 'react-dom';
import { Modal } from 'react-bootstrap';
import type { InterviewConfig, InterviewState } from '../../../types/interview';
import { InterviewApiError } from '../../../types/interview';
import {
  approveInterview,
  basePageUrl,
  listScopeUrl,
  openInterview,
  readInterview,
  recordScopeUrl,
  sendTurn,
  updateDraft,
} from '../../../utils/interviewApi';
import { AnswersPane } from './AnswersPane';
import { ApprovalDialog } from './ApprovalDialog';
import type { ChatNotice } from './InterviewChat';
import { InterviewChat } from './InterviewChat';
import { InterviewLauncher } from './InterviewLauncher';
import { NativeInterviewFields } from './NativeInterviewFields';
import { changedDraftNames, chatMessages, reviewRows, userMessageCount } from './interviewUtils';

interface LlmFormInterviewProps {
  config: InterviewConfig;
  mount: HTMLElement;
  nativeHolder: HTMLElement | null;
}

interface PendingEdit {
  v: unknown;
  ver: number;
}

type OpKind = 'idle' | 'open' | 'turn' | 'approve' | 'draft';

interface OpenOptions {
  history?: 'push' | 'replace' | 'none';
  /** Reset this thread and start a fresh draft. */
  restartId?: number;
  /** Reload or back/forward: keep the unfinished thread instead of starting fresh. */
  resume?: boolean;
  /** Re-open after the thread was cleared elsewhere: local edits move to the new thread. */
  keepPending?: boolean;
}

/** Theme variables the author may set; copied onto modals, which render on <body>. */
const THEME_VARS = [
  '--llm-iv-accent',
  '--llm-iv-done',
  '--llm-iv-border',
  '--llm-iv-radius',
  '--llm-iv-surface',
  '--llm-iv-panel-bg',
  '--llm-iv-chat-bg',
  '--llm-iv-gap',
  '--llm-iv-icon-bg',
  '--llm-iv-height',
  '--llm-iv-chat-width',
  '--llm-iv-cards-cols',
  '--llm-iv-ink',
  '--llm-iv-muted',
  '--llm-iv-soft',
  '--llm-iv-track',
  '--llm-iv-edited',
  '--llm-iv-sticky-top',
  '--llm-iv-modal-bg',
];

/** How long an answer the coach just filled in stays highlighted. */
const FLASH_MS = 2400;

function newToken(prefix: string): string {
  const rnd =
    typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
  return `${prefix}-${rnd}`;
}

/** Parse a strict record scope: 'new' or a positive int, else undefined. */
function parseRecordParam(raw: string | null): number | null | undefined {
  if (raw === null) return undefined;
  if (raw === 'new') return null;
  if (!/^\d+$/.test(raw)) return undefined;
  const n = parseInt(raw, 10);
  return n > 0 ? n : undefined;
}

/**
 * Which scope opens on load: a scoped deep link for this section, or the
 * configured record of a list-hidden mount. Foreign-section deep links
 * and malformed scopes never open. null = the launcher shows.
 */
function initialScope(config: InterviewConfig): { record: number | null } | null {
  if (!config.autoOpen) return null;
  const url = new URL(window.location.href);
  const sid = url.searchParams.get('sid');
  const next = parseRecordParam(url.searchParams.get('record'));
  if (sid === null && next === undefined) return { record: config.recordId };
  if (sid !== String(config.sectionId) || next === undefined) return null;
  return { record: next };
}

/** A reload or back/forward visit keeps the unfinished draft; a fresh visit follows the retention. */
function isRestoreNavigation(): boolean {
  try {
    const nav = window.performance
      .getEntriesByType('navigation')[0] as PerformanceNavigationTiming | undefined;
    return !!nav && (nav.type === 'reload' || nav.type === 'back_forward');
  } catch {
    return false;
  }
}

/** sessionStorage key: the same shared form can live on many pages. */
function storageKey(config: InterviewConfig, kind: string): string {
  return `llm-iv-${kind}:${config.sectionId}:${window.location.pathname}`;
}

/** The "saved" note outlives the list refresh that replaces this component. */
const SAVED_NOTE_TTL_MS = 60_000;

function writeSavedNote(config: InterviewConfig): void {
  try {
    window.sessionStorage.setItem(storageKey(config, 'saved'), String(Date.now()));
  } catch {
    /* storage unavailable */
  }
}

function hasSavedNote(config: InterviewConfig): boolean {
  try {
    const at = Number(window.sessionStorage.getItem(storageKey(config, 'saved')));
    return at > 0 && Date.now() - at < SAVED_NOTE_TTL_MS;
  } catch {
    return false;
  }
}

function clearSavedNote(config: InterviewConfig): void {
  try {
    window.sessionStorage.removeItem(storageKey(config, 'saved'));
  } catch {
    /* storage unavailable */
  }
}

/** Back to the plain page URL: the list (and launcher) is what a reload shows. */
function clearDeepLink(): void {
  try {
    const url = new URL(window.location.href);
    if (url.searchParams.has('record') || url.searchParams.has('sid')) {
      window.history.replaceState(window.history.state, '', basePageUrl() + url.hash);
    }
  } catch {
    /* history unavailable */
  }
}

export const LlmFormInterview: React.FC<LlmFormInterviewProps> = ({
  config,
  mount,
  nativeHolder,
}) => {
  const labels = config.labels.ui;
  const [open, setOpen] = useState(false);
  const [peeking, setPeeking] = useState(false);
  const [resume, setResume] = useState<{ done: number; total: number } | null>(null);
  const [savedNote, setSavedNote] = useState(() => hasSavedNote(config));
  const [recordId, setRecordId] = useState<number | null>(config.recordId);
  const [state, setState] = useState<InterviewState | null>(null);
  const [working, setWorking] = useState(0);
  const [opKind, setOpKind] = useState<OpKind>('idle');
  const [notice, setNotice] = useState<ChatNotice | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string> | null>(null);
  const [formErrors, setFormErrors] = useState<Record<string, string> | null>(null);
  const [approveOpen, setApproveOpen] = useState(false);
  const [clearOpen, setClearOpen] = useState(false);
  const [unsupportedFiles, setUnsupportedFiles] = useState<string[]>([]);
  const [pendingTick, setPendingTick] = useState(0);
  const [pendingText, setPendingText] = useState<string | null>(null);
  const [awaitingCoach, setAwaitingCoach] = useState(false);
  const [flash, setFlash] = useState<ReadonlySet<string>>(() => new Set<string>());

  const pendingPatch = useRef<Record<string, PendingEdit>>({});
  const pendingNativePatch = useRef<Record<string, PendingEdit>>({});
  const pendingSeq = useRef(0);
  const queueRef = useRef<Promise<void>>(Promise.resolve());
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const flashTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const abortRef = useRef<AbortController | null>(null);
  const epochRef = useRef(0);
  const turnIdRef = useRef<string | null>(null);
  const approveTokenRef = useRef<string | null>(null);
  const bootedRef = useRef(false);
  const mountedRef = useRef(true);
  const stateRef = useRef<InterviewState | null>(null);
  const validatorRef = useRef<((names: string[]) => boolean) | null>(null);
  const sendRef = useRef<((text: string, start?: boolean) => Promise<boolean>) | null>(null);
  const recoverScopeRef = useRef<() => void>(() => undefined);
  const openingRef = useRef(false);
  const workspaceRef = useRef<HTMLDivElement | null>(null);

  const beginOp = useCallback((kind: OpKind) => {
    setOpKind(kind);
    setWorking((w) => w + 1);
  }, []);
  const endOp = useCallback(() => {
    setWorking((w) => Math.max(0, w - 1));
  }, []);

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      epochRef.current += 1;
      abortRef.current?.abort();
      if (debounceRef.current) clearTimeout(debounceRef.current);
      if (flashTimerRef.current) clearTimeout(flashTimerRef.current);
    };
  }, []);

  // The saved note was handed over by the previous instance: show it once.
  useEffect(() => {
    clearSavedNote(config);
  }, [config]);
  useEffect(() => {
    if (!savedNote) return undefined;
    const timer = setTimeout(() => setSavedNote(false), 8000);
    return () => clearTimeout(timer);
  }, [savedNote]);

  // Page mode: while the workspace is open it takes the place of the
  // section's list (like the core page form). A modal sits above the
  // list instead, which stays where it is.
  useEffect(() => {
    const root = mount.closest<HTMLElement>('.formUserInputLogList');
    if (!root) return undefined;
    root.classList.toggle('llm-iv-open', open);
    root.classList.toggle('llm-iv-focus', open && !config.modal);
    return () => root.classList.remove('llm-iv-open', 'llm-iv-focus');
  }, [mount, open, config.modal]);

  /** Synchronous canonical-state adoption: the ref is truthy now, and
   * the scope record follows the binding (an approval binds a created
   * row to the thread). */
  const adoptState = useCallback((next: InterviewState | null) => {
    stateRef.current = next;
    setState(next);
    if (next) {
      setFieldErrors(null);
      setRecordId(next.record_id);
    }
  }, []);

  /** Briefly mark the answers the coach just filled in, so the participant
   * sees where they landed. The participant's own edits never flash. */
  const highlight = useCallback(
    (before: Record<string, unknown> | undefined, after: Record<string, unknown> | undefined) => {
      const names = changedDraftNames(before, after).filter(
        (n) => !(n in pendingPatch.current) && !(n in pendingNativePatch.current)
      );
      if (names.length === 0 || !mountedRef.current) return;
      setFlash(new Set(names));
      if (flashTimerRef.current) clearTimeout(flashTimerRef.current);
      flashTimerRef.current = setTimeout(() => setFlash(new Set<string>()), FLASH_MS);
    },
    []
  );

  const hasPending = useCallback(
    () =>
      Object.keys(pendingPatch.current).length > 0 ||
      Object.keys(pendingNativePatch.current).length > 0,
    []
  );

  const pendingFieldSet = useMemo(() => {
    const s = new Set<string>();
    Object.keys(pendingPatch.current).forEach((k) => s.add(k));
    Object.keys(pendingNativePatch.current).forEach((k) => s.add(k));
    return s;
    // pendingTick bumps on every local edit
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pendingTick]);

  const snapshotPending = useCallback(
    () => ({
      patch: { ...pendingPatch.current },
      native: { ...pendingNativePatch.current },
    }),
    []
  );

  const valuesOf = useCallback((snap: Record<string, PendingEdit>) => {
    const out: Record<string, unknown> = {};
    for (const [k, x] of Object.entries(snap)) out[k] = x.v;
    return out;
  }, []);

  /** Drop only the pending values the server actually acknowledged and
   * that did not change locally since the snapshot was taken. */
  const ackPending = useCallback(
    (snap: { patch: Record<string, PendingEdit>; native: Record<string, PendingEdit> }) => {
      let removed = false;
      for (const [k, x] of Object.entries(snap.patch)) {
        if (pendingPatch.current[k]?.ver === x.ver) {
          delete pendingPatch.current[k];
          removed = true;
        }
      }
      for (const [k, x] of Object.entries(snap.native)) {
        if (pendingNativePatch.current[k]?.ver === x.ver) {
          delete pendingNativePatch.current[k];
          removed = true;
        }
      }
      // Bump the memo so acknowledged fields leave pendingFieldSet and
      // their native controls accept server values again.
      if (removed) setPendingTick((t) => t + 1);
    },
    []
  );

  /** Serialize every mutation; stale-epoch tasks exit without running. */
  const enqueue = useCallback(<T,>(op: () => Promise<T>): Promise<T> => {
    const epoch = epochRef.current;
    const task = queueRef.current.then(() => {
      if (epoch !== epochRef.current) {
        throw new InterviewApiError('aborted', 'Interview scope changed.', 0);
      }
      return op();
    });
    queueRef.current = task.then(
      () => undefined,
      () => undefined
    );
    return task;
  }, []);

  /** Participant-facing text for an error; server messages stay in the
   * console/audit log, the UI speaks the participant's language. */
  const describe = useCallback(
    (e: InterviewApiError): string => {
      switch (e.type) {
        case 'danger_detected':
          // CMS `danger_blocked_message` (markdown) from the server.
          return e.message || labels.errorGeneric;
        case 'rate_limited':
          return labels.errorRate;
        case 'conflict':
          return labels.errorConflict;
        case 'llm_invalid':
          return labels.errorBusy;
        case 'validation':
          return labels.errorInvalid;
        case 'approval_failed':
          return labels.errorSave;
        default:
          return labels.errorGeneric;
      }
    },
    [labels]
  );

  const schedulePatchRef = useRef<() => void>(() => undefined);

  const applyError = useCallback(
    (e: unknown, retry?: () => void) => {
      if (e instanceof InterviewApiError) {
        if (e.type === 'aborted') return;
        if (e.state) {
          // Conflict recovery: adopt the fresh projection first (it
          // clears field errors), then surface the incoming errors.
          // Newer local edits stay pending and the patch retries with
          // the new revision.
          adoptState(e.state);
          if (e.type === 'conflict' && hasPending()) schedulePatchRef.current();
        } else if (e.type === 'conflict' && stateRef.current) {
          // The thread was cleared elsewhere and none is live: open a
          // fresh one for the same scope and carry local edits over.
          recoverScopeRef.current();
        }
        // Keep the technical detail for support, show the friendly text.
        console.warn('[llm-interview]', e.type, e.message);
        const isDanger = e.type === 'danger_detected';
        setNotice({
          message: describe(e),
          // Danger blocks the thread; retrying the same text is wrong.
          retry: isDanger ? undefined : retry,
          tone: e.type === 'conflict' ? 'info' : 'danger',
          markdown: isDanger,
        });
        if (e.fieldErrors) setFieldErrors(e.fieldErrors);
        if (e.formErrors) setFormErrors(e.formErrors);
      } else if (e instanceof DOMException && e.name === 'AbortError') {
        return;
      } else if (e instanceof Error) {
        console.warn('[llm-interview]', e.message);
        setNotice({ message: labels.errorGeneric, retry });
      }
    },
    [adoptState, describe, hasPending, labels.errorGeneric]
  );

  /** Flush pending debounced patches; throws on failure so callers
   * (send/approve/close/scope change) abort instead of racing ahead. */
  const flushPatches = useCallback(async (): Promise<void> => {
    if (debounceRef.current) {
      clearTimeout(debounceRef.current);
      debounceRef.current = null;
    }
    await enqueue(async () => {
      const cur = stateRef.current;
      // A thread that is being replaced takes no writes; the pending
      // edits are re-sent once the new one is adopted.
      if (!cur || openingRef.current) return;
      const snap = snapshotPending();
      const names = [...Object.keys(snap.patch), ...Object.keys(snap.native)];
      if (!names.length) return;
      // Native HTML validity gate: invalid pending values block the
      // remote call (blank optional values pass; editing continues).
      if (validatorRef.current && !validatorRef.current(names)) {
        throw new InterviewApiError('validation', 'Some values are invalid.', 422);
      }
      beginOp('draft');
      try {
        const next = await updateDraft(
          config,
          cur.record_id,
          cur.conversation_id,
          cur.revision,
          valuesOf(snap.patch),
          valuesOf(snap.native),
          abortRef.current?.signal
        );
        ackPending(snap);
        adoptState(next);
      } finally {
        endOp();
      }
    });
  }, [adoptState, ackPending, beginOp, config, endOp, enqueue, snapshotPending, valuesOf]);

  const schedulePatch = useCallback(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => {
      debounceRef.current = null;
      flushPatches().catch((e) => applyError(e));
    }, 400);
  }, [applyError, flushPatches]);
  schedulePatchRef.current = schedulePatch;

  /** Open (or continue) the interview for a scope. history defaults to
   * 'none' - starting pushes the scope URL, popstate/reload never touch
   * history. A fresh thread bootstraps the coach exactly once; an
   * existing thread is shown as it is. */
  const openScope = useCallback(
    async (nextRecord: number | null, opts: OpenOptions = {}) => {
      // Sync pending edits on the outgoing scope first; a failure blocks
      // the switch so nothing is silently discarded. (A recovery keeps
      // them instead: their thread is gone.)
      if (!opts.keepPending) {
        try {
          await flushPatches();
        } catch (e) {
          applyError(e);
          return;
        }
      }
      epochRef.current += 1;
      abortRef.current?.abort();
      const abort = new AbortController();
      abortRef.current = abort;
      if (debounceRef.current) {
        clearTimeout(debounceRef.current);
        debounceRef.current = null;
      }
      if (!opts.keepPending) {
        pendingPatch.current = {};
        pendingNativePatch.current = {};
        setNotice(null);
      }
      turnIdRef.current = null;
      approveTokenRef.current = null;
      setFieldErrors(null);
      setFormErrors(null);
      setUnsupportedFiles([]);
      setPendingText(null);
      setSavedNote(false);
      setFlash(new Set<string>());
      setRecordId(nextRecord);
      if (opts.history === 'push' || opts.history === 'replace') {
        try {
          const url = recordScopeUrl(config, nextRecord === null ? 'new' : nextRecord);
          if (opts.history === 'push') {
            window.history.pushState({ llmInterviewScope: nextRecord }, '', url);
          } else {
            window.history.replaceState({ llmInterviewScope: nextRecord }, '', url);
          }
        } catch {
          /* history unavailable or a cross-origin router URL */
        }
      }
      // Never show the previous thread's transcript under the new one.
      if (!opts.keepPending) {
        stateRef.current = null;
        setState(null);
      }
      // The workspace appears at once; its content follows.
      setOpen(true);
      beginOp('open');
      openingRef.current = true;
      try {
        const next = await openInterview(
          config,
          nextRecord,
          newToken('open'),
          opts.restartId ?? 0,
          abort.signal,
          opts.resume === true
        );
        if (abort.signal.aborted || !mountedRef.current) return;
        openingRef.current = false;
        adoptState(next);
        if (opts.keepPending && hasPending()) schedulePatchRef.current();
        // A fresh thread - or a saved entry opened for revision - starts
        // with the coach's opening question.
        if (next.needs_start) {
          void sendRef.current?.('', true);
        }
      } catch (e) {
        if (!abort.signal.aborted) {
          setOpen(false);
          applyError(e);
        }
      } finally {
        if (abortRef.current === abort) openingRef.current = false;
        endOp();
      }
    },
    [adoptState, applyError, beginOp, config, endOp, flushPatches, hasPending]
  );

  recoverScopeRef.current = () => {
    const cur = stateRef.current;
    if (cur) void openScope(cur.record_id, { history: 'none', keepPending: true });
  };

  /** The thread a request wrote to is gone: adopt the live one the server
   * handed over, or - when none is left - open a fresh one for the scope. */
  const recoverFromConflict = useCallback(
    async (e: InterviewApiError): Promise<boolean> => {
      if (e.state) {
        adoptState(e.state);
        return true;
      }
      const cur = stateRef.current;
      if (!cur) return false;
      try {
        const next = await enqueue(() =>
          openInterview(config, cur.record_id, newToken('open'), 0, abortRef.current?.signal)
        );
        if (!mountedRef.current) return false;
        adoptState(next);
        return true;
      } catch {
        return false;
      }
    },
    [adoptState, config, enqueue]
  );

  /** One turn: flush pending edits first, then send. start=true sends
   * the frozen bootstrap instruction for a fresh thread. Resolves true
   * when the participant's message reached the server (so the composer
   * keeps it cleared even if the coach failed to answer). A draft that
   * changed elsewhere is refreshed and the turn sent once more. */
  const send = useCallback(
    async (text: string, start = false): Promise<boolean> => {
      setNotice(null);
      setFieldErrors(null);
      setFormErrors(null);
      const before = userMessageCount(stateRef.current);
      if (!start && text !== '') setPendingText(text);
      setAwaitingCoach(true);
      try {
        for (let attempt = 0; ; attempt += 1) {
          try {
            await flushPatches();
            await enqueue(async () => {
              const cur = stateRef.current;
              if (!cur) return;
              const snap = snapshotPending();
              if (!turnIdRef.current) turnIdRef.current = newToken('req');
              beginOp('turn');
              try {
                const res = await sendTurn(
                  config,
                  cur.record_id,
                  cur.conversation_id,
                  cur.revision,
                  text,
                  turnIdRef.current,
                  start,
                  valuesOf(snap.patch),
                  valuesOf(snap.native),
                  abortRef.current?.signal
                );
                turnIdRef.current = null;
                ackPending(snap);
                adoptState(res.state);
                highlight(cur.draft, res.state.draft);
                setPendingText(null);
              } finally {
                endOp();
              }
            });
            return true;
          } catch (e) {
            if (attempt === 0 && e instanceof InterviewApiError && e.type === 'conflict') {
              turnIdRef.current = null;
              if (await recoverFromConflict(e)) {
                // The message made it before the conflict: nothing to resend.
                if (userMessageCount(stateRef.current) > before) return true;
                continue;
              }
            }
            // Prefer the error payload's state: a post-turn danger block
            // already stored the participant message even though sendTurn threw.
            const after =
              e instanceof InterviewApiError && e.state
                ? e.state
                : stateRef.current;
            const delivered = userMessageCount(after) > before;
            applyError(
              e,
              start
                ? () => void sendRef.current?.('', true)
                : delivered
                  ? () => void sendRef.current?.(text)
                  : undefined
            );
            return delivered || start;
          }
        }
      } finally {
        if (mountedRef.current) {
          setPendingText(null);
          setAwaitingCoach(false);
        }
      }
    },
    [
      adoptState,
      applyError,
      ackPending,
      beginOp,
      config,
      endOp,
      enqueue,
      flushPatches,
      highlight,
      recoverFromConflict,
      snapshotPending,
      valuesOf,
    ]
  );
  sendRef.current = send;

  /** Native/manual control edit -> split into patch buckets by def. */
  const onFieldValue = useCallback(
    (name: string, value: unknown, nativeOnly: boolean, unsupportedFile?: boolean) => {
      if (unsupportedFile) {
        setUnsupportedFiles((cur) => (cur.includes(name) ? cur : [...cur, name]));
        return;
      }
      setUnsupportedFiles((cur) => cur.filter((n) => n !== name));
      const ver = ++pendingSeq.current;
      if (nativeOnly) {
        pendingNativePatch.current[name] = { v: value, ver };
      } else {
        pendingPatch.current[name] = { v: value, ver };
      }
      setPendingTick((t) => t + 1);
      schedulePatch();
    },
    [schedulePatch]
  );

  /** Refetch the list page and soft-refresh this section's core list. */
  const refreshList = useCallback(async () => {
    const listUrl = listScopeUrl(config);
    try {
      const res = await fetch(listUrl, { credentials: 'same-origin' });
      const html = await res.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const live = document.querySelector<HTMLElement>(
        `.formUserInputLogList.style-section-${config.sectionId}`
      );
      const jq = (window as unknown as { jQuery?: unknown }).jQuery;
      const fn = (window as unknown as Record<string, unknown>)
        .softRefreshFormUserInputLogList as
        | ((el: unknown, d: Document, c: Record<string, unknown>, o: object) => void)
        | undefined;
      if (live && typeof fn === 'function') {
        let coreConfig: Record<string, unknown> = {};
        try {
          coreConfig = JSON.parse(live.getAttribute('data-config') || '{}');
        } catch {
          /* keep defaults */
        }
        fn(typeof jq === 'function' ? (jq as (e: HTMLElement) => unknown)(live) : live, doc, coreConfig, {});
        return;
      }
    } catch {
      /* fall through to navigation */
    }
    window.location.assign(listUrl);
  }, [config]);

  /** Leave the workspace for the launcher (and the list). The draft stays.
   * Synchronous, so a modal is gone from <body> before the core list
   * refresh sweeps `.modal-backdrop` nodes behind React's back. */
  const leaveWorkspace = useCallback(() => {
    flushSync(() => {
      setOpen(false);
      setApproveOpen(false);
    });
    clearDeepLink();
  }, []);

  /** Approval: the explicit Save button (plus the confirmation dialog when
   * the author configured one). The request_token is stable for retries
   * of the same approval. A saved entry closes the workspace, refreshes
   * the list and leaves a success note on the launcher. */
  const approve = useCallback(async () => {
    setNotice(null);
    setFormErrors(null);
    try {
      await flushPatches();
    } catch (e) {
      setApproveOpen(false);
      applyError(e);
      return;
    }
    try {
      await enqueue(async () => {
        const cur = stateRef.current;
        if (!cur) return;
        const snap = snapshotPending();
        if (!approveTokenRef.current) approveTokenRef.current = newToken('approve');
        beginOp('approve');
        try {
          const res = await approveInterview(
            config,
            cur.record_id,
            cur.conversation_id,
            cur.revision,
            approveTokenRef.current,
            valuesOf(snap.patch),
            valuesOf(snap.native),
            abortRef.current?.signal
          );
          approveTokenRef.current = null;
          ackPending(snap);
          if (!mountedRef.current) return;
          const redirect = res.approval?.redirect;
          if (redirect && (/^https?:\/\//i.test(redirect) || redirect.startsWith('/'))) {
            flushSync(() => setApproveOpen(false));
            writeSavedNote(config);
            window.location.assign(redirect);
            return;
          }
          // Saved: close the workspace and show the list again; the
          // refreshed section picks the success note up from storage.
          stateRef.current = null;
          setState(null);
          setResume(null);
          setSavedNote(true);
          leaveWorkspace();
          if (config.showList) {
            writeSavedNote(config);
            void refreshList();
          }
        } finally {
          endOp();
        }
      });
    } catch (e) {
      setApproveOpen(false);
      applyError(e);
    }
  }, [
    applyError,
    ackPending,
    beginOp,
    config,
    endOp,
    enqueue,
    flushPatches,
    leaveWorkspace,
    refreshList,
    snapshotPending,
    valuesOf,
  ]);

  const mode = state?.mode ?? 'new';
  // `edit` asks only when a saved entry is revised, `always` also for a
  // new one, `never` saves at once.
  const needsDialog =
    config.confirm.mode === 'always' || (config.confirm.mode === 'edit' && mode === 'edit');

  /** Save click: straight through unless a confirmation is configured. */
  const requestApprove = useCallback(() => {
    if (needsDialog) {
      setApproveOpen(true);
    } else {
      void approve();
    }
  }, [approve, needsDialog]);

  /** Start over: reset the thread (CAS on the supplied conversation id)
   * and open a fresh draft. */
  const restart = useCallback(async () => {
    setClearOpen(false);
    const cur = stateRef.current;
    await openScope(recordId, { history: 'none', restartId: cur?.conversation_id ?? 0 });
  }, [openScope, recordId]);

  /** Close the workspace, keep the draft: pending edits are flushed first -
   * a failed flush keeps the workspace open so nothing is silently lost.
   * Used by the minimize action, the modal's close button and Esc. */
  const minimize = useCallback(() => {
    void (async () => {
      // Closing while the thread is still loading cancels that open.
      if (openingRef.current) {
        epochRef.current += 1;
        abortRef.current?.abort();
      }
      try {
        await flushPatches();
      } catch (e) {
        applyError(e);
        return;
      }
      if (!mountedRef.current) return;
      const cur = stateRef.current;
      setResume(
        config.retention !== 'new_on_open' && cur && cur.mode === 'new' && !cur.completed_at
          ? { done: cur.progress?.done ?? 0, total: cur.progress?.total ?? 0 }
          : null
      );
      leaveWorkspace();
      // A deep-linked record left the list out of this page render.
      if (!config.listRendered && config.showList) void refreshList();
    })();
  }, [applyError, config, flushPatches, leaveWorkspace, refreshList]);

  /** Open a scope from a user action: the URL gains the scope, the page
   * mode workspace scrolls into view (a modal needs no scrolling). */
  const openFromUser = useCallback(
    (next: number | null) => {
      void openScope(next, { history: 'push' }).then(() => {
        if (!config.modal) mount.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    },
    [config.modal, mount, openScope]
  );

  // External open requests from the entry (CTA clicks dispatch here).
  useEffect(() => {
    const onOpenEvent = (ev: Event) => {
      const detail = (ev as CustomEvent).detail as
        | { sectionId?: number; recordId?: number | null }
        | undefined;
      if (detail && detail.sectionId !== undefined && detail.sectionId !== config.sectionId) {
        return;
      }
      const next =
        detail && detail.recordId !== undefined ? detail.recordId : config.recordId;
      openFromUser(next ?? null);
    };
    mount.addEventListener('llm-interview:open', onOpenEvent);
    return () => mount.removeEventListener('llm-interview:open', onOpenEvent);
  }, [mount, openFromUser, config.sectionId, config.recordId]);

  // First paint decision, exactly once: a scoped URL opens that scope
  // (a reload keeps the unfinished draft); otherwise the launcher shows
  // and - for retentions that keep drafts - offers to continue one.
  useEffect(() => {
    if (bootedRef.current) return;
    bootedRef.current = true;
    const scope = initialScope(config);
    if (scope) {
      void openScope(scope.record, { history: 'none', resume: isRestoreNavigation() });
      return;
    }
    // Every open starts a fresh chat: nothing to continue, nothing to peek at.
    if (config.retention === 'new_on_open') return;
    const epoch = epochRef.current;
    setPeeking(true);
    readInterview(config, null, null, undefined, true)
      .then((peek) => {
        if (!mountedRef.current || epoch !== epochRef.current) return;
        if (!peek || peek.completed_at || peek.record_id !== null) return;
        adoptState(peek);
        setResume({ done: peek.progress?.done ?? 0, total: peek.progress?.total ?? 0 });
      })
      .catch(() => {
        /* no unfinished draft - the launcher offers a new one */
      })
      .finally(() => {
        if (mountedRef.current) setPeeking(false);
      });
  }, [adoptState, config, openScope]);

  // popstate: a scoped URL re-opens that scope (no history write); an
  // unscoped or this-section-unscoped URL flush-gates the close so
  // pending edits are never dropped by a browser back/forward.
  useEffect(() => {
    const onPop = () => {
      const url = new URL(window.location.href);
      const sid = url.searchParams.get('sid');
      const next = parseRecordParam(url.searchParams.get('record'));
      if (sid === null && next === undefined) {
        minimize();
        return;
      }
      if (sid !== String(config.sectionId)) return;
      if (next === undefined) {
        // This section's URL but no resolvable record scope.
        minimize();
        return;
      }
      void openScope(next, { history: 'none', resume: true });
    };
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, [config.sectionId, openScope, minimize]);

  const messages = useMemo(() => chatMessages(state), [state]);
  const isWorking = working > 0;
  const opening = opKind === 'open' && isWorking;
  const approving = opKind === 'approve' && isWorking;
  // Crisis block locks the composer; it is not a normal saved/finished entry.
  const conversationBlocked = !!state?.blocked;
  const sendBlocked = awaitingCoach || approving || opening || conversationBlocked;
  const finished = !!state?.completed_at && state.mode === 'new';

  // Fields edited in a hosted native control sit in the slot of their own question.
  const nativeNames = useMemo(() => {
    const names = new Set<string>();
    for (const [name, def] of Object.entries(state?.fields ?? {})) {
      if (!def.hidden && (def.native_only || (config.allowManualEdit && def.manual_writable))) {
        names.add(name);
      }
    }
    return names;
  }, [state?.fields, config.allowManualEdit]);
  const hasNativeFields = nativeNames.size > 0;
  // CMS "none" = chat only: hide the answers pane entirely (hosted fields
  // would otherwise force a plain form even when the author picked chat-only).
  const chatOnly = config.progressLayout === 'none';

  // Page mode with the chat beside the answers: fill the viewport below
  // the workspace's top edge, so the composer is never cut off on the
  // first view. Once the page has scrolled that far, `position: sticky`
  // keeps the chat pinned at --llm-iv-sticky-top. (A modal sizes the
  // chat in CSS - its body scrolls instead of the page.)
  useEffect(() => {
    const workspace = workspaceRef.current;
    const beside = config.chatPosition === 'left' || config.chatPosition === 'right';
    if (!open || config.modal || chatOnly || !beside || !workspace) return undefined;
    const chat = workspace.querySelector<HTMLElement>('.llm-iv-chat');
    let frame = 0;
    const apply = () => {
      frame = 0;
      const pinned = chat ? parseFloat(getComputedStyle(chat).top) || 0 : 0;
      const top = Math.max(pinned, workspace.getBoundingClientRect().top);
      const free = Math.round(Math.min(680, Math.max(360, window.innerHeight - top - 16)));
      workspace.style.setProperty('--llm-iv-chat-h', `${free}px`);
    };
    const schedule = () => {
      if (!frame) frame = window.requestAnimationFrame(apply);
    };
    apply();
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule);
    return () => {
      window.removeEventListener('scroll', schedule);
      window.removeEventListener('resize', schedule);
      if (frame) window.cancelAnimationFrame(frame);
      workspace.style.removeProperty('--llm-iv-chat-h');
    };
  }, [open, config.modal, config.chatPosition, chatOnly]);

  const rows = useMemo(() => reviewRows(state), [state]);

  const dismissNotice = useCallback(() => setNotice(null), []);

  // Modals render on <body>, outside the themed section: while one is open
  // the documented theme variables and the section's classes are copied
  // onto it, so author CSS and the theme apply to it too.
  const workspaceModal = config.modal && open;
  const modalOpen = approveOpen || clearOpen || workspaceModal;
  const modalTheme = useMemo<React.CSSProperties>(() => {
    if (!modalOpen) return {};
    const computed = window.getComputedStyle(mount);
    const theme: Record<string, string> = {};
    THEME_VARS.forEach((name) => {
      const value = computed.getPropertyValue(name).trim();
      if (value) theme[name] = value;
    });
    return theme as React.CSSProperties;
  }, [mount, modalOpen]);
  const modalClass = useMemo(() => {
    if (!modalOpen) return '';
    const root = mount.closest<HTMLElement>('.formUserInputLogList');
    if (!root) return '';
    return Array.from(root.classList)
      .filter((c) => !/^(formUserInputLogList|llmFormLogList|llm-iv-)/.test(c))
      .join(' ');
  }, [mount, modalOpen]);

  const restartModal = (
    <Modal
      show={clearOpen}
      onHide={() => setClearOpen(false)}
      animation={false}
      centered
      className={`llm-iv-modal llm-iv-modal-nested ${modalClass}`.trim()}
      backdropClassName="llm-iv-backdrop-nested"
      dialogClassName="llm-iv-dialog llm-iv-dialog-small"
      data-llm-iv-modal={config.sectionId}
      style={modalTheme}
      aria-label={labels.restart}
    >
      <Modal.Header closeButton closeLabel={labels.close}>
        <Modal.Title>{labels.restart}</Modal.Title>
      </Modal.Header>
      <Modal.Body>{labels.restartConfirm}</Modal.Body>
      <Modal.Footer>
        <button type="button" className="btn btn-secondary" onClick={() => setClearOpen(false)}>
          {labels.back}
        </button>
        <button type="button" className="btn btn-danger" onClick={() => void restart()}>
          {labels.restart}
        </button>
      </Modal.Footer>
    </Modal>
  );

  const launcher = (
    <InterviewLauncher
      config={config}
      labels={labels}
      peeking={peeking}
      resume={resume}
      busy={opening && !config.modal}
      error={open ? null : notice?.message ?? null}
      errorMarkdown={!open && !!notice?.markdown}
      saved={savedNote}
      onDismissSaved={() => setSavedNote(false)}
      onDismissError={dismissNotice}
      onStart={() => openFromUser(null)}
      onRestart={() => setClearOpen(true)}
    />
  );

  // Collapsed: one call-to-action card.
  if (!open) {
    return (
      <>
        {launcher}
        {restartModal}
      </>
    );
  }

  const workspaceClass = [
    'llm-iv-workspace',
    `pos-${config.chatPosition}`,
    config.modal ? 'is-modal' : '',
    chatOnly ? 'is-chat-only' : '',
  ]
    .filter(Boolean)
    .join(' ');

  const workspace = (
    <div className={workspaceClass} ref={workspaceRef}>
      {chatOnly ? null : (
        <AnswersPane
          config={config}
          labels={labels}
          state={state}
          working={isWorking || awaitingCoach}
          approving={approving}
          fieldErrors={fieldErrors}
          formErrors={formErrors}
          unsupportedFiles={unsupportedFiles}
          nativeNames={nativeNames}
          flash={flash}
          onApprove={requestApprove}
          renderNative={(scope) =>
            hasNativeFields ? (
              <NativeInterviewFields
                holder={nativeHolder}
                scope={scope}
                nativeHtml={state?.native_html ?? {}}
                fields={state?.fields ?? {}}
                draft={state?.draft ?? {}}
                pendingFields={pendingFieldSet}
                allowManual={config.allowManualEdit}
                validatorRef={validatorRef}
                onFieldValue={onFieldValue}
              />
            ) : null
          }
        />
      )}
      <InterviewChat
        config={config}
        labels={labels}
        state={state}
        messages={messages}
        pendingText={pendingText}
        thinking={awaitingCoach}
        opening={opening}
        sendBlocked={sendBlocked}
        finished={finished}
        conversationBlocked={conversationBlocked}
        approving={approving}
        canApprove={!!state?.can_approve && !finished && !conversationBlocked}
        notice={notice}
        onDismissNotice={dismissNotice}
        onSend={(text) => send(text)}
        onApprove={requestApprove}
        onRestart={() => setClearOpen(true)}
        onMinimize={config.modal ? undefined : minimize}
        busyLock={approving}
      />
    </div>
  );

  const approval = (
    <ApprovalDialog
      show={approveOpen}
      title={
        config.confirm.title || (mode === 'edit' ? labels.reviewTitleEdit : labels.reviewTitleNew)
      }
      message={config.confirm.message}
      mode={mode}
      rows={rows}
      labels={labels}
      confirmLabel={config.confirm.continue || config.labels.approve}
      cancelLabel={config.confirm.cancel || labels.back}
      busy={approving}
      theme={modalTheme}
      themeClass={modalClass}
      sectionId={config.sectionId}
      onCancel={() => setApproveOpen(false)}
      onConfirm={() => void approve()}
    />
  );

  // Page mode: the workspace replaces the launcher in place.
  if (!config.modal) {
    return (
      <>
        {workspace}
        {approval}
        {restartModal}
      </>
    );
  }

  // Modal mode: a real modal window above the page. The launcher and the
  // list stay where they are; the draft survives closing the window.
  const nested = approveOpen || clearOpen;
  // The list's edit button label ("Open") is also the default header of the
  // window; `editTitle` (llm_interview_labels) lets an open entry say more.
  const windowTitle = recordId !== null ? labels.editTitle || config.labels.edit : config.labels.start;
  return (
    <>
      {launcher}
      <Modal
        show
        onHide={approving ? () => undefined : minimize}
        animation={false}
        backdrop="static"
        keyboard={!nested}
        enforceFocus={!nested}
        size={chatOnly ? 'lg' : 'xl'}
        scrollable
        className={`llm-iv-modal llm-iv-ws-modal ${modalClass}`.trim()}
        dialogClassName={`llm-iv-ws-dialog${chatOnly ? ' is-chat-only' : ''}`}
        data-llm-iv-modal={config.sectionId}
        style={modalTheme}
        aria-label={windowTitle}
      >
        <Modal.Header closeButton={!approving} closeLabel={labels.close}>
          <Modal.Title>{windowTitle}</Modal.Title>
        </Modal.Header>
        <Modal.Body className="llm-slim-scroll">{workspace}</Modal.Body>
      </Modal>
      {approval}
      {restartModal}
    </>
  );
};

export default LlmFormInterview;
