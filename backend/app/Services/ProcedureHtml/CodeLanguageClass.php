<?php

namespace App\Services\ProcedureHtml;

use HTMLPurifier_AttrDef;

/** class="language-xyz" on <code> (a TipTap code block's language), and no other class. */
class CodeLanguageClass extends HTMLPurifier_AttrDef
{
    public function validate($string, $config, $context)
    {
        $string = trim($string);

        return preg_match('/^language-[A-Za-z0-9_+#.-]{1,40}$/', $string) ? $string : false;
    }
}
