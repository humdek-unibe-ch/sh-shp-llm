/**
 * Native Interview Fields
 * =======================
 *
 * Hosts the server-rendered native control fragments without re-owning
 * the original DOM. The PHP holder element stays attached inside the
 * mount as a hidden parking element (never moved into React ownership):
 * per-field wrappers migrate between parking and the answers panel as
 * fields enter/leave the renderable set, so collapse/unmount never
 * destroys widget bindings - the cleanup simply moves everything back.
 *
 * Each wrapper is placed into the slot of its own question
 * (`[data-llm-iv-slot="<field>"]`, rendered by the answers panel), so the
 * control sits under its step. A wrapper without a slot lands in the
 * fallback host. Text areas grow with their content - the form expands
 * instead of scrolling inside a control.
 *
 * Server fragments already arrive wrapped in `.llm-native-field` - the
 * reconciler adopts that element itself (no innerHTML nesting). A field
 * is only re-rendered when its stable control signature (style, input
 * type, required, options, constraints) changes; plain draft updates go
 * through .value/.checked plus selectpicker refresh so dirty, focused
 * and custom widgets keep their state.
 */

import React, { useCallback, useEffect, useRef } from 'react';
import type { InterviewFieldDef } from '../../../types/interview';
import { fitTextarea } from './interviewUtils';

interface NativeInterviewFieldsProps {
  /** Hidden PHP parking element that owns the initial fragments. */
  holder: HTMLElement | null;
  /** The element that holds the per-question slots; also the event scope. */
  scope: HTMLElement | null;
  /** Per-field server fragments keyed by field name (each wrapped). */
  nativeHtml: Record<string, string>;
  /** Visible field definitions from the projection. */
  fields: Record<string, InterviewFieldDef>;
  /** Current canonical draft values. */
  draft: Record<string, unknown>;
  /** Field names with unsynced local edits - never overwritten. */
  pendingFields: ReadonlySet<string>;
  /** Manual edit toggle: manual_writable fields render only when true. */
  allowManual: boolean;
  /** Out-ref: validator for pending native edits before remote calls. */
  validatorRef: React.MutableRefObject<((names: string[]) => boolean) | null>;
  /** Callback: (fieldName, value, isNativeOnly, unsupportedFile) */
  onFieldValue: (name: string, value: unknown, nativeOnly: boolean, unsupportedFile?: boolean) => void;
}

type Control = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;

const FIELD_RE = /^([^\[\]]+)\[value\](\[\])?$/;
const WRAP_SEL = '.llm-native-field';

/** Extract the owning field name from a core control name. */
function fieldNameOf(el: Element): { name: string; multi: boolean } | null {
  const raw = el.getAttribute('name');
  if (!raw) return null;
  const m = raw.match(FIELD_RE);
  if (!m) return null;
  return { name: m[1], multi: !!m[2] };
}

/** Read the control's value in core save semantics. */
function readControlValue(el: Control): unknown {
  if (el instanceof HTMLInputElement && (el.type === 'checkbox' || el.type === 'radio')) {
    return el.checked ? el.value : '0';
  }
  if (el instanceof HTMLSelectElement && el.multiple) {
    return Array.from(el.selectedOptions).map((o) => o.value);
  }
  return el.value;
}

/** Push a draft value into the control without firing change loops. */
function writeControlValue(el: Control, value: unknown): void {
  if (document.activeElement === el) return;
  if (el instanceof HTMLInputElement && el.type === 'file') return;
  if (el instanceof HTMLInputElement && (el.type === 'checkbox' || el.type === 'radio')) {
    const target = Array.isArray(value) ? value.map(String) : [String(value ?? '')];
    const checked = target.includes(el.value);
    if (el.checked !== checked) el.checked = checked;
    return;
  }
  if (el instanceof HTMLSelectElement && el.multiple) {
    const target = new Set((Array.isArray(value) ? value : [value]).map((v) => String(v ?? '')));
    Array.from(el.options).forEach((o) => {
      const sel = target.has(o.value);
      if (o.selected !== sel) o.selected = sel;
    });
    const jq = (window as any).jQuery || (window as any).$;
    try {
      if (jq?.fn?.selectpicker) jq(el).selectpicker('refresh');
    } catch { /* widget not initialized */ }
    return;
  }
  const text = value === null || value === undefined ? '' : String(value);
  if (el.value !== text) el.value = text;
  const jq = (window as any).jQuery || (window as any).$;
  try {
    if (el instanceof HTMLSelectElement && jq?.fn?.selectpicker) jq(el).selectpicker('refresh');
  } catch { /* widget not initialized */ }
}

/** Initialize core widgets only on freshly inserted/replaced markup. */
function initWidgets(root: HTMLElement): void {
  const jq = (window as any).jQuery || (window as any).$;
  if (!jq || !jq.fn || !jq.fn.selectpicker) return;
  root.querySelectorAll('select').forEach((el) => {
    try {
      if (!jq(el).data('selectpicker')) jq(el).selectpicker();
    } catch { /* not a selectpicker control */ }
  });
}

