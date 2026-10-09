import { readFileSync } from 'node:fs';
import path from 'node:path';
import { expect, test } from '@playwright/test';

// DOMPurify needs a real DOM: the sanitiser runs in a browser page, with DOMPurify's browser build.
const purifyPath = path.join(__dirname, '..', 'node_modules', 'dompurify', 'dist', 'purify.js');
const sanitizerSource = readFileSync(path.join(__dirname, 'procedureHtmlSanitizer.js'), 'utf8');

const TIPTAP =
  '<h2>Sub</h2><p>Plain <strong>bold</strong> <em>it</em> <u>under</u> <s>strike</s> <code>inline</code> and ' +
  '<a target="_blank" rel="noopener noreferrer nofollow" href="https://example.com/a?b=1&amp;c=2">a link</a>.<br>after</p>' +
  '<ul><li><p>One</p></li></ul><ol start="3"><li><p>Three</p></li></ol><blockquote><p>Quote</p></blockquote>' +
  '<pre><code class="language-bash">echo &amp;&amp; ls</code></pre><hr>' +
  '<img src="https://example.com/x.png" alt="diagram" title="Diagram">' +
  '<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==">';

test.describe('procedure HTML sanitiser', () => {
  let clean;

  test.beforeEach(async ({ page }) => {
    await page.setContent('<!doctype html><html><body></body></html>');
    await page.addScriptTag({ path: purifyPath });
    await page.addScriptTag({
      type: 'module',
      content: `${sanitizerSource}\nwindow.__clean = createProcedureSanitizer(window.DOMPurify);`,
    });
    await page.waitForFunction(() => typeof window.__clean === 'function');
    clean = (html) => page.evaluate((h) => window.__clean(h), html);
  });

  test('strips event handlers, scripts and javascript: links', async () => {
    expect(await clean('<p>Hi<img src="x" onerror="alert(1)"></p>')).toBe('<p>Hi<img src="x"></p>');
    expect(await clean('<p>a</p><script>alert(2)</script>')).toBe('<p>a</p>');
    expect(await clean('<p><a href="javascript:alert(3)">x</a></p>')).toBe('<p><a>x</a></p>');
    expect(await clean('<p><a href="https://ok.example" onclick="alert(4)">x</a></p>')).toBe(
      '<p><a href="https://ok.example">x</a></p>',
    );
    expect(await clean('<p><a href="data:text/html,<script>alert(5)</script>">x</a></p>')).toBe(
      '<p><a>x</a></p>',
    );
    expect(
      await clean(
        '<iframe src="https://e.example"></iframe><p style="position:fixed" class="fixed">s</p>',
      ),
    ).toBe('<p>s</p>');
    expect(await clean('<pre><code class="fixed inset-0">x</code></pre>')).toBe(
      '<pre><code>x</code></pre>',
    );
  });

  test('keeps the editor markup, and makes new-tab links noopener', async () => {
    expect(await clean(TIPTAP)).toBe(TIPTAP);
    expect(await clean('<p><a href="https://x.example" target="_blank">x</a></p>')).toBe(
      '<p><a href="https://x.example" target="_blank" rel="noopener noreferrer">x</a></p>',
    );
    expect(await clean('<p><a href="https://x.example" target="_top">x</a></p>')).toBe(
      '<p><a href="https://x.example">x</a></p>',
    );
    expect(await clean(null)).toBe('');
  });
});
