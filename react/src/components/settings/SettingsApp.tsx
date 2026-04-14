import React, { useEffect, useState, useCallback } from 'react';
import type { SettingsBootConfig } from '../../settings';
import { ApiKeysSection } from './ApiKeysSection';
import { ModelDefaultsSection } from './ModelDefaultsSection';
import { MemoryConfigSection } from './MemoryConfigSection';

interface FieldDef {
  name: string;
  type: string;
  label: string;
  help: string;
  value: string;
  options?: { value: string; label: string }[];
}

interface SettingsGroup {
  label: string;
  fields: FieldDef[];
}

interface SettingsData {
  settings: Record<string, SettingsGroup>;
  acl: { select: boolean; update: boolean };
}

interface Props {
  config: SettingsBootConfig;
}

export const SettingsApp: React.FC<Props> = ({ config }) => {
  const [data, setData] = useState<SettingsData | null>(null);
  const [models, setModels] = useState<{ id: string; name?: string }[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [dirty, setDirty] = useState<Record<string, string>>({});

  const fetchConfig = useCallback(async () => {
    try {
      setLoading(true);
      const res = await fetch(window.location.pathname + '?action=get_config', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      const json = await res.json();
      if (json.error) throw new Error(json.error);
      setData(json);
    } catch (e: any) {
      setError(e.message || 'Failed to load settings');
    } finally {
      setLoading(false);
    }
  }, []);

  const fetchModels = useCallback(async () => {
    try {
      const res = await fetch(window.location.pathname + '?action=models', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      const json = await res.json();
      if (json.models) setModels(json.models);
    } catch {
      // non-critical
    }
  }, []);

  useEffect(() => {
    fetchConfig();
    fetchModels();
  }, [fetchConfig, fetchModels]);

  const handleChange = (name: string, value: string) => {
    setDirty(prev => ({ ...prev, [name]: value }));
    setSuccess(null);
  };

  const handleSave = async () => {
    if (Object.keys(dirty).length === 0) return;
    try {
      setSaving(true);
      setError(null);
      setSuccess(null);
      const res = await fetch(window.location.pathname + '?action=save_config', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ fields: dirty }),
      });
      const json = await res.json();
      if (json.error) throw new Error(json.error);
      setSuccess(`Saved ${json.saved?.length || 0} setting(s) successfully.`);
      setDirty({});
      fetchConfig();
    } catch (e: any) {
      setError(e.message || 'Failed to save settings');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="d-flex justify-content-center align-items-center py-5">
        <div className="spinner-border text-primary" role="status">
          <span className="sr-only">Loading...</span>
        </div>
      </div>
    );
  }

  if (error && !data) {
    return <div className="alert alert-danger m-3">{error}</div>;
  }

  if (!data) return null;

  const canUpdate = data.acl?.update;

  const getField = (group: string, name: string): FieldDef | undefined =>
    data.settings[group]?.fields.find(f => f.name === name);

  const getVal = (group: string, name: string): string => {
    if (dirty[name] !== undefined) return dirty[name];
    return getField(group, name)?.value ?? '';
  };

  return (
    <div className="llm-settings-app">
      {error && <div className="alert alert-danger alert-dismissible fade show" role="alert">
        {error}
        <button type="button" className="close" onClick={() => setError(null)} aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>}

      {success && <div className="alert alert-success alert-dismissible fade show" role="alert">
        {success}
        <button type="button" className="close" onClick={() => setSuccess(null)} aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>}

      <ApiKeysSection
        field={getField('api', 'llm_api_keys')}
        value={getVal('api', 'llm_api_keys')}
        onChange={v => handleChange('llm_api_keys', v)}
        disabled={!canUpdate}
      />

      <ModelDefaultsSection
        getField={(n: string) => getField('model_defaults', n)}
        getVal={(n: string) => getVal('model_defaults', n)}
        onChange={handleChange}
        models={models}
        disabled={!canUpdate}
      />

      <MemoryConfigSection
        getField={(n: string) => getField('memory', n)}
        getVal={(n: string) => getVal('memory', n)}
        onChange={handleChange}
        disabled={!canUpdate}
      />

      {canUpdate && (
        <div className="llm-settings-actions mt-4 mb-3">
          <button
            className="btn btn-sm btn-primary"
            onClick={handleSave}
            disabled={saving || Object.keys(dirty).length === 0}
          >
            {saving ? (
              <>
                <span className="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span>
                Saving...
              </>
            ) : (
              <>
                <i className="fa fa-save mr-2"></i>
                Save Changes
              </>
            )}
          </button>
          {Object.keys(dirty).length > 0 && (
            <span className="ml-3 text-muted small">
              {Object.keys(dirty).length} unsaved change(s)
            </span>
          )}
        </div>
      )}
    </div>
  );
};