/** Stable control identity: everything except the current values. */
function controlSignature(def: InterviewFieldDef): string {
  return JSON.stringify([
    def.style,
    def.input_type,
    def.required,
    def.options ?? null,
    def.constraints ?? null,
  ]);
}

/** Extract the .llm-native-field element from a server fragment. */
function parseWrapper(html: string): HTMLElement | null {
  const tpl = document.createElement('template');
  tpl.innerHTML = html.trim();
  const el = tpl.content.querySelector<HTMLElement>(WRAP_SEL);
  return el instanceof HTMLElement ? el : null;
}

function findWrapper(root: HTMLElement | null, name: string): HTMLElement | null {
  if (!root) return null;
  return root.querySelector<HTMLElement>(
    `${WRAP_SEL}[data-field-name="${CSS.escape(name)}"]`
  );
}

function controlsOf(wrap: HTMLElement, name: string): Control[] {
  const out: Control[] = [];
  wrap.querySelectorAll<Control>('input, select, textarea').forEach((el) => {
    const info = fieldNameOf(el);
    if (info && info.name === name) out.push(el);
  });
  return out;
}

function isRenderable(def: InterviewFieldDef | undefined, allowManual: boolean): boolean {
  return (
    !!def &&
    !def.hidden &&
    (!!def.native_only || (allowManual && !!def.manual_writable))
  );
}

/** Grow every text area of a wrapper to its content. */
function fitWrapper(wrap: HTMLElement): void {
  wrap.querySelectorAll('textarea').forEach((el) => fitTextarea(el));
}

