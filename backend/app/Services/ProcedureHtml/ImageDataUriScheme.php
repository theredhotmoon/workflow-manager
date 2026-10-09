<?php

namespace App\Services\ProcedureHtml;

use HTMLPurifier_URIScheme_data;

/**
 * HTMLPurifier's data: scheme (base64 only, the bytes must really be an image of an allowed type,
 * checked with getimagesize) with webp added for images pasted into the editor. No SVG: it can
 * carry script.
 */
class ImageDataUriScheme extends HTMLPurifier_URIScheme_data
{
    public $allowed_types = [
        'image/jpeg' => true,
        'image/gif' => true,
        'image/png' => true,
        'image/webp' => true,
    ];
}
