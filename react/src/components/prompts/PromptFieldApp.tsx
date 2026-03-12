import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Alert, Spinner } from 'react-bootstrap';
import { createPromptLabApi } from './promptApi';
import { usePromptBootstrap } from './promptHooks';
import { PromptBuilderModal } from './PromptBuilderModal';
import { PromptDiffModal } from './PromptDiffModal';
import { PromptEditor } from './PromptEditor';
import { PromptPlaygroundModal } from './PromptPlaygroundModal';
import { PromptToolbar } from './PromptToolbar';
import { PromptVersionsModal } from './PromptVersionsModal';
import type { PromptDescriptor, PromptMetaState, PromptVariableDefinition, PromptVersion } from './promptTypes';
import { parsePromptMeta, stringifyPromptMeta } from './promptTypes';

declare const $: any;

export interface PromptFieldConfig {
  endpoint: string;
  csrfToken?: string;
  disabled?: number;
  ownerType: 'style_field';
  ownerId: number;
  pageId?: number;
  promptSlot: string;
  languageId?: number;
  title?: string;
  styleName?: string;
  sectionName?: string;
}

interface PromptFieldAppProps {
  config: PromptFieldConfig;
  container: HTMLElement;
  contentInput: HTMLTextAreaElement;
  metaInput: HTMLInputElement;
}

interface DiffState {
  initialLeftKey: string;
  initialRightKey: string;
}

function dispatchFieldChange(input: HTMLInputElement | HTMLTextAreaElement, value: string): void {
  input.value = value;
  input.dispatchEvent(new Event('input', { bubbles: true }));
  input.dispatchEvent(new Event('change', { bubbles: true }));

  if (typeof $ === 'function') {
    $(input).trigger('change');
  }
}

function ensurePromptMeta(meta: PromptMetaState): NonNullable<PromptMetaState['prompt']> {
  if (!meta.prompt) {
    meta.prompt = {};
  }
  return meta.prompt;
}

function readFieldValue(element: HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement): string {
  if (element instanceof HTMLInputElement && element.type === 'checkbox') {
    return element.checked ? '1' : '0';
  }
  return element.value;
}

function collectCmsRuntimeOverrides(
  scope: HTMLElement,
  fieldNames: string[],
  languageId?: number,
): Record<string, unknown> {
  const overrides: Record<string, unknown> = {};
  const form = scope.closest('form') || document;

  fieldNames.forEach((fieldName) => {
    const selectors = [
      languageId != null
        ? `[name^="fields[${fieldName}][${languageId}]"][name$="[content]"]`
        : '',
      `[name^="fields[${fieldName}]"][name$="[content]"]`,
    ].filter(Boolean);

    for (const selector of selectors) {
      const element = form.querySelector(selector) as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement | null;
      if (element) {
        overrides[fieldName] = readFieldValue(element);
        break;
      }
    }
  });

  return overrides;
}

function extractActiveVersionModel(configJson?: string | null): string | null {
  if (!configJson) {
    return null;
  }

  try {
    const parsed = JSON.parse(configJson) as Record<string, unknown>;
    const model = parsed?.model;
    return typeof model === 'string' && model ? model : null;
  } catch {
    return null;
  }
}

