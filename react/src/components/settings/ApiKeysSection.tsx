/**
 * API Keys Section — manage provider API servers in the Settings page.
 *
 * Lists configured LLM servers with add/edit/delete actions. Stored keys
 * never reach the browser: a server only shows whether it has a key and its
 * last four characters (or the `env:` reference it reads the key from).
 * While editing, an empty key field keeps the stored key, a typed key
 * replaces it, and "Remove the stored key" clears it. The server matches the
 * saved list to the stored one by `id`.
 *
 * Every add / edit / delete is stored right away through `onSave` (no
 * "Save Changes" needed); a rejected save keeps the form open with the
 * server's message.
 *
 * @module components/settings/ApiKeysSection
 */
import React, { useState, useMemo } from 'react';

interface ServerEntry {
  id?: string;
  name: string;
  base_url: string;
  /** Typed by the administrator, never sent by the server. */
  api_key?: string;
  /** Remove the stored key. */
  clear_key?: boolean;
  has_key?: boolean;
  key_source?: string;
  key_hint?: string;
  key_readable?: boolean;
}

interface SecretsStatus {
  encryption_available: boolean;
  plain_keys: number;
  master_key_name: string;
}

interface FieldDef {
  name: string;
  type: string;
  label: string;
  help: string;
  value: string;
  meta?: SecretsStatus;
}

interface Props {
  field?: FieldDef;
  value: string;
  /** Store the list (JSON); resolves with an error message, or null when stored. */
  onSave: (value: string) => Promise<string | null>;
  disabled?: boolean;
  /** Encrypt the keys that are still stored as plain text. */
  onProtectKeys?: () => void;
  protecting?: boolean;
}

/** Text shown for the key of a server. */
function describeKey(entry: ServerEntry): string {
  if (!entry.has_key) return 'No key';
  const label = entry.key_source === 'env'
    ? `Environment variable ${entry.key_hint || ''}`
    : `Key: ${entry.key_hint || '********'}`;
  return entry.key_readable === false ? `${label} (cannot be read - enter the key again)` : label;
}

/** Same rules as the server: https (http only for localhost), no credentials/query/fragment. */
function baseUrlError(value: string): string | null {
  try {
    const url = new URL(value);
    const local = ['localhost', '127.0.0.1', '[::1]'].indexOf(url.hostname) !== -1;
    if (url.protocol !== 'https:' && !(url.protocol === 'http:' && local)) {
      return 'The base URL must use https (http is only accepted for localhost).';
    }
    if (url.username || url.password || url.search || url.hash) {
      return 'The base URL must not contain credentials, a query string or a fragment.';
    }
    return null;
  } catch {
    return 'The base URL must look like https://host/v1.';
  }
}

