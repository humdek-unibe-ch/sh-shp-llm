/**
 * Prompts hub editor: the text of a prompt of the registry (CMS prompt field, script, memory
 * rule), rendered into the body and the footer of the dialog (or side panel) that holds it.
 *
 * A CMS prompt field has one text per language, like the CMS form. Small language tabs sit on top
 * of the text: a tab shows the text, version, history and version comment of its language; the
 * text area always has the full width. One **Save** in the footer saves every language with
 * unsaved changes together (`save_all`, all or none). Scripts and memory rules have one text and
 * no tabs.
 *
 * A save is a new version that is live at once. Every save carries what the editor loaded per
 * language (the active version id and the hash of the text); if a language is not that any more
 * the server refuses the whole save with a conflict for that language and the user decides, so
 * nothing is ever overwritten silently.
 *
 * Mounted once per prompt field (`key`): its state is the editing state of that field.
 *
 * @module components/promptsHub/PromptsFieldEditor
 */
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Alert, Badge, Button, Form, Modal, Nav, Spinner } from 'react-bootstrap';
import { PromptDiffModal } from '../prompts/PromptDiffModal';
import { PromptEditor } from '../prompts/PromptEditor';
import { PromptVersionsModal } from '../prompts/PromptVersionsModal';
import { PromptConflictModal } from './PromptConflictModal';
import { conflictKeyOf, liveStateOf, promptsHubApi } from './promptsHubApi';
import type { PromptBaseline, PromptDetail, PromptLanguage, PromptLiveState } from './promptsHubTypes';
import {
  MAX_CHANGE_NOTE_LENGTH,
  baselineFromLive,
  baselineOf,
  formatWhen,
  isDirty,
  languageLabel,
  savedMessage,
  toLf,
  toVersionRow,
} from './promptsHubUtils';

/** Where the editor renders: the body and the footer of a modal or of a card. */
export interface PromptsShell {
  Body: React.FC<{ children: React.ReactNode }>;
  Footer: React.FC<{ children: React.ReactNode }>;
}

interface PromptsFieldEditorProps {
  /** The prompt that was opened (one language of a CMS field) */
  detail: PromptDetail;
  shell: PromptsShell;
  /** Shown at the top of the body (where the prompt is used) */
  intro: React.ReactNode;
  /** Shown at the start of the footer (the "Open in CMS" link) */
  footerStart: React.ReactNode;
  /** A prompt as the server has it now (after a save, a restore or a reload) */
  onSaved: (saved: PromptDetail) => void;
  /** Whether any language holds text that is not saved; the page asks before it leaves the prompt */
  onDirtyChange: (dirty: boolean) => void;
  /** Close the prompt (asks first when something is not saved) */
  onClose: () => void;
}

/** The editing state of one language. */
interface Entry {
  /** null while it loads */
  detail: PromptDetail | null;
  error: string | null;
  draft: string;
  note: string;
  /** What a save is checked against */
  baseline: PromptBaseline | null;
  /** Set after "Keep editing": what the next save replaces */
  replacing: PromptLiveState | null;
}

/** How long the "saved" notice stays. */
const NOTICE_MS = 8000;

/** What the user can read of a failed request. */
function failureText(error: unknown): string {
  return error instanceof Error && error.message !== '' ? error.message : 'The request failed.';
}

/** A language as it was loaded from the server, nothing edited. */
function entryOf(detail: PromptDetail): Entry {
  return { detail, error: null, draft: detail.content, note: '', baseline: baselineOf(detail), replacing: null };
}

