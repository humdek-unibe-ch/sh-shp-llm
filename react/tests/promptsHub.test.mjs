/**
 * Prompts hub: the pure helpers (filter state <-> page URL, request parameters, highlighting,
 * labels) and the API client.
 *
 * Runs the REAL promptsHubUtils.ts, promptsHubApi.ts and utils/api.ts (bundled together with
 * esbuild, so the client and setCsrfToken share one module instance) against a recording fetch.
 *
 * Run: npm run test:prompts-hub
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';

const reactRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const nodeRequire = createRequire(import.meta.url);

async function load(contents) {
  const result = await build({
    stdin: { contents, resolveDir: reactRoot, loader: 'ts' },
    bundle: true,
    platform: 'node',
    format: 'cjs',
    write: false,
    logLevel: 'silent',
  });
  const mod = { exports: {} };
  new Function('module', 'exports', 'require', result.outputFiles[0].text)(mod, mod.exports, nodeRequire);
  return mod.exports;
}

const hub = await load(`
export * from './src/components/promptsHub/promptsHubUtils';
export * from './src/components/promptsHub/promptsHubApi';
export { setCsrfToken } from './src/utils/api';
`);

const EMPTY = { q: '', sourceType: '', plugin: '', style: '', pageId: '', languageId: '', includeOrphaned: false };
const KEY = 'style_field:100:conversation_context:2';

/* ------------------------------------------------------------------------------------------
 * Page URL <-> UI state
 * ---------------------------------------------------------------------------------------- */

test('an empty URL is an empty state', () => {
  assert.deepEqual(hub.readHubState(''), { filters: EMPTY, key: null, listPage: 1, pageSize: 25 });
  assert.deepEqual(hub.readHubState('?'), { filters: EMPTY, key: null, listPage: 1, pageSize: 25 });
});

test('every part of the state is read from its parameter', () => {
  const state = hub.readHubState('?q=needle&source=llm_script&plugin=LLM&style=llmChat&page=12&lang=2&orphaned=1&key=style_field%3A100%3Aconversation_context%3A2');
  assert.deepEqual(state, {
    filters: { q: 'needle', sourceType: 'llm_script', plugin: 'LLM', style: 'llmChat', pageId: '12', languageId: '2', includeOrphaned: true },
    key: KEY,
    listPage: 1,
    pageSize: 25,
  });
  assert.equal(hub.readHubState('?orphaned=true').filters.includeOrphaned, true);
  assert.equal(hub.readHubState('?orphaned=0').filters.includeOrphaned, false);
});

test('values that are not valid read as not set', () => {
  const state = hub.readHubState('?q=a&source=bogus&page=12abc&lang=-1&orphaned=yes&key=%3Cscript%3E');
  assert.deepEqual(state, { filters: EMPTY, key: null, listPage: 1, pageSize: 25 });
  assert.equal(hub.readHubState('?page=1234567890').filters.pageId, '', 'more than 9 digits');
  assert.equal(hub.readHubState('?source=system').filters.sourceType, 'system');
  assert.equal(hub.readHubState('?key=' + 'a'.repeat(301)).key, null, 'a key that is too long');
  assert.equal(hub.readHubState('?key=' + encodeURIComponent('llm_script:5:script:1 ')).key, 'llm_script:5:script:1', 'trimmed');
});

test('the search text is trimmed, needs two characters and is cut at 200', () => {
  assert.equal(hub.readHubState('?q=%20ab%20').filters.q, 'ab');
  assert.equal(hub.readHubState('?q=a').filters.q, '');
  assert.equal(hub.readHubState('?q=' + 'x'.repeat(300)).filters.q.length, 200);
});

test('writing a state and reading it back gives the same state', () => {
  const states = [
    { filters: EMPTY, key: null, listPage: 1, pageSize: 25 },
    { filters: { ...EMPTY, q: 'needle', sourceType: 'llm_script', plugin: 'LLM', style: 'llmChat', pageId: '12', languageId: '2', includeOrphaned: true }, key: KEY, listPage: 1, pageSize: 25 },
    { filters: { ...EMPTY, q: '100% & more = "quoted" \u00fcml\u00e4ut \ud83d\ude00 a+b' }, key: null, listPage: 1, pageSize: 25 },
    { filters: EMPTY, key: 'system:0:core.memory.system:0', listPage: 1, pageSize: 25 },
  ];
  for (const state of states) {
    assert.deepEqual(hub.readHubState(hub.writeHubSearch(state)), state);
  }
});

test('the written query string has a fixed order and leaves empty values out', () => {
  assert.equal(hub.writeHubSearch({ filters: EMPTY, key: null, listPage: 1, pageSize: 25 }), '');
  assert.equal(
    hub.writeHubSearch({ filters: { ...EMPTY, q: 'needle', sourceType: 'llm_script', plugin: 'LLM', style: 'llmChat', pageId: '12', languageId: '2', includeOrphaned: true }, key: 'llm_script:5:script:1', listPage: 1, pageSize: 25 }),
    '?q=needle&source=llm_script&plugin=LLM&style=llmChat&page=12&lang=2&orphaned=1&key=llm_script%3A5%3Ascript%3A1'
  );
  assert.equal(hub.writeHubSearch({ filters: { ...EMPTY, includeOrphaned: false, q: 'ab' }, key: null, listPage: 1, pageSize: 25 }), '?q=ab');
});

