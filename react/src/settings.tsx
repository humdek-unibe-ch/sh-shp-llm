/**
 * LLM Settings React Entry Point
 * ===============================
 *
 * Mounts the SettingsApp React application into `#llm-settings-root`.
 * Provides the admin interface for managing API keys, model defaults,
 * and memory configuration for the LLM plugin.
 *
 * Built as a UMD bundle (`llm-settings.umd.js`).
 *
 * @module settings
 */

import React from 'react';
import ReactDOM from 'react-dom/client';
import { SettingsApp } from './components/settings/SettingsApp';
import './components/settings/Settings.css';

/** Bootstrap config passed from PHP via `data-config` attribute. */
export interface SettingsBootConfig {
  /** CSRF token for secure POST requests */
  csrfToken?: string;
  /** URL to the memory admin page (for navigation links) */
  memoryPageUrl?: string;
}

/** Parse config from the DOM container and mount the SettingsApp tree. */
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
