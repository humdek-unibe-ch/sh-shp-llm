import React from 'react';
import ReactDOM from 'react-dom/client';
import { MemoryManager } from './components/memory/MemoryManager';
import './components/memory/MemoryRulesEditor.css';

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
