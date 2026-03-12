import React, { useEffect, useRef, useState } from 'react';
import { Col, Modal, Row } from 'react-bootstrap';

declare const monaco: any;
declare const require: any;
declare const BASE_PATH: string;

interface PromptDiffModalProps {
  show: boolean;
  onHide: () => void;
  leftTitle: string;
  rightTitle: string;
  leftContent: string;
  rightContent: string;
}

export const PromptDiffModal: React.FC<PromptDiffModalProps> = ({
  show,
  onHide,
  leftTitle,
  rightTitle,
  leftContent,
  rightContent,
}) => {
  const diffRef = useRef<HTMLDivElement | null>(null);
  const editorRef = useRef<any>(null);
  const [fallback, setFallback] = useState(false);

  useEffect(() => {
    if (!show || !diffRef.current || fallback) {
      return;
    }

    let disposed = false;

    const mountDiff = () => {
      if (!diffRef.current || disposed) {
        return;
      }

      const originalModel = monaco.editor.createModel(leftContent, 'markdown');
      const modifiedModel = monaco.editor.createModel(rightContent, 'markdown');

      editorRef.current = monaco.editor.createDiffEditor(diffRef.current, {
        readOnly: true,
        automaticLayout: true,
        renderSideBySide: true,
        minimap: { enabled: false },
        scrollBeyondLastLine: false,
      });

      editorRef.current.setModel({
        original: originalModel,
        modified: modifiedModel,
      });
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
        const model = editorRef.current.getModel?.();
        model?.original?.dispose?.();
        model?.modified?.dispose?.();
        editorRef.current.dispose();
        editorRef.current = null;
      }
    };
  }, [fallback, leftContent, rightContent, show]);

  return (
    <Modal show={show} onHide={onHide} centered dialogClassName="prompt-modal-90 prompt-diff-modal">
      <Modal.Header closeButton className="py-2">
        <Modal.Title className="h6">
          <i className="fas fa-code-branch mr-2"></i>
          Compare Prompt Versions
        </Modal.Title>
      </Modal.Header>
      <Modal.Body>
        <Row className="small text-muted mb-2">
          <Col>{leftTitle}</Col>
          <Col className="text-right">{rightTitle}</Col>
        </Row>
        {fallback ? (
          <Row>
            <Col md={6}>
              <pre className="prompt-diff-fallback border rounded p-3 bg-light mb-0">{leftContent}</pre>
            </Col>
            <Col md={6}>
              <pre className="prompt-diff-fallback border rounded p-3 bg-light mb-0">{rightContent}</pre>
            </Col>
          </Row>
        ) : (
          <div ref={diffRef} className="prompt-diff-monaco" />
        )}
      </Modal.Body>
      <Modal.Footer className="py-2">
        <button type="button" className="btn btn-sm btn-secondary" onClick={onHide}>
          Close
        </button>
      </Modal.Footer>
    </Modal>
  );
};
