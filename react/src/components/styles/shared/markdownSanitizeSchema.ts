/**
 * Allow-list for raw HTML inside rendered Markdown.
 *
 * The content comes from LLM output, user messages and stored prompt
 * context, so it is untrusted. `rehype-raw` is kept so harmless inline HTML
 * (`<br>`, `<details>`, `<sub>`, tables, ...) still renders, but everything
 * passes through the GitHub-style default allow-list first: no script,
 * iframe, form, style, svg or media tags; no event-handler or `style`
 * attributes; link/image URLs limited to http(s)/mailto (or relative);
 * `id`/`name` values prefixed so injected markup cannot clobber page globals.
 * The only change is dropping `<style>` text instead of printing the CSS.
 *
 * Order matters: sanitize BEFORE rehype-highlight. Highlighting adds its own
 * `hljs-*` classes, which the allow-list would otherwise strip (the default
 * keeps just `language-*` on `<code>`, which is what CodeBlock reads).
 *
 * react-markdown's `urlTransform` also strips unsafe URLs; this schema is the
 * second layer and is tested on its own (tests/markdownRenderer.test.mjs).
 *
 * @module components/markdownSanitizeSchema
 */

import { defaultSchema } from 'rehype-sanitize';
import type { Options } from 'rehype-sanitize';

/** Sanitize schema used by MarkdownRenderer. */
export const markdownSanitizeSchema: Options = {
  ...defaultSchema,
  strip: [...(defaultSchema.strip ?? []), 'style']
};
