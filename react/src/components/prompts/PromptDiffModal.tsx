import React, { useEffect, useState } from 'react';
import Select from 'react-select';
import { Col, Form, Modal, Row } from 'react-bootstrap';
import type { createPromptLabApi } from './promptApi';
import type { PromptVersion } from './promptTypes';
import { PromptDiffViewer } from './PromptDiffViewer';

interface PromptDiffModalProps {
  show: boolean;
  onHide: () => void;
  api: ReturnType<typeof createPromptLabApi>;
  descriptor: {
    ownerType: 'style_field' | 'llm_script' | 'llm_memory_rule';
    ownerId: number;
    promptSlot: string;
    languageId?: number | null;
    pageId?: number | null;
    title?: string | null;
  };
  versions: PromptVersion[];
  draftContent: string;
  initialLeftKey?: string;
  initialRightKey?: string;
}

export const PromptDiffModal: React.FC<PromptDiffModalProps> = ({
  show,
  onHide,
  api,
  descriptor,
  versions,
  draftContent,
  initialLeftKey = 'draft',
  initialRightKey = 'draft',
}) => {
  const [hydratedVersions, setHydratedVersions] = useState<Record<number, PromptVersion>>({});
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
        const loaded = await api.getVersion(versionId, descriptor) as PromptVersion;
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

  return (
    <Modal
      show={show}
      onHide={onHide}
      centered
      dialogClassName="prompt-modal-90 prompt-diff-modal"
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
        <PromptDiffViewer leftContent={leftContent} rightContent={rightContent} className="prompt-diff-monaco flex-grow-1" />
      </Modal.Body>
      <Modal.Footer className="py-2">
        <button type="button" className="btn btn-sm btn-secondary" onClick={onHide}>
          Close
        </button>
      </Modal.Footer>
    </Modal>
  );
};
