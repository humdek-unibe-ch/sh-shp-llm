import React, { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import type { LlmFormConfig, LlmFormResult, LlmResultMeta } from '../../../types/form';
import { LlmResultDisplay } from './LlmResultDisplay';

interface LlmFormPanelProps {
  config: LlmFormConfig;
  formContainer: HTMLElement;
  formName: string;
}

function getMainForm(container: HTMLElement): HTMLFormElement | null {
  return container.querySelector('form.selfHelp-form') as HTMLFormElement | null;
}

function syncEditorValuesToForm(form: HTMLFormElement): void {
  const textareas = form.querySelectorAll('textarea');
  textareas.forEach((ta) => {
    const textarea = ta as HTMLTextAreaElement;
    const sibling = textarea.nextElementSibling as any;
    const siblingCodeMirror = sibling && sibling.CodeMirror ? sibling.CodeMirror : null;
    const nearbyCodeMirror = (textarea.parentElement?.querySelector('.CodeMirror') as any)?.CodeMirror ?? null;
    const cm = siblingCodeMirror || nearbyCodeMirror;
    if (cm && typeof cm.getValue === 'function') {
      textarea.value = String(cm.getValue());
    }
  });
}

function hasMeaningfulValue(value: FormDataEntryValue | null | undefined): boolean {
  if (value === null || value === undefined) return false;
  const raw = String(value).trim();
  if (raw === '') return false;
  return raw.replace(/<[^>]*>/g, '').replace(/&nbsp;/gi, ' ').trim() !== '';
}

function areContextFieldsFilled(form: HTMLFormElement, contextFieldKeys: string[]): boolean {
  if (!contextFieldKeys || contextFieldKeys.length === 0) return true;

  syncEditorValuesToForm(form);
  const formData = new FormData(form);

  for (const key of contextFieldKeys) {
    // Ignore interpolation keys that do not exist in this form instance.
    if (form.elements.namedItem(key) === null) continue;
    if (!formData.getAll(key).some((value) => hasMeaningfulValue(value))) {
      return false;
    }
  }

  return true;
}

function showFormAlert(container: HTMLElement, type: string, message: string): void {
  const existing = container.querySelector('.llm-form-alert');
  if (existing) existing.remove();

  const alert = document.createElement('div');
  alert.className = `alert alert-${type} alert-dismissible fade show llm-form-alert`;
  alert.role = 'alert';
  alert.innerHTML = `
    ${message}
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
      <span aria-hidden="true">&times;</span>
    </button>
  `;
  container.insertBefore(alert, container.firstChild);

  setTimeout(() => {
    if (alert.parentNode) alert.remove();
  }, 5000);
}

export const LlmFormPanel: React.FC<LlmFormPanelProps> = ({ config, formContainer, formName }) => {
  const hasPreviousResult = config.llmShowPreviousResult && !!config.previousResult;
  const [result, setResult] = useState<string | null>(hasPreviousResult ? config.previousResult : null);
  const [meta, setMeta] = useState<LlmResultMeta | null>(hasPreviousResult ? config.previousMeta : null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [closed, setClosed] = useState(false);
  const [freshResponse, setFreshResponse] = useState(false);
  const [feedbackButtonEnabled, setFeedbackButtonEnabled] = useState(false);
  const [feedbackButtonHost, setFeedbackButtonHost] = useState<HTMLElement | null>(null);

  const recordIdRef = useRef<string | null>(null);
  const requestInFlightRef = useRef(false);
  const disabledElementsRef = useRef<HTMLElement[]>([]);
  const isManualMode = config.manualFeedbackEnabled;
  const showRegenerate = config.llmRegenerateEnabled && !isManualMode;

  const setSubmissionLock = useCallback((locked: boolean) => {
    if (locked) {
      const elements = formContainer.querySelectorAll('button, input[type="submit"], input[type="button"]');
      const toDisable: HTMLElement[] = [];

      elements.forEach((el) => {
        const element = el as HTMLButtonElement | HTMLInputElement;
        if (element.disabled) return;
        if ((el as HTMLElement).classList.contains('llm-feedback-btn')) return;
        element.disabled = true;
        toDisable.push(el as HTMLElement);
      });

      disabledElementsRef.current = toDisable;
      return;
    }

    disabledElementsRef.current.forEach((el) => {
      const element = el as HTMLButtonElement | HTMLInputElement;
      element.disabled = false;
    });
    disabledElementsRef.current = [];
  }, [formContainer]);

  const runLockedRequest = useCallback(async (showSpinner: boolean, task: () => Promise<void>) => {
    if (requestInFlightRef.current) {
      console.warn('[LLM Form] runLockedRequest skipped: already in flight');
      return;
    }
    requestInFlightRef.current = true;
    setError(null);
    setClosed(false);
    if (showSpinner) setLoading(true);
    setSubmissionLock(true);
    console.log('[LLM Form] runLockedRequest started, showSpinner=', showSpinner);

    try {
      await task();
    } finally {
      requestInFlightRef.current = false;
      setSubmissionLock(false);
      if (showSpinner) setLoading(false);
      console.log('[LLM Form] runLockedRequest finished');
    }
  }, [setSubmissionLock]);

  const postForm = useCallback(async (url: string, data: FormData): Promise<LlmFormResult> => {
    console.log('[LLM Form] postForm sending to:', url);
    const response = await fetch(url, { method: 'POST', body: data });
    const contentType = response.headers.get('content-type') || '';
    console.log('[LLM Form] postForm response status:', response.status, 'content-type:', contentType);
    if (!contentType.includes('application/json')) {
      console.warn('[LLM Form] postForm: non-JSON response');
      return { success: false, error: 'Server returned an unexpected response', llm_result: '', llm_meta: {} as LlmResultMeta };
    }
    const json = await response.json();
    console.log('[LLM Form] postForm parsed JSON:', { success: json.success, hasResult: !!json.llm_result });
    return json;
  }, []);

  const updateResultFromResponse = useCallback((data: LlmFormResult, fallbackError: string) => {
    console.log('[LLM Form] updateResultFromResponse:', { success: data.success, hasResult: !!data.llm_result, error: data.error });
    if (data.success) {
      setResult(data.llm_result);
      setMeta(data.llm_meta);
      setFreshResponse(true);
      return;
    }
    if (config.llmShowErrors) {
      setError(data.error || fallbackError);
    }
  }, [config.llmShowErrors]);

  const submitFormWithLlm = useCallback(async (form: HTMLFormElement) => {
    if (form.dataset.llmSubmitting === '1') return;
    form.dataset.llmSubmitting = '1';

    try {
      await runLockedRequest(!isManualMode, async () => {
        syncEditorValuesToForm(form);
        const formData = new FormData(form);
        formData.append('__llm_form', '1');

        console.log('[LLM Form] DOM connected before fetch:', formContainer.isConnected);

        try {
          const data = await postForm(form.action || window.location.href, formData);

          console.log('[LLM Form] DOM connected after fetch:', formContainer.isConnected);

          if (data.manual_feedback_mode) {
            if (data.success) {
              showFormAlert(formContainer, 'success', config.llmShowErrors ? 'Form saved successfully.' : 'Saved.');
              recordIdRef.current = (formData.get('SELECTED_RECORD_ID') as string) || null;
              return;
            }
            if (data.form_errors && config.llmShowErrors) {
              setError(data.error || 'Form validation failed');
            }
            return;
          }

          if (data.success) {
            recordIdRef.current = (formData.get('SELECTED_RECORD_ID') as string) || null;
            showFormAlert(formContainer, 'success', 'Saved.');
          }
          updateResultFromResponse(data, 'LLM generation failed');
        } catch (err: unknown) {
          if (config.llmShowErrors) {
            setError(err instanceof Error ? err.message : 'Network error');
          }
        }
      });
    } finally {
      delete form.dataset.llmSubmitting;
    }
  }, [config.llmShowErrors, formContainer, isManualMode, postForm, runLockedRequest, updateResultFromResponse]);

  const triggerLlmAction = useCallback(async (action: 'retry' | 'regenerate') => {
    await runLockedRequest(true, async () => {
      const formData = new FormData();
      formData.append('__llm_action', action);
      formData.append('section_id', String(config.sectionId));
      if (recordIdRef.current) formData.append('__record_id', recordIdRef.current);

      try {
        const data = await postForm(window.location.href, formData);
        updateResultFromResponse(
          data,
          action === 'retry' ? 'Retry failed' : 'Regeneration failed'
        );
      } catch (err: unknown) {
        if (config.llmShowErrors) {
          setError(err instanceof Error ? err.message : 'Network error');
        }
      }
    });
  }, [config.llmShowErrors, config.sectionId, postForm, runLockedRequest, updateResultFromResponse]);

  const handleGenerateFeedback = useCallback(async () => {
    if (!feedbackButtonEnabled) {
      if (config.llmShowErrors) {
        setError('Please fill all required fields before generating feedback.');
      }
      return;
    }

    const form = getMainForm(formContainer);
    if (!form) {
      if (config.llmShowErrors) setError('Form not found');
      return;
    }

    await runLockedRequest(true, async () => {
      syncEditorValuesToForm(form);
      const formData = new FormData(form);
      formData.append('__llm_action', 'generate_feedback');
      formData.append('section_id', String(config.sectionId));

      try {
        const data = await postForm(form.action || window.location.href, formData);
        updateResultFromResponse(data, 'Feedback generation failed');
      } catch (err: unknown) {
        if (config.llmShowErrors) {
          setError(err instanceof Error ? err.message : 'Network error');
        }
      }
    });
  }, [config.llmShowErrors, config.sectionId, feedbackButtonEnabled, formContainer, postForm, runLockedRequest, updateResultFromResponse]);

  const submitFormRef = useRef(submitFormWithLlm);
  submitFormRef.current = submitFormWithLlm;

  const handleFormSubmit = useCallback((e: Event) => {
    const form = e.target as HTMLFormElement;
    if (!form || form.tagName !== 'FORM' || !config.llmEnabled) return;
    if (requestInFlightRef.current || form.dataset.llmSubmitting === '1') {
      e.preventDefault();
      e.stopImmediatePropagation();
      console.warn('[LLM Form] handleFormSubmit blocked: in-flight or submitting');
      return;
    }

    const submittedName = new FormData(form).get('__form_name');
    if (formName && submittedName && submittedName !== formName) {
      console.log('[LLM Form] handleFormSubmit skipped: form name mismatch', { formName, submittedName });
      return;
    }

    console.log('[LLM Form] handleFormSubmit intercepted, preventing default');
    e.preventDefault();
    e.stopImmediatePropagation();

    const confirmationAttr = form.getAttribute('data-confirmation');
    if (confirmationAttr) {
      try {
        const conf = JSON.parse(confirmationAttr);
        const jquery = (window as any).$;
        if (conf.confirmation_title && jquery?.confirm) {
          jquery.confirm({
            title: conf.confirmation_title,
            content: conf.confirmation_message || '',
            buttons: {
              confirm: { text: conf.confirmation_continue || 'OK', action: () => submitFormRef.current(form) },
              cancel: { text: conf.confirmation_cancel || 'Cancel', action: () => {} },
            },
          });
          return;
        }
      } catch {
        // Invalid confirmation payload; submit directly.
      }
    }

    submitFormRef.current(form);
  }, [config.llmEnabled, formName]);

  useEffect(() => {
    if (!isManualMode) return;

    const form = getMainForm(formContainer);
    if (!form) return;

    const update = () => setFeedbackButtonEnabled(areContextFieldsFilled(form, config.contextFieldKeys));
    update();
    form.addEventListener('input', update);
    form.addEventListener('change', update);

    return () => {
      form.removeEventListener('input', update);
      form.removeEventListener('change', update);
    };
  }, [config.contextFieldKeys, formContainer, isManualMode]);

  useEffect(() => {
    const submitHandler = (e: Event) => handleFormSubmit(e);

    const jq = (window as any).jQuery || (window as any).$;

    // Register on the container in capture phase so it fires BEFORE any
    // jQuery handlers bound directly on <form> elements (initForm, formSubmitEvent).
    formContainer.addEventListener('submit', submitHandler, true);

    const forms = formContainer.querySelectorAll('form.selfHelp-form');
    if (jq) {
      forms.forEach((form) => {
        try { jq(form).off('submit'); } catch { /* ignore */ }
      });
    }

    // Disable SelfHelp's jQuery AJAX form submission by setting the ajax flag
    // to a value the core handler won't match. The core checks:
    //   if ($(this).find('input[name="ajax"]').val() == 1)
    // Setting it to "llm" makes the check fail so it won't fire $.ajax()
    // and won't run updateValues() which would replace our React root's DOM.
    const ajaxInput = formContainer.querySelector('input[name="ajax"]') as HTMLInputElement | null;
    const origAjaxValue = ajaxInput?.value ?? null;
    if (ajaxInput) ajaxInput.value = 'llm';

    return () => {
      formContainer.removeEventListener('submit', submitHandler, true);
      if (ajaxInput && origAjaxValue !== null) ajaxInput.value = origAjaxValue;
      requestInFlightRef.current = false;
      setSubmissionLock(false);
    };
  }, [formContainer, handleFormSubmit, setSubmissionLock]);

  useEffect(() => {
    const root = formContainer.closest('.llm-form-root') || formContainer;
    const buttons = root.querySelectorAll(
      '.btn, button[type="submit"], button[type="button"], input[type="submit"], input[type="button"]'
    );

    buttons.forEach((el) => {
      const element = el as HTMLElement;
      if (config.useSmallButtons) element.classList.add('btn-sm');
      else element.classList.remove('btn-sm');
    });
  }, [config.useSmallButtons, formContainer, loading, result, error]);

  useEffect(() => {
    if (!isManualMode) {
      setFeedbackButtonHost(null);
      return;
    }

    const form = getMainForm(formContainer);
    const submit = form?.querySelector('button[type="submit"], input[type="submit"]') as HTMLElement | null;
    if (!form || !submit || !submit.parentElement) {
      setFeedbackButtonHost(null);
      return;
    }

    // Remove any stale host elements first to prevent duplicates
    form.querySelectorAll('.llm-feedback-inline-host').forEach((el) => el.remove());

    const host = document.createElement('span');
    host.className = 'llm-feedback-inline-host ml-2 d-inline-block align-middle';
    submit.insertAdjacentElement('afterend', host);
    setFeedbackButtonHost(host);

    return () => {
      if (host.parentNode) host.parentNode.removeChild(host);
      setFeedbackButtonHost(null);
    };
  }, [formContainer, isManualMode]);

  const feedbackButton = isManualMode && feedbackButtonHost
    ? createPortal(
        <button
          type="button"
          className={`btn btn-${config.feedbackButtonColor || 'primary'} ${config.useSmallButtons ? 'btn-sm' : ''} llm-feedback-btn`.trim()}
          onClick={handleGenerateFeedback}
          disabled={loading || !feedbackButtonEnabled}
          title={!feedbackButtonEnabled ? 'Fill all required context fields to enable feedback generation.' : undefined}
        >
          {config.feedbackButtonLabel || 'Generate Feedback'}
        </button>,
        feedbackButtonHost
      )
    : null;

  if (closed && config.llmResultClosable) {
    return <>{feedbackButton}</>;
  }

  const hasContent = (result !== null && result !== '') || loading || error !== null;
  console.log('[LLM Form] render:', { hasContent, loading, hasResult: result !== null && result !== '', error, closed });

  return (
    <>
      {feedbackButton}
      {hasContent && (
        <LlmResultDisplay
          config={config}
          result={result}
          meta={meta}
          loading={loading}
          error={error}
          freshResponse={freshResponse}
          onClose={() => setClosed(true)}
          onRetry={config.llmRetryEnabled ? () => triggerLlmAction('retry') : undefined}
          onRegenerate={showRegenerate ? () => triggerLlmAction('regenerate') : undefined}
        />
      )}
    </>
  );
};
