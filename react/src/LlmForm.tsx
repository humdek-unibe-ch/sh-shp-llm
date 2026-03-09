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

  // Remove SelfHelp's core AJAX form handler immediately to prevent it from
  // racing with React's managed submission and replacing the DOM via updateValues().
  const jq = (window as any).jQuery || (window as any).$;
  if (jq) {
    const forms = formContentEl.querySelectorAll('form.selfHelp-form');
    forms.forEach((form) => {
      try { jq(form).off('submit'); } catch { /* ignore */ }
    });
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
