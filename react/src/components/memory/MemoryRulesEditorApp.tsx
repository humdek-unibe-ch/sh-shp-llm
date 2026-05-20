/**
 * Memory Rules Editor — CRUD interface for memory extraction rules.
 *
 * Each rule defines a memory field: source conversation or data table,
 * LLM prompt template, and target column. Rules are linked to the
 * Prompt Lab for prompt editing and evaluation.
 *
 * @module components/memory/MemoryRulesEditorApp
 */
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import CreatableSelect from 'react-select/creatable';
import { Alert, Badge, Button, Card, Col, Dropdown, Form, Row, Spinner } from 'react-bootstrap';
import { InfoPopover } from '../shared/InfoPopover';
import { PromptBuilderModal } from '../prompts/PromptBuilderModal';
import { PromptDatasetsModal } from '../prompts/PromptDatasetsModal';
import { PromptDiffModal } from '../prompts/PromptDiffModal';
import { PromptEditor } from '../prompts/PromptEditor';
import { PromptPlaygroundModal } from '../prompts/PromptPlaygroundModal';
import { PromptToolbar } from '../prompts/PromptToolbar';
import { PromptVersionsModal } from '../prompts/PromptVersionsModal';
import { createPromptLabApi } from '../prompts/promptApi';
import { usePromptBootstrap } from '../prompts/promptHooks';
import {
  parsePromptMeta,
  stringifyPromptMeta,
  type PromptDescriptor,
  type PromptMetaState,
  type PromptPlaygroundResponse,
  type PromptVariableDefinition,
  type PromptVersion,
} from '../prompts/promptTypes';
import { memoryApi } from '../../utils/api';
import { SearchableSelect } from '../settings/SearchableSelect';
import '../prompts/PromptLab.css';
import './MemoryRulesEditor.css';

declare const $: any;

interface Option {
  value: string;
  label: string;
}

interface MemoryKeyOption {
  code: string;
  label: string;
  description?: string;
  enabled: boolean;
}

interface EditorDefaults {
  llm_model: string;
  llm_temperature: string;
  llm_max_tokens: string;
  storage_mode: string;
}

interface SectionInfo {
  id: number;
  name: string;
}

const memoryPromptInterpolationDocs = [
  '**Automatic context** — The system always injects the current memory state, submitted data (form fields, login profile, etc.), and any Data Config results into the LLM call. You do NOT need to reference them in your prompt.',
  '**Your prompt = instructions only** — Write what the LLM should do with the data, e.g. "Extract the user\'s hobbies and preferences from the submitted form data."',
  '**Language** — The user\'s language is auto-detected from their session and the LLM writes memory content in that language. You can reference {{user_language}} (e.g. "Deutsch (Schweiz)") or {{user_language_locale}} (e.g. "de-CH") if needed.',
  '**Built-in variables**: {{memory_key}}, {{memory_text}}, {{memory_json}}, {{source_type}}, {{trigger_type}}, {{event_payload_json}}, {{user_language}}, {{user_language_locale}}, {{readable_text}}.',
  '**Submitted data fields**: All fields from the form submission or event payload are available by name. For example, if the form has a "hobbies" field, you can use {{hobbies}}.',
  '**Data Config variables**: Extra data sources configured above are injected as additional context. Mapped aliases use the target name, scoped values as {{scope.field_name}}, and count helpers as {{table_name_count}}.',
];

/** Type definition for memory rule draft. */
export interface MemoryRuleDraft {
  id: number;
  label: string;
  enabled: boolean;
  memory_keys: string[];
  source_type: string;
  source_match: Record<string, unknown>;
  trigger_types: string[];
  storage_mode_override: string;
  data_config: Array<Record<string, unknown>>;
  llm_model: string;
  llm_temperature: string;
  llm_max_tokens: string;
  refresh_sections: Array<number | string>;
  prompt_template: string;
  prompt_meta_json: string;
  sources_count?: number;
}

interface PlaygroundCapture {
  variables: Record<string, unknown>;
  messageHistory: Array<{ role: 'system' | 'user' | 'assistant'; content: string }>;
  runtimeOverrides: Record<string, unknown>;
  runRef?: {
    id_llm_prompt_playground_runs?: number | null;
    id_llmConversations?: number | null;
    id_llmMessages_request?: number | null;
    id_llmMessages_response?: number | null;
  } | null;
}

/** Type definition for memory rules editor page config. */
export interface MemoryRulesEditorPageConfig {
  promptLabEndpoint: string;
  csrfToken?: string;
  pageId?: number | null;
  selectedRuleId?: number | null;
  onRuleSelected?: (ruleId: number | null) => void;
}

interface DiffState {
  initialLeftKey: string;
  initialRightKey: string;
}

/** parseJsonObject utility. */
function parseJsonObject(value: string, fallback: Record<string, unknown> = {}): Record<string, unknown> {
  if (!value.trim()) return fallback;
  const parsed = JSON.parse(value) as Record<string, unknown>;
  return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : fallback;
}

function parseJsonArray<T = Record<string, unknown>>(value: string, fallback: T[] = []): T[] {
  if (!value.trim()) return fallback;
  const parsed = JSON.parse(value) as T[];
  return Array.isArray(parsed) ? parsed : fallback;
}

/** toPrettyJson function. */
function toPrettyJson(value: unknown): string {
  return JSON.stringify(value ?? {}, null, 2);
}

