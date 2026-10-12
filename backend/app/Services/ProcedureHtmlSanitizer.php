<?php

namespace App\Services;

use App\Services\ProcedureHtml\CodeLanguageClass;
use App\Services\ProcedureHtml\ImageDataUriScheme;
use HTMLPurifier;
use HTMLPurifier_Config;
use HTMLPurifier_URISchemeRegistry;

/**
 * Cleans procedure HTML before it is stored. The SPA renders it with v-html, so only what the
 * procedure editor itself produces may stay: TipTap's StarterKit (paragraphs, headings, lists,
 * blockquote, code and code blocks, hr, br, bold/italic/underline/strike), Link and Image. Scripts,
 * event handlers, styles, classes (other than a code block's language-*), frames and forms go;
 * links keep only http(s)/mailto, images only http(s) or a base64 png/jpeg/gif/webp. A link that
 * opens a new tab always gets rel="noopener noreferrer".
 *
 * Built on plain HTMLPurifier (no Laravel wrapper package); its definition cache is off, so nothing
 * is written to disk and config:cache is unaffected. Cleaning is idempotent.
 */
class ProcedureHtmlSanitizer
{
    private ?HTMLPurifier $purifier = null;

    public function clean(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        return $this->purifier()->purify($html);
    }

    private function purifier(): HTMLPurifier
    {
        return $this->purifier ??= new HTMLPurifier($this->config());
    }

    private function config(): HTMLPurifier_Config
    {
        // data: images as the editor stores pasted ones; the stock scheme rejects webp.
        HTMLPurifier_URISchemeRegistry::instance()->register('data', new ImageDataUriScheme);

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        // HTML (not XHTML) output, so <br>, <hr> and <img> come back as the editor writes them.
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed', implode(',', [
            'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote',
            'ul', 'ol[start|type]', 'li', 'pre', 'code[class]',
            'strong', 'b', 'em', 'i', 'u', 's', 'strike',
            'a[href|target|rel|title]', 'img[src|alt|title|width|height]',
        ]));
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'data' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Attr.AllowedRel', ['noopener', 'noreferrer', 'nofollow']);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.TargetNoreferrer', true);
        // The editor leaves out alt when an image has none; don't invent one.
        $config->set('Attr.DefaultImageAlt', '');

        $config->set('HTML.DefinitionID', 'procedure-content');
        $config->set('HTML.DefinitionRev', 1);
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            // A code block's language, as TipTap writes it (class="language-bash"); no other class.
            $def->addAttribute('code', 'class', new CodeLanguageClass);
        }

        return $config;
    }
}