test('the hub replaces its own parameters in the page URL and keeps the others', () => {
  const state = { filters: { ...EMPTY, q: 'needle', includeOrphaned: true }, key: KEY, listPage: 1, pageSize: 25 };
  assert.equal(
    hub.mergeHubSearch('?q=old&source=system&key=zzz&foo=bar&page=3&lang=2&orphaned=1&plugin=x&style=y', state),
    '?foo=bar&q=needle&orphaned=1&key=style_field%3A100%3Aconversation_context%3A2'
  );
  assert.equal(hub.mergeHubSearch('?q=old&key=zzz', { filters: EMPTY, key: null, listPage: 1, pageSize: 25 }), '');
  assert.equal(hub.mergeHubSearch('?foo=bar', { filters: EMPTY, key: null, listPage: 1, pageSize: 25 }), '?foo=bar');
  assert.equal(hub.mergeHubSearch('', state), '?q=needle&orphaned=1&key=style_field%3A100%3Aconversation_context%3A2');
  // What the hub wrote, it reads back
  assert.deepEqual(hub.readHubState(hub.mergeHubSearch('?foo=bar', state)), state);
});

/* ------------------------------------------------------------------------------------------
 * Search text, request parameters
 * ---------------------------------------------------------------------------------------- */

test('the applied search text', () => {
  assert.equal(hub.effectiveQuery(''), '');
  assert.equal(hub.effectiveQuery('  a '), '');
  assert.equal(hub.effectiveQuery(' ab '), 'ab');
  assert.equal(hub.effectiveQuery('\u00e4\u00f6'), '\u00e4\u00f6');
  assert.equal(hub.effectiveQuery('x'.repeat(250)).length, 200);
});

test('hasActiveFilters is true when anything restricts the list', () => {
  assert.equal(hub.hasActiveFilters(EMPTY), false);
  for (const change of [{ q: 'ab' }, { sourceType: 'system' }, { plugin: 'LLM' }, { style: 'llmChat' }, { pageId: '1' }, { languageId: '2' }, { includeOrphaned: true }]) {
    assert.equal(hub.hasActiveFilters({ ...EMPTY, ...change }), true, JSON.stringify(change));
  }
});

test('list parameters use the names of the server and leave empty filters out', () => {
  assert.deepEqual(hub.filtersToParams(EMPTY), { limit: '25', offset: '0' });
  assert.deepEqual(
    hub.filtersToParams({ q: 'needle', sourceType: 'llm_script', plugin: 'LLM', style: 'llmChat', pageId: '12', languageId: '2', includeOrphaned: true }, 200, 50),
    { limit: '50', offset: '200', q: 'needle', sourceType: 'llm_script', plugin: 'LLM', style: 'llmChat', pageId: '12', languageId: '2', includeOrphaned: '1' }
  );
  assert.equal('q' in hub.filtersToParams({ ...EMPTY, q: 'a' }), false, 'a search text that is too short is not sent');
});

test('the page of the list and its size are in the URL, defaults are left out', () => {
  const state = { filters: EMPTY, key: null, listPage: 3, pageSize: 50 };
  assert.equal(hub.writeHubSearch(state), '?p=3&size=50');
  assert.deepEqual(hub.readHubState('?p=3&size=50'), state);
  assert.equal(hub.writeHubSearch({ ...state, listPage: 1, pageSize: 25 }), '');
  assert.equal(hub.readHubState('?p=0').listPage, 1, 'page 0 is page 1');
  assert.equal(hub.readHubState('?p=abc').listPage, 1);
  assert.equal(hub.readHubState('?size=7').pageSize, 25, 'a size that is not offered is the default');
  assert.equal(hub.mergeHubSearch('?p=4&size=100&foo=bar', { filters: EMPTY, key: null, listPage: 1, pageSize: 25 }), '?foo=bar');
  // The page filter (`page`) and the page of the list (`p`) do not mix
  assert.deepEqual(hub.readHubState('?page=12&p=2'), { filters: { ...EMPTY, pageId: '12' }, key: null, listPage: 2, pageSize: 25 });
});

test('page count and the page numbers the pagination shows', () => {
  assert.equal(hub.pageCount(0, 25), 1);
  assert.equal(hub.pageCount(25, 25), 1);
  assert.equal(hub.pageCount(26, 25), 2);
  assert.equal(hub.pageCount(1000, 25), 40);
  assert.deepEqual(hub.pageWindow(1, 1), [1]);
  assert.deepEqual(hub.pageWindow(1, 5), [1, 2, 3, 4, 5]);
  assert.deepEqual(hub.pageWindow(1, 40), [1, 2, 3, null, 40]);
  assert.deepEqual(hub.pageWindow(20, 40), [1, null, 18, 19, 20, 21, 22, null, 40]);
  assert.deepEqual(hub.pageWindow(40, 40), [1, null, 38, 39, 40]);
  assert.deepEqual(hub.pageWindow(4, 40), [1, 2, 3, 4, 5, 6, null, 40], 'no gap for a single left-out page');
});

