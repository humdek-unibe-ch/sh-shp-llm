/**
 * Date display of interview answers: stored ISO values are shown in the
 * display format of the field (core `date_format`). Renders the REAL
 * interviewUtils.ts (bundled with esbuild).
 *
 * Run: npm run test:interview-dates
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
    entryPoints: [path.join(reactRoot, 'src/components/styles/interview/interviewUtils.ts')],
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

const { valueText } = await loadUtils();

const def = (input_type, date_format, locale = 'en') => ({
  input_type,
  options: null,
  constraints: { date_format, locale },
});

test('numeric display formats', () => {
  assert.equal(valueText(def('date', 'd.m.Y'), '2026-12-05'), '05.12.2026');
  assert.equal(valueText(def('date', 'm/d/Y'), '2026-12-05'), '12/05/2026');
  assert.equal(valueText(def('date', 'Y-m-d'), '2026-12-05'), '2026-12-05');
});

test('month names follow the locale', () => {
  assert.equal(valueText(def('date', 'j F Y', 'en'), '2026-12-05'), '5 December 2026');
  assert.equal(valueText(def('date', 'j. F Y', 'de'), '2026-12-05'), '5. Dezember 2026');
  assert.equal(valueText(def('date', 'F j, Y', 'en'), '2026-12-05'), 'December 5, 2026');
});

test('datetime and time', () => {
  assert.equal(valueText(def('datetime', 'd.m.Y'), '2026-12-24 18:45'), '24.12.2026 18:45');
  assert.equal(valueText(def('time', 'd.m.Y'), '09:05'), '09:05');
});

test('other values and types stay as they are', () => {
  assert.equal(valueText(def('date', 'd.m.Y'), '20.11.2026'), '20.11.2026');
  assert.equal(valueText(def('date', 'd.m.Y'), ''), '');
  assert.equal(valueText({ input_type: 'text', options: null, constraints: null }, '2026-12-05'), '2026-12-05');
});
