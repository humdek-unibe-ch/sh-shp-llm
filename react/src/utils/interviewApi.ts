/**
 * Interview API client
 * ====================
 *
 * Typed requests for the llmFormLogList interview contract. All
 * requests go to the same page URL; mutations POST FormData with the
 * __llm_interview_action marker, section id, CSRF token, conversation
 * id and expected revision. Reads use GET and are side-effect free.
 *
 * @module utils/interviewApi
 */

import type {
  InterviewApprovalReceipt,
  InterviewConfig,
  InterviewSafety,
  InterviewState,
} from '../types/interview';
import { InterviewApiError } from '../types/interview';

const ACTION_PARAM = '__llm_interview_action';
const SECTION_PARAM = 'section_id';
const RECORD_PARAM = 'record';
const SID_PARAM = 'sid';

/** Query keys the interview adds to the request/scope URLs. */
const INTERVIEW_QUERY_KEYS = [
  ACTION_PARAM,
  SECTION_PARAM,
  'conversation_id',
  'record_id',
  'peek',
  'revision',
  RECORD_PARAM,
  SID_PARAM,
];

/** The same-page URL without any record/sid or interview markers. */
export function basePageUrl(): string {
  const url = new URL(window.location.href);
  for (const key of INTERVIEW_QUERY_KEYS) {
    url.searchParams.delete(key);
  }
  url.hash = '';
  return url.pathname + url.search;
}

/**
 * Same-page URL for interview requests: record/sid markers are cleared
 * because the scope travels in the POST body - a stale address-bar
 * selection must never preload the wrong row server-side. Every other
 * query key (the URL parameters of the section's `extra_params`) stays on
 * the URL, so the endpoint request resolves the same parameters as the page.
 */
function requestUrl(_recordId: number | null): string {
  return basePageUrl();
}

/** URL to open the interview for a record (or 'new') on this section. */
export function recordScopeUrl(config: InterviewConfig, record: string | number): string {
  // Resolve against the page URL, not origin: the router may emit an
  // absolute URL or a base-path-relative one. `urls.new` already carries
  // the page's query (the URL parameters), the server builds it from it.
  const url = new URL(config.urls.new, window.location.href);
  url.searchParams.set(RECORD_PARAM, String(record));
  url.searchParams.set(SID_PARAM, String(config.sectionId));
  return url.pathname + url.search + url.hash;
}

/** The section list URL supplied by the server config. */
export function listScopeUrl(config: InterviewConfig): string {
  return config.urls.list;
}

interface RawResult {
  success?: boolean;
  ok?: boolean;
  state?: InterviewState;
  error?: { type?: string; message?: string };
  field_errors?: Record<string, unknown> | null;
  form_errors?: Record<string, unknown> | null;
  approval?: InterviewApprovalReceipt;
  idempotent?: boolean;
  replayed?: boolean;
  /** Present when the coach reply carried a safety assessment. */
  safety?: InterviewSafety;
  /** CMS danger_blocked_message when the thread was blocked this turn. */
  safety_message?: string | null;
  blocked?: boolean;
}

/** Critical/emergency LLM safety or an already-blocked projection. */
function isCriticalBlock(state: InterviewState | null | undefined, safety?: InterviewSafety | null): boolean {
  if (state?.blocked) return true;
  const level = safety?.danger_level;
  return level === 'critical' || level === 'emergency';
}

/**
 * Prefer the CMS blocked message from the payload; fall back to the LLM
 * safety_message, then a generic lock notice.
 */
function blockedMessage(body: RawResult | null): string {
  if (typeof body?.safety_message === 'string' && body.safety_message.trim() !== '') {
    return body.safety_message;
  }
  const fromSafety = body?.safety?.safety_message;
  if (typeof fromSafety === 'string' && fromSafety.trim() !== '') {
    return fromSafety;
  }
  return body?.error?.message || 'This conversation has been blocked due to safety concerns.';
}

/** Core write validation errors arrive as a name => message map, or
 * (GUMP) as a plain message array - normalized to index => message. */
function normalizeErrors(raw: unknown): Record<string, string> | null {
  if (!raw || typeof raw !== 'object') {
    return null;
  }
  const out: Record<string, string> = {};
  if (Array.isArray(raw)) {
    raw.forEach((value, i) => {
      out[String(i)] = typeof value === 'string' ? value : JSON.stringify(value);
    });
    return raw.length ? out : null;
  }
  for (const [key, value] of Object.entries(raw as Record<string, unknown>)) {
    out[key] = typeof value === 'string' ? value : JSON.stringify(value);
  }
  return out;
}

async function parseResult(res: Response): Promise<RawResult> {
  let body: RawResult | null = null;
  try {
    body = (await res.json()) as RawResult;
  } catch {
    body = null;
  }
  if (!res.ok || !body || body.success !== true) {
    const type = body?.error?.type || 'error';
    const message =
      type === 'danger_detected'
        ? blockedMessage(body)
        : body?.error?.message || `Interview request failed (${res.status})`;
    throw new InterviewApiError(type, message, res.status, {
      fieldErrors: normalizeErrors(body?.field_errors),
      formErrors: normalizeErrors(body?.form_errors),
      state: body?.state ?? null,
    });
  }
  // Belt-and-suspenders: a 200 that still flipped blocked / critical
  // safety must not drop the CMS blocked message (same UX as keyword).
  if (isCriticalBlock(body.state, body.safety)) {
    throw new InterviewApiError('danger_detected', blockedMessage(body), 403, {
      state: body.state ?? null,
    });
  }
  return body;
}

