/**
 * Prompts hub detail: one prompt with its full text, where it is used and a link that opens it
 * where it is edited (the CMS section, the Scripts page, the Memory rules, the agentic chat
 * settings).
 *
 * It renders as the plugin's dialogs do: a header with the title, a body that scrolls and a
 * footer with every button. In a modal (`variant="modal"`, inside a `prompt-modal-90` dialog)
 * that is Modal.Header/Body/Footer; in the side panel of wide screens a card with the same three
 * parts.
 *
 * Prompts of the registry (CMS prompt fields, scripts, memory rules) open in PromptsFieldEditor:
 * language tabs, edit, one Save, history, restore. Built-in system prompts and personas have no
 * history and are shown read-only.
 *
 * @module components/promptsHub/PromptsDetailPanel
 */
import React from 'react';
import { Alert, Badge, Button, Card, Modal, Spinner } from 'react-bootstrap';
import { PromptEditor } from '../prompts/PromptEditor';
import '../prompts/PromptLab.css';
import { PromptsFieldEditor } from './PromptsFieldEditor';
import type { PromptsShell } from './PromptsFieldEditor';
import { UpdatedInfo } from './PromptsList';
import type { PromptDetail } from './promptsHubTypes';
import {
  REGISTRY_SOURCES,
  SOURCE_BADGE_VARIANT,
  SOURCE_LABELS,
  SOURCE_OPEN_LABELS,
  formatWhen,
  languageLabel,
  readOnlyText,
  versionLabel,
} from './promptsHubUtils';

interface PromptsDetailPanelProps {
  detail: PromptDetail | null;
  loading: boolean;
  error: string | null;
  /** modal: the parts of a Modal; card: the parts of a Card (side panel) */
  variant: 'modal' | 'card';
  onClose: () => void;
  /** A prompt as the server has it now (after a save, a restore or a reload); also another language */
  onSaved: (saved: PromptDetail) => void;
  /** Whether the editor holds text that is not saved */
  onDirtyChange: (dirty: boolean) => void;
}

const MODAL_SHELL: PromptsShell = {
  Body: ({ children }) => <Modal.Body>{children}</Modal.Body>,
  Footer: ({ children }) => <Modal.Footer className="py-2">{children}</Modal.Footer>,
};

const CARD_SHELL: PromptsShell = {
  Body: ({ children }) => <Card.Body className="llm-prompts-panel-body">{children}</Card.Body>,
  Footer: ({ children }) => (
    <Card.Footer className="bg-light py-2 d-flex align-items-center justify-content-end flex-wrap llm-prompts-panel-footer">
      {children}
    </Card.Footer>
  ),
};

/** The read-only text has nothing to change. */
const noChange = (): void => undefined;

