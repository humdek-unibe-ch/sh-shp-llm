/**
 * API Keys Section — manage provider API keys in the Settings page.
 *
 * Lists configured LLM providers with masked key display, add/edit/delete
 * actions, and a connection-test button.
 *
 * @module components/settings/ApiKeysSection
 */
import React, { useState, useMemo } from 'react';

interface ServerEntry {
  name: string;
  base_url: string;
  api_key: string;
}

interface FieldDef {
  name: string;
  type: string;
  label: string;
  help: string;
  value: string;
}

interface Props {
  field?: FieldDef;
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
}

/** maskKey function. */
function maskKey(key: string): string {
  if (!key || key.length < 8) return '********';
  return key.substring(0, 4) + '****' + key.substring(key.length - 4);
}

/** ApiKeysSection component. */
export const ApiKeysSection: React.FC<Props> = ({ field, value, onChange, disabled }) => {
  const [editIndex, setEditIndex] = useState<number | null>(null);
  const [formData, setFormData] = useState<Partial<ServerEntry>>({});

  const servers: ServerEntry[] = useMemo(() => {
    try {
      return JSON.parse(value) || [];
    } catch {
      return [];
    }
  }, [value]);

  const updateServers = (newServers: ServerEntry[]) => {
    onChange(JSON.stringify(newServers));
  };

  const startAdd = () => {
    setFormData({ name: '', base_url: '', api_key: '' });
    setEditIndex(-1);
  };

  const startEdit = (idx: number) => {
    setFormData({ ...servers[idx] });
    setEditIndex(idx);
  };

  const cancelEdit = () => {
    setEditIndex(null);
    setFormData({});
  };

  const saveEntry = () => {
    if (!formData.name?.trim()) return;
    if (!formData.base_url?.trim()) return;

    const entry: ServerEntry = {
      name: formData.name!.trim(),
      base_url: formData.base_url!.trim(),
      api_key: formData.api_key?.trim() || '',
    };

    const updated = [...servers];
    if (editIndex === -1) {
      updated.push(entry);
    } else if (editIndex !== null) {
      updated[editIndex] = entry;
    }
    updateServers(updated);
    cancelEdit();
  };

  const deleteEntry = (idx: number) => {
    if (!window.confirm('Remove this server configuration?')) return;
    const updated = servers.filter((_, i) => i !== idx);
    updateServers(updated);
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

        {servers.length === 0 && editIndex === null && (
          <p className="text-muted small mb-0">
            No servers configured.{!disabled && ' Click "Add Server" to add your first LLM endpoint.'}
          </p>
        )}

        {servers.map((server, idx) => (
          <div key={idx} className="llm-settings-server-card mb-2 p-3 border rounded bg-light">
            <div className="d-flex justify-content-between align-items-start">
              <div>
                <strong>{server.name}</strong>
                <div className="text-muted small mt-1">{server.base_url}</div>
                <div className="text-muted small">Key: {maskKey(server.api_key)}</div>
              </div>
              {!disabled && editIndex === null && (
                <div className="btn-group btn-group-sm">
                  <button className="btn btn-sm btn-outline-secondary" onClick={() => startEdit(idx)} title="Edit">
                    <i className="fa fa-edit"></i>
                  </button>
                  <button className="btn btn-sm btn-outline-danger" onClick={() => deleteEntry(idx)} title="Delete">
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
                type="text"
                className="form-control form-control-sm"
                placeholder="e.g. sk-abc123..."
                value={formData.api_key || ''}
                onChange={e => setFormData(p => ({ ...p, api_key: e.target.value }))}
              />
            </div>
            <div className="d-flex justify-content-end">
              <button className="btn btn-sm btn-outline-secondary mr-2" onClick={cancelEdit}>Cancel</button>
              <button
                className="btn btn-sm btn-primary"
                onClick={saveEntry}
                disabled={!formData.name?.trim() || !formData.base_url?.trim()}
              >
                Save
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};
