<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Support;

/**
 * Text for a view that Vue will compile.
 *
 * Statamic hands a utility's rendered markup to Vue as a template, so
 * Blade's escaping is only half the job: `{{ }}` escapes `<>&"'` and
 * leaves braces alone, and a value containing `{{`, which a researcher's
 * title or a source's failure detail can, is read by Vue as an expression
 * and evaluated in the viewer's control-panel session. Vue finds its
 * delimiters in the raw source before it decodes entities, so a brace
 * written as an entity is text and nothing else.
 */
final class VueSafe
{
    public static function text(mixed $value): string
    {
        return \str_replace(['{', '}'], ['&#123;', '&#125;'], e((string) $value));
    }
}
