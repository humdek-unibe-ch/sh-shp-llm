/**
 * LLM Admin Console React Entry Point
 * ====================================
 *
 * Mounts the AdminConsole React application into the `#llm-admin-root`
 * DOM element rendered by `ModuleLlmAdminConsoleView`. Configuration is
 * read from the `data-config` JSON attribute set by the PHP template.
 *
 * Built as a UMD bundle (`llm-admin.umd.js`) and loaded in the CMS
 * admin page for the LLM plugin.
 *
 * @module admin
 */

import React from 'react';
import ReactDOM from 'react-dom/client';
import { AdminConsole } from './components/admin/AdminConsole';
import './components/admin/AdminConsole.css';
import type { AdminConfig } from './types';

/**
 * Locate the admin root element, parse its JSON config, and mount
 * the React AdminConsole tree. Called once on DOMContentLoaded.
 */
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






