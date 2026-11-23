/**
 * Prompts hub API
 * ===============
 * Talks to ModuleLlmPromptsController. Every request goes to the hub page itself
 * (origin + path, `?action=...`) and carries the session CSRF token the page handed
 * over (utils/api.ts: setCsrfToken / csrfHeaders).
 *
 * The URL is built from origin and path only, never from `location.href`: the page URL
 * carries the UI state (`?q=`, filters, `?key=`) and none of it may leak into a request.
 *
 * Reads are GET; `save` and `restore` are POST with a form-encoded body (the text of a
 * prompt can be long, a query string is not for that) and the token in the header.
 *
 * @module components/promptsHub/promptsHubApi
 */
import { csrfHeaders } from '../../utils/api';
import { PAGE_SIZE, filtersToParams } from './promptsHubUtils';
import type {
  PromptBaseline,
  PromptDetail,
  PromptFilters,
  PromptListResponse,
  PromptLiveState,
  PromptSaveAllResponse,
  PromptSaveItem,
  PromptSaveResponse,
  PromptVersionText,
} from './promptsHubTypes';

/** An answer the hub could not use: the server's message and the HTTP status. */
export class PromptsHubError extends Error {
  readonly status: number;
  readonly data: Record<string, unknown> | null;

  constructor(message: string, status: number, data: Record<string, unknown> | null = null) {
    super(message);
    this.name = 'PromptsHubError';
    this.status = status;
    this.data = data;
  }
}

/**
 * What a refused save or restore says is live now, when the answer is a conflict (409).
 *
 * @param failure What a request threw
 * @returns null for any other failure, and for a conflict answer that does not say
 */
export function liveStateOf(failure: unknown): PromptLiveState | null {
  if (!(failure instanceof PromptsHubError) || failure.status !== 409 || failure.data === null) {
    return null;
  }
  const current = failure.data.current;
  if (current === null || typeof current !== 'object') {
    return null;
  }
  const live = current as Record<string, unknown>;
  if (typeof live.content !== 'string' || typeof live.content_hash !== 'string') {
    return null;
  }
  return {
    version_id: typeof live.version_id === 'number' ? live.version_id : null,
    version_no: typeof live.version_no === 'number' ? live.version_no : null,
    content: live.content,
    content_hash: live.content_hash,
    updated_at: typeof live.updated_at === 'string' ? live.updated_at : null,
    updated_by: typeof live.updated_by === 'string' ? live.updated_by : null,
  };
}

/**
 * The prompt a refused "save all" names as the one that changed (409), or null.
 *
 * @param failure What a request threw
 */
export function conflictKeyOf(failure: unknown): string | null {
  if (!(failure instanceof PromptsHubError) || failure.status !== 409 || failure.data === null) {
    return null;
  }
  const current = failure.data.current;
  if (current === null || typeof current !== 'object') {
    return null;
  }
  const key = (current as Record<string, unknown>).key;
  return typeof key === 'string' ? key : null;
}

/** The endpoint of the hub page. */
function endpoint(action: string): URL {
  const url = new URL(window.location.origin + window.location.pathname);
  url.searchParams.set('action', action);
  return url;
}

/** The JSON of an answer, or the error it stands for. */
async function answerOf<T>(response: Response): Promise<T> {
  let data: unknown = null;
  try {
    data = await response.json();
  } catch {
    // Not JSON: the status below says what went wrong
  }

  if (!response.ok) {
    const body = data !== null && typeof data === 'object' ? (data as Record<string, unknown>) : null;
    // A conflict names itself in `error` ('conflict') and says what happened in `message`
    const text = body !== null && typeof body.message === 'string' && body.message !== ''
      ? body.message
      : body !== null && typeof body.error === 'string' && body.error !== '' ? body.error : '';
    throw new PromptsHubError(text !== '' ? text : `HTTP ${response.status}`, response.status, body);
  }
  if (data === null || typeof data !== 'object') {
    throw new PromptsHubError('The server sent an answer the page cannot read.', response.status);
  }

  return data as T;
}

async function get<T>(action: string, params: Record<string, string>, signal?: AbortSignal): Promise<T> {
  const url = endpoint(action);
  Object.entries(params).forEach(([name, value]) => url.searchParams.set(name, value));

  const response = await fetch(url.toString(), {
    method: 'GET',
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...csrfHeaders(),
    },
    credentials: 'same-origin',
    signal,
  });
  return answerOf<T>(response);
}

async function post<T>(action: string, fields: Record<string, string>): Promise<T> {
  const response = await fetch(endpoint(action).toString(), {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
      'X-Requested-With': 'XMLHttpRequest',
      ...csrfHeaders(),
    },
    body: new URLSearchParams(fields).toString(),
    credentials: 'same-origin',
  });
  return answerOf<T>(response);
}

export const promptsHubApi = {
  /**
   * One page of the list.
   *
   * @param filters The applied filters
   * @param offset Rows to skip
   * @param limit Rows to return
   */
  list(filters: PromptFilters, offset = 0, limit = PAGE_SIZE, signal?: AbortSignal): Promise<PromptListResponse> {
    return get<PromptListResponse>('list', filtersToParams(filters, offset, limit), signal);
  },

  /** One prompt with its full text and its version history. */
  get(key: string, signal?: AbortSignal): Promise<PromptDetail> {
    return get<PromptDetail>('get', { key }, signal);
  },

  /** The text of one version of a prompt. */
  getVersion(key: string, versionId: number, signal?: AbortSignal): Promise<PromptVersionText> {
    return get<PromptVersionText>('get_version', { key, version_id: String(versionId) }, signal);
  },

  /**
   * A new text as a new version, live at once. Refused with a 409 (see liveStateOf) when the
   * prompt is no longer what the editor loaded.
   *
   * @param key The prompt
   * @param content The new text
   * @param changeNote Why (empty = no note)
   * @param baseline What the editor loaded
   */
  save(key: string, content: string, changeNote: string, baseline: PromptBaseline): Promise<PromptSaveResponse> {
    const fields: Record<string, string> = {
      key,
      content,
      expected_active_version_id: String(baseline.versionId),
      expected_content_hash: baseline.contentHash,
    };
    if (changeNote.trim() !== '') {
      fields.change_note = changeNote.trim();
    }
    return post<PromptSaveResponse>('save', fields);
  },

  /**
   * Several languages of one prompt saved together, all or none. A 409 (see liveStateOf and
   * conflictKeyOf) names the language that changed; then nothing was saved.
   *
   * @param items One entry per changed language
   */
  saveAll(items: PromptSaveItem[]): Promise<PromptSaveAllResponse> {
    return post<PromptSaveAllResponse>('save_all', {
      items: JSON.stringify(items.map((item) => {
        const entry: Record<string, string> = {
          key: item.key,
          content: item.content,
          expected_active_version_id: String(item.baseline.versionId),
          expected_content_hash: item.baseline.contentHash,
        };
        if (item.changeNote.trim() !== '') {
          entry.change_note = item.changeNote.trim();
        }
        return entry;
      })),
    });
  },

  /**
   * The text of an old version as a new version (the history is never rewritten).
   *
   * @param key The prompt
   * @param versionId The version to bring back
   * @param baseline What the editor loaded
   */
  restore(key: string, versionId: number, baseline: PromptBaseline): Promise<PromptSaveResponse> {
    return post<PromptSaveResponse>('restore', {
      key,
      version_id: String(versionId),
      expected_active_version_id: String(baseline.versionId),
      expected_content_hash: baseline.contentHash,
    });
  },
};
