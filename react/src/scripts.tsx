/**
 * LLM Scripts Manager React Entry Point
 * ======================================
 *
 * Mounts the ScriptsManager React application into `#llm-scripts-root`.
 * Provides CRUD for LLM scripts (automated prompts executed by the
 * job scheduler) with a JSON editor, test variable support, and
 * integration with the Prompt Lab.
 *
 * Built as a UMD bundle (`llm-scripts.umd.js`).
 *
 * @module scripts
 */

import React from 'react';
import ReactDOM from 'react-dom/client';
import { ScriptsManager } from './components/scripts/ScriptsManager';
import './components/scripts/ScriptsManager.css';

/** Bootstrap config passed from PHP via `data-config` attribute. */
export interface ScriptsConfig {
  /** CSRF token for secure POST requests */
  csrfToken?: string;
  /** Base URL for the Prompt Lab AJAX endpoint */
  promptLabEndpoint?: string;
}

/** Parse config from the DOM container and mount the ScriptsManager tree. */
function initializeScriptsManager(): void {
  const container = document.getElementById('llm-scripts-root');
  if (!container) {
    return;
  }

  const configData = container.getAttribute('data-config') || '{}';
  let config: ScriptsConfig | null = null;
  try {
    config = JSON.parse(configData);
  } catch (e) {
    console.error('LLM Scripts: Failed to parse config', e);
  }

  if (!config) {
    container.innerHTML = '<div class="alert alert-danger m-3">Scripts config missing</div>';
    return;
  }

  const root = ReactDOM.createRoot(container);
  root.render(<ScriptsManager config={config} />);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializeScriptsManager);
} else {
  initializeScriptsManager();
}
