/**
 * LLM Prompts Hub React Entry Point
 * =================================
 *
 * Mounts the PromptsHubApp React application into `#llm-prompts-root`.
 * The prompts hub lists every LLM prompt (CMS prompt fields, scripts, memory rules,
 * built-in system prompts, agentic chat personas) with search and filters, and links each
 * one to where it is used.
 *
 * Built as a UMD bundle (`llm-prompts.umd.js`).
 *
 * @module prompts-hub
 */

import React from 'react';
import ReactDOM from 'react-dom/client';
import { PromptsHubApp } from './components/promptsHub/PromptsHubApp';
import type { PromptsHubConfig } from './components/promptsHub/promptsHubTypes';
import { setCsrfToken } from './utils/api';

/** Parse config from the DOM container and mount the PromptsHubApp tree. */
function initializePromptsHub(): void {
  const container = document.getElementById('llm-prompts-root');
  if (!container) {
    return;
  }

  const configData = container.getAttribute('data-config') || '{}';
  let config: PromptsHubConfig | null = null;
  try {
    config = JSON.parse(configData);
  } catch (error) {
    console.error('LLM Prompts: Failed to parse config', error);
  }

  if (!config) {
    container.innerHTML = '<div class="alert alert-danger m-3">Prompts config missing</div>';
    return;
  }

  // Requests that change data are refused without the session's token
  setCsrfToken(typeof config.csrfToken === 'string' ? config.csrfToken : '');

  const root = ReactDOM.createRoot(container);
  root.render(<PromptsHubApp config={config} />);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializePromptsHub);
} else {
  initializePromptsHub();
}