function mutationBody(
  config: InterviewConfig,
  action: string,
  recordId: number | null,
  extra: Record<string, string>
): FormData {
  const body = new FormData();
  body.set(ACTION_PARAM, action);
  body.set(SECTION_PARAM, String(config.sectionId));
  body.set('csrf_token', config.csrfToken);
  if (recordId !== null && recordId > 0) {
    body.set('record_id', String(recordId));
  }
  for (const [key, value] of Object.entries(extra)) {
    if (value !== undefined && value !== null) {
      body.set(key, value);
    }
  }
  return body;
}

async function post(
  config: InterviewConfig,
  action: string,
  recordId: number | null,
  extra: Record<string, string>,
  signal?: AbortSignal
): Promise<RawResult> {
  const res = await fetch(requestUrl(recordId), {
    method: 'POST',
    credentials: 'same-origin',
    body: mutationBody(config, action, recordId, extra),
    signal,
  });
  return parseResult(res);
}

/**
 * Open (or continue) the scoped interview. open_token is required for
 * retry idempotency; restart clears the active thread. `resume` marks
 * the restore of a session that was still open (page reload), which a
 * "fresh chat on every open" retention must keep.
 */
export async function openInterview(
  config: InterviewConfig,
  recordId: number | null,
  openToken: string,
  restartConversationId = 0,
  signal?: AbortSignal,
  resume = false
): Promise<InterviewState> {
  const extra: Record<string, string> = { open_token: openToken };
  if (resume) {
    extra.resume = '1';
  }
  if (restartConversationId > 0) {
    extra.conversation_id = String(restartConversationId);
    const res = await post(config, 'reset', recordId, extra, signal);
    return res.state as InterviewState;
  }
  const res = await post(config, 'open', recordId, extra, signal);
  return res.state as InterviewState;
}

/**
 * Read-only state fetch (GET).
 * Omit `conversationId` to resolve the latest usable thread for the scope.
 * With `peek`, "no thread yet" resolves to null instead of a 404 error.
 */
export async function readInterview(
  config: InterviewConfig,
  recordId: number | null,
  conversationId?: number | null,
  signal?: AbortSignal,
  peek = false
): Promise<InterviewState | null> {
  const url = new URL(window.location.origin + requestUrl(recordId));
  url.searchParams.set(ACTION_PARAM, 'state');
  url.searchParams.set(SECTION_PARAM, String(config.sectionId));
  if (peek) {
    url.searchParams.set('peek', '1');
  }
  if (conversationId !== undefined && conversationId !== null && conversationId > 0) {
    url.searchParams.set('conversation_id', String(conversationId));
  }
  if (recordId !== null && recordId > 0) {
    url.searchParams.set('record_id', String(recordId));
  }
  const res = await fetch(url.pathname + url.search, {
    method: 'GET',
    credentials: 'same-origin',
    signal,
  });
  const body = await parseResult(res);
  return (body.state ?? null) as InterviewState | null;
}

/** Apply a manual/native draft patch under CAS. */
export async function updateDraft(
  config: InterviewConfig,
  recordId: number | null,
  conversationId: number,
  revision: number,
  patch: Record<string, unknown>,
  nativePatch: Record<string, unknown>,
  signal?: AbortSignal
): Promise<InterviewState> {
  const res = await post(config, 'update_draft', recordId, {
    conversation_id: String(conversationId),
    revision: String(revision),
    patch: JSON.stringify(patch),
    native_patch: JSON.stringify(nativePatch),
  }, signal);
  return res.state as InterviewState;
}

/**
 * Run one turn. `start=true` sends the frozen bootstrap instruction
 * instead of a participant message.
 */
export async function sendTurn(
  config: InterviewConfig,
  recordId: number | null,
  conversationId: number,
  revision: number,
  message: string,
  requestId: string,
  start: boolean,
  patch: Record<string, unknown>,
  nativePatch: Record<string, unknown>,
  signal?: AbortSignal
): Promise<{ state: InterviewState; replayed: boolean; safety?: InterviewSafety }> {
  const extra: Record<string, string> = {
    conversation_id: String(conversationId),
    revision: String(revision),
    message,
    request_id: requestId,
    patch: JSON.stringify(patch),
    native_patch: JSON.stringify(nativePatch),
  };
  if (start) {
    extra.start = '1';
  }
  const res = await post(config, 'turn', recordId, extra, signal);
  return {
    state: res.state as InterviewState,
    replayed: res.replayed === true,
    safety: res.safety,
  };
}

/** Approve the draft through the inherited core write path. */
export async function approveInterview(
  config: InterviewConfig,
  recordId: number | null,
  conversationId: number,
  revision: number,
  requestToken: string,
  patch: Record<string, unknown>,
  nativePatch: Record<string, unknown>,
  signal?: AbortSignal
): Promise<{ approval: InterviewApprovalReceipt; state: InterviewState; idempotent: boolean }> {
  const res = await post(config, 'approve', recordId, {
    conversation_id: String(conversationId),
    revision: String(revision),
    request_token: requestToken,
    patch: JSON.stringify(patch),
    native_patch: JSON.stringify(nativePatch),
  }, signal);
  return {
    approval: res.approval as InterviewApprovalReceipt,
    state: res.state as InterviewState,
    idempotent: res.idempotent === true,
  };
}