/* ------------------------------------------------------------------------------------------
 * Highlighting
 * ---------------------------------------------------------------------------------------- */

const marked = (text, query) => hub.splitHighlights(text, query).filter((p) => p.match).map((p) => p.text);
const joined = (text, query) => hub.splitHighlights(text, query).map((p) => p.text).join('');

test('a hit is marked, case-insensitive, and the original case is kept', () => {
  assert.deepEqual(hub.splitHighlights('Hello World', 'world'), [{ text: 'Hello ', match: false }, { text: 'World', match: true }]);
  assert.deepEqual(marked('Needle in a NEEDLE stack needle', 'needle'), ['Needle', 'NEEDLE', 'needle']);
});

test('the search text is literal: it is never a pattern', () => {
  assert.deepEqual(marked('aXb a.b', 'a.b'), ['a.b']);
  assert.deepEqual(marked('100% sure, 100x', '100%'), ['100%']);
  assert.deepEqual(marked('call (really) now', '(really)'), ['(really)']);
  assert.deepEqual(marked('a [yes] b', '[yes]'), ['[yes]']);
  assert.deepEqual(marked('x a\\b y', 'a\\b'), ['a\\b']);
  assert.deepEqual(marked('price $5 ^ 2 | 3 * 4 + 1 ? {x}', '$5 ^ 2 | 3 * 4 + 1 ? {x}'), ['$5 ^ 2 | 3 * 4 + 1 ? {x}']);
  assert.deepEqual(marked('time_x and timeXx', 'time_'), ['time_']);
});

test('umlauts and emojis are found', () => {
  assert.deepEqual(marked('\u00c4rger mit \u00c4RGER', '\u00e4rger'), ['\u00c4rger', '\u00c4RGER']);
  assert.deepEqual(marked('ok \ud83d\ude00 fine \ud83d\ude00', '\ud83d\ude00'), ['\ud83d\ude00', '\ud83d\ude00']);
});

test('without a hit, or without a search text, the text stays whole', () => {
  assert.deepEqual(hub.splitHighlights('nothing here', 'zzz'), [{ text: 'nothing here', match: false }]);
  assert.deepEqual(hub.splitHighlights('nothing here', ''), [{ text: 'nothing here', match: false }]);
  assert.deepEqual(hub.splitHighlights('', 'ab'), [{ text: '', match: false }]);
  assert.deepEqual(hub.splitHighlights('ab', 'abc'), [{ text: 'ab', match: false }]);
});

test('hits are separate parts that do not overlap', () => {
  assert.deepEqual(marked('aaaaa', 'aa'), ['aa', 'aa']);
  assert.deepEqual(hub.splitHighlights('abab', 'ab'), [{ text: 'ab', match: true }, { text: 'ab', match: true }]);
});

test('the parts always add up to the original text, markup included (React renders them as text)', () => {
  const texts = ['<script>alert(1)</script> and <b>bold</b>', '<img src=x onerror=alert(1)>', 'a < b && c > d', '&amp; &lt;', '', ' ', 'line\nbreak', '\u2026 snippet \u2026'];
  const queries = ['script', '<b>', 'alert(1)', '&', 'x', '<', 'bold</b>', 'line\nbreak', '\u2026'];
  for (const text of texts) {
    for (const query of queries) {
      assert.equal(joined(text, query), text, JSON.stringify([text, query]));
    }
  }
  // The markup around a hit stays text; "b" matches the tag names, which are only text here
  assert.deepEqual(marked('<b>bold</b>', 'bold'), ['bold']);
  assert.deepEqual(hub.splitHighlights('<b>bold</b>', 'bold').map((p) => p.text), ['<b>', 'bold', '</b>']);
});

/* ------------------------------------------------------------------------------------------
 * Labels
 * ---------------------------------------------------------------------------------------- */

test('language labels', () => {
  assert.equal(hub.languageLabel({ languageId: null, languageCode: null }), '\u2014');
  assert.equal(hub.languageLabel({ languageId: 1, languageCode: 'all' }), 'all languages');
  assert.equal(hub.languageLabel({ languageId: 3, languageCode: 'de-CH' }), 'de-CH');
  assert.equal(hub.languageLabel({ languageId: 3, languageCode: null }), '#3');
});

test('version labels', () => {
  assert.equal(hub.versionLabel({ activeVersionNo: null }), '\u2014');
  assert.equal(hub.versionLabel({ activeVersionNo: 0 }), '\u2014');
  assert.equal(hub.versionLabel({ activeVersionNo: 7 }), 'v7');
});

test('"where" shows the first page and counts the others', () => {
  const pages = (n) => Array.from({ length: n }, (_, i) => ({ id: i + 1, label: 'Page ' + 'ABC'[i] }));
  assert.deepEqual(hub.whereParts({ ownerLabel: 'LLM script #5', hostPages: [] }), { owner: 'LLM script #5', page: null, pageTitle: null });
  assert.deepEqual(hub.whereParts({ ownerLabel: 'chat', hostPages: pages(1) }), { owner: 'chat', page: 'Page A', pageTitle: 'Page A' });
  assert.deepEqual(hub.whereParts({ ownerLabel: 'chat', hostPages: pages(3) }), { owner: 'chat', page: 'Page A (+2 more)', pageTitle: 'Page A, Page B, Page C' });
});

