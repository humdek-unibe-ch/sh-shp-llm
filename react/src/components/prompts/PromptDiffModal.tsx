import React, { useEffect, useRef, useState } from 'react';
import Select from 'react-select';
import { Col, Form, Modal, Row } from 'react-bootstrap';
import type { createPromptLabApi } from './promptApi';
import type { PromptVersion } from './promptTypes';

declare const monaco: any;
declare const require: any;
declare const BASE_PATH: string;

interface PromptDiffModalProps {
  show: boolean;
  onHide: () => void;
  api: ReturnType<typeof createPromptLabApi>;
  versions: PromptVersion[];
  draftContent: string;
  initialLeftKey?: string;
  initialRightKey?: string;
}

export const PromptDiffModal: React.FC<PromptDiffModalProps> = ({
  show,
  onHide,
  api,
  versions,
  draftContent,
  initialLeftKey = 'draft',
  initialRightKey = 'draft',
}) => {
  const diffRef = useRef<HTMLDivElement | null>(null);
  const editorRef = useRef<any>(null);
  const [hydratedVersions, setHydratedVersions] = useState<Record<number, PromptVersion>>({});
  const [fallback, setFallback] = useState(false);
  const [leftKey, setLeftKey] = useState(initialLeftKey);
  const [rightKey, setRightKey] = useState(initialRightKey);
  useEffect(() => {
    if (!show) {
      return;
    }
    setLeftKey(initialLeftKey);
    setRightKey(initialRightKey);
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
    const version = hydratedVersions[versionId] || versions.find((item) => item.id === versionId);
    if (!version) {
      return null;
    }

    return {
      title: `v${version.version_no}`,
      content: version.template_raw || '',
    };
  };

  const leftVersion = resolveVersionByKey(leftKey);
  const rightVersion = resolveVersionByKey(rightKey);
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
    if (!show) {
      return;
    }

    const initialMap: Record<number, PromptVersion> = {};
    versions.forEach((version) => {
      if (version.id) {
        initialMap[version.id] = version;
      }
    });
    setHydratedVersions(initialMap);
  }, [show, versions]);

  useEffect(() => {
    if (!show) {
      return;
    }

    const maybeLoad = async (key: string) => {
      if (!key.startsWith('v:')) {
        return;
      }
      const versionId = Number(key.replace('v:', ''));
      if (!versionId || hydratedVersions[versionId]?.template_raw) {
        return;
      }

      try {
        const loaded = await api.getVersion(versionId) as PromptVersion;
        setHydratedVersions((current) => ({
          ...current,
          [versionId]: loaded,
        }));
      } catch {
        // keep fallback data from bootstrap
      }
    };

    maybeLoad(leftKey);
    maybeLoad(rightKey);
  }, [api, hydratedVersions, leftKey, rightKey, show]);

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
          <Col md={6}>
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
          <Col md={6}>
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
