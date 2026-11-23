/**
 * Prompts hub types
 * =================
 * Shapes of the answers of ModuleLlmPromptsController (`list`, `get`, `get_version`)
 * and of the UI state that is mirrored into the page URL.
 *
 * @module components/promptsHub/promptsHubTypes
 */

/** Where a prompt comes from. The order is the order of the list. */
export type PromptSourceType = 'style_field' | 'llm_script' | 'llm_memory_rule' | 'agentic_persona' | 'system';

/** Why a prompt cannot be edited here (null = it can). */
export type ReadOnlyReason = 'system_prompt' | 'persona_link_only' | 'no_access' | 'orphaned';

/** A page a CMS section is shown on. */
export interface PromptHostPage {
  id: number;
  label: string;
}

/** A search hit: the excerpt around it and the line it is on. */
export interface PromptSnippet {
  line: number;
  text: string;
}

/** One row of the list. */
export interface PromptRow {
  /** `<sourceType>:<ownerId>:<promptSlot>:<languageId>`, the stable id of the row */
  key: string;
  sourceType: PromptSourceType;
  plugin: string;
  title: string;
  ownerLabel: string;
  pageLabel: string | null;
  /** 1 = all languages, null = not language specific */
  languageId: number | null;
  languageCode: string | null;
  preview: string;
  contentLength: number;
  activeVersionNo: number | null;
  activeVersionId: number | null;
  updatedAt: string | null;
  updatedBy: string | null;
  editable: boolean;
  readOnlyReason: ReadOnlyReason | null;
  orphaned: boolean;
  /** Page that shows the prompt in its own editor */
  goToUrl: string | null;
  matches: PromptSnippet[];
  ownerId: number;
  promptSlot: string;
  styleName: string | null;
  /** System prompts: path of the file in the plugin */
  assetPath: string | null;
  hostPages: PromptHostPage[];
}

/** The values the filters can take, for the current search text. */
export interface PromptFacets {
  plugins: string[];
  styles: string[];
  languages: Array<{ id: number; code: string }>;
  sourceTypes: PromptSourceType[];
  pages: PromptHostPage[];
}

/** Answer of `list`. */
export interface PromptListResponse {
  rows: PromptRow[];
  /** Rows matching the filters, also those beyond this page */
  total: number;
  facets: PromptFacets;
}

/** One entry of the version history of a prompt. */
export interface PromptVersionSummary {
  id: number;
  no: number;
  created_at: string | null;
  author: string | null;
  change_note: string | null;
}

/** Answer of `get`: the row plus the full text and the history. */
export interface PromptDetail extends PromptRow {
  content: string;
  /** sha256 of `content` */
  contentHash: string;
  versions: PromptVersionSummary[];
  /** Style fields: the stored meta of the field */
  meta?: Record<string, unknown> | null;
  /** Style fields: every language the CMS has for this field of the section (this one included) */
  languages?: PromptLanguage[];
}

/** One language of a CMS prompt field, and the key of its prompt. */
export interface PromptLanguage {
  id: number;
  code: string;
  key: string;
}

/** Answer of `get_version`. */
export interface PromptVersionText {
  version_no: number;
  template_raw: string;
  created_at: string | null;
  change_note: string | null;
}

/**
 * What the editor knew about a prompt when it was loaded. A save carries it, so a prompt that
 * changed in between is found (409) instead of being overwritten.
 */
export interface PromptBaseline {
  /** Active version id, 0 = the prompt had no version yet */
  versionId: number;
  /** sha256 of the text at the source */
  contentHash: string;
}

/** What is live now: the body of a refused save or restore (409). */
export interface PromptLiveState {
  version_id: number | null;
  version_no: number | null;
  content: string;
  content_hash: string;
  updated_at: string | null;
  updated_by: string | null;
}

/** Answer of `save` and `restore`: the prompt as it is now. */
export interface PromptSaveResponse {
  ok: boolean;
  /** false = the text was already live, nothing was written */
  changed: boolean;
  row: PromptDetail;
}

/** One language of a "save all". */
export interface PromptSaveItem {
  key: string;
  content: string;
  changeNote: string;
  baseline: PromptBaseline;
}

/** Answer of `save_all`: the languages as they are now, in the order of the request. */
export interface PromptSaveAllResponse {
  ok: boolean;
  /** false = every text was already live, nothing was written */
  changed: boolean;
  rows: PromptDetail[];
}

/** What the user chose in the filter bar. Empty string = no restriction. */
export interface PromptFilters {
  /** The applied search text (at least MIN_QUERY_LENGTH characters, or empty) */
  q: string;
  sourceType: PromptSourceType | '';
  plugin: string;
  style: string;
  /** Page id as digits */
  pageId: string;
  /** Language id as digits */
  languageId: string;
  includeOrphaned: boolean;
}

/** The part of the UI state that lives in the page URL. */
export interface HubUrlState {
  filters: PromptFilters;
  /** The prompt open in the detail panel */
  key: string | null;
  /** Page of the list (1-based) */
  listPage: number;
  /** Rows per page (one of PAGE_SIZES) */
  pageSize: number;
}

/** Configuration the PHP view hands to the page. */
export interface PromptsHubConfig {
  csrfToken?: string;
  pageUrl?: string;
  pageId?: number | null;
  basePath?: string;
}
