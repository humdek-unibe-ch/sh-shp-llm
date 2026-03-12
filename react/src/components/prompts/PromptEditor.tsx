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
  const [fallbackToTextarea, setFallbackToTextarea] = useState(editorMode !== 'monaco');

  useEffect(() => {
    if (editorMode !== 'monaco') {
      setFallbackToTextarea(true);
      return;
    }

    setFallbackToTextarea(false);
  }, [editorMode]);

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

      editorRef.current = monaco.editor.create(editorContainerRef.current, {
        value,
        language,
        automaticLayout: true,
        renderLineHighlight: 'none',
        wordWrap: 'on',
        minimap: { enabled: false },
        fontSize: 14,
        lineNumbers: 'on',
        scrollBeyondLastLine: false,
        readOnly: disabled,
      });

      editorRef.current.onDidChangeModelContent(() => {
        const nextValue = editorRef.current?.getValue?.() ?? '';
        lastValueRef.current = nextValue;
        onChange(nextValue);
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
  }, [disabled, editorMode, fallbackToTextarea, language, onChange, value]);

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

