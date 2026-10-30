/**
 * Interview helpers
 * =================
 *
 * Small pure helpers shared by the interview components.
 */

import type { InterviewFieldDef, InterviewState } from '../../../types/interview';

/** Resolve a draft value to its readable option label(s). */
export function optionLabel(def: InterviewFieldDef | undefined, value: unknown): string {
  if (Array.isArray(value)) {
    return value.map((v) => optionLabel(def, v)).join(', ');
  }
  const text = value === null || value === undefined ? '' : String(value);
  const hit = (def?.options ?? []).find((o) => String(o.value) === text);
  return hit ? hit.label : text;
}

/** Replace `{name}` placeholders in a label template. */
export function fillTemplate(
  template: string | undefined,
  vars: Record<string, string | number>
): string {
  return (template ?? '').replace(/\{(\w+)\}/g, (match, key: string) =>
    Object.prototype.hasOwnProperty.call(vars, key) ? String(vars[key]) : match
  );
}

/** Participant-visible chat entry. */
export interface ChatMessage {
  id: string;
  role: 'user' | 'assistant';
  content: string;
}

/**
 * Participant transcript from the projected state. A retried turn
 * persists its user message again; consecutive identical user entries
 * collapse into one bubble so a retry never looks like a double send.
 */
export function chatMessages(state: InterviewState | null): ChatMessage[] {
  if (!state) return [];
  const out: ChatMessage[] = [];
  for (const m of state.messages) {
    if (m.role !== 'user' && m.role !== 'assistant') continue;
    const prev = out[out.length - 1];
    if (prev && prev.role === 'user' && m.role === 'user' && prev.content === m.content) {
      out[out.length - 1] = { ...prev, id: String(m.id ?? prev.id) };
      continue;
    }
    out.push({
      id: String(m.id ?? `${m.role}-${m.timestamp ?? out.length}`),
      role: m.role,
      content: m.content,
    });
  }
  return out;
}

/** Number of participant messages in the projected state. */
export function userMessageCount(state: InterviewState | null): number {
  return state ? state.messages.filter((m) => m.role === 'user').length : 0;
}

/**
 * Closed single-choice options of the field the coach is asking about
 * (select/radio only), so they can be offered as quick-reply buttons
 * even when the coach did not suggest any. Long lists stay a typed
 * answer.
 */
export function choicesOf(
  state: InterviewState | null,
  max = 8
): Array<{ value: string; label: string }> {
  if (!state) return [];
  const active = state.asking ?? state.progress?.active;
  if (!active) return [];
  const def = state.fields?.[active];
  if (!def || !def.llm_writable || !def.options || def.options.length === 0) return [];
  if (def.input_type !== 'select' && def.input_type !== 'radio') return [];
  const rows = def.options.filter((o) => o.label !== '');
  return rows.length > 0 && rows.length <= max ? rows : [];
}

/** Readable text of a draft value; '' when the answer is empty. */
export function valueText(def: InterviewFieldDef | undefined, raw: unknown): string {
  return raw === undefined || raw === null || raw === '' ? '' : optionLabel(def, raw);
}

/** Names whose draft value differs between two projections (compared by value). */
export function changedDraftNames(
  before: Record<string, unknown> | undefined,
  after: Record<string, unknown> | undefined
): string[] {
  const a = before ?? {};
  const b = after ?? {};
  const names = new Set<string>([...Object.keys(a), ...Object.keys(b)]);
  const out: string[] = [];
  names.forEach((name) => {
    if (JSON.stringify(a[name] ?? null) !== JSON.stringify(b[name] ?? null)) out.push(name);
  });
  return out;
}

/**
 * Grow a textarea to its content, so the form expands instead of
 * scrolling inside the control. Controls that are not rendered (parked,
 * collapsed) have no layout and keep their height.
 */
export function fitTextarea(el: HTMLTextAreaElement): void {
  if (!el.isConnected || el.offsetParent === null) return;
  el.style.height = 'auto';
  const style = window.getComputedStyle(el);
  const border =
    (parseFloat(style.borderTopWidth) || 0) + (parseFloat(style.borderBottomWidth) || 0);
  el.style.height = `${el.scrollHeight + border}px`;
}

/** One line of the approval dialog: the saved answer next to the new one. */
export interface ReviewRow {
  name: string;
  label: string;
  /** What the saved record holds today (edit mode, changed rows). */
  before: string;
  /** What will be saved. */
  after: string;
  changed: boolean;
}

/** Every visible answer in schema order, flagged when it differs from the saved record. */
export function reviewRows(state: InterviewState | null): ReviewRow[] {
  if (!state) return [];
  const changed = new Set(state.changed ?? []);
  const baseline = state.baseline ?? {};
  return Object.entries(state.fields ?? {})
    .filter(([, def]) => !def.hidden)
    .map(([name, def]) => ({
      name,
      label: def.label || name,
      before: valueText(def, baseline[name]),
      after: valueText(def, state.draft?.[name]),
      changed: changed.has(name),
    }));
}
