/**
 * Prompts hub: every LLM prompt in one list, with search, filters and pages.
 *
 * What the user looks at lives in the page URL (`replaceState`, like the memory page): the
 * applied search text, the filters, the page of the list and the open prompt (`?key=`), so a
 * link can be shared. The list is requested again whenever the filters or the page change; an
 * older answer never replaces a newer one (each request is cancelled when the next one starts).
 * A change of the search text or of a filter goes back to the first page.
 *
 * On very wide screens a prompt opens in a side panel next to the list, otherwise in a modal.
 *
 * A prompt with unsaved text is never left silently: closing it, opening another one or leaving
 * the page asks first. A saved prompt replaces its row in the list (version, preview, who and
 * when) without loading the list again.
 *
 * @module components/promptsHub/PromptsHubApp
 */
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Alert, Badge, Button, Card, Col, Modal, Row } from 'react-bootstrap';
import './PromptsHub.css';
import { PromptsHubError, promptsHubApi } from './promptsHubApi';
import { PromptsDetailPanel } from './PromptsDetailPanel';
import { PromptsFilters } from './PromptsFilters';
import { PromptsList } from './PromptsList';
import type {
  PromptDetail,
  PromptFacets,
  PromptFilters,
  PromptRow,
  PromptsHubConfig,
} from './promptsHubTypes';
import {
  EMPTY_FILTERS,
  effectiveQuery,
  hasActiveFilters,
  mergeHubSearch,
  pageCount,
  readHubState,
  rowOf,
} from './promptsHubUtils';

/** Pause after the last keystroke before the search is applied. */
const SEARCH_DEBOUNCE_MS = 300;

/**
 * From this width a prompt opens next to the list instead of in a modal. The table needs about
 * 800px, the panel about 450px, and the admin sidebar takes 290px: on narrower screens the list
 * would lose its right-hand columns.
 */
const WIDE_QUERY = '(min-width: 1700px)';

const EMPTY_FACETS: PromptFacets = { plugins: [], styles: [], languages: [], sourceTypes: [], pages: [] };

/** Whether the screen is wide enough for the side panel. */
function useIsWide(): boolean {
  const [wide, setWide] = useState(() => window.matchMedia(WIDE_QUERY).matches);
  useEffect(() => {
    const media = window.matchMedia(WIDE_QUERY);
    const update = () => setWide(media.matches);
    media.addEventListener('change', update);
    update();
    return () => media.removeEventListener('change', update);
  }, []);
  return wide;
}

/** The text of a failed request. */
function messageOf(error: unknown): string {
  return error instanceof Error && error.message !== '' ? error.message : 'The request failed.';
}

