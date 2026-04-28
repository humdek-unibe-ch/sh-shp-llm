/**
 * Prompt Diff Viewer — Monaco-based side-by-side diff rendering.
 *
 * Uses Monaco's `createDiffEditor` for rich inline diffs. Falls back
 * to a plain text comparison when Monaco is not loaded.
 *
 * @module components/prompts/PromptDiffViewer
 */
import React, { useEffect, useRef, useState } from 'react';

declare const monaco: any;
declare const require: any;
declare const BASE_PATH: string;

interface PromptDiffViewerProps {
  leftContent: string;
  rightContent: string;
  className?: string;
  readOnly?: boolean;
  onRightContentChange?: (value: string) => void;
}

/** PromptDiffViewer component. */
export const PromptDiffViewer: React.FC<PromptDiffViewerProps> = ({
  leftContent,
  rightContent,
  className = 'prompt-diff-monaco',
  readOnly = true,
  onRightContentChange,
}) => {
  const diffRef = useRef<HTMLDivElement | null>(null);
  const editorRef = useRef<any>(null);
  const leftModelRef = useRef<any>(null);
  const rightModelRef = useRef<any>(null);
  const leftContentRef = useRef(leftContent);
  const rightContentRef = useRef(rightContent);
  const onRightContentChangeRef = useRef(onRightContentChange);
  const isSyncingRef = useRef(false);
  const [fallback, setFallback] = useState(false);

  useEffect(() => {
    leftContentRef.current = leftContent;
  }, [leftContent]);

  useEffect(() => {
    rightContentRef.current = rightContent;
  }, [rightContent]);

  useEffect(() => {
    onRightContentChangeRef.current = onRightContentChange;
  }, [onRightContentChange]);

  useEffect(() => {
    if (!diffRef.current || fallback || editorRef.current) {
      return;
    }

    let disposed = false;

    const mountDiff = () => {
      if (!diffRef.current || disposed) {
        return;
      }

      const originalModel = monaco.editor.createModel(leftContentRef.current || '', 'markdown');
      const modifiedModel = monaco.editor.createModel(rightContentRef.current || '', 'markdown');
      leftModelRef.current = originalModel;
      rightModelRef.current = modifiedModel;

      editorRef.current = monaco.editor.createDiffEditor(diffRef.current, {
        readOnly,
        automaticLayout: true,
        renderSideBySide: true,
        ignoreTrimWhitespace: false,
        minimap: { enabled: false },
        scrollBeyondLastLine: false,
        wordWrap: 'on',
        hideUnchangedRegions: { enabled: false },
        overviewRulerLanes: 0,
      });

      editorRef.current.setModel({
        original: originalModel,
        modified: modifiedModel,
      });

      const originalEditor = editorRef.current.getOriginalEditor?.();
      const modifiedEditor = editorRef.current.getModifiedEditor?.();
      originalEditor?.updateOptions?.({
        scrollbar: {
          vertical: 'hidden',
          horizontal: 'auto',
        },
      });
      modifiedEditor?.updateOptions?.({
        scrollbar: {
          vertical: 'hidden',
          horizontal: 'auto',
        },
        readOnly,
      });

      const changeDisposable = !readOnly && onRightContentChangeRef.current
        ? modifiedEditor?.onDidChangeModelContent?.(() => {
            if (isSyncingRef.current) {
              return;
            }
            onRightContentChangeRef.current?.(modifiedEditor.getValue?.() || '');
          })
        : null;

      let disposeOriginalSync: any = null;
      let disposeModifiedSync: any = null;
      if (readOnly) {
        let syncing = false;
        const syncScroll = (source: any, target: any) => source?.onDidScrollChange?.((event: any) => {
          if (!event?.scrollTopChanged || syncing || !target) {
            return;
          }
          syncing = true;
          target.setScrollTop?.(source.getScrollTop?.() || 0);
          syncing = false;
        });

        disposeOriginalSync = syncScroll(originalEditor, modifiedEditor);
        disposeModifiedSync = syncScroll(modifiedEditor, originalEditor);
      }

      originalEditor?.setScrollTop?.(0);
      modifiedEditor?.setScrollTop?.(0);
      originalEditor?.revealLine?.(1);
      modifiedEditor?.revealLine?.(1);
      editorRef.current.layout?.();

      (editorRef.current as any).__promptSyncDisposables = [disposeOriginalSync, disposeModifiedSync, changeDisposable];
    };

    try {
      if (typeof monaco !== 'undefined' && monaco?.editor) {
        mountDiff();
      } else if (typeof require !== 'undefined') {
        require.config({ paths: { vs: `${BASE_PATH}/js/ext/vs` } });
        require(['vs/editor/editor.main'], mountDiff);
      } else {
        setFallback(true);
      }
    } catch {
      setFallback(true);
    }

    return () => {
      disposed = true;
      if (editorRef.current) {
        const syncDisposables = (editorRef.current as any).__promptSyncDisposables || [];
        syncDisposables.forEach((disposable: any) => disposable?.dispose?.());
        const model = editorRef.current.getModel?.();
        model?.original?.dispose?.();
        model?.modified?.dispose?.();
        editorRef.current.dispose();
        editorRef.current = null;
      }
    };
  }, [fallback, readOnly]);

  useEffect(() => {
    const leftModel = leftModelRef.current;
    if (!leftModel) return;

    const incoming = leftContent || '';
    const current = leftModel.getValue?.() || '';
    if (incoming !== current) {
      isSyncingRef.current = true;
      leftModel.setValue(incoming);
      isSyncingRef.current = false;
    }
  }, [leftContent]);

  useEffect(() => {
    const rightModel = rightModelRef.current;
    if (!rightModel) return;

    const incoming = rightContent || '';
    const current = rightModel.getValue?.() || '';
    if (incoming !== current) {
      isSyncingRef.current = true;
      rightModel.setValue(incoming);
      isSyncingRef.current = false;
    }
  }, [rightContent]);

  if (fallback) {
    return (
      <div className="row">
        <div className="col-md-6 mb-2 mb-md-0">
          <pre className="prompt-diff-fallback border rounded p-2 bg-light mb-0">{leftContent || ''}</pre>
        </div>
        <div className="col-md-6">
          {readOnly ? (
            <pre className="prompt-diff-fallback border rounded p-2 bg-light mb-0">{rightContent || ''}</pre>
          ) : (
            <textarea
              className="form-control prompt-diff-fallback-editor"
              value={rightContent || ''}
              onChange={(event) => onRightContentChange?.(event.target.value)}
              rows={12}
            />
          )}
        </div>
      </div>
    );
  }

  return <div ref={diffRef} className={className} />;
};
