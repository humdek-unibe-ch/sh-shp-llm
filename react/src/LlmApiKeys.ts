import './components/styles/apikeys/LlmApiKeys.css';

/**
 * LLM API Keys Manager
 * ====================
 *
 * Standalone (no React) script that powers the multi-server
 * API keys CRUD interface inside the CMS admin page.
 *
 * It watches for `.llm-api-keys-manager` containers (which may
 * appear after initial page load due to CMS dynamic rendering),
 * reads the hidden textarea JSON, and builds an interactive card
 * list with add / edit / delete capabilities.
 */

interface ServerEntry {
  name: string;
  base_url: string;
  api_key: string;
}

function maskKey(key: string): string {
  if (!key || key.length < 8) return '********';
  return key.substring(0, 4) + '****' + key.substring(key.length - 4);
}

function initApiKeysManager(container: HTMLElement): void {
  const textarea = container.querySelector<HTMLTextAreaElement>('.llm-api-keys-value');
  const listEl = container.querySelector<HTMLElement>('.llm-api-keys-list');
  const addBtn = container.querySelector<HTMLButtonElement>('.llm-api-keys-add');
  if (!textarea || !listEl) return;

  const isDisabled = container.dataset.disabled === '1';

  function getData(): ServerEntry[] {
    try { return JSON.parse(textarea!.value) || []; }
    catch { return []; }
  }

  function setData(data: ServerEntry[]): void {
    textarea!.value = JSON.stringify(data);
    render();
  }

  function render(): void {
    const data = getData();
    listEl!.innerHTML = '';

    if (data.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'llm-apk-empty';
      empty.textContent = isDisabled
        ? 'No servers configured.'
        : 'No servers configured. Click "Add Server" to add your first LLM endpoint.';
      listEl!.appendChild(empty);
      return;
    }

    data.forEach((entry, idx) => {
      const card = document.createElement('div');
      card.className = 'llm-apk-card';

      const header = document.createElement('div');
      header.className = 'llm-apk-header';

      const nameSpan = document.createElement('span');
      nameSpan.className = 'llm-apk-name';
      nameSpan.textContent = entry.name || 'Unnamed';
      header.appendChild(nameSpan);

      if (!isDisabled) {
        const actions = document.createElement('div');
        actions.className = 'btn-group btn-group-sm llm-apk-actions';

        const editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'btn btn-outline-secondary';
        editBtn.title = 'Edit';
        editBtn.setAttribute('aria-label', 'Edit server');
        editBtn.innerHTML = '<i class="fa fa-edit"></i>';
        editBtn.addEventListener('click', () => editEntry(idx));
        actions.appendChild(editBtn);

        const delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'btn btn-outline-danger';
        delBtn.title = 'Delete';
        delBtn.setAttribute('aria-label', 'Delete server');
        delBtn.innerHTML = '<i class="fa fa-trash"></i>';
        delBtn.addEventListener('click', () => deleteEntry(idx));
        actions.appendChild(delBtn);

        header.appendChild(actions);
      }

      card.appendChild(header);

      const urlDiv = document.createElement('div');
      urlDiv.className = 'llm-apk-url';
      urlDiv.textContent = entry.base_url || '';
      card.appendChild(urlDiv);

      const keyDiv = document.createElement('div');
      keyDiv.className = 'llm-apk-key';
      keyDiv.textContent = 'Key: ' + maskKey(entry.api_key);
      card.appendChild(keyDiv);

      listEl!.appendChild(card);
    });
  }

  function showForm(entry: Partial<ServerEntry>, onSave: (e: ServerEntry) => void): void {
    const overlay = document.createElement('div');
    overlay.className = 'llm-apk-card llm-apk-form';

    const title = document.createElement('div');
    title.className = 'llm-apk-form-title';
    title.textContent = entry.name ? 'Edit Server' : 'Add Server';
    overlay.appendChild(title);

    function makeField(label: string, cls: string, placeholder: string, value: string): HTMLDivElement {
      const group = document.createElement('div');
      group.className = 'form-group mb-2';
      const lbl = document.createElement('label');
      lbl.textContent = label;
      group.appendChild(lbl);
      const input = document.createElement('input');
      input.type = 'text';
      input.className = 'form-control form-control-sm ' + cls;
      input.placeholder = placeholder;
      input.value = value;
      group.appendChild(input);
      return group;
    }

    overlay.appendChild(makeField('Server Name', 'llm-f-name', 'e.g. GPUStack Production', entry.name || ''));
    overlay.appendChild(makeField('Base URL', 'llm-f-url', 'e.g. https://gpustack.example.com/v1', entry.base_url || ''));
    overlay.appendChild(makeField('API Key', 'llm-f-key', 'e.g. sk-abc123...', entry.api_key || ''));

    const btnRow = document.createElement('div');
    btnRow.className = 'd-flex justify-content-end mt-2';

    const saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'btn btn-sm btn-primary mr-2';
    saveBtn.textContent = 'Save';
    saveBtn.addEventListener('click', () => {
      const n = (overlay.querySelector<HTMLInputElement>('.llm-f-name')!).value.trim();
      const u = (overlay.querySelector<HTMLInputElement>('.llm-f-url')!).value.trim();
      const k = (overlay.querySelector<HTMLInputElement>('.llm-f-key')!).value.trim();
      if (!n) { alert('Server name is required.'); return; }
      if (!u) { alert('Base URL is required.'); return; }
      onSave({ name: n, base_url: u, api_key: k });
      overlay.remove();
    });
    btnRow.appendChild(saveBtn);

    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'btn btn-sm btn-outline-secondary';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.addEventListener('click', () => overlay.remove());
    btnRow.appendChild(cancelBtn);

    overlay.appendChild(btnRow);
    listEl!.appendChild(overlay);
    overlay.querySelector<HTMLInputElement>('.llm-f-name')!.focus();
  }

  function editEntry(idx: number): void {
    const data = getData();
    listEl!.querySelectorAll('.llm-apk-form').forEach(f => f.remove());
    showForm(data[idx], updated => {
      data[idx] = updated;
      setData(data);
    });
  }

  function deleteEntry(idx: number): void {
    const doDelete = () => {
      const data = getData();
      data.splice(idx, 1);
      setData(data);
    };

    if (typeof (window as any).$.confirm === 'function') {
      (window as any).$.confirm({
        title: 'Delete Server',
        content: 'Remove this server configuration?',
        type: 'red',
        buttons: {
          confirm: () => doDelete(),
          cancel: () => {}
        }
      });
      return;
    }

    if (window.confirm('Remove this server configuration?')) {
      doDelete();
    }
  }

  if (addBtn) {
    addBtn.addEventListener('click', () => {
      listEl!.querySelectorAll('.llm-apk-form').forEach(f => f.remove());
      showForm({}, entry => {
        const data = getData();
        data.push(entry);
        setData(data);
      });
    });
  }

  render();
}

function initAll(): void {
  document.querySelectorAll<HTMLElement>('.llm-api-keys-manager').forEach(el => {
    if (el.dataset.initialized === '1') return;
    el.dataset.initialized = '1';
    initApiKeysManager(el);
  });
}

// Run immediately if DOM ready, otherwise on DOMContentLoaded
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initAll);
} else {
  initAll();
}

// MutationObserver to catch containers rendered after initial load
const observer = new MutationObserver(() => { initAll(); });
observer.observe(document.documentElement, { childList: true, subtree: true });

// Safety: also poll briefly for late-arriving elements
let pollCount = 0;
const pollInterval = setInterval(() => {
  initAll();
  pollCount++;
  if (pollCount >= 20) clearInterval(pollInterval);
}, 500);