/** ensurePromptMeta function. */
function ensurePromptMeta(meta: PromptMetaState): NonNullable<PromptMetaState['prompt']> {
  if (!meta.prompt || typeof meta.prompt !== 'object') {
    meta.prompt = {};
  }
  return meta.prompt;
}

/** humanizeKeyLabel function. */
function humanizeKeyLabel(code: string): string {
  const cleaned = String(code || '').replace(/[_-]+/g, ' ').trim();
  return cleaned ? cleaned.replace(/\b\w/g, (char) => char.toUpperCase()) : 'Global';
}

/** Fetch or retrieve get default rule data. */
function getDefaultRule(index = 0): MemoryRuleDraft {
  return {
    id: 0,
    label: `Memory Rule ${index + 1}`,
    enabled: true,
    memory_keys: ['global'],
    source_type: 'form_action_submit',
    source_match: {},
    trigger_types: ['finished'],
    storage_mode_override: '',
    data_config: [],
    llm_model: '',
    llm_temperature: '',
    llm_max_tokens: '',
    refresh_sections: [],
    prompt_template: '',
    prompt_meta_json: '{}',
    sources_count: 0,
  };
}

/** normalizeRule function. */
function normalizeRule(raw: any, index: number): MemoryRuleDraft {
  const fallback = getDefaultRule(index);
  const memoryKeys = Array.isArray(raw?.memory_keys)
    ? raw.memory_keys.map((value: unknown) => String(value).trim()).filter(Boolean)
    : [];
  const nextMemoryKeys = memoryKeys.length > 0
    ? Array.from(new Set(memoryKeys)) as string[]
    : ['global'];

  return {
    id: Number(raw?.id || 0),
    label: typeof raw?.label === 'string' ? raw.label : fallback.label,
    enabled: raw?.enabled !== false,
    memory_keys: nextMemoryKeys,
    source_type: typeof raw?.source_type === 'string' ? raw.source_type : fallback.source_type,
    source_match: raw?.source_match && typeof raw.source_match === 'object' && !Array.isArray(raw.source_match) ? raw.source_match : {},
    trigger_types: Array.isArray(raw?.trigger_types) ? raw.trigger_types.map((value: unknown) => String(value)) : ['finished'],
    storage_mode_override: typeof raw?.storage_mode_override === 'string' ? raw.storage_mode_override : '',
    data_config: Array.isArray(raw?.data_config) ? raw.data_config : [],
    llm_model: typeof raw?.llm_model === 'string' ? raw.llm_model : '',
    llm_temperature: raw?.llm_temperature != null ? String(raw.llm_temperature) : '',
    llm_max_tokens: raw?.llm_max_tokens != null ? String(raw.llm_max_tokens) : '',
    refresh_sections: Array.isArray(raw?.refresh_sections) ? raw.refresh_sections : [],
    prompt_template: typeof raw?.prompt_template === 'string' ? raw.prompt_template : '',
    prompt_meta_json: typeof raw?.prompt_meta_json === 'string' ? raw.prompt_meta_json : '{}',
    sources_count: Number(raw?.sources_count || 0),
  };
}

/** sanitizeRule function. */
function sanitizeRule(rule: MemoryRuleDraft): Record<string, unknown> {
  return {
    id: rule.id,
    label: rule.label.trim(),
    enabled: !!rule.enabled,
    memory_keys: rule.memory_keys,
    source_type: rule.source_type.trim(),
    source_match: rule.source_match || {},
    trigger_types: rule.trigger_types.filter(Boolean),
    storage_mode_override: rule.storage_mode_override || '',
    data_config: rule.data_config || [],
    llm_model: rule.llm_model || '',
    llm_temperature: rule.llm_temperature || '',
    llm_max_tokens: rule.llm_max_tokens || '',
    refresh_sections: rule.refresh_sections || [],
  };
}

/** validateRule function. */
function validateRule(rule: MemoryRuleDraft): string[] {
  const errors: string[] = [];
  if (!rule.label.trim()) errors.push('Rule label is required.');
  if (!rule.source_type.trim()) errors.push('Source type is required.');
  if (!Array.isArray(rule.memory_keys) || rule.memory_keys.length === 0) errors.push('Select at least one memory key.');
  return errors;
}

