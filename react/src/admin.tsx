import React from 'react';
import ReactDOM from 'react-dom/client';
import { AdminConsole } from './components/admin/AdminConsole';
import './components/admin/styles/LlmAdmin.css';
import type { AdminConfig } from './types';

function initializeAdminConsole(): void {
  const container = document.getElementById('llm-admin-root');
  if (!container) {
    // If the script loads before the DOM element exists, wait for DOM ready.
    return;
  }

  const configData = container.getAttribute('data-config') || '{}';
  let config: AdminConfig | null = null;
  try {
    config = JSON.parse(configData);
  } catch (e) {
    console.error('LLM Admin: Failed to parse config', e);
  }

  if (!config) {
    container.innerHTML = '<div class="alert alert-danger m-3">Admin config missing</div>';
    return;
  }

  const root = ReactDOM.createRoot(container);
  root.render(<AdminConsole config={config} />);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializeAdminConsole);
} else {
  initializeAdminConsole();
}

