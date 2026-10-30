/**
 * Tri-state safety tests for the response helpers.
 *
 * `safety.is_safe` is true, false, or null ("the model gave no assessment").
 * Only an explicit false may become a visible safety notice; null must never
 * be read as unsafe. Renders the REAL llmResponseUtils.ts (bundled with esbuild).
 *
 * Run: npm run test:utils
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';

const reactRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const nodeRequire = createRequire(import.meta.url);

async function loadUtils() {
  const result = await build({
    entryPoints: [path.join(reactRoot, 'src/utils/llmResponseUtils.ts')],
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

const { llmResponseToMarkdown } = await loadUtils();

function response(safety) {
  return {
    type: 'response',
    safety,
    content: { text_blocks: [{ type: 'text', content: 'Hello there' }] },
  };
}

const NOTICE = 'Safety Notice';

test('is_safe=false with a message shows the safety notice before the content', () => {
  const md = llmResponseToMarkdown(response({ is_safe: false, danger_level: 'warning', detected_concerns: [], requires_intervention: false, safety_message: 'Please take care' }));
  assert.ok(md.includes(NOTICE));
  assert.ok(md.includes('Please take care'));
  assert.ok(md.indexOf(NOTICE) < md.indexOf('Hello there'));
});

test('is_safe=null (unassessed) is not shown as a safety notice', () => {
  const md = llmResponseToMarkdown(response({ is_safe: null, danger_level: null, detected_concerns: [], requires_intervention: false, safety_message: 'Please take care' }));
  assert.ok(!md.includes(NOTICE));
  assert.ok(md.includes('Hello there'));
});

test('is_safe=true shows no notice', () => {
  const md = llmResponseToMarkdown(response({ is_safe: true, danger_level: null, detected_concerns: [], requires_intervention: false, safety_message: 'Please take care' }));
  assert.ok(!md.includes(NOTICE));
});

test('is_safe=false without a message adds nothing', () => {
  const md = llmResponseToMarkdown(response({ is_safe: false, danger_level: 'warning', detected_concerns: [], requires_intervention: false, safety_message: null }));
  assert.ok(!md.includes(NOTICE));
});