/** Root application component for memory rules editor app. */
export const MemoryRulesEditorApp: React.FC<{ config: MemoryRulesEditorPageConfig }> = ({ config }) => {
  const [rules, setRules] = useState<MemoryRuleDraft[]>([]);
  const [selectedRuleId, setSelectedRuleId] = useState<number | null>(config.selectedRuleId ?? null);
  const [draft, setDraft] = useState<MemoryRuleDraft | null>(null);
  const [metaState, setMetaState] = useState<PromptMetaState>(parsePromptMeta('{}'));
  const [lastPlaygroundCapture, setLastPlaygroundCapture] = useState<PlaygroundCapture | null>(null);
  const [filter, setFilter] = useState('');
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [dataConfigJson, setDataConfigJson] = useState('[]');
  const [refreshSectionsJson, setRefreshSectionsJson] = useState('[]');
  const [showVersions, setShowVersions] = useState(false);
  const [showDiff, setShowDiff] = useState(false);
  const [showPlayground, setShowPlayground] = useState(false);
  const [showDatasets, setShowDatasets] = useState(false);
  const [showBuilder, setShowBuilder] = useState(false);
  const [diffState, setDiffState] = useState<DiffState>({ initialLeftKey: 'draft', initialRightKey: 'draft' });
  const [availableKeys, setAvailableKeys] = useState<MemoryKeyOption[]>([]);
  const [defaults, setDefaults] = useState<EditorDefaults>({ llm_model: '', llm_temperature: '', llm_max_tokens: '', storage_mode: 'both' });
  const [models, setModels] = useState<Array<{ id: string; name?: string }>>([]);
  const [sourceTypeOptions, setSourceTypeOptions] = useState<Option[]>([]);
  const [storageModeOptions, setStorageModeOptions] = useState<Option[]>([]);
  const [sections, setSections] = useState<SectionInfo[]>([]);
  const [sectionSearch, setSectionSearch] = useState('');

  const api = useMemo(() => createPromptLabApi(config.promptLabEndpoint, config.csrfToken), [config.csrfToken, config.promptLabEndpoint]);

  const upsertAvailableKeys = (incomingKeys: MemoryKeyOption[]) => {
    setAvailableKeys((current) => {
      const merged = new Map<string, MemoryKeyOption>();
      [...current, ...incomingKeys].forEach((item) => {
        if (!item?.code) return;
        merged.set(item.code, {
          code: item.code,
          label: item.label || humanizeKeyLabel(item.code),
          description: item.description || '',
          enabled: item.enabled !== false,
        });
      });
      return Array.from(merged.values()).sort((a, b) => a.label.localeCompare(b.label));
    });
  };

  const loadRule = async (ruleId: number, currentRules?: MemoryRuleDraft[]) => {
    const existing = (currentRules || rules).find((rule) => rule.id === ruleId);
    setSelectedRuleId(ruleId);
    config.onRuleSelected?.(ruleId);
    setSuccess(null);
    try {
      const response = await memoryApi.getRule(ruleId);
      if (response.error) throw new Error(response.error);
      const normalized = normalizeRule(response.rule, existing ? (currentRules || rules).indexOf(existing) : 0);
      upsertAvailableKeys(normalized.memory_keys.map((code) => ({ code, label: humanizeKeyLabel(code), description: '', enabled: true })));
      setDraft(normalized);
      setMetaState(parsePromptMeta(normalized.prompt_meta_json));
      setLastPlaygroundCapture(null);
      setDataConfigJson(toPrettyJson(normalized.data_config));
      setRefreshSectionsJson(toPrettyJson(normalized.refresh_sections));
      if (response.prompt_bootstrap) {
        setPromptBootstrap(response.prompt_bootstrap);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load rule');
    }
  };

  const loadRulesBootstrap = async (nextSelectedId?: number | null) => {
    setLoading(true);
    setError(null);
    try {
      const response = await memoryApi.getRulesBootstrap();
      if (response.error) throw new Error(response.error);

      const nextRules = (response.rules || []).map((rule, index) => normalizeRule(rule, index));
      setRules(nextRules);
      setDefaults(response.editor?.defaults || { llm_model: '', llm_temperature: '', llm_max_tokens: '', storage_mode: 'both' });
      setModels(response.editor?.models || []);
      setSourceTypeOptions(response.editor?.source_types || []);
      setStorageModeOptions(response.editor?.storage_modes || []);
      setSections(response.editor?.sections || []);
      upsertAvailableKeys(response.editor?.available_keys || []);

      const requestedId = nextSelectedId ?? selectedRuleId ?? config.selectedRuleId ?? null;
      const preferredId = requestedId && nextRules.some((rule) => rule.id === requestedId)
        ? requestedId
        : nextRules[0]?.id ?? null;

      if (preferredId) {
        await loadRule(preferredId, nextRules);
      } else {
        setSelectedRuleId(null);
        config.onRuleSelected?.(null);
        setDraft(null);
        setMetaState(parsePromptMeta('{}'));
        setPromptBootstrap(null);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load rules');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadRulesBootstrap(config.selectedRuleId ?? null).catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    const modal = document.querySelector('#data-config-builder-wrapper .data_config_builder_modal_holder');
    if (modal) {
      document.body.appendChild(modal);
    }
  }, []);

  useEffect(() => {
    if (config.selectedRuleId && config.selectedRuleId !== selectedRuleId) {
      loadRule(config.selectedRuleId).catch(() => undefined);
    }
  }, [config.selectedRuleId, selectedRuleId]);

  const filteredRules = useMemo(() => {
    const query = filter.trim().toLowerCase();
    if (!query) return rules;
    return rules.filter((rule) =>
      rule.label.toLowerCase().includes(query)
      || rule.source_type.toLowerCase().includes(query)
      || rule.memory_keys.some((key) => key.toLowerCase().includes(query))
    );
  }, [filter, rules]);

  const keyLabelMap = useMemo(() => {
    const map = new Map<string, MemoryKeyOption>();
    availableKeys.forEach((item) => map.set(item.code, item));
    return map;
  }, [availableKeys]);

  const selectedKeyOptions = useMemo(() => {
    if (!draft) return [];
    return draft.memory_keys.map((code) => ({
      value: code,
      label: keyLabelMap.get(code)?.label || humanizeKeyLabel(code),
    }));
  }, [draft, keyLabelMap]);

  const memoryKeyOptions = useMemo(() => availableKeys.map((item) => ({
    value: item.code,
    label: item.label,
  })), [availableKeys]);

  const sourceTypeLabelMap = useMemo(() => {
    const map = new Map<string, string>();
    sourceTypeOptions.forEach((item) => map.set(item.value, item.label));
    return map;
  }, [sourceTypeOptions]);

  const storageModeLabelMap = useMemo(() => {
    const map = new Map<string, string>();
    storageModeOptions.forEach((item) => map.set(item.value, item.label));
    return map;
  }, [storageModeOptions]);

  const modelOptions = useMemo(() => [
    {
      value: '',
      label: defaults.llm_model ? `Use module default (${defaults.llm_model})` : 'Use module default',
    },
    ...models.map((model) => ({
      value: model.id,
      label: model.name || model.id,
    })),
  ], [defaults.llm_model, models]);

  const promptDescriptor = useMemo<PromptDescriptor | null>(() => {
    if (!draft?.id) {
      return null;
    }
    return {
      ownerType: 'llm_memory_rule',
      ownerId: draft.id,
      promptSlot: 'memory_rule',
      languageId: 1,
      pageId: config.pageId ?? null,
      title: draft.label || `Rule #${draft.id}`,
    };
  }, [config.pageId, draft?.id, draft?.label]);

  const effectiveModel = draft?.llm_model || defaults.llm_model || '';
  const effectiveTemperature = draft?.llm_temperature || defaults.llm_temperature || '';
  const effectiveMaxTokens = draft?.llm_max_tokens || defaults.llm_max_tokens || '';
  const promptRuntimeOverrides = useMemo(() => ({
    llm_model: effectiveModel,
    llm_temperature: effectiveTemperature,
    llm_max_tokens: effectiveMaxTokens,
  }), [effectiveMaxTokens, effectiveModel, effectiveTemperature]);

  // Stable callback so the playground modal never sees a fresh identity on
  // parent rerenders triggered by onRunComplete state captures.
  const resolveRuntimeOverridesCallback = useCallback(
    () => promptRuntimeOverrides,
    [promptRuntimeOverrides],
  );

  const { bootstrap: promptBootstrap, loading: promptLoading, error: promptError, reload: reloadPromptBootstrap, setBootstrap: setPromptBootstrap } = usePromptBootstrap({
    api,
    descriptor: promptDescriptor || {
      ownerType: 'llm_memory_rule',
      ownerId: 0,
      promptSlot: 'memory_rule',
      languageId: 1,
      pageId: config.pageId ?? null,
      title: 'Memory Rule Prompt',
    },
    currentContent: draft?.prompt_template || '',
    currentMeta: stringifyPromptMeta(metaState),
    runtimeOverrides: promptRuntimeOverrides,
    enabled: !!promptDescriptor,
  });

  const activeVersion = promptBootstrap?.active_version || null;
  const versions = promptBootstrap?.versions || [];
  const effectiveVariablesSchema = metaState.prompt?.variablesSchema || promptBootstrap?.variables_schema || [];
  const promptChangeNote = metaState.prompt?.pendingChangeNote || '';
  const isDirty = (draft?.prompt_template || '') !== (activeVersion?.template_raw || '');
  const defaultPromptModel = String(effectiveModel) || promptBootstrap?.models?.[0]?.id || null;

  const setDraftPatch = (patch: Partial<MemoryRuleDraft>) => {
    setDraft((current) => current ? { ...current, ...patch } : current);
  };

  const getSelectedSectionIds = (): number[] => {
    if (!draft?.refresh_sections) {
      return [];
    }
    return draft.refresh_sections
      .map((value) => Number(value))
      .filter((value) => !Number.isNaN(value));
  };

  const setSelectedSectionIds = (ids: number[]) => {
    setDraftPatch({ refresh_sections: ids });
    setRefreshSectionsJson(toPrettyJson(ids));
  };

  const toggleSection = (sectionId: number) => {
    const current = getSelectedSectionIds();
    if (current.includes(sectionId)) {
      setSelectedSectionIds(current.filter((id) => id !== sectionId));
      return;
    }
    setSelectedSectionIds([...current, sectionId]);
  };

  const filteredSections = sections.filter((section) => (
    !sectionSearch || section.name.toLowerCase().includes(sectionSearch.toLowerCase())
  ));

  const getDataConfigLabel = (): string => {
    if (!draft?.data_config || draft.data_config.length === 0) {
      return 'Add Data Config';
    }
    return 'Edit Data Config';
  };

  const openDataConfigModal = () => {
    if (typeof $ === 'undefined') {
      return;
    }

    try {
      const textarea = document.querySelector('textarea[name="data_config"]') as HTMLTextAreaElement | null;
      if (textarea) {
        textarea.value = dataConfigJson || '';
        textarea.dispatchEvent(new Event('change'));
      }

      if (typeof (window as any).dataConfigEditor !== 'undefined' && (window as any).dataConfigEditor) {
        try {
          const value = dataConfigJson ? JSON.parse(dataConfigJson) : [];
          (window as any).dataConfigEditor.setValue(value);
        } catch {
          // keep current editor state when JSON is invalid
        }
      }

      const saveBtn = document.querySelector('.saveDataConfig');
      if (saveBtn) {
        saveBtn.setAttribute('data-dismiss', 'modal');
      }

      $('.saveDataConfig').off('click.llmmemory').on('click.llmmemory', () => {
        let value = '[]';
        if (typeof (window as any).dataConfigEditor !== 'undefined' && (window as any).dataConfigEditor) {
          value = JSON.stringify((window as any).dataConfigEditor.getValue(), null, 3);
        } else if (textarea) {
          value = textarea.value || '[]';
        }

        setDataConfigJson(value);
        try {
          setDraftPatch({ data_config: parseJsonArray<Record<string, unknown>>(value) });
        } catch {
          // keep JSON text for later validation on save
        }
      });

      $('.data_config_builder_modal_holder').modal({ backdrop: false });
    } catch {
      // keep silent, same behavior as scripts UI
    }
  };

  const syncPromptTemplate = (nextValue: string) => {
    setDraftPatch({ prompt_template: nextValue });
  };

  const syncMeta = (nextMeta: PromptMetaState) => {
    setMetaState(nextMeta);
    setDraft((current) => current ? { ...current, prompt_meta_json: stringifyPromptMeta(nextMeta) } : current);
  };

  const handleChangeNote = (value: string) => {
    const nextMeta = { ...metaState };
    const prompt = ensurePromptMeta(nextMeta);
    prompt.pendingChangeNote = value;
    syncMeta(nextMeta);
  };

  const handleUseVersion = (version: PromptVersion) => {
    syncPromptTemplate(version.template_raw || '');
    handleChangeNote(promptChangeNote || `Restored from version ${version.version_no}`);
    setShowVersions(false);
  };

  const openDiffWithVersion = (version: PromptVersion) => {
    const nextMeta = { ...metaState };
    const prompt = ensurePromptMeta(nextMeta);
    prompt.lastComparedVersionId = version.id;
    syncMeta(nextMeta);
    setShowVersions(false);
    setDiffState({
      initialLeftKey: `v:${version.id}`,
      initialRightKey: 'draft',
    });
    setShowDiff(true);
  };

  const handleBuilderApply = (nextPrompt: string, variables: PromptVariableDefinition[], changeSummary: string) => {
    syncPromptTemplate(nextPrompt);
    if (variables.length > 0) {
      const nextMeta = { ...metaState };
      const prompt = ensurePromptMeta(nextMeta);
      prompt.variablesSchema = variables;
      if (!prompt.pendingChangeNote && changeSummary) {
        prompt.pendingChangeNote = changeSummary;
      }
      syncMeta(nextMeta);
    } else if (changeSummary && !promptChangeNote) {
      handleChangeNote(changeSummary);
    }
    setShowBuilder(false);
  };

  const handleCreate = async () => {
    const baseRule = getDefaultRule(rules.length);
    setSaving(true);
    setError(null);
    try {
      const created = await memoryApi.createRule(sanitizeRule(baseRule), baseRule.prompt_template, baseRule.prompt_meta_json, '');
      if (created.error) throw new Error(created.error);
      await loadRulesBootstrap(created.rule.id);
      setSuccess('Rule created.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create rule');
    } finally {
      setSaving(false);
    }
  };

  const handleDuplicate = async () => {
    if (!draft?.id) return;
    setSaving(true);
    setError(null);
    try {
      const duplicated = await memoryApi.duplicateRule(draft.id);
      if (duplicated.error) throw new Error(duplicated.error);
      await loadRulesBootstrap(duplicated.rule.id);
      setSuccess('Rule duplicated.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to duplicate rule');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!draft?.id) return;

    const message = `Delete memory rule "${draft.label || `Rule #${draft.id}`}"?`;
    const performDelete = async () => {
      setSaving(true);
      setError(null);
      try {
        const response = await memoryApi.deleteRule(draft.id);
        if (response.error) throw new Error(response.error);
        await loadRulesBootstrap(null);
        setSuccess('Rule deleted.');
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to delete rule');
      } finally {
        setSaving(false);
      }
    };

    const jquery = (window as any).$ || (window as any).jQuery;
    if (typeof jquery?.confirm === 'function') {
      jquery.confirm({
        title: 'Delete Memory Rule',
        content: message,
        type: 'red',
        buttons: {
          confirm: {
            text: 'Delete',
            btnClass: 'btn-danger',
            action: () => { void performDelete(); },
          },
          cancel: {
            text: 'Cancel',
            action: () => {},
          },
        },
      });
      return;
    }

    if (window.confirm(message)) {
      await performDelete();
    }
  };

  const handleSave = async () => {
    if (!draft) return;
    let nextDraft: MemoryRuleDraft;
    try {
      nextDraft = {
        ...draft,
        source_match: {},
        data_config: parseJsonArray<Record<string, unknown>>(dataConfigJson),
        refresh_sections: parseJsonArray<number | string>(refreshSectionsJson),
        prompt_meta_json: stringifyPromptMeta(metaState),
      };
    } catch (err) {
      setError(err instanceof Error ? err.message : 'One of the JSON fields is invalid.');
      return;
    }

    const errors = validateRule(nextDraft);
    if (errors.length > 0) {
      setError(errors.join(' '));
      return;
    }

    setSaving(true);
    setError(null);
    try {
      const response = await memoryApi.updateRule(nextDraft.id, sanitizeRule(nextDraft), nextDraft.prompt_template, nextDraft.prompt_meta_json, promptChangeNote);
      if (response.error) throw new Error(response.error);
      const savedRule = normalizeRule(response.rule, rules.findIndex((rule) => rule.id === nextDraft.id));
      await loadRulesBootstrap(nextDraft.id);
      if (response.prompt_bootstrap) {
        setPromptBootstrap(response.prompt_bootstrap);
      } else {
        try {
          const bootstrap = await api.bootstrapOwner(
            {
              ownerType: 'llm_memory_rule',
              ownerId: savedRule.id,
              promptSlot: 'memory_rule',
              languageId: 1,
              pageId: config.pageId ?? null,
              title: savedRule.label || `Rule #${savedRule.id}`,
            },
            response.rule.prompt_template || savedRule.prompt_template || '',
            response.rule.prompt_meta_json || savedRule.prompt_meta_json || '{}',
            {
              llm_model: savedRule.llm_model || defaults.llm_model || '',
              llm_temperature: savedRule.llm_temperature || defaults.llm_temperature || '',
              llm_max_tokens: savedRule.llm_max_tokens || defaults.llm_max_tokens || '',
            },
          );
          setPromptBootstrap(bootstrap);
        } catch {
          await reloadPromptBootstrap().catch(() => undefined);
        }
      }
      setSuccess('Rule saved.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save rule');
    } finally {
      setSaving(false);
    }
  };

  const renderRuleCard = (rule: MemoryRuleDraft) => {
    const firstKey = rule.memory_keys[0];
    const firstKeyLabel = keyLabelMap.get(firstKey)?.label || humanizeKeyLabel(firstKey || '');
    const extraKeys = Math.max(0, rule.memory_keys.length - 1);
    const isActive = rule.id === selectedRuleId;

    return (
      <button
        key={rule.id}
        type="button"
        className={`memory-rule-card ${isActive ? 'is-active' : ''}`}
        onClick={() => loadRule(rule.id).catch(() => undefined)}
      >
        <div className="memory-rule-card__header">
          <div>
            <div className="memory-rule-card__title">{rule.label || `Rule #${rule.id}`}</div>
            <div className="memory-rule-card__slug">Rule #{rule.id}</div>
          </div>
          <Badge variant={rule.enabled ? 'success' : 'secondary'}>{rule.enabled ? 'Enabled' : 'Disabled'}</Badge>
        </div>
        <div className="memory-rule-card__badges">
          <span className="badge badge-secondary">{sourceTypeLabelMap.get(rule.source_type) || rule.source_type}</span>
        </div>
        <div className="memory-rule-card__meta">
          <span>{firstKeyLabel}{extraKeys > 0 ? ` +${extraKeys}` : ''}</span>
          <span>{rule.sources_count || 0} sources</span>
        </div>
      </button>
    );
  };

  const showAutomaticSourceHint = draft?.source_type === 'login' || draft?.source_type === 'profile_name_change';

  return (
    <div className="llm-memory-rules-editor-root">
      {error && <Alert variant="danger" onClose={() => setError(null)} dismissible>{error}</Alert>}
      {success && <Alert variant="success" onClose={() => setSuccess(null)} dismissible>{success}</Alert>}
      {promptError && <Alert variant="warning">Prompt Lab: {promptError}</Alert>}
      <Row>
        <Col lg={4} className="mb-3">
            <Card className="memory-rule-sidebar">
            <Card.Header className="d-flex justify-content-between align-items-center">
              <strong>Rules</strong>
              <Button size="sm" onClick={handleCreate} disabled={saving}>New Rule</Button>
            </Card.Header>
            <Card.Body>
              <div className="memory-filter-field mb-3">
                <Form.Control
                  type="text"
                  size="sm"
                  placeholder="Filter rules..."
                  value={filter}
                  onChange={(event) => setFilter(event.target.value)}
                  className="memory-filter-field__input"
                />
                {filter ? (
                  <button
                    type="button"
                    className="memory-filter-field__clear"
                    onClick={() => setFilter('')}
                    aria-label="Clear rule filter"
                    title="Clear"
                  >
                    <i className="fas fa-times"></i>
                  </button>
                ) : null}
              </div>
              {loading ? <div className="text-center py-3"><Spinner animation="border" size="sm" /></div> : <div className="memory-rule-list">{filteredRules.map(renderRuleCard)}{filteredRules.length === 0 && <div className="text-muted small px-1 py-2">No rules found.</div>}</div>}
            </Card.Body>
          </Card>
        </Col>
        <Col lg={8}>
          {!draft ? <Card><Card.Body className="text-muted text-center py-5">Select a memory rule to edit it.</Card.Body></Card> : (
            <Card className="memory-rule-editor">
              <Card.Header className="d-flex justify-content-between align-items-center flex-wrap">
                <div><strong>{draft.label || `Rule #${draft.id}`}</strong><div className="memory-rule-subtitle text-muted">Rule ID {draft.id}</div></div>
                <div className="d-flex align-items-center">
                  <Button size="sm" variant="outline-secondary" className="mr-2" onClick={handleDuplicate} disabled={saving}>Duplicate</Button>
                  <Button size="sm" variant="outline-danger" className="mr-2" onClick={handleDelete} disabled={saving}>Delete</Button>
                  <Button size="sm" onClick={handleSave} disabled={saving || promptLoading}>{saving ? 'Saving...' : 'Save Rule'}</Button>
                </div>
              </Card.Header>
              <Card.Body>
                <Card className="memory-rule-section"><Card.Header>Identity</Card.Header><Card.Body>
                  <Row>
                    <Col md={12}><Form.Group><Form.Label>Rule Label</Form.Label><Form.Control value={draft.label} onChange={(event) => setDraftPatch({ label: event.target.value })} placeholder="Profile name change memory" /><Form.Text className="text-muted">Use a human-readable name. Internal identifiers are handled automatically.</Form.Text></Form.Group></Col>
                  </Row>
                  <Form.Group className="mb-0">
                    <Form.Label className="d-block">Enabled</Form.Label>
                    <div className="custom-control custom-switch memory-rule-enabled-switch">
                      <input
                        type="checkbox"
                        className="custom-control-input"
                        id={`memory-rule-enabled-${draft.id || 'new'}`}
                        checked={draft.enabled}
                        onChange={(event) => setDraftPatch({ enabled: event.target.checked })}
                      />
                      <label
                        className="custom-control-label"
                        htmlFor={`memory-rule-enabled-${draft.id || 'new'}`}
                      >
                        {draft.enabled ? 'Enabled' : 'Disabled'}
                      </label>
                    </div>
                  </Form.Group>
                </Card.Body></Card>

                <Card className="memory-rule-section"><Card.Header>When This Runs</Card.Header><Card.Body>
                  <Row>
                    <Col md={12}><Form.Group className="mb-0"><Form.Label>Source Type</Form.Label><SearchableSelect options={sourceTypeOptions} value={draft.source_type} onChange={(value) => setDraftPatch({ source_type: value })} placeholder="Select source type" /><Form.Text className="text-muted">This decides whether the rule runs from login, profile updates, form actions, or llmChat fallback.</Form.Text></Form.Group></Col>
                  </Row>
                  {showAutomaticSourceHint ? <Alert variant="info" className="mb-0 mt-3">This source runs automatically from the system hook.</Alert> : <Alert variant="light" className="mb-0 mt-3">This rule runs only when it is explicitly attached to a form action, llmChat fallback, or matching source hook.</Alert>}
                </Card.Body></Card>

                <Card className="memory-rule-section"><Card.Header>Where It Writes</Card.Header><Card.Body><Form.Group className="mb-0"><Form.Label>Memory Keys</Form.Label><CreatableSelect isMulti className="memory-rule-select" classNamePrefix="react-select" value={selectedKeyOptions} options={memoryKeyOptions} placeholder="Search or create memory keys..." onCreateOption={(inputValue) => { const code = inputValue.trim().toLowerCase().replace(/[^a-z0-9_-]/g, '_').replace(/_+/g, '_').replace(/^_+|_+$/g, ''); if (!code) return; upsertAvailableKeys([{ code, label: humanizeKeyLabel(code), description: '', enabled: true }]); setDraftPatch({ memory_keys: Array.from(new Set([...(draft.memory_keys || []), code])) }); }} onChange={(values) => { const nextKeys = (values || []).map((item) => item.value); setDraftPatch({ memory_keys: nextKeys }); }} /><Form.Text className="text-muted">Selected keys become the memory spaces this rule updates. You can search existing keys or create new ones here.</Form.Text></Form.Group></Card.Body></Card>

                <Card className="memory-rule-section"><Card.Header>Runtime Settings</Card.Header><Card.Body>
                  <Row>
                    <Col md={6}><Form.Group><Form.Label>Storage Override</Form.Label><SearchableSelect options={storageModeOptions} value={draft.storage_mode_override} onChange={(value) => setDraftPatch({ storage_mode_override: value })} placeholder="Use module default" /><Form.Text className="text-muted">Module default: {storageModeLabelMap.get(defaults.storage_mode) || defaults.storage_mode}</Form.Text></Form.Group></Col>
                    <Col md={6}><Form.Group><Form.Label>LLM Model</Form.Label><SearchableSelect options={modelOptions} value={draft.llm_model} onChange={(value) => setDraftPatch({ llm_model: value })} placeholder="Use module default" /><Form.Text className="text-muted">Default model: {defaults.llm_model || 'Not configured'}</Form.Text></Form.Group></Col>
                  </Row>
                  <Row><Col md={6}><Form.Group><Form.Label>Temperature</Form.Label><Form.Control value={draft.llm_temperature} onChange={(event) => setDraftPatch({ llm_temperature: event.target.value })} placeholder={effectiveTemperature} /><Form.Text className="text-muted">Leave blank to inherit the module default ({effectiveTemperature}).</Form.Text></Form.Group></Col><Col md={6}><Form.Group className="mb-0"><Form.Label>Max Tokens</Form.Label><Form.Control value={draft.llm_max_tokens} onChange={(event) => setDraftPatch({ llm_max_tokens: event.target.value })} placeholder={effectiveMaxTokens} /><Form.Text className="text-muted">Leave blank to inherit the module default ({effectiveMaxTokens}).</Form.Text></Form.Group></Col></Row>
                </Card.Body></Card>

                <Card className="memory-rule-section"><Card.Header>Data Config</Card.Header><Card.Body><div className="d-flex justify-content-between align-items-center flex-wrap gap-2"><div><div className="font-weight-bold">Data Config</div><div className="text-muted small">Reuse the shared data-config builder to pull extra values into the prompt context.</div></div><Button size="sm" variant={draft.data_config.length > 0 ? 'warning' : 'outline-secondary'} onClick={openDataConfigModal}>{getDataConfigLabel()}</Button></div>{draft.data_config.length > 0 ? <div className="mt-3 small text-muted">{draft.data_config.length} data config item{draft.data_config.length > 1 ? 's' : ''} configured.</div> : <div className="mt-3 small text-muted">No extra data sources configured yet.</div>}</Card.Body></Card>
                <Card className="memory-rule-section"><Card.Header><div className="d-flex align-items-center">Prompt<InfoPopover title="Available Prompt Variables" placement="left" buttonClassName="ml-2" ariaLabel="Available prompt variables help">{memoryPromptInterpolationDocs.map((line) => <div key={line} className="small mb-2">{line}</div>)}</InfoPopover></div></Card.Header><Card.Body><PromptToolbar activeVersion={activeVersion} dirty={isDirty} disabled={!promptDescriptor || promptLoading} changeNote={promptChangeNote} onChangeNote={handleChangeNote} onOpenVersions={() => setShowVersions(true)} onOpenCompare={() => { const activeKey = activeVersion ? `v:${activeVersion.id}` : 'draft'; setDiffState({ initialLeftKey: activeKey, initialRightKey: 'draft' }); setShowDiff(true); }} onOpenPlayground={() => setShowPlayground(true)} onOpenDatasets={() => setShowDatasets(true)} onOpenBuilder={() => setShowBuilder(true)} /><PromptEditor value={draft.prompt_template} language="markdown" onChange={syncPromptTemplate} minHeight={260} /></Card.Body></Card>

                <Card className="memory-rule-section"><Card.Header>Advanced</Card.Header><Card.Body><Form.Group><Form.Label>Refresh Sections</Form.Label><Dropdown><Dropdown.Toggle size="sm" variant="outline-secondary" className="w-100 text-left d-flex justify-content-between align-items-center"><span className="text-truncate">{getSelectedSectionIds().length === 0 ? 'Select sections...' : `${getSelectedSectionIds().length} section${getSelectedSectionIds().length > 1 ? 's' : ''} selected`}</span></Dropdown.Toggle><Dropdown.Menu className="w-100 sections-dropdown-menu" style={{ maxHeight: '250px', overflowY: 'auto' }}><div className="px-2 pb-2"><Form.Control size="sm" type="text" placeholder="Search sections..." value={sectionSearch} onChange={(event) => setSectionSearch(event.target.value)} onClick={(event) => event.stopPropagation()} /></div>{filteredSections.length === 0 ? <Dropdown.ItemText className="text-muted small">No sections found</Dropdown.ItemText> : filteredSections.map((section) => (<Dropdown.Item key={section.id} as="button" className="small py-1" active={getSelectedSectionIds().includes(Number(section.id))} onClick={(event) => { event.preventDefault(); event.stopPropagation(); toggleSection(Number(section.id)); }}><Form.Check type="checkbox" checked={getSelectedSectionIds().includes(Number(section.id))} onChange={() => undefined} label={<span>{section.name} <small className="text-muted">({section.id})</small></span>} className="mb-0" /></Dropdown.Item>))}</Dropdown.Menu></Dropdown>{getSelectedSectionIds().length > 0 ? <div className="mt-2">{getSelectedSectionIds().map((id) => { const section = sections.find((item) => Number(item.id) === id); return <Badge key={id} variant="info" className="mr-1 mb-1 cursor-pointer" onClick={() => toggleSection(id)}>{section?.name || id} <i className="fas fa-times ml-1"></i></Badge>; })}</div> : null}<Form.Text className="text-muted">Sections to refresh after a successful memory update.</Form.Text></Form.Group></Card.Body></Card>
              </Card.Body>
            </Card>
          )}
        </Col>
      </Row>

      {promptDescriptor && draft ? <>
        <PromptVersionsModal show={showVersions} onHide={() => setShowVersions(false)} versions={versions} activeVersionId={activeVersion?.id || null} disabled={!promptDescriptor} onUseVersion={handleUseVersion} onCompareVersion={openDiffWithVersion} />
        <PromptDiffModal show={showDiff} onHide={() => setShowDiff(false)} api={api} descriptor={promptDescriptor} versions={versions} draftContent={draft.prompt_template} initialLeftKey={diffState.initialLeftKey} initialRightKey={diffState.initialRightKey} />
        <PromptPlaygroundModal show={showPlayground} onHide={() => setShowPlayground(false)} api={api} descriptor={promptDescriptor} executionProfile={promptBootstrap?.execution_profile || 'memory_runtime'} playgroundRuntimeType={promptBootstrap?.playground_runtime_type || 'script'} models={promptBootstrap?.models || []} variablesSchema={effectiveVariablesSchema} promptValue={draft.prompt_template} disabled={!promptDescriptor || (promptBootstrap?.playground_runtime_type || 'script') === 'none'} defaultModel={defaultPromptModel} resolveRuntimeOverrides={resolveRuntimeOverridesCallback} onApplyDraft={handleBuilderApply} onRunComplete={({ variables, messageHistory, runtimeOverrides, response }: { variables: Record<string, unknown>; messageHistory: Array<{ role: 'system' | 'user' | 'assistant'; content: string }>; runtimeOverrides: Record<string, unknown>; response: PromptPlaygroundResponse; }) => { const firstRun = response.runs?.[0]; setLastPlaygroundCapture({ variables, messageHistory, runtimeOverrides, runRef: firstRun ? { id_llm_prompt_playground_runs: firstRun.id_llm_prompt_playground_runs ?? null, id_llmConversations: firstRun.id_llmConversations ?? null, id_llmMessages_request: firstRun.id_llmMessages_request ?? null, id_llmMessages_response: firstRun.id_llmMessages_response ?? null } : null }); }} />
        <PromptDatasetsModal show={showDatasets} onHide={() => setShowDatasets(false)} api={api} descriptor={promptDescriptor} versions={versions} activeVersionId={activeVersion?.id || null} models={promptBootstrap?.models || []} executionProfile={promptBootstrap?.execution_profile || 'memory_runtime'} promptValue={draft.prompt_template} disabled={!promptDescriptor} defaultModel={defaultPromptModel} resolveRuntimeOverrides={resolveRuntimeOverridesCallback} lastPlaygroundCapture={lastPlaygroundCapture} />
        <PromptBuilderModal show={showBuilder} onHide={() => setShowBuilder(false)} api={api} descriptor={promptDescriptor} currentPrompt={draft.prompt_template} models={promptBootstrap?.models || []} defaultModel={defaultPromptModel} disabled={!promptDescriptor} onApplySuggestion={handleBuilderApply} />
      </> : null}
    </div>
  );
};
