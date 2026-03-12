import React from 'react';
import ReactDOM from 'react-dom/client';
import { PromptFieldApp, type PromptFieldConfig } from './components/prompts/PromptFieldApp';
import './components/prompts/PromptLab.css';

const PROMPT_FIELD_INIT_KEY = '__llmPromptFieldInitialized';

function parseConfig(element: HTMLElement): PromptFieldConfig | null {
  const raw = element.getAttribute('data-config');
  if (!raw) {
    return null;
  }

  try {
    return JSON.parse(raw) as PromptFieldConfig;
  } catch {
    return null;
  }
}

function initPromptField(container: HTMLElement): void {
  if (container.dataset.promptInitialized === '1') {
    return;
  }

  const config = parseConfig(container);
  const rootEl = container.querySelector<HTMLElement>('.llm-prompt-field-root');
  const contentInput = container.querySelector<HTMLTextAreaElement>('.llm-prompt-content-input');
  const metaInput = container.querySelector<HTMLInputElement>('.llm-prompt-meta-input');

  if (!config || !rootEl || !contentInput || !metaInput) {
    return;
  }

  container.dataset.promptInitialized = '1';
  const root = ReactDOM.createRoot(rootEl);
  root.render(
    <PromptFieldApp
      config={config}
      container={container}
      contentInput={contentInput}
      metaInput={metaInput}
    />,
  );
}

function initAllPromptFields(): void {
  document.querySelectorAll<HTMLElement>('.llm-prompt-field[data-config]').forEach(initPromptField);
}

if (!(window as any)[PROMPT_FIELD_INIT_KEY]) {
  (window as any)[PROMPT_FIELD_INIT_KEY] = true;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAllPromptFields);
  } else {
    initAllPromptFields();
  }

  const observer = new MutationObserver(() => initAllPromptFields());
  observer.observe(document.documentElement, { childList: true, subtree: true });
}

