/**
 * Prompts hub helpers
 * ===================
 * Pure functions, no React and no DOM access (tested under Node in
 * tests/promptsHub.test.mjs): the filter state and the page URL it is mirrored
 * into, the parameters of the `list` request, labels, and the search-hit
 * highlighting.
 *
 * @module components/promptsHub/promptsHubUtils
 */
import type {
  HubUrlState,
  PromptBaseline,
  PromptDetail,
  PromptFilters,
  PromptLiveState,
  PromptRow,
  PromptSaveResponse,
  PromptSourceType,
  PromptVersionSummary,
  ReadOnlyReason,
} from './promptsHubTypes';

/** A search shorter than this is not sent (it would match nearly everything). */
export const MIN_QUERY_LENGTH = 2;

/** Longest search text the server accepts. */
export const MAX_QUERY_LENGTH = 200;

/** Rows per page of the list, unless the user picks another size. */
export const PAGE_SIZE = 25;

/** The page sizes the user can pick. */
export const PAGE_SIZES = [25, 50, 100];

/** The order of the list. */
export const SOURCE_ORDER: PromptSourceType[] = ['style_field', 'llm_script', 'llm_memory_rule', 'agentic_persona', 'system'];

export const SOURCE_LABELS: Record<PromptSourceType, string> = {
  style_field: 'Style field',
  llm_script: 'Script',
  llm_memory_rule: 'Memory rule',
  agentic_persona: 'Persona',
  system: 'System',
};

/** Bootstrap badge variant of a source. */
export const SOURCE_BADGE_VARIANT: Record<PromptSourceType, string> = {
  style_field: 'primary',
  llm_script: 'info',
  llm_memory_rule: 'success',
  agentic_persona: 'warning',
  system: 'secondary',
};

/** Text of the button that opens a prompt where it is used (null = nowhere to go). */
export const SOURCE_OPEN_LABELS: Record<PromptSourceType, string | null> = {
  style_field: 'Open in CMS',
  llm_script: 'Open in Scripts',
  llm_memory_rule: 'Open in Memory',
  agentic_persona: 'Open agentic chat settings',
  system: null,
};

const READ_ONLY_TEXT: Record<ReadOnlyReason, string> = {
  system_prompt: 'Built-in system prompt. It ships with the plugin and cannot be edited here.',
  persona_link_only: 'Personas are edited in the agentic chat settings.',
  no_access: 'You can read this prompt, but you do not have update access to change it.',
  orphaned: 'Nothing uses this prompt any more (its section is on no page, or its section, script or rule was deleted).',
};

export const EMPTY_FILTERS: PromptFilters = {
  q: '',
  sourceType: '',
  plugin: '',
  style: '',
  pageId: '',
  languageId: '',
  includeOrphaned: false,
};

const DIGITS = /^[0-9]{1,9}$/;

/** The server decides what a key is; this only keeps junk out of the requests and the URL. */
const KEY_FORMAT = /^[A-Za-z0-9_.:-]{1,300}$/;

/** Whether a value is one of the known sources. */
export function isSourceType(value: unknown): value is PromptSourceType {
  return typeof value === 'string' && (SOURCE_ORDER as string[]).includes(value);
}

/**
 * The search text that is applied: trimmed, and empty while it is too short.
 *
 * @param raw What is in the search box
 */
export function effectiveQuery(raw: string): string {
  const text = raw.trim().slice(0, MAX_QUERY_LENGTH);
  return text.length >= MIN_QUERY_LENGTH ? text : '';
}

/** Whether anything restricts the list (the search text or a filter). */
export function hasActiveFilters(filters: PromptFilters): boolean {
  return Boolean(
    filters.q || filters.sourceType || filters.plugin || filters.style
    || filters.pageId || filters.languageId || filters.includeOrphaned
  );
}

/**
 * Query parameters of a `list` request. Empty filters are left out.
 *
 * @param filters The applied filters
 * @param offset Rows to skip (the rows of the pages before)
 * @param limit Rows to return
 */
export function filtersToParams(filters: PromptFilters, offset = 0, limit = PAGE_SIZE): Record<string, string> {
  const params: Record<string, string> = { limit: String(limit), offset: String(offset) };
  const q = effectiveQuery(filters.q);
  if (q) params.q = q;
  if (filters.sourceType) params.sourceType = filters.sourceType;
  if (filters.plugin) params.plugin = filters.plugin;
  if (filters.style) params.style = filters.style;
  if (filters.pageId) params.pageId = filters.pageId;
  if (filters.languageId) params.languageId = filters.languageId;
  if (filters.includeOrphaned) params.includeOrphaned = '1';
  return params;
}

/**
 * The UI state in a page URL (`location.search`). Anything that is not a valid value reads as
 * "not set", so a hand-edited link never breaks the page.
 *
 * @param search `location.search`
 */