test('every read-only reason has a text, and an editable prompt has none', () => {
  assert.equal(hub.readOnlyText(null), '');
  for (const reason of ['system_prompt', 'persona_link_only', 'no_access', 'orphaned']) {
    assert.ok(hub.readOnlyText(reason).length > 10, reason);
  }
  assert.equal(hub.readOnlyText('something_new'), 'This prompt is read-only.', 'a reason from a newer server');
});

test('every source has a label, a badge and an order', () => {
  assert.deepEqual(hub.SOURCE_ORDER, ['style_field', 'llm_script', 'llm_memory_rule', 'agentic_persona', 'system']);
  for (const source of hub.SOURCE_ORDER) {
    assert.ok(hub.SOURCE_LABELS[source], source);
    assert.ok(hub.SOURCE_BADGE_VARIANT[source], source);
    assert.ok(source in hub.SOURCE_OPEN_LABELS, source);
  }
  assert.equal(hub.SOURCE_OPEN_LABELS.system, null, 'a system prompt has nowhere to go');
  assert.equal(hub.isSourceType('system'), true);
  assert.equal(hub.isSourceType('nope'), false);
  assert.equal(hub.isSourceType(5), false);
});

test('timestamps', () => {
  assert.equal(hub.formatWhen(null), '');
  assert.equal(hub.formatWhen(''), '');
  assert.match(hub.formatWhen('2026-11-04 10:00:00'), /2026/);
  assert.equal(hub.formatWhen('not a date'), 'not a date');
});

/* ------------------------------------------------------------------------------------------
 * API client
 * ---------------------------------------------------------------------------------------- */

const TOKEN = 'tok_0123456789abcdef';

/** Every request, in order. */
let calls = [];
/** What the next fetch answers: [status, body (object, or a string sent as-is)]. */
let answer = [200, {}];
/** Make the next fetch fail like a network error. */
let networkError = null;

