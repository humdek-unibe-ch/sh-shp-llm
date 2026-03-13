import React, { useEffect, useRef, useState } from 'react';

declare const monaco: any;
declare const require: any;
declare const BASE_PATH: string;

interface PromptDiffViewerProps {
  leftContent: string;
  rightContent: string;
  className?: string;
}

export const PromptDiffViewer: React.FC<PromptDiffViewerProps> = ({
  leftContent,
  rightContent,
  className = 'prompt-diff-monaco',
}) => {
  const diffRef = useRef<HTMLDivElement | null>(null);
  const editorRef = useRef<any>(null);
  const [fallback, setFallback] = useState(false);

  useEffect(() => {
    if (!diffRef.current || fallback) {
      return;
    }

    let disposed = false;

    const mountDiff = () => {
      if (!diffRef.current || disposed) {
        return;
      }

      const originalModel = monaco.editor.createModel(leftContent || '', 'markdown');
      const modifiedModel = monaco.editor.createModel(rightContent || '', 'markdown');

      editorRef.current = monaco.editor.createDiffEditor(diffRef.current, {
        readOnly: true,
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
      });

      let syncing = false;
      const syncScroll = (source: any, target: any) => source?.onDidScrollChange?.((event: any) => {
        if (!event?.scrollTopChanged || syncing || !target) {
          return;
        }
        syncing = true;
        target.setScrollTop?.(source.getScrollTop?.() || 0);
        syncing = false;
      });

      const disposeOriginalSync = syncScroll(originalEditor, modifiedEditor);
      const disposeModifiedSync = syncScroll(modifiedEditor, originalEditor);
      originalEditor?.setScrollTop?.(0);
      modifiedEditor?.setScrollTop?.(0);
      originalEditor?.revealLine?.(1);
      modifiedEditor?.revealLine?.(1);
      editorRef.current.layout?.();

      (editorRef.current as any).__promptSyncDisposables = [disposeOriginalSync, disposeModifiedSync];
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
  }, [fallback, leftContent, rightContent]);

  if (fallback) {
    return (
      <div className="row">
        <div className="col-md-6 mb-2 mb-md-0">
          <pre className="prompt-diff-fallback border rounded p-2 bg-light mb-0">{leftContent || ''}</pre>
        </div>
        <div className="col-md-6">
          <pre className="prompt-diff-fallback border rounded p-2 bg-light mb-0">{rightContent || ''}</pre>
        </div>
      </div>
    );
  }

  return <div ref={diffRef} className={className} />;
};
