import React from 'react';
import ReactDOM from 'react-dom/client';
import { SettingsApp } from './components/settings/SettingsApp';
import './components/settings/Settings.css';

export interface SettingsBootConfig {
  csrfToken?: string;
}

function initializeSettings(): void {
  const container = document.getElementById('llm-settings-root');
  if (!container) {
    return;
  }

  const configData = container.getAttribute('data-config') || '{}';
  let config: SettingsBootConfig | null = null;
  try {
    config = JSON.parse(configData);
  } catch (e) {
    console.error('LLM Settings: Failed to parse config', e);
  }

  if (!config) {
    container.innerHTML = '<div class="alert alert-danger m-3">Settings config missing</div>';
    return;
  }

  const root = ReactDOM.createRoot(container);
  root.render(<SettingsApp config={config} />);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializeSettings);
} else {
  initializeSettings();
}
