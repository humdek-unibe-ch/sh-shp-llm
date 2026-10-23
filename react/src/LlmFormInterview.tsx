/**
 * LLM Form Interview Entry Point
 * ==============================
 *
 * Mounts the interview shell on every .llmFormLogList-interview mount
 * element (one per section). Intercepts the section's New/Edit CTA
 * links that target this section (exact sid match, record='new' or a
 * positive id), keeps a WeakMap of mounted roots so nothing mounts
 * twice, and survives the core soft-refresh root replacement through
 * the selfhelp:loglist events (plus a MutationObserver fallback for
 * roots inserted or removed without them).
 *
 * The native fragment holder rendered by PHP stays in the mount as a
 * hidden parking element - NativeInterviewFields adopts its per-field
 * wrappers, it is never cloned or re-rendered by React.
 */

import React from 'react';
import ReactDOM from 'react-dom/client';
// Shared chat styles (MessageList/MessageInput) first, interview
// overrides after - never a copy.
import './components/styles/chat/LlmChat.css';
import './components/styles/interview/interview.css';
import { LlmFormInterview } from './components/styles/interview/LlmFormInterview';
import type { InterviewConfig } from './types/interview';

const MOUNT_SELECTOR = '.llmFormLogList-interview';
const INIT_FLAG = 'llmInterviewMounted';
const roots = new WeakMap<HTMLElement, ReactDOM.Root>();
let observer: MutationObserver | null = null;

function parseConfig(el: HTMLElement): InterviewConfig | null {
  const raw = el.getAttribute('data-config');
  if (!raw) return null;
  try {
    const cfg = JSON.parse(raw) as InterviewConfig;
    return typeof cfg.sectionId === 'number' ? cfg : null;
  } catch {
    return null;
  }
}

/** The PHP-rendered native fragment holder (hidden parking element). */
function nativeHolderOf(mount: HTMLElement): HTMLElement | null {
  return mount.querySelector<HTMLElement>('.llmFormLogList-interview-native');
}

function mountInterview(mount: HTMLElement): void {
  if (roots.has(mount) || (mount.dataset as DOMStringMap)[INIT_FLAG] === '1') return;
  const config = parseConfig(mount);
  if (!config) return;
  (mount.dataset as DOMStringMap)[INIT_FLAG] = '1';

  const slot = document.createElement('div');
  slot.className = 'llm-interview-react';
  mount.appendChild(slot);

  // Park the PHP-rendered controls hidden before React mounts;
  // NativeInterviewFields adopts per-field wrappers from it.
  const holder = nativeHolderOf(mount);
  if (holder) holder.style.display = 'none';

  const root = ReactDOM.createRoot(slot);
  roots.set(mount, root);
  root.render(
    <LlmFormInterview
      config={config}
      mount={mount}
      nativeHolder={holder}
    />
  );
}

function unmountInterview(mount: HTMLElement): void {
  const root = roots.get(mount);
  if (root) {
    roots.delete(mount);
    try {
      root.unmount();
    } catch {
      /* already unmounted */
    }
    // Safety net: the core list refresh strips every .modal-backdrop from
    // <body> before it fires its replace event. An interview modal still
    // open at that point makes React's portal teardown fail and strands
    // the dialog as an invisible layer that swallows every click. The
    // root is gone, so whatever still carries its marker is an orphan.
    const sectionId = parseConfig(mount)?.sectionId;
    if (sectionId !== undefined) {
      document
        .querySelectorAll(`[data-llm-iv-modal="${sectionId}"]`)
        .forEach((orphan) => orphan.remove());
    }
  }
  delete (mount.dataset as DOMStringMap)[INIT_FLAG];
}

function initializeAll(): void {
  document
    .querySelectorAll<HTMLElement>(MOUNT_SELECTOR)
    .forEach((el) => mountInterview(el));
}

/**
 * CTA interception: only the section's own New/Edit buttons open the
 * interview. The link must carry this section's sid plus record='new'
 * or a positive id; foreign sections, arbitrary content links,
 * cross-origin targets, malformed ids and modifier/new-tab clicks all
 * keep normal browser navigation.
 */
function onCtaClick(ev: MouseEvent): void {
  if (ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
  const link = (ev.target as HTMLElement).closest('a[href]');
  if (!(link instanceof HTMLAnchorElement)) return;
  const isCta =
    link.classList.contains('formUserInputLogList-btn-new') ||
    link.classList.contains('llmFormLogList-btn-new') ||
    link.classList.contains('formUserInputLogList-btn-edit');
  if (!isCta) return;
  const sectionRoot = link.closest('.formUserInputLogList');
  if (!(sectionRoot instanceof HTMLElement)) return;
  const mount = sectionRoot.querySelector<HTMLElement>(MOUNT_SELECTOR);
  if (!(mount instanceof HTMLElement)) return;
  const config = parseConfig(mount);
  if (!config) return;
  let href: URL;
  try {
    href = new URL(link.href, window.location.href);
  } catch {
    return;
  }
  if (href.origin !== window.location.origin) return;
  const sid = href.searchParams.get('sid');
  const record = href.searchParams.get('record');
  if (sid !== String(config.sectionId) || record === null) return;
  if (
    record !== 'new' &&
    !(/^\d+$/.test(record) && parseInt(record, 10) > 0)
  ) {
    return;
  }
  ev.preventDefault();
  mount.dispatchEvent(
    new CustomEvent('llm-interview:open', {
      bubbles: false,
      detail: {
        sectionId: config.sectionId,
        recordId: record === 'new' ? null : parseInt(record, 10),
      },
    })
  );
}

function onBeforeReplace(ev: Event): void {
  const detail = (ev as CustomEvent).detail as
    | { root?: HTMLElement; sectionId?: number }
    | undefined;
  const root = detail?.root;
  if (!(root instanceof HTMLElement)) return;
  root.querySelectorAll<HTMLElement>(MOUNT_SELECTOR).forEach(unmountInterview);
}

function onAfterReplace(): void {
  initializeAll();
}

function installObserver(): void {
  if (observer !== null) return;
  observer = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
      for (const node of Array.from(mutation.addedNodes)) {
        if (!(node instanceof HTMLElement)) continue;
        if (node.matches(MOUNT_SELECTOR)) {
          mountInterview(node);
        }
        node
          .querySelectorAll<HTMLElement>(MOUNT_SELECTOR)
          .forEach((el) => mountInterview(el));
      }
      for (const node of Array.from(mutation.removedNodes)) {
        if (!(node instanceof HTMLElement)) continue;
        // Skip nodes that are still connected - a moved node is not a
        // removed mount.
        if (node.isConnected) continue;
        if (node.matches(MOUNT_SELECTOR)) {
          unmountInterview(node);
        }
        node
          .querySelectorAll<HTMLElement>(MOUNT_SELECTOR)
          .forEach((el) => unmountInterview(el));
      }
    }
  });
  observer.observe(document.body, { childList: true, subtree: true });
}

const GLOBAL_KEY = '__llmFormInterviewInitialized';
if (!(window as any)[GLOBAL_KEY]) {
  (window as any)[GLOBAL_KEY] = true;
  document.addEventListener('click', onCtaClick, true);
  document.addEventListener('selfhelp:loglist:before-replace', onBeforeReplace);
  document.addEventListener('selfhelp:loglist:after-replace', onAfterReplace);
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      initializeAll();
      installObserver();
    });
  } else {
    initializeAll();
    installObserver();
  }
}

export { LlmFormInterview };