/** The editor of one prompt field. */
export const PromptsFieldEditor: React.FC<PromptsFieldEditorProps> = ({
  detail,
  shell,
  intro,
  footerStart,
  onSaved,
  onDirtyChange,
  onClose,
}) => {
  const { Body, Footer } = shell;

  /** The languages of the field; a script or memory rule (or a one-language field) has one */
  const languages: PromptLanguage[] = useMemo(() => {
    if (detail.sourceType === 'style_field' && detail.languages && detail.languages.length > 1) {
      return detail.languages;
    }
    return [{ id: detail.languageId ?? 0, code: languageLabel(detail), key: detail.key }];
    // The languages of a field do not change while it is open
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  const multi = languages.length > 1;

  const [entries, setEntries] = useState<Record<string, Entry>>(() => {
    const initial: Record<string, Entry> = {};
    languages.forEach((language) => {
      initial[language.key] = language.key === detail.key
        ? entryOf(detail)
        : { detail: null, error: null, draft: '', note: '', baseline: null, replacing: null };
    });
    return initial;
  });
  const [activeKey, setActiveKey] = useState(detail.key);
  const [busy, setBusy] = useState<'save' | 'restore' | 'reload' | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [conflict, setConflict] = useState<{ key: string; live: PromptLiveState; action: 'save' | 'restore' } | null>(null);
  const [showVersions, setShowVersions] = useState(false);
  const [restoreTarget, setRestoreTarget] = useState<{ id: number; version_no: number } | null>(null);
  const [diff, setDiff] = useState({ show: false, left: 'draft', right: 'draft' });

  const updateEntry = useCallback((key: string, change: Partial<Entry>) => {
    setEntries((current) => ({ ...current, [key]: { ...current[key], ...change } }));
  }, []);

  /** A language as the server has it now: its editor starts over from it, the list follows */
  const applySaved = useCallback((saved: PromptDetail) => {
    setEntries((current) => ({ ...current, [saved.key]: entryOf(saved) }));
    onSaved(saved);
  }, [onSaved]);

  // The other languages of the field
  useEffect(() => {
    const controller = new AbortController();
    languages.filter((language) => language.key !== detail.key).forEach((language) => {
      promptsHubApi
        .get(language.key, controller.signal)
        .then((result) => {
          if (!controller.signal.aborted) {
            setEntries((current) => ({ ...current, [language.key]: entryOf(result) }));
          }
        })
        .catch((error: unknown) => {
          if (!controller.signal.aborted) {
            updateEntry(language.key, { error: failureText(error) });
          }
        });
    });
    return () => controller.abort();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const dirtyKeys = languages
    .map((language) => language.key)
    .filter((key) => {
      const entry = entries[key];
      return entry.detail !== null && entry.detail.editable && isDirty(entry.draft, entry.detail.content);
    });
  const dirty = dirtyKeys.length > 0;

  useEffect(() => {
    onDirtyChange(dirty);
  }, [dirty, onDirtyChange]);
  useEffect(() => () => onDirtyChange(false), [onDirtyChange]);

  useEffect(() => {
    if (notice === null) {
      return undefined;
    }
    const timer = window.setTimeout(() => setNotice(null), NOTICE_MS);
    return () => window.clearTimeout(timer);
  }, [notice]);

  const active = entries[activeKey];
  const activeDetail = active.detail;
  const activeLanguage = languages.find((language) => language.key === activeKey) ?? languages[0];
  const editable = activeDetail !== null && activeDetail.editable;
  const versionRows = useMemo(() => (activeDetail ? activeDetail.versions.map(toVersionRow) : []), [activeDetail]);

  /** The API the compare dialog fetches the text of a version with */
  const diffApi = useMemo(() => ({
    getVersion: async (versionId: number) => {
      const version = await promptsHubApi.getVersion(activeKey, versionId);
      return {
        id: versionId,
        version_no: version.version_no,
        created_at: version.created_at ?? '',
        change_note: version.change_note,
        template_raw: version.template_raw,
      };
    },
  }), [activeKey]);

  const descriptor = useMemo(() => (activeDetail === null ? null : {
    ownerType: activeDetail.sourceType as 'style_field' | 'llm_script' | 'llm_memory_rule',
    ownerId: activeDetail.ownerId,
    promptSlot: activeDetail.promptSlot,
    languageId: activeDetail.languageId,
    title: activeDetail.title,
  }), [activeDetail]);

  const failed = (error: unknown, action: 'save' | 'restore', fallbackKey: string) => {
    const live = liveStateOf(error);
    if (live !== null) {
      const key = conflictKeyOf(error) ?? fallbackKey;
      setActiveKey(key);
      setConflict({ key, live, action });
      return;
    }
    setFailure(failureText(error));
  };

  const codeOf = (key: string): string => languages.find((language) => language.key === key)?.code ?? key;

  const save = async () => {
    if (!dirty || busy !== null) {
      return;
    }
    setBusy('save');
    setFailure(null);
    try {
      const result = await promptsHubApi.saveAll(dirtyKeys.map((key) => ({
        key,
        content: entries[key].draft,
        changeNote: entries[key].note,
        baseline: entries[key].baseline as PromptBaseline,
      })));
      result.rows.forEach(applySaved);
      if (!result.changed) {
        setNotice('Nothing changed: these texts are already live');
      } else if (multi) {
        setNotice('Saved ' + result.rows.map((row) => `${codeOf(row.key)} as v${row.activeVersionNo ?? '?'}`).join(', ') + ' — live now');
      } else {
        setNotice(savedMessage({ ok: true, changed: true, row: result.rows[0] }));
      }
    } catch (error) {
      failed(error, 'save', dirtyKeys[0]);
    } finally {
      setBusy(null);
    }
  };

  const restore = async () => {
    if (restoreTarget === null || active.baseline === null) {
      return;
    }
    const target = restoreTarget;
    const key = activeKey;
    setBusy('restore');
    setFailure(null);
    try {
      const result = await promptsHubApi.restore(key, target.id, active.baseline);
      setRestoreTarget(null);
      applySaved(result.row);
      setNotice((multi ? codeOf(key) + ': ' : '') + savedMessage(result, target.version_no));
    } catch (error) {
      setRestoreTarget(null);
      failed(error, 'restore', key);
    } finally {
      setBusy(null);
    }
  };

  const reloadTheirs = async () => {
    if (conflict === null) {
      return;
    }
    setBusy('reload');
    try {
      applySaved(await promptsHubApi.get(conflict.key));
      setConflict(null);
      setNotice((multi ? codeOf(conflict.key) + ': ' : '') + 'loaded what is live now.');
    } catch (error) {
      setFailure(failureText(error));
    } finally {
      setBusy(null);
    }
  };

  const keepEditing = () => {
    if (conflict === null) {
      return;
    }
    updateEntry(conflict.key, { baseline: baselineFromLive(conflict.live), replacing: conflict.live });
    setConflict(null);
  };

  const discardAll = () => {
    setEntries((current) => {
      const next = { ...current };
      dirtyKeys.forEach((key) => {
        const entry = current[key];
        next[key] = { ...entry, draft: entry.detail ? entry.detail.content : '', note: '' };
      });
      return next;
    });
  };

  const compareWith = (versionId: number | null) => {
    setShowVersions(false);
    setDiff({ show: true, left: versionId === null ? 'draft' : `v:${versionId}`, right: 'draft' });
  };

  const activeDirty = dirtyKeys.includes(activeKey);
  const characters = activeDetail === null ? 0 : (activeDirty ? toLf(active.draft).length : activeDetail.contentLength);
  const noteId = 'llm-prompts-change-note-' + activeKey.replace(/[^A-Za-z0-9_-]/g, '-');

  /** Version, date and history buttons of the language shown */
  const toolbar = activeDetail !== null && (
    <div className="d-flex justify-content-between align-items-center flex-wrap">
      <div className="mb-1">
        {activeDetail.activeVersionNo !== null && activeDetail.activeVersionNo > 0 ? (
          <Badge variant="secondary" className="mr-2">v{activeDetail.activeVersionNo}</Badge>
        ) : (
          <Badge variant="light" className="mr-2 border">No version</Badge>
        )}
        {activeDirty && <Badge variant="warning" className="mr-2">Unsaved changes</Badge>}
        {activeDetail.updatedAt && (
          <span className="small text-muted">
            {formatWhen(activeDetail.updatedAt)}
            {activeDetail.updatedBy ? ` by ${activeDetail.updatedBy}` : ''}
          </span>
        )}
      </div>
      <div className="btn-group btn-group-sm mb-1">
        <Button variant="outline-secondary" onClick={() => setShowVersions(true)} disabled={busy !== null}>
          <i className="fas fa-history mr-1" aria-hidden="true"></i>
          Versions
        </Button>
        <Button
          variant="outline-secondary"
          onClick={() => compareWith(activeDetail.activeVersionId)}
          disabled={busy !== null || activeDetail.activeVersionId === null}
          title={activeDetail.activeVersionId === null ? 'There is no saved version to compare with yet' : undefined}
        >
          <i className="fas fa-code-branch mr-1" aria-hidden="true"></i>
          Compare with active
        </Button>
      </div>
    </div>
  );

  return (
    <>
      <Body>
        {intro}

        {notice !== null && (
          <Alert variant="success" dismissible className="py-2 small" onClose={() => setNotice(null)} role="status">
            <i className="fas fa-check mr-1" aria-hidden="true"></i>
            {notice}
          </Alert>
        )}
        {failure !== null && (
          <Alert variant="danger" dismissible className="py-2 small" onClose={() => setFailure(null)}>
            {failure}
          </Alert>
        )}

        {multi && (
          <Nav variant="tabs" className="llm-prompts-lang-tabs" activeKey={activeKey} onSelect={(key) => key && setActiveKey(key)}>
            {languages.map((language) => (
              <Nav.Item key={language.key}>
                <Nav.Link eventKey={language.key}>
                  {language.code}
                  {dirtyKeys.includes(language.key) && (
                    <span className="llm-prompts-dirty-dot ml-1" title="Unsaved changes" aria-label="unsaved changes"></span>
                  )}
                  {entries[language.key].detail !== null && entries[language.key].detail?.content === '' && !dirtyKeys.includes(language.key) && (
                    <span className="text-muted ml-1">(empty)</span>
                  )}
                </Nav.Link>
              </Nav.Item>
            ))}
          </Nav>
        )}

        <div className={multi ? 'llm-prompts-lang-pane border border-top-0 rounded-bottom p-2' : 'llm-prompts-lang-pane'}>
          {active.error !== null ? (
            <Alert variant="warning" className="small mb-0">{active.error}</Alert>
          ) : activeDetail === null ? (
            <div className="text-center text-muted small py-5">
              <Spinner animation="border" size="sm" className="mr-2" />
              Loading {activeLanguage.code}...
            </div>
          ) : (
            <>
              {active.replacing !== null && (
                <Alert variant="warning" className="py-2 small">
                  Someone else saved {active.replacing.version_no ? `v${active.replacing.version_no}` : 'a new text'}
                  {active.replacing.updated_by ? ` (${active.replacing.updated_by})` : ''} while you were editing. Your text is
                  kept: the next Save replaces it.
                </Alert>
              )}
              {toolbar}
              {editable && (
                <Form.Group className="mb-2" controlId={noteId}>
                  <Form.Label className="small text-muted mb-1">Version comment (optional)</Form.Label>
                  <Form.Control
                    size="sm"
                    type="text"
                    maxLength={MAX_CHANGE_NOTE_LENGTH}
                    value={active.note}
                    disabled={busy !== null}
                    onChange={(event) => updateEntry(activeKey, { note: event.target.value })}
                    placeholder="What did you change, and why?"
                  />
                </Form.Group>
              )}
              {/* One editor per language (key): its undo history stays with its text */}
              <PromptEditor
                key={activeKey}
                value={active.draft}
                onChange={(value) => updateEntry(activeKey, { draft: value })}
                disabled={!editable || busy !== null}
                editorMode="monaco"
                language="markdown"
                minHeight={420}
                rows={18}
                placeholder="This prompt is empty."
              />
              <div className="small text-muted mt-1">
                {characters} characters
                {editable && <span> &middot; a saved text is live immediately</span>}
              </div>
            </>
          )}
        </div>
      </Body>

      <Footer>
        <div className="mr-auto">{footerStart}</div>
        {dirty && multi && (
          <span className="small text-muted mr-2">
            Unsaved: {dirtyKeys.map(codeOf).join(', ')}
          </span>
        )}
        {dirty && (
          <Button variant="link" size="sm" disabled={busy !== null} onClick={discardAll}>
            Discard changes
          </Button>
        )}
        <Button variant="secondary" size="sm" disabled={busy !== null} onClick={onClose}>
          {dirty ? 'Cancel' : 'Close'}
        </Button>
        {editable && (
          <Button variant="primary" size="sm" disabled={!dirty || busy !== null} onClick={save}>
            {busy === 'save' ? (
              <>
                <Spinner animation="border" size="sm" className="mr-1" />
                Saving...
              </>
            ) : (
              <>
                <i className="fas fa-save mr-1" aria-hidden="true"></i>
                {dirtyKeys.length > 1 ? `Save ${dirtyKeys.length} languages` : 'Save'}
              </>
            )}
          </Button>
        )}
      </Footer>

      {activeDetail !== null && (
        <PromptVersionsModal
          show={showVersions}
          onHide={() => setShowVersions(false)}
          versions={versionRows}
          activeVersionId={activeDetail.activeVersionId}
          disabled={busy !== null}
          useDisabled={!editable}
          useLabel="Restore"
          onUseVersion={(version) => {
            setShowVersions(false);
            setRestoreTarget({ id: version.id, version_no: version.version_no });
          }}
          onCompareVersion={(version) => compareWith(version.id)}
        />
      )}

      {descriptor !== null && (
        <PromptDiffModal
          show={diff.show}
          onHide={() => setDiff((current) => ({ ...current, show: false }))}
          api={diffApi}
          descriptor={descriptor}
          versions={versionRows}
          draftContent={toLf(active.draft)}
          initialLeftKey={diff.left}
          initialRightKey={diff.right}
        />
      )}

      <Modal show={restoreTarget !== null} onHide={() => (busy === null ? setRestoreTarget(null) : undefined)} centered>
        <Modal.Header closeButton className="py-2">
          <Modal.Title className="h6">
            Restore v{restoreTarget?.version_no}{multi ? ` (${activeLanguage.code})` : ''}?
          </Modal.Title>
        </Modal.Header>
        <Modal.Body className="small">
          <p className={activeDirty ? '' : 'mb-0'}>
            The text of v{restoreTarget?.version_no} is saved as a new version and is live immediately. The history stays as it is.
          </p>
          {activeDirty && <p className="text-danger mb-0">The unsaved changes of this language are discarded.</p>}
        </Modal.Body>
        <Modal.Footer className="py-2">
          <Button variant="secondary" size="sm" disabled={busy !== null} onClick={() => setRestoreTarget(null)}>
            Cancel
          </Button>
          <Button variant="primary" size="sm" disabled={busy !== null} onClick={restore}>
            {busy === 'restore' ? (
              <>
                <Spinner animation="border" size="sm" className="mr-1" />
                Restoring...
              </>
            ) : (
              'Restore'
            )}
          </Button>
        </Modal.Footer>
      </Modal>

      {conflict !== null && (
        <PromptConflictModal
          live={conflict.live}
          mine={conflict.action === 'save' ? entries[conflict.key].draft : null}
          busy={busy === 'reload'}
          onReload={reloadTheirs}
          onKeepEditing={conflict.action === 'save' ? keepEditing : null}
          onCancel={() => setConflict(null)}
        />
      )}
    </>
  );
};