export const NativeInterviewFields: React.FC<NativeInterviewFieldsProps> = ({
  holder,
  scope,
  nativeHtml,
  fields,
  draft,
  pendingFields,
  allowManual,
  validatorRef,
  onFieldValue,
}) => {
  const hostRef = useRef<HTMLDivElement>(null);
  /** Wrappers this component currently owns, by field name. */
  const placed = useRef<Map<string, HTMLElement>>(new Map());
  const appliedSigs = useRef<Record<string, string>>({});
  const appliedDraft = useRef<Record<string, unknown>>({});
  const fieldsRef = useRef(fields);
  fieldsRef.current = fields;
  const allowManualRef = useRef(allowManual);
  allowManualRef.current = allowManual;
  const fitFrame = useRef(0);

  /** Re-fit all text areas on the next frame (layout must have settled). */
  const scheduleFit = useCallback(() => {
    if (fitFrame.current) return;
    fitFrame.current = window.requestAnimationFrame(() => {
      fitFrame.current = 0;
      placed.current.forEach((wrap) => fitWrapper(wrap));
    });
  }, []);

  useEffect(
    () => () => {
      if (fitFrame.current) window.cancelAnimationFrame(fitFrame.current);
    },
    []
  );

  // The holder is the hidden parking element: it stays attached inside
  // the mount outside React ownership so wrappers survive unmount.
  useEffect(() => {
    if (holder) holder.style.display = 'none';
  }, [holder]);

  // Reconcile renderable wrappers between parking and their slots.
  useEffect(() => {
    const host = hostRef.current;
    const root = scope ?? host;
    if (!host || !root) return;

    // Adopt/create wrappers for renderable fields in schema order.
    for (const [name, def] of Object.entries(fields)) {
      if (!isRenderable(def, allowManual)) continue;
      const html = nativeHtml[name];
      const sig = controlSignature(def);
      let wrap: HTMLElement | null =
        placed.current.get(name) ?? findWrapper(root, name) ?? findWrapper(holder, name);
      const fresh = typeof html === 'string' && html !== '' ? parseWrapper(html) : null;
      if (wrap && fresh && appliedSigs.current[name] !== sig) {
        // Same field, changed control surface: replace the element (the
        // fragment already includes the wrapper, so nothing nests).
        wrap.replaceWith(fresh);
        wrap = fresh;
        initWidgets(fresh);
        delete appliedDraft.current[name];
      } else if (!wrap && fresh) {
        wrap = fresh;
        initWidgets(fresh);
      }
      if (!wrap) continue;
      appliedSigs.current[name] = sig;
      placed.current.set(name, wrap);
      const slot = scope
        ? scope.querySelector<HTMLElement>(`[data-llm-iv-slot="${CSS.escape(name)}"]`)
        : null;
      const target = slot ?? host;
      if (wrap.parentElement !== target) {
        target.appendChild(wrap);
        if (!pendingFields.has(name)) {
          delete appliedDraft.current[name];
          controlsOf(wrap, name).forEach((el) => writeControlValue(el, draft[name]));
          appliedDraft.current[name] = draft[name];
        }
      }
    }

    // Park wrappers that left the renderable set (hidden/ineligible).
    placed.current.forEach((wrap, name) => {
      if (!isRenderable(fields[name], allowManual) && holder) {
        holder.appendChild(wrap);
        placed.current.delete(name);
        delete appliedSigs.current[name];
        delete appliedDraft.current[name];
      }
    });
    scheduleFit();
  }, [fields, nativeHtml, holder, scope, draft, allowManual, pendingFields, scheduleFit]);

  // Push canonical draft values into the controls (dirty/focused/pending
  // fields are skipped - the local edit is newer than the echo).
  useEffect(() => {
    let wrote = false;
    for (const [name, value] of Object.entries(draft)) {
      if (pendingFields.has(name)) continue;
      if (appliedDraft.current[name] === value) continue;
      const wrap = placed.current.get(name);
      if (!wrap) continue;
      controlsOf(wrap, name).forEach((el) => writeControlValue(el, value));
      appliedDraft.current[name] = value;
      wrote = true;
    }
    if (wrote) scheduleFit();
  }, [draft, pendingFields, scheduleFit]);

  // The panel can change width (window resize, chat position): wrapped
  // text then needs a different height.
  useEffect(() => {
    if (!scope || typeof ResizeObserver === 'undefined') return undefined;
    let lastWidth = scope.clientWidth;
    const observer = new ResizeObserver(() => {
      if (scope.clientWidth !== lastWidth) {
        lastWidth = scope.clientWidth;
        scheduleFit();
      }
    });
    observer.observe(scope);
    return () => observer.disconnect();
  }, [scope, scheduleFit]);

  // Native HTML validity gate for pending edits: called by the parent
  // before flush/approve. Blank optional values pass; a failed check
  // reports natively and blocks the remote call.
  useEffect(() => {
    validatorRef.current = (names: string[]) => {
      for (const name of names) {
        const def = fieldsRef.current[name];
        const wrap = placed.current.get(name);
        if (!def || !wrap) continue;
        const controls = controlsOf(wrap, name);
        const hasValue = controls.some((el) => {
          const v = readControlValue(el);
          return Array.isArray(v) ? v.length > 0 : v !== '' && v !== '0';
        });
        if (!def.required && !hasValue) continue;
        for (const el of controls) {
          if (typeof el.checkValidity === 'function' && !el.checkValidity()) {
            el.reportValidity();
            return false;
          }
        }
      }
      return true;
    };
    return () => {
      validatorRef.current = null;
    };
  }, [validatorRef]);

  // Delegated change collection from the native controls.
  const onChange = useCallback(
    (ev: Event) => {
      const el = ev.target as Control;
      if (!el || !(el instanceof HTMLElement)) return;
      const info = fieldNameOf(el);
      if (!info) return;
      const def = fieldsRef.current[info.name];
      if (!isRenderable(def, allowManualRef.current)) return;
      if (el instanceof HTMLTextAreaElement) fitTextarea(el);
      if (el instanceof HTMLInputElement && el.type === 'radio' && !el.checked) return;
      if (el instanceof HTMLInputElement && el.type === 'file') {
        // No interview upload endpoint: surface the boundary instead of
        // collecting a fake filename as the field value.
        onFieldValue(info.name, null, !!def.native_only, true);
        return;
      }
      let value = readControlValue(el);
      if (
        info.multi &&
        el instanceof HTMLInputElement &&
        (el.type === 'checkbox' || el.type === 'radio')
      ) {
        // Option groups store the array of checked values, not the
        // single control that fired the event.
        const wrap = el.closest<HTMLElement>(WRAP_SEL);
        value = wrap
          ? controlsOf(wrap, info.name)
              .filter(
                (c): c is HTMLInputElement =>
                  c instanceof HTMLInputElement &&
                  (c.type === 'checkbox' || c.type === 'radio') &&
                  c.checked
              )
              .map((c) => c.value)
          : value;
      }
      appliedDraft.current[info.name] = value;
      onFieldValue(info.name, value, !!def.native_only, false);
    },
    [onFieldValue]
  );

  useEffect(() => {
    const root = scope ?? hostRef.current;
    if (!root) return undefined;
    root.addEventListener('input', onChange, true);
    root.addEventListener('change', onChange, true);
    return () => {
      root.removeEventListener('input', onChange, true);
      root.removeEventListener('change', onChange, true);
    };
  }, [onChange, scope]);

  // Unmount: park every wrapper back into the hidden holder so a later
  // resume finds the controls (and their widget bindings) intact. The
  // signature/draft memory stays so remount does not re-render or
  // re-write the still-valid controls.
  useEffect(() => {
    const owned = placed.current;
    return () => {
      if (!holder) return;
      owned.forEach((wrap) => holder.appendChild(wrap));
      owned.clear();
    };
  }, [holder]);

  return <div className="llm-interview-native" ref={hostRef} />;
};

export default NativeInterviewFields;
