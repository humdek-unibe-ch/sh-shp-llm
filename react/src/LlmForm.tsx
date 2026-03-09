/**
 * LLM Form React Entry Point
 * ==========================
 * 
 * Mounts the LLM result panel on .llm-form-root containers.
 * Intercepts form submissions to trigger LLM generation and
 * displays the result in a configurable panel.
 */

import React from 'react';
import ReactDOM from 'react-dom/client';
import 'bootstrap/dist/css/bootstrap.min.css';
import './components/styles/form/LlmForm.css';
import { LlmFormPanel } from './components/styles/form/LlmFormPanel';
import type { LlmFormConfig } from './types/form';

const LLM_FORM_INIT_KEY = '__llmFormInitialized';

function parseLlmFormConfig(container: HTMLElement): LlmFormConfig | null {
  const raw = container.getAttribute('data-llm-config');
  if (!raw) return null;
  try {
    return JSON.parse(raw) as LlmFormConfig;
  } catch {
    console.error('LLM Form: Failed to parse config');
    return null;
  }
}

function initializeLlmForm(container: HTMLElement): void {
  if (container.dataset.llmInitialized === '1') return;

  const config = parseLlmFormConfig(container);
  if (!config || !config.llmEnabled) return;

  const formContentEl = container.querySelector('.llm-form-content');
  const resultContainerEl = container.querySelector('.llm-result-container');
  if (!formContentEl || !resultContainerEl) return;

  const formName = container.getAttribute('data-form-name') || '';

  container.dataset.llmInitialized = '1';

  // Neutralize SelfHelp's jQuery AJAX form handler to prevent it from
  // racing with React's submission and replacing the DOM via updateValues().
  const jq = (window as any).jQuery || (window as any).$;
  if (jq) {
    const forms = formContentEl.querySelectorAll('form.selfHelp-form');
    forms.forEach((form) => {
      try { jq(form).off('submit'); } catch { /* ignore */ }
    });
  }
  // Set the ajax hidden input to a non-matching value so the core handler's
  // check `$(this).find('input[name="ajax"]').val() == 1` returns false.
  const ajaxInput = formContentEl.querySelector('input[name="ajax"]') as HTMLInputElement | null;
  if (ajaxInput) ajaxInput.value = 'llm';

  // Watch for DOM replacement that would orphan our React root
  const llmRoot = container;
  if (llmRoot.parentElement) {
    const observer = new MutationObserver((mutations) => {
      for (const m of mutations) {
        for (let i = 0; i < m.removedNodes.length; i++) {
          const removed = m.removedNodes[i];
          if (removed === llmRoot || (removed instanceof Element && removed.contains(llmRoot))) {
            console.error('[LLM Form] DOM REPLACED! Our .llm-form-root was removed from the document.', removed);
          }
        }
      }
    });
    observer.observe(llmRoot.parentElement, { childList: true });
  }

  const root = ReactDOM.createRoot(resultContainerEl as HTMLElement);
  root.render(
    <LlmFormPanel
      config={config}
      formContainer={formContentEl as HTMLElement}
      formName={formName}
    />
  );
}

function initializeAllLlmForms(): void {
  const containers = document.querySelectorAll('.llm-form-root');
  if (containers.length === 0) return;
  containers.forEach((el) => {
    initializeLlmForm(el as HTMLElement);
  });
}

if (!(window as any)[LLM_FORM_INIT_KEY]) {
  (window as any)[LLM_FORM_INIT_KEY] = true;
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeAllLlmForms);
  } else {
    initializeAllLlmForms();
  }
}

export { LlmFormPanel };
