/**
 * LLM Form Panel Component
 * ========================
 * 
 * Manages the LLM result display for llmFormRecord and llmFormLog styles.
 * Intercepts the form submit, sends the AJAX request, triggers LLM generation,
 * and displays the result in a configurable panel.
 */

import React, { useState, useEffect, useCallback, useRef } from 'react';
import type { LlmFormConfig, LlmFormResult, LlmResultMeta } from '../../../types/form';
import { LlmResultDisplay } from './LlmResultDisplay';

interface LlmFormPanelProps {
  config: LlmFormConfig;
  formContainer: HTMLElement;
  formName: string;
}

export const LlmFormPanel: React.FC<LlmFormPanelProps> = ({ config, formContainer, formName }) => {
  const hasPreviousResult = config.llmShowPreviousResult && !!config.previousResult;
  const [result, setResult] = useState<string | null>(hasPreviousResult ? config.previousResult : null);
  const [meta, setMeta] = useState<LlmResultMeta | null>(hasPreviousResult ? config.previousMeta : null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [closed, setClosed] = useState(false);
  const [freshResponse, setFreshResponse] = useState(false);
  const recordIdRef = useRef<string | null>(null);
  const requestInFlightRef = useRef(false);
  const disabledElementsRef = useRef<HTMLElement[]>([]);

  const setSubmissionLock = useCallback((locked: boolean) => {
    if (locked) {
      const elements = formContainer.querySelectorAll(
        'button, input[type="submit"], input[type="button"]'
      );
      const toDisable: HTMLElement[] = [];
      elements.forEach((el) => {
        const element = el as HTMLElement;
        const htmlEl = element as HTMLButtonElement | HTMLInputElement;
        if (htmlEl.disabled) return;
        htmlEl.disabled = true;
        toDisable.push(element);
      });
      disabledElementsRef.current = toDisable;
      return;
    }

    disabledElementsRef.current.forEach((element) => {
      const htmlEl = element as HTMLButtonElement | HTMLInputElement;
      htmlEl.disabled = false;
    });
    disabledElementsRef.current = [];
  }, [formContainer]);

  const applyButtonSizing = useCallback(() => {
    const root = formContainer.closest('.llm-form-root') || formContainer;
    const btnElements = root.querySelectorAll(
      '.btn, button[type="submit"], button[type="button"], input[type="submit"], input[type="button"]'
    );

    btnElements.forEach((el) => {
      const element = el as HTMLElement;
      if (config.useSmallButtons) {
        element.classList.add('btn-sm');
      } else {
        element.classList.remove('btn-sm');
      }
    });
  }, [config.useSmallButtons, formContainer]);

  const submitFormWithLlm = useCallback(async (form: HTMLFormElement) => {
    if (requestInFlightRef.current) return;
    if (form.dataset.llmSubmitting === '1') return;
    requestInFlightRef.current = true;
    form.dataset.llmSubmitting = '1';

    const formData = new FormData(form);
    formData.append('__llm_form', '1');

    setLoading(true);
    setError(null);
    setClosed(false);
    setSubmissionLock(true);

    try {
      const response = await fetch(form.action || window.location.href, {
        method: 'POST',
        body: formData,
      });

      const data: LlmFormResult = await response.json();

      if (data.success) {
        setResult(data.llm_result);
        setMeta(data.llm_meta);
        setFreshResponse(true);
        recordIdRef.current = formData.get('SELECTED_RECORD_ID') as string || null;
      } else {
        if (config.llmShowErrors) {
          setError(data.error || 'LLM generation failed');
        }
        setResult(data.llm_result || null);
        setMeta(data.llm_meta || null);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Network error';
      if (config.llmShowErrors) {
        setError(msg);
      }
    } finally {
      delete form.dataset.llmSubmitting;
      requestInFlightRef.current = false;
      setSubmissionLock(false);
      setLoading(false);
    }
  }, [config, setSubmissionLock]);

  const handleFormSubmit = useCallback((e: Event) => {
    if (e.defaultPrevented) return;
    const form = e.target as HTMLFormElement;
    if (!form || !config.llmEnabled) return;
    if (requestInFlightRef.current || form.dataset.llmSubmitting === '1') {
      e.preventDefault();
      e.stopPropagation();
      return;
    }

    const formData = new FormData(form);
    const submittedName = formData.get('__form_name');
    if (formName && submittedName && submittedName !== formName) return;

    e.preventDefault();
    e.stopPropagation();

    // Check for confirmation dialog ($.confirm from jquery-confirm)
    const confirmationAttr = form.getAttribute('data-confirmation');
    if (confirmationAttr) {
      try {
        const conf = JSON.parse(confirmationAttr);
        if (conf.confirmation_title && typeof (window as any).$ !== 'undefined' && (window as any).$.confirm) {
          (window as any).$.confirm({
            title: conf.confirmation_title,
            content: conf.confirmation_message || '',
            buttons: {
              confirm: {
                text: conf.confirmation_continue || 'OK',
                action: () => { submitFormWithLlm(form); },
              },
              cancel: {
                text: conf.confirmation_cancel || 'Cancel',
                action: () => {},
              },
            },
          });
          return;
        }
      } catch { /* no valid confirmation, proceed directly */ }
    }

    submitFormWithLlm(form);
  }, [config, formName, submitFormWithLlm]);

  const handleRegenerate = useCallback(async () => {
    if (requestInFlightRef.current) return;
    requestInFlightRef.current = true;
    setLoading(true);
    setError(null);
    setClosed(false);
    setSubmissionLock(true);

    const formData = new FormData();
    formData.append('__llm_action', 'regenerate');
    formData.append('section_id', String(config.sectionId));
    if (recordIdRef.current) {
      formData.append('__record_id', recordIdRef.current);
    }

    try {
      const response = await fetch(window.location.href, {
        method: 'POST',
        body: formData,
      });

      const data: LlmFormResult = await response.json();

      if (data.success) {
        setResult(data.llm_result);
        setMeta(data.llm_meta);
        setFreshResponse(true);
      } else {
        if (config.llmShowErrors) {
          setError(data.error || 'Regeneration failed');
        }
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Network error';
      if (config.llmShowErrors) {
        setError(msg);
      }
    } finally {
      requestInFlightRef.current = false;
      setSubmissionLock(false);
      setLoading(false);
    }
  }, [config, setSubmissionLock]);

  const handleRetry = useCallback(async () => {
    if (requestInFlightRef.current) return;
    requestInFlightRef.current = true;
    setLoading(true);
    setError(null);
    setClosed(false);
    setSubmissionLock(true);

    const formData = new FormData();
    formData.append('__llm_action', 'retry');
    formData.append('section_id', String(config.sectionId));
    if (recordIdRef.current) {
      formData.append('__record_id', recordIdRef.current);
    }

    try {
      const response = await fetch(window.location.href, {
        method: 'POST',
        body: formData,
      });

      const data: LlmFormResult = await response.json();

      if (data.success) {
        setResult(data.llm_result);
        setMeta(data.llm_meta);
        setFreshResponse(true);
      } else {
        if (config.llmShowErrors) {
          setError(data.error || 'Retry failed');
        }
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Network error';
      if (config.llmShowErrors) {
        setError(msg);
      }
    } finally {
      requestInFlightRef.current = false;
      setSubmissionLock(false);
      setLoading(false);
    }
  }, [config, setSubmissionLock]);

  useEffect(() => {
    const forms = formContainer.querySelectorAll('form.selfHelp-form');
    const handler = (e: Event) => handleFormSubmit(e);

    forms.forEach((form) => {
      form.addEventListener('submit', handler, true);
    });

    // Also intercept the AJAX submission if it exists
    const ajaxInput = formContainer.querySelector('input[name="ajax"]') as HTMLInputElement | null;
    if (ajaxInput) {
      ajaxInput.value = '1';
    }

    return () => {
      forms.forEach((form) => {
        form.removeEventListener('submit', handler, true);
      });
      requestInFlightRef.current = false;
      setSubmissionLock(false);
    };
  }, [formContainer, handleFormSubmit, setSubmissionLock]);

  useEffect(() => {
    applyButtonSizing();
  }, [applyButtonSizing, loading, result, error]);

  if (closed && config.llmResultClosable) {
    return null;
  }

  const hasContent = (result !== null && result !== '') || loading || error !== null;
  if (!hasContent) {
    return null;
  }

  return (
    <LlmResultDisplay
      config={config}
      result={result}
      meta={meta}
      loading={loading}
      error={error}
      freshResponse={freshResponse}
      onClose={() => setClosed(true)}
      onRetry={config.llmRetryEnabled ? handleRetry : undefined}
      onRegenerate={config.llmRegenerateEnabled ? handleRegenerate : undefined}
    />
  );
};

function showFormAlert(container: HTMLElement, type: string, message: string) {
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
