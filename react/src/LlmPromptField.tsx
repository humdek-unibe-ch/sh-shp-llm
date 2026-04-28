/**
 * LLM Prompt Field React Entry Point
 * ====================================
 *
 * Enhances CMS textarea fields with the Prompt Lab UI. Each prompt field
 * in the CMS admin gets a React-powered editor with versioning, variable
 * extraction, playground testing, and AI-assisted prompt building.
 *
 * Finds all `.llm-prompt-field[data-config]` elements and mounts a
 * PromptFieldApp inside each one. Uses a MutationObserver to handle
 * dynamically added fields (e.g., when sections are loaded via AJAX).
 *
 * Built as a UMD bundle (`llm-prompt.umd.js`).
 *
 * @module LlmPromptField
 */

import React from 'react';
import ReactDOM from 'react-dom/client';
import { PromptFieldApp, type PromptFieldConfig } from './components/prompts/PromptFieldApp';
import './components/prompts/PromptLab.css';

const PROMPT_FIELD_INIT_KEY = '__llmPromptFieldInitialized';

/** Parse the JSON config from a prompt field container's data-config attribute. */
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

/** Mount a PromptFieldApp on a single prompt field container (idempotent). */
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

/** initAllPromptFields function. */
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

