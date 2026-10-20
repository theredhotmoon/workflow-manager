// Display-side cleaning of procedure HTML before it is rendered with v-html. The backend already
// sanitises every write (ProcedureHtmlSanitizer, same allowlist); this is defence in depth for rows
// stored before that, or anything that slips past it. DOMPurify comes in as a parameter, so each
// caller gets its own hooked instance (procedureHtml.js builds the app's; the unit test builds one
// under jsdom).

// What the procedure editor (TipTap StarterKit + Link + Image + Underline) produces.
const ALLOWED_TAGS = [
  'p',
  'br',
  'hr',
  'h1',
  'h2',
  'h3',
  'h4',
  'h5',
  'h6',
  'blockquote',
  'ul',
  'ol',
  'li',
  'pre',
  'code',
  'strong',
  'b',
  'em',
  'i',
  'u',
  's',
  'strike',
  'a',
  'img',
];
const ALLOWED_ATTR = [
  'href',
  'target',
  'rel',
  'title',
  'src',
  'alt',
  'width',
  'height',
  'start',
  'type',
  'class',
];
// http(s), mailto and relative links; data: only on img src (DOMPurify's DATA_URI_TAGS default).
const ALLOWED_URI_REGEXP = /^(?:(?:https?|mailto):|[^a-z]|[a-z+.-]+(?:[^a-z+.\-:]|$))/i;
const CODE_LANGUAGE_CLASS = /^language-[A-Za-z0-9_+#.-]{1,40}$/;

/**
 * Builds the sanitiser from a DOMPurify factory (the `dompurify` default export, or the browser
 * build's window.DOMPurify). It gets its own instance, so the hooks below touch no other caller.
 * @param {Function} createDOMPurify
 * @returns {(html: string | null | undefined) => string}
 */
export function createProcedureSanitizer(createDOMPurify) {
  const purify = createDOMPurify(window);

  purify.addHook('uponSanitizeAttribute', (node, data) => {
    // Only a code block's language class (TipTap: class="language-bash"); no styling hooks.
    if (data.attrName === 'class') {
      data.keepAttr = node.nodeName === 'CODE' && CODE_LANGUAGE_CLASS.test(data.attrValue.trim());
    }
    if (data.attrName === 'target') {
      data.keepAttr = data.attrValue === '_blank';
    }
  });
  purify.addHook('afterSanitizeAttributes', (node) => {
    if (node.nodeName === 'A' && node.getAttribute('target') === '_blank') {
      const rel = new Set((node.getAttribute('rel') || '').split(/\s+/).filter(Boolean));
      rel.add('noopener');
      rel.add('noreferrer');
      node.setAttribute('rel', [...rel].join(' '));
    }
  });

  return (html) =>
    html
      ? purify.sanitize(html, {
          ALLOWED_TAGS,
          ALLOWED_ATTR,
          ALLOWED_URI_REGEXP,
          ALLOW_DATA_ATTR: false,
          ALLOW_ARIA_ATTR: false,
        })
      : '';
}
