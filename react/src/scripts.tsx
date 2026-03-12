import React from 'react';
import ReactDOM from 'react-dom/client';
import { ScriptsManager } from './components/scripts/ScriptsManager';
import './components/scripts/ScriptsManager.css';

export interface ScriptsConfig {
  csrfToken?: string;
  promptLabEndpoint?: string;
}

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
