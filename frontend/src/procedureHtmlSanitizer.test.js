// @vitest-environment jsdom
// DOMPurify needs a DOM: this file runs under jsdom (see the directive above).
import DOMPurify from 'dompurify';
import { describe, expect, test } from 'vitest';
import { sanitizeProcedureHtml } from './procedureHtml.js';
import { createProcedureSanitizer } from './procedureHtmlSanitizer.js';

const TIPTAP =
  '<h2>Sub</h2><p>Plain <strong>bold</strong> <em>it</em> <u>under</u> <s>strike</s> <code>inline</code> and ' +
  '<a target="_blank" rel="noopener noreferrer nofollow" href="https://example.com/a?b=1&amp;c=2">a link</a>.<br>after</p>' +
  '<ul><li><p>One</p></li></ul><ol start="3"><li><p>Three</p></li></ol><blockquote><p>Quote</p></blockquote>' +
  '<pre><code class="language-bash">echo &amp;&amp; ls</code></pre><hr>' +
  '<img src="https://example.com/x.png" alt="diagram" title="Diagram">' +
  '<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==">';

describe('procedure HTML sanitiser', () => {
  const clean = sanitizeProcedureHtml;

  test('strips event handlers, scripts and javascript: links', () => {
    expect(clean('<p>Hi<img src="x" onerror="alert(1)"></p>')).toBe('<p>Hi<img src="x"></p>');
    expect(clean('<p>a</p><script>alert(2)</script>')).toBe('<p>a</p>');
    expect(clean('<p><a href="javascript:alert(3)">x</a></p>')).toBe('<p><a>x</a></p>');
    expect(clean('<p><a href="https://ok.example" onclick="alert(4)">x</a></p>')).toBe(
      '<p><a href="https://ok.example">x</a></p>',
    );
    expect(clean('<p><a href="data:text/html,<script>alert(5)</script>">x</a></p>')).toBe(
      '<p><a>x</a></p>',
    );
    expect(
      clean(
        '<iframe src="https://e.example"></iframe><p style="position:fixed" class="fixed">s</p>',
      ),
    ).toBe('<p>s</p>');
    expect(clean('<pre><code class="fixed inset-0">x</code></pre>')).toBe(
      '<pre><code>x</code></pre>',
    );
  });

  test('keeps the editor markup, and makes new-tab links noopener', () => {
    expect(clean(TIPTAP)).toBe(TIPTAP);
    expect(clean('<p><a href="https://x.example" target="_blank">x</a></p>')).toBe(
      '<p><a href="https://x.example" target="_blank" rel="noopener noreferrer">x</a></p>',
    );
    expect(clean('<p><a href="https://x.example" target="_top">x</a></p>')).toBe(
      '<p><a href="https://x.example">x</a></p>',
    );
  });

  test('keeps only a code block’s language class and no data- or aria- attributes', () => {
    expect(clean('<pre><code class="language-c++">x</code></pre>')).toBe(
      '<pre><code class="language-c++">x</code></pre>',
    );
    expect(clean('<p class="language-bash" data-x="1" aria-label="l">x</p>')).toBe('<p>x</p>');
    expect(
      clean('<p><a href="mailto:a@b.example" title="Mail">m</a> <a href="/relative">r</a></p>'),
    ).toBe('<p><a href="mailto:a@b.example" title="Mail">m</a> <a href="/relative">r</a></p>');
  });

  test('nothing in, nothing out', () => {
    expect(clean(null)).toBe('');
    expect(clean(undefined)).toBe('');
    expect(clean('')).toBe('');
  });

  test('each sanitiser is its own DOMPurify instance, with the same rules', () => {
    const another = createProcedureSanitizer(DOMPurify);
    expect(another('<p onclick="x">a<script>b</script></p>')).toBe('<p>a</p>');
    expect(another(TIPTAP)).toBe(clean(TIPTAP));
    // The shared `dompurify` default instance is untouched by the hooks above.
    expect(DOMPurify.sanitize('<p class="fixed">s</p>')).toBe('<p class="fixed">s</p>');
  });
});
