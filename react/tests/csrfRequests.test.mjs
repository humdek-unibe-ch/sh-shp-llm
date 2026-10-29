/**
 * CSRF token plumbing of the React clients.
 *
 * The page hands the session's CSRF token to React. Every request that changes
 * data must carry it as the X-CSRF-Token header, and nothing may put it into the
 * URL or into the posted form (the core saves every posted form field as data,
 * and JSON bodies have no fields). Runs the REAL utils/api.ts and
 * scripts/scriptsApi.ts (bundled with esbuild) against a recording fetch.
 *
 * Run: npm run test:csrf
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';

const reactRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const nodeRequire = createRequire(import.meta.url);

async function load(entry) {
  const result = await build({
    entryPoints: [path.join(reactRoot, entry)],
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

const api = await load('src/utils/api.ts');
const scripts = await load('src/components/scripts/scriptsApi.ts');

const TOKEN = 'tok_0123456789abcdef';

/** Every request the clients make, in order. */
let calls = [];
/** What the next fetch answers. */
let answer = {};

globalThis.window = { location: { href: 'https://selfhelp.test/chat/page?x=1', pathname: '/chat/page' } };
globalThis.fetch = async (url, init = {}) => {
  calls.push({
    url: String(url),
    method: init.method || 'GET',
    headers: init.headers || {},
    body: init.body,
  });
  return new Response(JSON.stringify(answer), { status: 200, headers: { 'content-type': 'application/json' } });
};

function reset(token) {
  calls = [];
  answer = { conversation_id: '5', conversations: [], scripts: [], script: { id: 1 }, success: true };
  api.setCsrfToken(token);
}

/** Header value regardless of how the client spelled the name. */
function header(call, name) {
  const key = Object.keys(call.headers).find((k) => k.toLowerCase() === name.toLowerCase());
  return key === undefined ? undefined : call.headers[key];
}

test('without a token no request carries the header', async () => {
  reset(undefined);
  await api.createConversationsApi(3).create('Title');
  await api.createConversationsApi(3).getAll();
  await api.memoryApi.saveConfig({ a: 'b' });

  assert.equal(calls.length, 3);
  for (const call of calls) {
    assert.equal(header(call, 'X-CSRF-Token'), undefined, `${call.method} ${call.url}`);
  }
});

test('with a token every POST and GET carries it as a header', async () => {
  reset(TOKEN);
  await api.createConversationsApi(3).create('Title');
  await api.createConversationsApi(3).delete('9');
  await api.createConversationsApi(3).getAll();
  // A GET that changes data (the server seeds the conversation)
  await api.createAutoStartApi(3).start();

  assert.deepEqual(calls.map((c) => c.method), ['POST', 'POST', 'GET', 'GET']);
  assert.ok(calls[3].url.includes('start_auto_conversation'));
  for (const call of calls) {
    assert.equal(header(call, 'X-CSRF-Token'), TOKEN, `${call.method} ${call.url}`);
  }
});

test('the token never appears in the URL or in the posted form', async () => {
  reset(TOKEN);
  await api.createConversationsApi(3).create('Title');
  await api.createConversationsApi(3).getAll();

  for (const call of calls) {
    assert.ok(!call.url.includes(TOKEN), 'token in URL: ' + call.url);
    assert.ok(!call.url.includes('csrf_token'), 'csrf_token in URL: ' + call.url);
  }
  const form = calls[0].body;
  assert.ok(form instanceof FormData);
  assert.equal(form.has('csrf_token'), false);
  assert.ok(![...form.values()].includes(TOKEN));
});

test('a JSON body (memory settings) carries the token in the header, not in the body', async () => {
  reset(TOKEN);
  await api.memoryApi.saveConfig({ memory_enabled: '1' });

  assert.equal(calls.length, 1);
  assert.equal(header(calls[0], 'X-CSRF-Token'), TOKEN);
  assert.equal(header(calls[0], 'Content-Type'), 'application/json');
  assert.deepEqual(JSON.parse(calls[0].body), { fields: { memory_enabled: '1' } });
});

test('the chat configuration from the server provides the token', async () => {
  reset(undefined);
  answer = { config: { userId: 7, csrfToken: 'from_server' } };
  await api.configApi.get(3);

  calls = [];
  answer = { conversation_id: '5' };
  await api.createConversationsApi(3).create('Title');
  assert.equal(header(calls[0], 'X-CSRF-Token'), 'from_server');
});

test('a configuration without a token keeps the one already known', async () => {
  reset('known_token');
  answer = { config: { userId: 7 } };
  await api.configApi.get(3);

  calls = [];
  answer = { conversation_id: '5' };
  await api.createConversationsApi(3).create('Title');
  assert.equal(header(calls[0], 'X-CSRF-Token'), 'known_token');
});

test('anything that is not a token string forgets the token', async () => {
  for (const value of [undefined, null, '', 123, {}]) {
    reset(TOKEN);
    api.setCsrfToken(value);
    await api.createConversationsApi(3).create('Title');
    assert.equal(header(calls[0], 'X-CSRF-Token'), undefined, `value ${JSON.stringify(value)}`);
  }
});

test('csrfHeaders is empty without a token and carries it with one', () => {
  reset(undefined);
  assert.deepEqual(api.csrfHeaders(), {});
  api.setCsrfToken(TOKEN);
  assert.deepEqual(api.csrfHeaders(), { 'X-CSRF-Token': TOKEN });
});

test('scripts: every action that changes data carries the token', async () => {
  reset(undefined);
  const client = scripts.createScriptsApi(TOKEN);
  await client.create();
  await client.update({ sid: 1, name: 'n' });
  await client.remove(1);
  await client.test({ script: 'x' });

  assert.deepEqual(calls.map((c) => c.method), ['POST', 'POST', 'POST', 'POST']);
  const actions = calls.map((c) => c.body.get('action'));
  assert.deepEqual(actions, ['create', 'update', 'delete', 'test']);
  for (const call of calls) {
    assert.equal(header(call, 'X-CSRF-Token'), TOKEN, String(call.body.get('action')));
    assert.equal(call.body.has('csrf_token'), false);
    assert.ok(!call.url.includes(TOKEN));
  }
});

test('scripts: without a token nothing is sent', async () => {
  reset(undefined);
  await scripts.createScriptsApi().create();
  assert.equal(header(calls[0], 'X-CSRF-Token'), undefined);
});

test('scripts: the token is per client, not shared with the chat client', async () => {
  reset(TOKEN);
  await scripts.createScriptsApi().create();
  assert.equal(header(calls[0], 'X-CSRF-Token'), undefined);
});