/** The detail of the prompts hub, as dialog or side panel. */
export const PromptsDetailPanel: React.FC<PromptsDetailPanelProps> = ({
  detail,
  loading,
  error,
  variant,
  onClose,
  onSaved,
  onDirtyChange,
}) => {
  const shell = variant === 'modal' ? MODAL_SHELL : CARD_SHELL;
  const { Body, Footer } = shell;

  const titleContent = detail === null ? (
    <span className="text-muted">{error !== null ? 'Prompt' : 'Loading prompt...'}</span>
  ) : (
    <>
      <span className="llm-prompts-detail-title mr-2">{detail.title}</span>
      <Badge variant={SOURCE_BADGE_VARIANT[detail.sourceType]} className="mr-1">{SOURCE_LABELS[detail.sourceType]}</Badge>
      <span className="small text-muted font-weight-normal">{detail.plugin}</span>
      {detail.orphaned && <Badge variant="light" className="ml-2 border">orphaned</Badge>}
    </>
  );

  const header = variant === 'modal' ? (
    <Modal.Header closeButton className="py-2">
      <Modal.Title className="h6 mb-0">{titleContent}</Modal.Title>
    </Modal.Header>
  ) : (
    <Card.Header className="d-flex align-items-center py-2">
      <h6 className="mb-0 flex-grow-1">{titleContent}</h6>
      <Button variant="light" size="sm" className="ml-2" aria-label="Close the prompt" onClick={onClose}>
        <i className="fas fa-times" aria-hidden="true"></i>
      </Button>
    </Card.Header>
  );

  const closeFooter = (
    <Footer>
      <Button variant="secondary" size="sm" onClick={onClose}>Close</Button>
    </Footer>
  );

  if (error !== null || detail === null) {
    return (
      <>
        {header}
        <Body>
          {error !== null ? (
            <Alert variant="warning" className="mb-0">{error}</Alert>
          ) : (
            <div className="text-center text-muted py-5">
              {loading && (
                <>
                  <Spinner animation="border" size="sm" className="mr-2" />
                  Loading prompt...
                </>
              )}
            </div>
          )}
        </Body>
        {closeFooter}
      </>
    );
  }

  const openLabel = SOURCE_OPEN_LABELS[detail.sourceType];
  const readOnlyNote = detail.editable ? '' : readOnlyText(detail.readOnlyReason) || 'This prompt is read-only.';
  const author = detail.updatedBy ? ` by ${detail.updatedBy}` : '';
  const languages = detail.sourceType === 'style_field' && detail.languages ? detail.languages : [];
  const multi = languages.length > 1;

  const openLink = openLabel !== null && detail.goToUrl !== null ? (
    <a className="btn btn-outline-secondary btn-sm" href={detail.goToUrl}>
      <i className="fas fa-external-link-alt mr-1" aria-hidden="true"></i>
      {openLabel}
    </a>
  ) : openLabel !== null ? (
    <span className="small text-muted">
      {detail.orphaned ? 'Nothing uses this prompt, so there is no page to open.' : 'There is no page to open this prompt on.'}
    </span>
  ) : null;

  const intro = (
    <>
      <section className="mb-3">
        <div className="small font-weight-bold text-uppercase text-muted mb-1">Where it is used</div>
        <dl className="llm-prompts-facts mb-0">
          {detail.sourceType !== 'system' && (
            <>
              <dt>Used by</dt>
              <dd>{detail.ownerLabel}</dd>
            </>
          )}
          {detail.hostPages.length > 0 && (
            <>
              <dt>{detail.hostPages.length > 1 ? 'Pages' : 'Page'}</dt>
              <dd>{detail.hostPages.map((page) => page.label).join(', ')}</dd>
            </>
          )}
          {detail.sourceType === 'system' && (
            <>
              <dt>Asset key</dt>
              <dd><code>{detail.promptSlot}</code></dd>
              <dt>File</dt>
              <dd><code>{detail.assetPath ?? '—'}</code></dd>
            </>
          )}
          {!multi && detail.languageId !== null && (
            <>
              <dt>Language</dt>
              <dd>{languageLabel(detail)}</dd>
            </>
          )}
          {!multi && detail.sourceType !== 'system' && detail.sourceType !== 'agentic_persona' && (
            <>
              <dt>Version</dt>
              <dd>
                {versionLabel(detail)}
                {detail.updatedAt && <span className="text-muted"> &middot; {formatWhen(detail.updatedAt)}{author}</span>}
                <UpdatedInfo placement="right" />
              </dd>
            </>
          )}
        </dl>
      </section>
      {readOnlyNote !== '' && (
        <Alert variant="light" className="small py-2 border">
          <i className="fas fa-lock mr-1" aria-hidden="true"></i>
          {readOnlyNote}
        </Alert>
      )}
    </>
  );

  if (REGISTRY_SOURCES.includes(detail.sourceType)) {
    // One editor per field: another language of the same field is the same editor
    const fieldKey = `${detail.sourceType}:${detail.ownerId}:${detail.promptSlot}`;
    return (
      <>
        {header}
        <PromptsFieldEditor
          key={fieldKey}
          detail={detail}
          shell={shell}
          intro={intro}
          footerStart={openLink}
          onSaved={onSaved}
          onDirtyChange={onDirtyChange}
          onClose={onClose}
        />
      </>
    );
  }

  return (
    <>
      {header}
      <Body>
        {intro}
        <PromptEditor
          value={detail.content}
          onChange={noChange}
          disabled
          editorMode="monaco"
          language="markdown"
          minHeight={420}
          rows={18}
          placeholder="This prompt is empty."
        />
        <div className="small text-muted mt-1">{detail.contentLength} characters</div>
      </Body>
      <Footer>
        <div className="mr-auto">{openLink}</div>
        <Button variant="secondary" size="sm" onClick={onClose}>Close</Button>
      </Footer>
    </>
  );
};
