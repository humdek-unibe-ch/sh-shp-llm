/**
 * LLM Memory Manager React Entry Point
 * =====================================
 *
 * Mounts the MemoryManager React application into `#llm-memory-root`.
 * This admin module allows managing per-user memory rules, browsing
 * stored memory entries, and configuring memory extraction settings.
 *
 * Built as a UMD bundle (`llm-memory.umd.js`).
 *
 * @module memory
 */

import React from 'react';
import ReactDOM from 'react-dom/client';
import { MemoryManager } from './components/memory/MemoryManager';
import './components/memory/MemoryRulesEditor.css';

/** Parse config from the DOM container and mount the MemoryManager tree. */
function initializeMemoryManager(): void {
  const container = document.getElementById('llm-memory-root');
  if (!container) {
    return;
  }

  const configData = container.getAttribute('data-config') || '{}';
  let config: Record<string, unknown> | null = null;
  try {
    config = JSON.parse(configData);
  } catch (error) {
    console.error('LLM Memory: Failed to parse config', error);
  }

  if (!config) {
    container.innerHTML = '<div class="alert alert-danger m-3">Memory config missing</div>';
    return;
  }

  const root = ReactDOM.createRoot(container);
  root.render(<MemoryManager config={config as any} />);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializeMemoryManager);
} else {
  initializeMemoryManager();
}