export const PromptFieldApp: React.FC<PromptFieldAppProps> = ({
  config,
  container,
  contentInput,
  metaInput,
}) => {
  const [promptValue, setPromptValue] = useState(contentInput.value || '');
  const [metaState, setMetaState] = useState<PromptMetaState>(parsePromptMeta(metaInput.value));
  const [showVersions, setShowVersions] = useState(false);
  const [showDiff, setShowDiff] = useState(false);
  const [showPlayground, setShowPlayground] = useState(false);
  const [showBuilder, setShowBuilder] = useState(false);
  const [diffState, setDiffState] = useState<DiffState>({
    initialLeftKey: 'draft',
    initialRightKey: 'draft',
  });
  const [variablesSchemaOverride, setVariablesSchemaOverride] = useState<PromptVariableDefinition[] | null>(
    metaState.prompt?.variablesSchema || null,
  );
  const promptValueRef = useRef(promptValue);
  const metaStateRef = useRef(metaState);

  const api = useMemo(() => createPromptLabApi(config.endpoint, config.csrfToken), [config.csrfToken, config.endpoint]);
  const descriptor = useMemo<PromptDescriptor>(() => ({
    ownerType: config.ownerType,
    ownerId: config.ownerId,
    promptSlot: config.promptSlot,
    languageId: config.languageId,
    pageId: config.pageId,
    title: config.title,
  }), [config.languageId, config.ownerId, config.ownerType, config.pageId, config.promptSlot, config.title]);

  const resolveRuntimeOverrides = useCallback((fieldNames: string[]) => (
    collectCmsRuntimeOverrides(container, fieldNames, config.languageId)
  ), [config.languageId, container]);

  const { bootstrap, loading, error, reload } = usePromptBootstrap({
    api,
    descriptor,
    currentContent: promptValue,
    currentMeta: stringifyPromptMeta(metaState),
    runtimeOverrides: resolveRuntimeOverrides([]),
    enabled: !!config.ownerId,
  });

  const disabled = config.disabled === 1;
  const activeVersion = bootstrap?.active_version || null;
  const effectiveVariablesSchema = variablesSchemaOverride || metaState.prompt?.variablesSchema || bootstrap?.variables_schema || [];
  const changeNote = metaState.prompt?.pendingChangeNote || '';
  const isDirty = promptValue !== (activeVersion?.template_raw || '');
  const currentRuntimeOverrides = resolveRuntimeOverrides(bootstrap?.companion_field_names || []);
  const defaultPromptModel =
    String(currentRuntimeOverrides.llm_model || '') ||
    extractActiveVersionModel(activeVersion?.config_json) ||
    bootstrap?.models?.[0]?.id ||
    null;

  const syncContent = useCallback((nextValue: string) => {
    setPromptValue(nextValue);
    dispatchFieldChange(contentInput, nextValue);
  }, [contentInput]);

  const syncMeta = useCallback((nextMeta: PromptMetaState) => {
    setMetaState(nextMeta);
    dispatchFieldChange(metaInput, stringifyPromptMeta(nextMeta));
  }, [metaInput]);

  useEffect(() => {
    promptValueRef.current = promptValue;
  }, [promptValue]);

  useEffect(() => {
    metaStateRef.current = metaState;
  }, [metaState]);

  useEffect(() => {
    const pullExternalState = () => {
      const nextContent = contentInput.value || '';
      const nextMetaRaw = metaInput.value || '';

      let hasChanged = false;

      if (nextContent !== promptValueRef.current) {
        setPromptValue(nextContent);
        hasChanged = true;
      }

      const currentMetaRaw = stringifyPromptMeta(metaStateRef.current);
      if (nextMetaRaw !== currentMetaRaw) {
        setMetaState(parsePromptMeta(nextMetaRaw));
        hasChanged = true;
      }

      if (hasChanged) {
        reload().catch(() => undefined);
      }
    };

    const onWindowFocus = () => pullExternalState();
    contentInput.addEventListener('change', pullExternalState);
    contentInput.addEventListener('input', pullExternalState);
    metaInput.addEventListener('change', pullExternalState);
    metaInput.addEventListener('input', pullExternalState);
    window.addEventListener('focus', onWindowFocus);

    return () => {
      contentInput.removeEventListener('change', pullExternalState);
      contentInput.removeEventListener('input', pullExternalState);
      metaInput.removeEventListener('change', pullExternalState);
      metaInput.removeEventListener('input', pullExternalState);
      window.removeEventListener('focus', onWindowFocus);
    };
  }, [contentInput, metaInput, reload]);

  const handleChangeNote = (value: string) => {
    const nextMeta = { ...metaState };
    const prompt = ensurePromptMeta(nextMeta);
    prompt.pendingChangeNote = value;
    syncMeta(nextMeta);
  };

  const handleUseVersion = (version: PromptVersion) => {
    syncContent(version.template_raw || '');
    handleChangeNote(changeNote || `Restored from version ${version.version_no}`);
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
    syncContent(nextPrompt);
    if (variables.length > 0) {
      setVariablesSchemaOverride(variables);
      const nextMeta = { ...metaState };
      const prompt = ensurePromptMeta(nextMeta);
      prompt.variablesSchema = variables;
      if (!prompt.pendingChangeNote && changeSummary) {
        prompt.pendingChangeNote = changeSummary;
      }
      syncMeta(nextMeta);
    } else if (changeSummary && !changeNote) {
      handleChangeNote(changeSummary);
    }
    setShowBuilder(false);
  };

  return (
    <div className="prompt-field-app">
      {error && <Alert variant="danger" className="small py-2">{error}</Alert>}

      <PromptToolbar
        activeVersion={activeVersion}
        dirty={isDirty}
        disabled={disabled}
        changeNote={changeNote}
        onChangeNote={handleChangeNote}
        onOpenVersions={() => {
          reload().catch(() => undefined);
          setShowVersions(true);
        }}
        onOpenCompare={() => {
          const activeKey = activeVersion ? `v:${activeVersion.id}` : 'draft';
          setDiffState({
            initialLeftKey: activeKey,
            initialRightKey: 'draft',
          });
          setShowDiff(true);
        }}
        onOpenPlayground={() => setShowPlayground(true)}
        onOpenBuilder={() => setShowBuilder(true)}
      />

      {loading && !bootstrap ? (
        <div className="border rounded bg-light p-4 text-center text-muted small">
          <Spinner animation="border" size="sm" className="mr-2" />
          Loading prompt history...
        </div>
      ) : (
        <>
          <PromptEditor
            value={promptValue}
            onChange={syncContent}
            disabled={disabled}
            editorMode="textarea"
            rows={14}
            placeholder="Write the prompt template here"
          />
          <div className="small text-muted mt-2">
            Prompt versions are created only when the normal CMS save runs.
          </div>
        </>
      )}

      <PromptVersionsModal
        show={showVersions}
        onHide={() => setShowVersions(false)}
        versions={bootstrap?.versions || []}
        activeVersionId={activeVersion?.id}
        disabled={disabled}
        onUseVersion={handleUseVersion}
        onCompareVersion={openDiffWithVersion}
      />

      <PromptDiffModal
        show={showDiff}
        onHide={() => setShowDiff(false)}
        api={api}
        descriptor={descriptor}
        versions={bootstrap?.versions || []}
        draftContent={promptValue}
        initialLeftKey={diffState.initialLeftKey}
        initialRightKey={diffState.initialRightKey}
      />

      <PromptPlaygroundModal
        show={showPlayground}
        onHide={() => setShowPlayground(false)}
        api={api}
        descriptor={descriptor}
        executionProfile={bootstrap?.execution_profile || 'text_only'}
        models={bootstrap?.models || []}
        variablesSchema={effectiveVariablesSchema}
        promptValue={promptValue}
        disabled={disabled || (bootstrap?.execution_profile || 'text_only') === 'text_only'}
        defaultModel={defaultPromptModel}
        resolveRuntimeOverrides={() => currentRuntimeOverrides}
      />

      <PromptBuilderModal
        show={showBuilder}
        onHide={() => setShowBuilder(false)}
        api={api}
        descriptor={descriptor}
        currentPrompt={promptValue}
        models={bootstrap?.models || []}
        defaultModel={defaultPromptModel}
        disabled={disabled}
        onApplySuggestion={handleBuilderApply}
      />
    </div>
  );
};