// The page URL carries UI state that must never reach a request
globalThis.window = {
  location: {
    origin: 'https://selfhelp.test',
    pathname: '/admin/module_llm/prompts',
    href: 'https://selfhelp.test/admin/module_llm/prompts?q=old&source=system&key=zzz&action=evil',
    search: '?q=old&source=system&key=zzz&action=evil',
  },
};
globalThis.fetch = async (url, init = {}) => {
  calls.push({ url: String(url), init });
  if (networkError) {
    throw networkError;
  }
  const [status, body] = answer;
  return new Response(typeof body === 'string' ? body : JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
};

function reset(token, response = [200, { rows: [], total: 0, facets: {} }]) {
  calls = [];
  answer = response;
  networkError = null;
  hub.setCsrfToken(token);
}

function header(call, name) {
  const headers = call.init.headers || {};
  const key = Object.keys(headers).find((k) => k.toLowerCase() === name.toLowerCase());
  return key === undefined ? undefined : headers[key];
}

test('list goes to the hub page with the action and the filters, and nothing of the page URL', async () => {
  reset(TOKEN);
  await hub.promptsHubApi.list({ ...EMPTY, sourceType: 'llm_script', pageId: '12' }, 200, 50);

  assert.equal(calls.length, 1);
  const url = new URL(calls[0].url);
  assert.equal(url.origin + url.pathname, 'https://selfhelp.test/admin/module_llm/prompts');
  assert.deepEqual(Object.fromEntries(url.searchParams), { action: 'list', limit: '50', offset: '200', sourceType: 'llm_script', pageId: '12' });
  assert.equal(url.searchParams.get('key'), null, 'the open prompt of the page URL is not sent');
  assert.equal(url.searchParams.get('q'), null, 'the old search of the page URL is not sent');
  assert.equal(calls[0].init.method, 'GET');
  assert.equal(calls[0].init.credentials, 'same-origin');
  assert.equal(header(calls[0], 'Accept'), 'application/json');
  assert.equal(header(calls[0], 'X-Requested-With'), 'XMLHttpRequest');
});

test('get and get_version carry the key and the version id', async () => {
  reset(TOKEN, [200, { key: KEY, listPage: 1, pageSize: 25 }]);
  await hub.promptsHubApi.get(KEY);
  const getUrl = new URL(calls[0].url);
  assert.equal(getUrl.origin + getUrl.pathname, 'https://selfhelp.test/admin/module_llm/prompts');
  assert.deepEqual(Object.fromEntries(getUrl.searchParams), { action: 'get', key: KEY });

  reset(TOKEN, [200, { version_no: 3 }]);
  await hub.promptsHubApi.getVersion(KEY, 90);
  const versionUrl = new URL(calls[0].url);
  assert.equal(versionUrl.origin + versionUrl.pathname, 'https://selfhelp.test/admin/module_llm/prompts');
  assert.deepEqual(Object.fromEntries(versionUrl.searchParams), { action: 'get_version', key: KEY, version_id: '90' });
});

test('the token is sent as a header, never in the URL, and not at all without one', async () => {
  reset(TOKEN);
  await hub.promptsHubApi.list(EMPTY);
  await hub.promptsHubApi.get(KEY);
  assert.equal(header(calls[0], 'X-CSRF-Token'), TOKEN);
  assert.equal(header(calls[1], 'X-CSRF-Token'), TOKEN);
  for (const call of calls) {
    assert.ok(!call.url.includes(TOKEN), 'token in URL: ' + call.url);
    assert.ok(!call.url.includes('csrf'), 'csrf in URL: ' + call.url);
  }

  reset(undefined);
  await hub.promptsHubApi.list(EMPTY);
  assert.equal(header(calls[0], 'X-CSRF-Token'), undefined);
});

test('the answer is returned as the server sent it', async () => {
  const body = { rows: [{ key: KEY, listPage: 1, pageSize: 25 }], total: 1, facets: { plugins: [] } };
  reset(TOKEN, [200, body]);
  assert.deepEqual(await hub.promptsHubApi.list(EMPTY), body);
});

test('an error answer becomes an error with the message and the status of the server', async () => {
  for (const [status, message] of [[403, 'Access denied'], [404, 'Prompt not found'], [400, 'Malformed prompt key'], [500, 'Something went wrong. Please try again.']]) {
    reset(TOKEN, [status, { error: message }]);
    await assert.rejects(hub.promptsHubApi.get(KEY), (error) => {
      assert.ok(error instanceof hub.PromptsHubError);
      assert.equal(error.message, message);
      assert.equal(error.status, status);
      assert.deepEqual(error.data, { error: message });
      return true;
    });
  }
});

test('an error answer without a message says the status', async () => {
  reset(TOKEN, [502, '<html>Bad gateway</html>']);
  await assert.rejects(hub.promptsHubApi.list(EMPTY), (error) => {
    assert.ok(error instanceof hub.PromptsHubError);
    assert.equal(error.message, 'HTTP 502');
    assert.equal(error.status, 502);
    assert.equal(error.data, null);
    return true;
  });

  reset(TOKEN, [500, { error: '' }]);
  await assert.rejects(hub.promptsHubApi.list(EMPTY), /HTTP 500/);
});

test('an answer that is not JSON is an error, not data', async () => {
  reset(TOKEN, [200, '<html>login page</html>']);
  await assert.rejects(hub.promptsHubApi.list(EMPTY), (error) => {
    assert.ok(error instanceof hub.PromptsHubError);
    assert.match(error.message, /cannot read/);
    return true;
  });

  reset(TOKEN, [200, 'null']);
  await assert.rejects(hub.promptsHubApi.list(EMPTY), hub.PromptsHubError);
  reset(TOKEN, [200, '"text"']);
  await assert.rejects(hub.promptsHubApi.list(EMPTY), hub.PromptsHubError);
});

test('a request can be cancelled, and a network failure is passed on', async () => {
  reset(TOKEN);
  const controller = new AbortController();
  await hub.promptsHubApi.list(EMPTY, 0, 200, controller.signal);
  await hub.promptsHubApi.get(KEY, controller.signal);
  await hub.promptsHubApi.getVersion(KEY, 1, controller.signal);
  assert.ok(calls.every((call) => call.init.signal === controller.signal));

  reset(TOKEN);
  networkError = new TypeError('Failed to fetch');
  await assert.rejects(hub.promptsHubApi.list(EMPTY), (error) => error instanceof TypeError && error.message === 'Failed to fetch');
});

/* ------------------------------------------------------------------------------------------
 * Editing: helpers
 * ---------------------------------------------------------------------------------------- */

test('line endings are LF for comparing, and a different line ending is not a change', () => {
  assert.equal(hub.toLf('a\r\nb\rc\nd'), 'a\nb\nc\nd');
  assert.equal(hub.toLf(''), '');
  assert.equal(hub.toLf('\r\n\r\n'), '\n\n');
  assert.equal(hub.isDirty('a\r\nb', 'a\nb'), false, 'what Monaco hands back on Windows is the saved text');
  assert.equal(hub.isDirty('a\nb', 'a\r\nb'), false);
  assert.equal(hub.isDirty('a\nb ', 'a\nb'), true, 'a trailing space is a change');
  assert.equal(hub.isDirty('', 'a'), true);
  assert.equal(hub.isDirty('a', ''), true);
  assert.equal(hub.isDirty('', ''), false);
  assert.equal(hub.isDirty('A', 'a'), true, 'the case counts');
});

test('the baseline of a save is the active version and the hash of the text as it was loaded', () => {
  assert.deepEqual(hub.baselineOf({ activeVersionId: 91, contentHash: 'abc' }), { versionId: 91, contentHash: 'abc' });
  assert.deepEqual(hub.baselineOf({ activeVersionId: null, contentHash: 'abc' }), { versionId: 0, contentHash: 'abc' }, '0 = the prompt had no version');
  assert.deepEqual(
    hub.baselineFromLive({ version_id: 92, version_no: 8, content: 'x', content_hash: 'def', updated_at: null, updated_by: null }),
    { versionId: 92, contentHash: 'def' }
  );
  assert.deepEqual(
    hub.baselineFromLive({ version_id: null, version_no: null, content: 'x', content_hash: 'def', updated_at: null, updated_by: null }),
    { versionId: 0, contentHash: 'def' }
  );
});

test('a history entry is listed the way the shared dialogs expect it', () => {
  assert.deepEqual(
    hub.toVersionRow({ id: 91, no: 7, created_at: '2026-11-20 14:03:00', author: 'Jane Admin', change_note: 'shorter' }),
    { id: 91, version_no: 7, created_at: '2026-11-20 14:03:00', created_user_name: 'Jane Admin', change_note: 'shorter' }
  );
  const bare = hub.toVersionRow({ id: 5, no: 1, created_at: null, author: null, change_note: null });
  assert.deepEqual(bare, { id: 5, version_no: 1, created_at: '', created_user_name: null, change_note: null });
  assert.ok(!('template_raw' in bare), 'no text: the compare dialog fetches it');
});

test('a list row is the detail without what only the detail has', () => {
  const detail = {
    key: KEY, title: 'Conversation context', preview: 'Du bist', contentLength: 7, activeVersionNo: 8,
    content: 'Du bist ein Coach.', contentHash: 'abc', versions: [{ id: 1 }], meta: { prompt: {} },
  };
  const row = hub.rowOf(detail);
  assert.deepEqual(Object.keys(row).sort(), ['activeVersionNo', 'contentLength', 'key', 'preview', 'title']);
  assert.equal(row.activeVersionNo, 8);
  assert.equal('content' in detail, true, 'the detail itself is not changed');
});

test('what is said after a save and after a restore', () => {
  const saved = (changed, no) => ({ ok: true, changed, row: { activeVersionNo: no } });
  assert.equal(hub.savedMessage(saved(true, 8)), 'Saved as v8 \u2014 live now');
  assert.equal(hub.savedMessage(saved(false, 7)), 'Nothing changed: this text is already live as v7');
  assert.equal(hub.savedMessage(saved(true, 9), 5), 'Restored v5 as v9 \u2014 live now');
  assert.equal(hub.savedMessage(saved(false, 5), 5), 'v5 is already the live text, nothing changed');
  assert.equal(hub.savedMessage(saved(true, null)), 'Saved as a new version \u2014 live now');
  assert.equal(hub.savedMessage(saved(true, 0), 3), 'Restored v3 as a new version \u2014 live now');
  assert.equal(hub.savedMessage(saved(false, null)), 'Nothing changed: this text is already live');
});

test('the registry sources are the ones that can be edited, and the note has the length of the server', () => {
  assert.deepEqual([...hub.REGISTRY_SOURCES], ['style_field', 'llm_script', 'llm_memory_rule']);
  assert.equal(hub.MAX_CHANGE_NOTE_LENGTH, 255);
});

/* ------------------------------------------------------------------------------------------
 * Editing: save and restore requests
 * ---------------------------------------------------------------------------------------- */

const BASELINE = { versionId: 91, contentHash: 'ab'.repeat(32) };
const SAVED = { ok: true, changed: true, row: { key: KEY, activeVersionNo: 8 } };

test('save is a POST to the hub page with the action in the URL and the fields in the body', async () => {
  reset(TOKEN, [200, SAVED]);
  const result = await hub.promptsHubApi.save(KEY, 'New text', 'Friendlier', BASELINE);

  assert.deepEqual(result, SAVED);
  assert.equal(calls.length, 1);
  const url = new URL(calls[0].url);
  assert.equal(url.origin + url.pathname, 'https://selfhelp.test/admin/module_llm/prompts');
  assert.deepEqual(Object.fromEntries(url.searchParams), { action: 'save' }, 'nothing of the page URL, nothing of the text');
  assert.equal(calls[0].init.method, 'POST');
  assert.equal(calls[0].init.credentials, 'same-origin');
  assert.equal(header(calls[0], 'Content-Type'), 'application/x-www-form-urlencoded;charset=UTF-8');
  assert.equal(header(calls[0], 'Accept'), 'application/json');
  assert.equal(header(calls[0], 'X-Requested-With'), 'XMLHttpRequest');
  assert.deepEqual(Object.fromEntries(new URLSearchParams(calls[0].init.body)), {
    key: KEY,
    content: 'New text',
    expected_active_version_id: '91',
    expected_content_hash: BASELINE.contentHash,
    change_note: 'Friendlier',
  });
});

test('the token of a save is a header, never in the URL or the body', async () => {
  reset(TOKEN, [200, SAVED]);
  await hub.promptsHubApi.save(KEY, 'x', '', BASELINE);
  await hub.promptsHubApi.restore(KEY, 90, BASELINE);
  assert.equal(calls.length, 2);
  for (const call of calls) {
    assert.equal(header(call, 'X-CSRF-Token'), TOKEN);
    assert.ok(!call.url.includes(TOKEN), 'token in URL');
    assert.ok(!String(call.init.body).includes(TOKEN), 'token in body');
    assert.ok(!String(call.init.body).includes('csrf'), 'csrf field in body');
  }
  reset(undefined, [200, SAVED]);
  await hub.promptsHubApi.save(KEY, 'x', '', BASELINE);
  assert.equal(header(calls[0], 'X-CSRF-Token'), undefined);
});

test('a text survives the form encoding exactly: line breaks, umlauts, emojis, & = % + and quotes', async () => {
  const text = 'Zeile 1\nZeile 2\r\n"quoted" & a=b%20c + d \u00e4\u00f6\u00fc \u{1F600} {{field.path}} <b>x</b>\t\n';
  reset(TOKEN, [200, SAVED]);
  await hub.promptsHubApi.save(KEY, text, 'n\u00e4', BASELINE);
  const fields = new URLSearchParams(calls[0].init.body);
  assert.equal(fields.get('content'), text);
  assert.equal(fields.get('change_note'), 'n\u00e4');
});

test('an empty text is sent (it is a text), an empty or blank note is not, a note is trimmed', async () => {
  reset(TOKEN, [200, SAVED]);
  await hub.promptsHubApi.save(KEY, '', '', BASELINE);
  const fields = new URLSearchParams(calls[0].init.body);
  assert.equal(fields.has('content'), true);
  assert.equal(fields.get('content'), '');
  assert.equal(fields.has('change_note'), false);

  reset(TOKEN, [200, SAVED]);
  await hub.promptsHubApi.save(KEY, 'x', '   \t ', BASELINE);
  assert.equal(new URLSearchParams(calls[0].init.body).has('change_note'), false);

  reset(TOKEN, [200, SAVED]);
  await hub.promptsHubApi.save(KEY, 'x', '  trimmed  ', BASELINE);
  assert.equal(new URLSearchParams(calls[0].init.body).get('change_note'), 'trimmed');
});

test('a prompt without a version is saved against version 0', async () => {
  reset(TOKEN, [200, SAVED]);
  await hub.promptsHubApi.save(KEY, 'x', '', { versionId: 0, contentHash: 'cd'.repeat(32) });
  assert.equal(new URLSearchParams(calls[0].init.body).get('expected_active_version_id'), '0');
});

test('restore is a POST with the version to bring back and what the editor loaded', async () => {
  reset(TOKEN, [200, SAVED]);
  const result = await hub.promptsHubApi.restore(KEY, 90, BASELINE);

  assert.deepEqual(result, SAVED);
  const url = new URL(calls[0].url);
  assert.equal(url.origin + url.pathname, 'https://selfhelp.test/admin/module_llm/prompts');
  assert.deepEqual(Object.fromEntries(url.searchParams), { action: 'restore' });
  assert.equal(calls[0].init.method, 'POST');
  assert.deepEqual(Object.fromEntries(new URLSearchParams(calls[0].init.body)), {
    key: KEY,
    version_id: '90',
    expected_active_version_id: '91',
    expected_content_hash: BASELINE.contentHash,
  });
});

/* ------------------------------------------------------------------------------------------
 * Editing: refusals
 * ---------------------------------------------------------------------------------------- */

const CURRENT = {
  version_id: 92, version_no: 8, content: 'CMS text', content_hash: 'ef'.repeat(32),
  updated_at: '2026-11-23 12:00:00', updated_by: 'Jane Admin',
};

test('a conflict says what happened and carries what is live now', async () => {
  reset(TOKEN, [409, { error: 'conflict', message: 'This prompt was changed by someone else', current: CURRENT }]);
  await assert.rejects(hub.promptsHubApi.save(KEY, 'x', '', BASELINE), (error) => {
    assert.ok(error instanceof hub.PromptsHubError);
    assert.equal(error.status, 409);
    assert.equal(error.message, 'This prompt was changed by someone else', 'the message, not the word "conflict"');
    assert.deepEqual(hub.liveStateOf(error), CURRENT);
    return true;
  });
  reset(TOKEN, [409, { error: 'conflict', message: 'This prompt was changed by someone else', current: CURRENT }]);
  await assert.rejects(hub.promptsHubApi.restore(KEY, 90, BASELINE), (error) => hub.liveStateOf(error).version_no === 8);
});

test('a conflict of a prompt without a version names none', async () => {
  const live = { ...CURRENT, version_id: null, version_no: null, updated_at: null, updated_by: null };
  reset(TOKEN, [409, { error: 'conflict', message: 'm', current: live }]);
  await assert.rejects(hub.promptsHubApi.save(KEY, 'x', '', BASELINE), (error) => {
    assert.deepEqual(hub.liveStateOf(error), live);
    return true;
  });
});

test('only a complete conflict answer is a live state', async () => {
  const conflict = async (body) => {
    reset(TOKEN, [409, body]);
    try {
      await hub.promptsHubApi.save(KEY, 'x', '', BASELINE);
    } catch (error) {
      return hub.liveStateOf(error);
    }
    throw new Error('did not fail');
  };
  assert.equal(await conflict({ error: 'conflict' }), null, 'no current');
  assert.equal(await conflict({ error: 'conflict', current: 'x' }), null, 'current is not an object');
  assert.equal(await conflict({ error: 'conflict', current: null }), null);
  assert.equal(await conflict({ error: 'conflict', current: { ...CURRENT, content: 5 } }), null, 'no text');
  assert.equal(await conflict({ error: 'conflict', current: { ...CURRENT, content_hash: undefined } }), null, 'no hash');
  const odd = await conflict({ error: 'conflict', current: { ...CURRENT, version_id: '92', version_no: 'x', updated_at: 5, updated_by: {} } });
  assert.deepEqual(odd, { ...CURRENT, version_id: null, version_no: null, updated_at: null, updated_by: null }, 'wrong types read as not known');

  // Any other failure is not a conflict, whatever the body says
  for (const status of [403, 400, 500]) {
    reset(TOKEN, [status, { error: 'x', current: CURRENT }]);
    await assert.rejects(hub.promptsHubApi.save(KEY, 'x', '', BASELINE), (error) => hub.liveStateOf(error) === null);
  }
  assert.equal(hub.liveStateOf(new Error('x')), null);
  assert.equal(hub.liveStateOf(null), null);
  assert.equal(hub.liveStateOf('conflict'), null);
});

test('a refused write says why: the message of the server and its status', async () => {
  for (const [status, body, message] of [
    [403, { error: 'You may not change this prompt', reason: 'no_access' }, 'You may not change this prompt'],
    [403, { error: 'Invalid CSRF token. Reload the page and try again.' }, 'Invalid CSRF token. Reload the page and try again.'],
    [404, { error: 'Prompt not found' }, 'Prompt not found'],
    [400, { error: 'The prompt text is too long' }, 'The prompt text is too long'],
    [405, { error: 'Method not allowed' }, 'Method not allowed'],
    [500, { error: 'Something went wrong. Please try again.' }, 'Something went wrong. Please try again.'],
  ]) {
    reset(TOKEN, [status, body]);
    await assert.rejects(hub.promptsHubApi.save(KEY, 'x', '', BASELINE), (error) => {
      assert.ok(error instanceof hub.PromptsHubError);
      assert.equal(error.message, message);
      assert.equal(error.status, status);
      assert.deepEqual(error.data, body);
      return true;
    });
  }
  reset(TOKEN, [403, { error: 'You may not change this prompt', reason: 'orphaned' }]);
  await assert.rejects(hub.promptsHubApi.restore(KEY, 90, BASELINE), (error) => error.data.reason === 'orphaned');
});

test('a network failure of a write is passed on, and nothing is retried', async () => {
  reset(TOKEN);
  networkError = new TypeError('Failed to fetch');
  await assert.rejects(hub.promptsHubApi.save(KEY, 'x', '', BASELINE), (error) => error instanceof TypeError);
  assert.equal(calls.length, 1, 'one attempt');
});

test('an answer that is not JSON is an error for a write too', async () => {
  reset(TOKEN, [200, '<html>login page</html>']);
  await assert.rejects(hub.promptsHubApi.save(KEY, 'x', '', BASELINE), (error) => error instanceof hub.PromptsHubError && /cannot read/.test(error.message));
});

/* ------------------------------------------------------------------------------------------
 * Save all languages of a field
 * ---------------------------------------------------------------------------------------- */

test('save_all sends every language as one JSON list, notes trimmed and empty ones left out', async () => {
  const keyEn = 'style_field:100:conversation_context:3';
  const answerAll = { ok: true, changed: true, rows: [] };
  reset(TOKEN, [200, answerAll]);
  const result = await hub.promptsHubApi.saveAll([
    { key: KEY, content: 'Zeile\n\u00e4 & =', changeNote: '  beide ', baseline: BASELINE },
    { key: keyEn, content: '', changeNote: '  ', baseline: { versionId: 0, contentHash: 'e3' } },
  ]);
  assert.deepEqual(result, answerAll);
  assert.equal(calls.length, 1);
  assert.deepEqual(Object.fromEntries(new URL(calls[0].url).searchParams), { action: 'save_all' });
  assert.equal(calls[0].init.method, 'POST');
  assert.equal(header(calls[0], 'X-CSRF-Token'), TOKEN);
  const fields = new URLSearchParams(calls[0].init.body);
  assert.deepEqual([...fields.keys()], ['items']);
  assert.deepEqual(JSON.parse(fields.get('items')), [
    { key: KEY, content: 'Zeile\n\u00e4 & =', expected_active_version_id: '91', expected_content_hash: BASELINE.contentHash, change_note: 'beide' },
    { key: keyEn, content: '', expected_active_version_id: '0', expected_content_hash: 'e3' },
  ]);
});

test('a refused save_all names the language that changed', async () => {
  reset(TOKEN, [409, { error: 'conflict', message: 'changed', current: { version_id: 95, version_no: 9, content: 'x', content_hash: 'h', key: KEY } }]);
  const failure = await hub.promptsHubApi.saveAll([{ key: KEY, content: 'y', changeNote: '', baseline: BASELINE }]).catch((e) => e);
  assert.equal(hub.conflictKeyOf(failure), KEY);
  assert.equal(hub.liveStateOf(failure).version_no, 9);
  assert.equal(hub.conflictKeyOf(new Error('x')), null);
  reset(TOKEN, [409, { error: 'conflict', current: { content: 'x', content_hash: 'h' } }]);
  assert.equal(hub.conflictKeyOf(await hub.promptsHubApi.save(KEY, 'y', '', BASELINE).catch((e) => e)), null);
});