/** ApiKeysSection component. */
export const ApiKeysSection: React.FC<Props> = ({ field, value, onSave, disabled, onProtectKeys, protecting }) => {
  const [editIndex, setEditIndex] = useState<number | null>(null);
  const [formData, setFormData] = useState<Partial<ServerEntry>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const servers: ServerEntry[] = useMemo(() => {
    try {
      return JSON.parse(value) || [];
    } catch {
      return [];
    }
  }, [value]);

  const secrets = field?.meta;
  const hasStoredKey = editIndex !== null && editIndex >= 0 && !!servers[editIndex]?.has_key;

  /** Store the list; resolves with an error message, or null when stored. */
  const storeServers = async (newServers: ServerEntry[]): Promise<string | null> => {
    setSaving(true);
    try {
      return await onSave(JSON.stringify(newServers));
    } finally {
      setSaving(false);
    }
  };

  const startAdd = () => {
    setFormData({ name: '', base_url: '', api_key: '' });
    setFormError(null);
    setDeleteError(null);
    setEditIndex(-1);
  };

  const startEdit = (idx: number) => {
    // The key field always starts empty: the stored key is not known here.
    setFormData({ ...servers[idx], api_key: '', clear_key: false });
    setFormError(null);
    setDeleteError(null);
    setEditIndex(idx);
  };

  const cancelEdit = () => {
    setEditIndex(null);
    setFormData({});
    setFormError(null);
  };

  const saveEntry = async () => {
    const name = formData.name?.trim() || '';
    const baseUrl = formData.base_url?.trim() || '';
    if (!name || !baseUrl) return;

    const urlError = baseUrlError(baseUrl);
    if (urlError) {
      setFormError(urlError);
      return;
    }

    const previous = editIndex !== null && editIndex >= 0 ? servers[editIndex] : undefined;
    const clear = !!formData.clear_key;
    const typed = formData.api_key?.trim() || '';
    const entry: ServerEntry = {
      id: previous?.id,
      name,
      base_url: baseUrl,
      has_key: previous?.has_key,
      key_source: previous?.key_source,
      key_hint: previous?.key_hint,
      key_readable: previous?.key_readable,
      api_key: typed || undefined,
      clear_key: clear && !typed ? true : undefined,
    };

    const updated = [...servers];
    if (editIndex === -1) {
      updated.push(entry);
    } else if (editIndex !== null) {
      updated[editIndex] = entry;
    }
    const error = await storeServers(updated);
    if (error) {
      // Keep the form (and what was typed) open so it can be corrected
      setFormError(error);
      return;
    }
    cancelEdit();
  };

  const deleteEntry = async (idx: number) => {
    if (!window.confirm('Remove this server configuration?')) return;
    setDeleteError(null);
    const error = await storeServers(servers.filter((_, i) => i !== idx));
    if (error) setDeleteError(error);
  };

  return (
    <div className="card mb-3">
      <div className="card-header d-flex justify-content-between align-items-center">
        <h6 className="mb-0">
          <i className="fa fa-key mr-2 text-muted"></i>
          API Configuration
        </h6>
        {!disabled && editIndex === null && (
          <button className="btn btn-sm btn-outline-primary" onClick={startAdd}>
            <i className="fa fa-plus mr-1"></i> Add Server
          </button>
        )}
      </div>
      <div className="card-body">
        {field?.help && <p className="text-muted small mb-3">{field.help}</p>}

        {secrets && secrets.plain_keys > 0 && !secrets.encryption_available && (
          <div className="alert alert-warning small" role="alert">
            {secrets.plain_keys} API key(s) are stored as plain text. Set the environment variable{' '}
            <code>{secrets.master_key_name}</code> (at least 16 characters) on the server to encrypt keys, or
            reference an environment variable (<code>env:LLM_NAME</code>) instead of typing a key.
          </div>
        )}

        {secrets && secrets.plain_keys > 0 && secrets.encryption_available && !disabled && (
          <div className="alert alert-warning small d-flex justify-content-between align-items-center" role="alert">
            <span>{secrets.plain_keys} API key(s) are still stored as plain text.</span>
            <button
              className="btn btn-sm btn-outline-dark ml-3"
              onClick={onProtectKeys}
              disabled={protecting || !onProtectKeys}
            >
              {protecting ? 'Encrypting...' : 'Encrypt stored keys now'}
            </button>
          </div>
        )}

        {deleteError && <div className="alert alert-danger small py-2" style={{ whiteSpace: 'pre-line' }}>{deleteError}</div>}

        {servers.length === 0 && editIndex === null && (
          <p className="text-muted small mb-0">
            No servers configured.{!disabled && ' Click "Add Server" to add your first LLM endpoint.'}
          </p>
        )}

        {servers.map((server, idx) => (
          <div key={server.id || idx} className="llm-settings-server-card mb-2 p-3 border rounded bg-light">
            <div className="d-flex justify-content-between align-items-start">
              <div>
                <strong>{server.name}</strong>
                <div className="text-muted small mt-1">{server.base_url}</div>
                <div className="text-muted small">{describeKey(server)}</div>
              </div>
              {!disabled && editIndex === null && (
                <div className="btn-group btn-group-sm">
                  <button className="btn btn-sm btn-outline-secondary" onClick={() => startEdit(idx)} title="Edit">
                    <i className="fa fa-edit"></i>
                  </button>
                  <button className="btn btn-sm btn-outline-danger" onClick={() => deleteEntry(idx)} title="Delete" disabled={saving}>
                    <i className="fa fa-trash"></i>
                  </button>
                </div>
              )}
            </div>
          </div>
        ))}

        {editIndex !== null && (
          <div className="border rounded p-3 mt-2 bg-white">
            <h6 className="mb-3">{editIndex === -1 ? 'Add Server' : 'Edit Server'}</h6>
            {formError && <div className="alert alert-danger small py-2" style={{ whiteSpace: 'pre-line' }}>{formError}</div>}
            <div className="form-group">
              <label className="small font-weight-bold">Server Name</label>
              <input
                type="text"
                className="form-control form-control-sm"
                placeholder="e.g. GPUStack Production"
                value={formData.name || ''}
                onChange={e => setFormData(p => ({ ...p, name: e.target.value }))}
              />
            </div>
            <div className="form-group">
              <label className="small font-weight-bold">Base URL</label>
              <input
                type="text"
                className="form-control form-control-sm"
                placeholder="e.g. https://gpustack.example.com/v1"
                value={formData.base_url || ''}
                onChange={e => setFormData(p => ({ ...p, base_url: e.target.value }))}
              />
            </div>
            <div className="form-group">
              <label className="small font-weight-bold">API Key</label>
              <input
                type="password"
                autoComplete="new-password"
                className="form-control form-control-sm"
                placeholder={hasStoredKey ? 'Leave empty to keep the current key' : 'e.g. sk-abc123... or env:LLM_API_KEY_NAME'}
                value={formData.api_key || ''}
                onChange={e => setFormData(p => ({ ...p, api_key: e.target.value }))}
              />
              <small className="form-text text-muted">
                The key is stored {secrets?.encryption_available ? 'encrypted' : 'on the server'} and is never shown again.
                Use <code>env:LLM_NAME</code> to read it from an environment variable instead.
              </small>
            </div>
            {hasStoredKey && (
              <div className="form-check mb-3">
                <input
                  type="checkbox"
                  className="form-check-input"
                  id="llm-settings-clear-key"
                  checked={!!formData.clear_key}
                  onChange={e => setFormData(p => ({ ...p, clear_key: e.target.checked }))}
                />
                <label className="form-check-label small" htmlFor="llm-settings-clear-key">
                  Remove the stored key
                </label>
              </div>
            )}
            <div className="d-flex justify-content-end">
              <button className="btn btn-sm btn-outline-secondary mr-2" onClick={cancelEdit}>Cancel</button>
              <button
                className="btn btn-sm btn-primary"
                onClick={saveEntry}
                disabled={saving || !formData.name?.trim() || !formData.base_url?.trim()}
              >
                {saving ? 'Saving...' : 'Save'}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};