export function readHubState(search: string): HubUrlState {
  const params = new URLSearchParams(search);
  const text = (name: string): string => (params.get(name) ?? '').trim().slice(0, 100);
  const digits = (name: string): string => {
    const value = text(name);
    return DIGITS.test(value) ? value : '';
  };
  const source = text('source');
  const orphaned = text('orphaned');
  const key = (params.get('key') ?? '').trim();
  const listPage = Number(digits('p'));
  const pageSize = Number(digits('size'));

  return {
    filters: {
      q: effectiveQuery(params.get('q') ?? ''),
      sourceType: isSourceType(source) ? source : '',
      plugin: text('plugin'),
      style: text('style'),
      pageId: digits('page'),
      languageId: digits('lang'),
      includeOrphaned: orphaned === '1' || orphaned === 'true',
    },
    key: KEY_FORMAT.test(key) ? key : null,
    listPage: listPage >= 1 ? listPage : 1,
    pageSize: PAGE_SIZES.includes(pageSize) ? pageSize : PAGE_SIZE,
  };
}

/**
 * The query string for a UI state: what readHubState() reads back. Empty values are left out.
 *
 * @returns `?a=b&...`, or '' when nothing is set
 */
export function writeHubSearch(state: HubUrlState): string {
  const filters = state.filters;
  const params = new URLSearchParams();
  if (filters.q) params.set('q', filters.q);
  if (filters.sourceType) params.set('source', filters.sourceType);
  if (filters.plugin) params.set('plugin', filters.plugin);
  if (filters.style) params.set('style', filters.style);
  if (filters.pageId) params.set('page', filters.pageId);
  if (filters.languageId) params.set('lang', filters.languageId);
  if (filters.includeOrphaned) params.set('orphaned', '1');
  if (state.key) params.set('key', state.key);
  if (state.listPage > 1) params.set('p', String(state.listPage));
  if (state.pageSize !== PAGE_SIZE) params.set('size', String(state.pageSize));
  const text = params.toString();
  return text ? '?' + text : '';
}

/** The query parameters of the page URL that belong to the hub. */
const HUB_URL_PARAMS = ['q', 'source', 'plugin', 'style', 'page', 'lang', 'orphaned', 'key', 'p', 'size'];

/**
 * A page URL's query string with the hub's parameters replaced by the current state; any other
 * parameter of the page is kept.
 *
 * @param search `location.search`
 * @param state The UI state to write
 * @returns `?a=b&...`, or '' when nothing is left
 */
export function mergeHubSearch(search: string, state: HubUrlState): string {
  const params = new URLSearchParams(search);
  HUB_URL_PARAMS.forEach((name) => params.delete(name));
  new URLSearchParams(writeHubSearch(state)).forEach((value, name) => params.set(name, value));
  const text = params.toString();
  return text ? '?' + text : '';
}

/** Language column / filter text: language 1 is "all languages", no language is a dash. */
export function languageLabel(row: Pick<PromptRow, 'languageId' | 'languageCode'>): string {
  if (row.languageId === null) return '\u2014';
  if (row.languageId === 1) return 'all languages';
  return row.languageCode || '#' + row.languageId;
}

/** Version column text: `v7`, or a dash while the prompt has no version. */
export function versionLabel(row: Pick<PromptRow, 'activeVersionNo'>): string {
  return row.activeVersionNo !== null && row.activeVersionNo > 0 ? 'v' + row.activeVersionNo : '\u2014';
}

/**
 * The "Where" column: what owns the prompt, and the page(s) it is on ("Page A (+2 more)", with
 * all pages as the tooltip).
 */
export function whereParts(row: Pick<PromptRow, 'ownerLabel' | 'hostPages'>): { owner: string; page: string | null; pageTitle: string | null } {
  const pages = row.hostPages;
  if (pages.length === 0) {
    return { owner: row.ownerLabel, page: null, pageTitle: null };
  }
  return {
    owner: row.ownerLabel,
    page: pages.length > 1 ? `${pages[0].label} (+${pages.length - 1} more)` : pages[0].label,
    pageTitle: pages.map((page) => page.label).join(', '),
  };
}

/** Tooltip / notice for a read-only prompt ('' while it can be edited). */
export function readOnlyText(reason: ReadOnlyReason | null): string {
  if (reason === null) return '';
  return READ_ONLY_TEXT[reason] ?? 'This prompt is read-only.';
}

/**
 * Cut a text into plain and matching parts, case-insensitive and literal ("%", "." and "(" in the
 * search are text). The parts always add up to the original text; the caller renders them as
 * React text and `<mark>` elements, so nothing in the text is ever interpreted as HTML.
 *
 * @param text The text of a snippet
 * @param query The applied search text
 */
