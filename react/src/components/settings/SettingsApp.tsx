/**
 * Settings App — main container for the LLM Settings admin page.
 *
 * Renders these sections:
 * - **API Keys**: Provider API key management (ApiKeysSection)
 * - **Model Defaults**: Default model, temperature, max tokens (ModelDefaultsSection)
 * - **Safety**: Global danger detection keywords, alert emails, blocked message (SafetySection)
 * - **Memory Config**: Summary of memory subsystem configuration (MemorySettingsSummary)
 *
 * Loads initial config from a bootstrap payload passed by the PHP view.
 *
 * @module components/settings/SettingsApp
 */
import React, { useEffect, useState, useCallback } from 'react';
import type { SettingsBootConfig } from '../../settings';
import { ApiKeysSection } from './ApiKeysSection';
import { ModelDefaultsSection } from './ModelDefaultsSection';
import { SafetySection } from './SafetySection';
import { MemorySettingsSummary } from './MemorySettingsSummary';

interface FieldDef {
  name: string;
  type: string;
  label: string;
  help: string;
  /** A string; a per-language field (translatable) sends {id_languages: text}. */
  value: any;
  options?: { value: string; label: string }[];
  /** Extra information from the server (API keys: encryption status). */
  meta?: any;
  translatable?: boolean;
  languages?: { id: number; locale: string; language: string }[];
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

/** Root application component for settings app. */
export const SettingsApp: React.FC<Props> = ({ config }) => {
  const [data, setData] = useState<SettingsData | null>(null);
  const [models, setModels] = useState<{ id: string; name?: string }[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [dirty, setDirty] = useState<Record<string, string | Record<string, string>>>({});
  const [validationErrors, setValidationErrors] = useState<string[]>([]);
  const [protecting, setProtecting] = useState(false);

  /** Headers of the requests that change data: JSON body + session CSRF token. */
  const postHeaders = (): Record<string, string> => ({
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-CSRF-Token': config.csrfToken ?? '',
  });

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

  const handleChange = (name: string, value: string | Record<string, string>) => {
    setDirty(prev => ({ ...prev, [name]: value }));
    setSuccess(null);
  };

  /**
   * Store the API server list right away (no "Save Changes" needed).
   * Resolves with an error message, or null once the list is stored.
   */
  const saveApiKeys = async (value: string): Promise<string | null> => {
    // An earlier "saved" notice must not stand next to this save's error
    setSuccess(null);
    try {
      const res = await fetch(window.location.pathname + '?action=save_config', {
        method: 'POST',
        headers: postHeaders(),
        body: JSON.stringify({ fields: { llm_api_keys: value } }),
      });
      const json = await res.json().catch(() => null);
      if (res.ok && json?.success && Array.isArray(json.api_keys)) {
        // Show the stored state (ids, key hints, no keys)
        const stored = JSON.stringify(json.api_keys);
        setData(prev => prev && {
          ...prev,
          settings: Object.fromEntries(Object.entries(prev.settings).map(([key, group]) => [key, {
            ...group,
            fields: group.fields.map(f => f.name === 'llm_api_keys' ? { ...f, value: stored } : f),
          }])),
        });
        setDirty(prev => {
          const { llm_api_keys: _saved, ...rest } = prev;
          return rest;
        });
        setSuccess('API servers saved.');
        return null;
      }
      const fieldErrors = json?.errors?.llm_api_keys;
      if (Array.isArray(fieldErrors) && fieldErrors.length > 0) return fieldErrors.join('\n');
      return json?.error || `The API servers could not be saved (HTTP ${res.status}).`;
    } catch {
      return 'The API servers could not be saved. Check the connection and try again.';
    }
  };

  /** "Label: message" lines for the per-field messages of a rejected save. */
  const flattenErrors = (errors: Record<string, string[]>): string[] => {
    const labelOf = (name: string): string => {
      for (const group of Object.values(data?.settings ?? {})) {
        const found = group.fields.find(f => f.name === name);
        if (found) return found.label;
      }
      return name;
    };
    return Object.entries(errors).flatMap(([name, messages]) =>
      (Array.isArray(messages) ? messages : [String(messages)]).map(m => `${labelOf(name)}: ${m}`)
    );
  };

  const handleProtectKeys = async () => {
    try {
      setProtecting(true);
      setError(null);
      setSuccess(null);
      setValidationErrors([]);
      const res = await fetch(window.location.pathname + '?action=protect_keys', {
        method: 'POST',
        headers: postHeaders(),
        body: '{}',
      });
      const json = await res.json();
      if (json.error) throw new Error(json.error);
      setSuccess(`Encrypted ${json.converted ?? 0} API key(s).`);
      fetchConfig();
    } catch (e: any) {
      setError(e.message || 'Failed to encrypt the API keys');
    } finally {
      setProtecting(false);
    }
  };

  const handleSave = async () => {
    if (Object.keys(dirty).length === 0) return;
    try {
      setSaving(true);
      setError(null);
      setSuccess(null);
      setValidationErrors([]);
      const res = await fetch(window.location.pathname + '?action=save_config', {
        method: 'POST',
        headers: postHeaders(),
        body: JSON.stringify({ fields: dirty }),
      });
      const json = await res.json();
      if (json.errors) setValidationErrors(flattenErrors(json.errors));
      if (json.error) throw new Error(json.error);
      if (json.failed?.length) {
        throw new Error(`Failed to save: ${json.failed.join(', ')}`);
      }
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
    if (typeof dirty[name] === 'string') return dirty[name] as string;
    return getField(group, name)?.value ?? '';
  };

  const blockedMessages: Record<string, string> =
    (dirty.danger_blocked_message as Record<string, string> | undefined)
    ?? { ...(getField('safety', 'danger_blocked_message')?.value ?? {}) };

  return (
    <div className="llm-settings-app">
      {error && <div className="alert alert-danger alert-dismissible fade show" role="alert">
        {error}
        {validationErrors.length > 0 && (
          <ul className="mb-0 mt-2 pl-3">
            {validationErrors.map((message, i) => <li key={i}>{message}</li>)}
          </ul>
        )}
        <button type="button" className="close" onClick={() => { setError(null); setValidationErrors([]); }} aria-label="Close">
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
        onSave={saveApiKeys}
        disabled={!canUpdate}
        onProtectKeys={handleProtectKeys}
        protecting={protecting}
      />

      <ModelDefaultsSection
        getField={(n: string) => getField('model_defaults', n)}
        getVal={(n: string) => getVal('model_defaults', n)}
        onChange={handleChange}
        models={models}
        disabled={!canUpdate}
      />

      <SafetySection
        getField={(n: string) => getField('safety', n)}
        getVal={(n: string) => getVal('safety', n)}
        messages={blockedMessages}
        onChange={handleChange}
        disabled={!canUpdate}
      />

      <MemorySettingsSummary
        enabled={getVal('memory', 'llm_memory_enabled') === '1'}
        memoryPageUrl={config.memoryPageUrl}
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
