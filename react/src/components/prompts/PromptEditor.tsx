/**
 * Prompt Editor — code-mirror / Monaco-based text editor for prompt templates.
 *
 * Provides syntax highlighting, auto-resize, and an optional read-only mode.
 * Falls back to a plain `<textarea>` when Monaco is unavailable. Supports
 * both markdown and JSON language modes.
 *
 * Used as the primary text input across the Prompt Lab, Scripts Manager,
 * and JSON editors.
 *
 * The Monaco editor lifecycle is intentionally decoupled from parent
 * callback identity so that an unstable `onChange` (e.g. an inline arrow
 * passed from a parent that rerenders on every keystroke) never causes
 * the editor instance to be torn down and recreated. Recreation would
 * blur the field, drop the caret to position 0, and visibly thrash for
 * the user. The editor is only recreated when true config (editorMode,
 * language, fallback availability) changes.
 *
 * @module components/prompts/PromptEditor
 */
import React, { useEffect, useRef, useState } from 'react';
import { Form } from 'react-bootstrap';

declare const monaco: any;
declare const require: any;
declare const BASE_PATH: string;

interface PromptEditorProps {
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
  editorMode?: 'textarea' | 'monaco';
  language?: string;
  placeholder?: string;
  rows?: number;
  minHeight?: number;
  className?: string;
}

/** PromptEditor component. */
export const PromptEditor: React.FC<PromptEditorProps> = ({
  value,
  onChange,
  disabled = false,
  editorMode = 'textarea',
  language = 'markdown',
  placeholder,
  rows = 14,
  minHeight = 280,
  className = '',
}) => {
  const editorContainerRef = useRef<HTMLDivElement | null>(null);
  const editorRef = useRef<any>(null);
  const lastValueRef = useRef<string>(value);
  const onChangeRef = useRef(onChange);
  const valueRef = useRef(value);
  const disabledRef = useRef(disabled);
  const [fallbackToTextarea, setFallbackToTextarea] = useState(editorMode !== 'monaco');

  // Keep the latest onChange/value/disabled in refs so the Monaco listener
  // can read them without forcing the editor to be recreated when the
  // parent passes a fresh callback identity on every render.
  useEffect(() => {
    onChangeRef.current = onChange;
  }, [onChange]);

  useEffect(() => {
    valueRef.current = value;
  }, [value]);

  useEffect(() => {
    disabledRef.current = disabled;
  }, [disabled]);

  useEffect(() => {
    if (editorMode !== 'monaco') {
      setFallbackToTextarea(true);
      return;
    }

    setFallbackToTextarea(false);
  }, [editorMode]);

  // Mount/teardown the Monaco editor. Intentionally depends ONLY on truly
  // structural inputs (editorMode, language, fallback toggle). Changes to
  // `disabled`, `value`, or `onChange` are propagated via the dedicated
  // sync effects below so the live editor instance keeps its caret/focus.
  useEffect(() => {
    if (editorMode !== 'monaco' || fallbackToTextarea || !editorContainerRef.current) {
      return;
    }

    let disposed = false;

    const mountEditor = () => {
      if (!editorContainerRef.current || disposed) {
        return;
      }

      if (editorRef.current) {
        editorRef.current.dispose();
      }

      const initialValue = valueRef.current ?? '';
      lastValueRef.current = initialValue;

      editorRef.current = monaco.editor.create(editorContainerRef.current, {
        value: initialValue,
        language,
        automaticLayout: true,
        renderLineHighlight: 'none',
        wordWrap: 'on',
        minimap: { enabled: false },
        fontSize: 14,
        lineNumbers: 'on',
        scrollBeyondLastLine: false,
        readOnly: disabledRef.current,
      });

      editorRef.current.onDidChangeModelContent(() => {
        const nextValue = editorRef.current?.getValue?.() ?? '';
        lastValueRef.current = nextValue;
        onChangeRef.current(nextValue);
      });
    };

    try {
      if (typeof monaco !== 'undefined' && monaco?.editor) {
        mountEditor();
      } else if (typeof require !== 'undefined') {
        require.config({ paths: { vs: `${BASE_PATH}/js/ext/vs` } });
        require(['vs/editor/editor.main'], mountEditor);
      } else {
        setFallbackToTextarea(true);
      }
    } catch {
      setFallbackToTextarea(true);
    }

    return () => {
      disposed = true;
      if (editorRef.current) {
        editorRef.current.dispose();
        editorRef.current = null;
      }
    };
  }, [editorMode, fallbackToTextarea, language]);

  // Mirror controlled `value` prop into the live editor without disturbing
  // the caret when the user is the source of the change. The `lastValueRef`
  // guard makes setValue a no-op when the value coming back from the parent
  // matches the value we just emitted from the change listener.
  useEffect(() => {
    if (!editorRef.current) {
      return;
    }

    if (lastValueRef.current === value) {
      return;
    }

    lastValueRef.current = value;
    editorRef.current.setValue(value);
  }, [value]);

  useEffect(() => {
    if (!editorRef.current) {
      return;
    }

    editorRef.current.updateOptions({ readOnly: disabled });
  }, [disabled]);

  if (editorMode !== 'monaco' || fallbackToTextarea) {
    return (
      <Form.Control
        as="textarea"
        rows={rows}
        value={value}
        disabled={disabled}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        className={`prompt-editor-textarea ${className}`.trim()}
      />
    );
  }

  return (
    <div
      ref={editorContainerRef}
      className={`prompt-editor-monaco ${className}`.trim()}
      style={{ minHeight }}
    />
  );
};