/** The prompts hub page. */
export const PromptsHubApp: React.FC<{ config: PromptsHubConfig }> = () => {
  const initial = useMemo(() => readHubState(window.location.search), []);
  const wide = useIsWide();

  const [filters, setFilters] = useState<PromptFilters>(initial.filters);
  const [searchText, setSearchText] = useState(initial.filters.q);
  const [selectedKey, setSelectedKey] = useState<string | null>(initial.key);
  const [listPage, setListPage] = useState(initial.listPage);
  const [pageSize, setPageSize] = useState(initial.pageSize);

  const [rows, setRows] = useState<PromptRow[]>([]);
  const [total, setTotal] = useState(0);
  const [facets, setFacets] = useState<PromptFacets>(EMPTY_FACETS);
  /** The search text the rows on the screen were found with */
  const [listQuery, setListQuery] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [detail, setDetail] = useState<PromptDetail | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState<string | null>(null);

  /** The editor holds text that is not saved */
  const [dirty, setDirty] = useState(false);
  /** Where the user wanted to go when the unsaved text stopped them (null = closing the prompt) */
  const [leaving, setLeaving] = useState<{ key: string | null } | null>(null);

  // The search box applies its text after a pause, from the first page
  useEffect(() => {
    const next = effectiveQuery(searchText);
    if (next === filters.q) {
      return undefined;
    }
    const timer = window.setTimeout(() => {
      setFilters((current) => ({ ...current, q: next }));
      setListPage(1);
    }, SEARCH_DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
  }, [searchText, filters.q]);

  // The list follows the filters and the page
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);

    promptsHubApi
      .list(filters, (listPage - 1) * pageSize, pageSize, controller.signal)
      .then((result) => {
        if (controller.signal.aborted) {
          return;
        }
        // A page beyond the end (fewer prompts than when the link was made): the last page
        const last = pageCount(result.total, pageSize);
        if (listPage > last) {
          setListPage(last);
          return;
        }
        setRows(result.rows);
        setTotal(result.total);
        setFacets(result.facets);
        setListQuery(filters.q);
        setLoading(false);
      })
      .catch((failure: unknown) => {
        if (controller.signal.aborted) {
          return;
        }
        setError(messageOf(failure));
        setLoading(false);
      });

    return () => controller.abort();
  }, [filters, listPage, pageSize]);

  // The open prompt
  useEffect(() => {
    if (selectedKey === null) {
      setDetail(null);
      setDetailError(null);
      setDetailLoading(false);
      return undefined;
    }

    const controller = new AbortController();
    setDetail((current) => (current !== null && current.key === selectedKey ? current : null));
    setDetailError(null);
    setDetailLoading(true);

    promptsHubApi
      .get(selectedKey, controller.signal)
      .then((result) => {
        if (controller.signal.aborted) {
          return;
        }
        setDetail(result);
        setDetailLoading(false);
      })
      .catch((failure: unknown) => {
        if (controller.signal.aborted) {
          return;
        }
        setDetail(null);
        setDetailError(
          failure instanceof PromptsHubError && failure.status === 404
            ? 'This prompt does not exist (any more), or you may not see it.'
            : messageOf(failure)
        );
        setDetailLoading(false);
      });

    return () => controller.abort();
  }, [selectedKey]);

  // The page URL follows the state (other parameters of the page are kept)
  useEffect(() => {
    const search = mergeHubSearch(window.location.search, { filters, key: selectedKey, listPage, pageSize });
    window.history.replaceState(window.history.state, '', window.location.pathname + search + window.location.hash);
  }, [filters, selectedKey, listPage, pageSize]);

  const changeFilters = useCallback((change: Partial<PromptFilters>) => {
    setFilters((current) => ({ ...current, ...change }));
    setListPage(1);
  }, []);

  const clearAll = useCallback(() => {
    setSearchText('');
    setFilters(EMPTY_FILTERS);
    setListPage(1);
  }, []);

  const changePageSize = useCallback((size: number) => {
    setPageSize(size);
    setListPage(1);
  }, []);

  /** Open another prompt, or none; unsaved text asks first */
  const openPrompt = useCallback((key: string | null) => {
    if (key === selectedKey) {
      return;
    }
    if (dirty) {
      setLeaving({ key });
      return;
    }
    setSelectedKey(key);
  }, [dirty, selectedKey]);
  const closePrompt = useCallback(() => openPrompt(null), [openPrompt]);

  const discardAndLeave = () => {
    const target = leaving === null ? null : leaving.key;
    setLeaving(null);
    setDirty(false);
    setSelectedKey(target);
  };

  // The browser asks before the page is left with unsaved text
  useEffect(() => {
    if (!dirty) {
      return undefined;
    }
    const warn = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = '';
    };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  /**
   * A prompt as the server has it now (after a save, a restore or a reload): the open prompt and
   * its row. Also another language of the open prompt, whose row may be in the list too.
   */
  const promptChanged = useCallback((saved: PromptDetail) => {
    setDetail((current) => (current !== null && current.key === saved.key ? saved : current));
    const row = rowOf(saved);
    setRows((current) => current.map((item) => (item.key === saved.key ? { ...item, ...row } : item)));
  }, []);

  const panel = (variant: 'modal' | 'card') => (
    <PromptsDetailPanel
      detail={detail}
      loading={detailLoading}
      error={detailError}
      variant={variant}
      onClose={closePrompt}
      onSaved={promptChanged}
      onDirtyChange={setDirty}
    />
  );
  const sideBySide = selectedKey !== null && wide;

  return (
    <div className="llm-prompts-hub">
      <div className="d-flex align-items-center flex-wrap mb-1">
        <h4 className="text-dark mb-0 font-weight-bold">
          <i className="fas fa-file-alt mr-2 text-secondary" aria-hidden="true"></i>
          Prompts
        </h4>
        <Badge variant="secondary" className="ml-2">
          {total.toLocaleString()} {total === 1 ? 'prompt' : 'prompts'}
        </Badge>
      </div>
      <p className="text-muted small mb-3">
        Every LLM prompt in one place: CMS prompt fields, scripts, memory rules, built-in system prompts and personas.
      </p>

      <PromptsFilters
        searchText={searchText}
        onSearchText={setSearchText}
        filters={filters}
        facets={facets}
        onChange={changeFilters}
        onClear={clearAll}
      />

      {error !== null && (
        <Alert variant="danger" dismissible onClose={() => setError(null)}>
          <i className="fas fa-exclamation-triangle mr-2" aria-hidden="true"></i>
          {error}
        </Alert>
      )}

      <Row>
        <Col lg={sideBySide ? 8 : 12} className="mb-3">
          <PromptsList
            rows={rows}
            total={total}
            query={listQuery}
            selectedKey={selectedKey}
            loading={loading}
            hasFilters={hasActiveFilters(filters)}
            listPage={listPage}
            pageSize={pageSize}
            onSelect={openPrompt}
            onPage={setListPage}
            onPageSize={changePageSize}
            onClear={clearAll}
          />
        </Col>
        {sideBySide && (
          <Col lg={4} className="mb-3">
            <Card className="border llm-prompts-side-panel">{panel('card')}</Card>
          </Col>
        )}
      </Row>

      {selectedKey !== null && !wide && (
        <Modal show onHide={closePrompt} centered dialogClassName="prompt-modal-90 llm-prompts-modal">
          {panel('modal')}
        </Modal>
      )}

      <Modal show={leaving !== null} centered onHide={() => setLeaving(null)}>
        <Modal.Header closeButton className="py-2">
          <Modal.Title className="h6">Discard unsaved changes?</Modal.Title>
        </Modal.Header>
        <Modal.Body className="small">
          The text you edited is not saved. If you leave this prompt now, your changes are lost.
        </Modal.Body>
        <Modal.Footer className="py-2">
          <Button variant="secondary" size="sm" onClick={() => setLeaving(null)}>
            Keep editing
          </Button>
          <Button variant="danger" size="sm" onClick={discardAndLeave}>
            Discard changes
          </Button>
        </Modal.Footer>
      </Modal>
    </div>
  );
};