export function splitHighlights(text: string, query: string): Array<{ text: string; match: boolean }> {
  if (!query || !text) {
    return [{ text, match: false }];
  }

  // Escapes the syntax characters only: any other escape is an error with the "u" flag
  const literal = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const parts: Array<{ text: string; match: boolean }> = [];
  let last = 0;
  for (const hit of text.matchAll(new RegExp(literal, 'giu'))) {
    const start = hit.index ?? 0;
    if (hit[0].length === 0) continue;
    if (start > last) parts.push({ text: text.slice(last, start), match: false });
    parts.push({ text: hit[0], match: true });
    last = start + hit[0].length;
  }
  if (last < text.length) parts.push({ text: text.slice(last), match: false });

  return parts.length > 0 ? parts : [{ text, match: false }];
}

/**
 * A database timestamp ("2026-11-04 10:00:00") for display.
 *
 * @returns The date in the browser's format, the original text if it is not a date, '' for null
 */
export function formatWhen(value: string | null): string {
  if (!value) return '';
  const date = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/** Number of pages of a list (at least 1, so "page 1 of 1" also for an empty list). */
export function pageCount(total: number, pageSize: number): number {
  return Math.max(1, Math.ceil(total / pageSize));
}

/**
 * The page numbers the pagination shows: the first, the last and the ones around the current
 * page, with null where pages are left out ("1 … 4 5 6 … 40").
 *
 * @param current The page shown (1-based)
 * @param pages Number of pages
 * @param around Pages shown on each side of the current one
 */
export function pageWindow(current: number, pages: number, around = 2): Array<number | null> {
  const shown = (page: number): boolean => page === 1 || page === pages || Math.abs(page - current) <= around;
  const items: Array<number | null> = [];
  for (let page = 1; page <= pages; page += 1) {
    // A gap of one page shows that page: "…" would take the same room
    if (shown(page) || (shown(page - 1) && shown(page + 1))) {
      items.push(page);
    } else if (items[items.length - 1] !== null) {
      items.push(null);
    }
  }
  return items;
}

/* ----------------------------------------------------------------------------------------
 * Editing
 * -------------------------------------------------------------------------------------- */

/** Longest version comment the server accepts. */
export const MAX_CHANGE_NOTE_LENGTH = 255;

/** The sources whose prompts live in the prompt registry: they have a version history and can be edited here. */
export const REGISTRY_SOURCES: PromptSourceType[] = ['style_field', 'llm_script', 'llm_memory_rule'];

/**
 * A text with LF line endings. The server stores LF; an editor on Windows may hand back CRLF
 * for the same text, which is not a change.
 */
export function toLf(text: string): string {
  return text.replace(/\r\n?/g, '\n');
}

/** Whether the text in the editor differs from the saved one (line endings do not count). */
export function isDirty(draft: string, saved: string): boolean {
  return toLf(draft) !== toLf(saved);
}

/** What a save is checked against, from the prompt as it was loaded. */
export function baselineOf(detail: Pick<PromptDetail, 'activeVersionId' | 'contentHash'>): PromptBaseline {
  return { versionId: detail.activeVersionId ?? 0, contentHash: detail.contentHash };
}

/** What a save is checked against after the user chose to keep editing over what someone else saved. */
export function baselineFromLive(live: PromptLiveState): PromptBaseline {
  return { versionId: live.version_id ?? 0, contentHash: live.content_hash };
}

/** A history entry the way the shared versions and compare dialogs list it. */
export function toVersionRow(version: PromptVersionSummary): {
  id: number;
  version_no: number;
  created_at: string;
  created_user_name: string | null;
  change_note: string | null;
} {
  return {
    id: version.id,
    version_no: version.no,
    created_at: version.created_at ?? '',
    created_user_name: version.author,
    change_note: version.change_note,
  };
}

/** The list row of a prompt: the detail without the parts only the detail has. */
export function rowOf(detail: PromptDetail): PromptRow {
  const { content, contentHash, versions, meta, languages, ...row } = detail;
  return row;
}

/**
 * What the toast says after a save or a restore.
 *
 * @param result The answer of the server
 * @param restoredNo The version that was restored, null for a save
 */
export function savedMessage(result: PromptSaveResponse, restoredNo: number | null = null): string {
  const live = result.row.activeVersionNo !== null && result.row.activeVersionNo > 0 ? 'v' + result.row.activeVersionNo : '';
  if (restoredNo !== null) {
    return result.changed
      ? `Restored v${restoredNo} as ${live || 'a new version'} \u2014 live now`
      : `v${restoredNo} is already the live text, nothing changed`;
  }
  return result.changed
    ? `Saved as ${live || 'a new version'} \u2014 live now`
    : `Nothing changed: this text is already live${live ? ' as ' + live : ''}`;
}
