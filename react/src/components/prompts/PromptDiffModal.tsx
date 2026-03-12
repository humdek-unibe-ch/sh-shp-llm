import React, { useEffect, useRef, useState } from 'react';
import Select from 'react-select';
import { Button, Col, Form, Modal, Row } from 'react-bootstrap';
import type { PromptVersion } from './promptTypes';

declare const monaco: any;
declare const require: any;
declare const BASE_PATH: string;

interface PromptDiffModalProps {
  show: boolean;
  onHide: () => void;
  versions: PromptVersion[];
  draftContent: string;
  initialLeftKey?: string;
  initialRightKey?: string;
}

export const PromptDiffModal: React.FC<PromptDiffModalProps> = ({
  show,
  onHide,
  versions,
  draftContent,
  initialLeftKey = 'draft',
  initialRightKey = 'draft',
}) => {
  const diffRef = useRef<HTMLDivElement | null>(null);
  const editorRef = useRef<any>(null);
  const [fallback, setFallback] = useState(false);
  const [leftKey, setLeftKey] = useState(initialLeftKey);
  const [rightKey, setRightKey] = useState(initialRightKey);
  const [comparedLeftKey, setComparedLeftKey] = useState(initialLeftKey);
  const [comparedRightKey, setComparedRightKey] = useState(initialRightKey);

  useEffect(() => {
    if (!show) {
      return;
    }
    setLeftKey(initialLeftKey);
    setRightKey(initialRightKey);
    setComparedLeftKey(initialLeftKey);
    setComparedRightKey(initialRightKey);
  }, [initialLeftKey, initialRightKey, show]);

  const resolveVersionByKey = (key: string) => {
    if (key === 'draft') {
      return {
        title: 'Current Draft',
        content: draftContent,
      };
    }

    if (!key.startsWith('v:')) {
      return null;
    }

    const versionId = Number(key.replace('v:', ''));
    const version = versions.find((item) => item.id === versionId);
    if (!version) {
      return null;
    }

    return {
      title: `v${version.version_no}`,
      content: version.template_raw || '',
    };
  };

  const leftVersion = resolveVersionByKey(comparedLeftKey);
  const rightVersion = resolveVersionByKey(comparedRightKey);
  const leftTitle = leftVersion?.title || 'Left';
  const rightTitle = rightVersion?.title || 'Right';
  const leftContent = leftVersion?.content || '';
  const rightContent = rightVersion?.content || '';

  const versionOptions = [
    { value: 'draft', label: 'Current Draft' },
    ...versions.map((version) => ({
      value: `v:${version.id}`,
      label: `v${version.version_no} - ${version.created_at}${version.change_note ? ` - ${version.change_note}` : ''}`,
    })),
  ];

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
        ignoreTrimWhitespace: false,
        minimap: { enabled: false },
        scrollBeyondLastLine: false,
        wordWrap: 'on',
      });

      editorRef.current.setModel({
        original: originalModel,
        modified: modifiedModel,
      });

      const originalEditor = editorRef.current.getOriginalEditor?.();
      const modifiedEditor = editorRef.current.getModifiedEditor?.();
      originalEditor?.setScrollTop?.(0);
      modifiedEditor?.setScrollTop?.(0);
      originalEditor?.revealLine?.(1);
      modifiedEditor?.revealLine?.(1);
      editorRef.current.layout?.();
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
    <Modal
      show={show}
      onHide={onHide}
      centered
      dialogClassName="prompt-modal-90 prompt-diff-modal"
      onEntered={() => editorRef.current?.layout?.()}
    >
      <Modal.Header closeButton className="py-2">
        <Modal.Title className="h6">
          <i className="fas fa-code-branch mr-2"></i>
          Compare Prompt Versions
        </Modal.Title>
      </Modal.Header>
      <Modal.Body>
        <Row className="align-items-end mb-3">
          <Col md={5}>
            <Form.Label className="small font-weight-bold text-muted mb-1">Left Version</Form.Label>
            <Select
              className="prompt-diff-select"
              classNamePrefix="react-select"
              options={versionOptions}
              value={versionOptions.find((option) => option.value === leftKey) || null}
              onChange={(option) => setLeftKey(option?.value || 'draft')}
              isSearchable
            />
          </Col>
          <Col md={5}>
            <Form.Label className="small font-weight-bold text-muted mb-1">Right Version</Form.Label>
            <Select
              className="prompt-diff-select"
              classNamePrefix="react-select"
              options={versionOptions}
              value={versionOptions.find((option) => option.value === rightKey) || null}
              onChange={(option) => setRightKey(option?.value || 'draft')}
              isSearchable
            />
          </Col>
          <Col md={2}>
            <Button
              size="sm"
              variant="primary"
              className="w-100 prompt-diff-compare-btn"
              disabled={leftKey === rightKey}
              onClick={() => {
                setComparedLeftKey(leftKey);
                setComparedRightKey(rightKey);
              }}
            >
              Compare
            </Button>
          </Col>
        </Row>

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
