<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Modifiers;

use Statamic\Fields\Value;
use Statamic\Fields\Values;
use Statamic\Modifiers\Modifier;

/**
 * `{{ authors | scholarly_authors }}` joins the stored author names.
 *
 * `{{ authors | scholarly_authors:3 }}` shows the first three and "et al."
 * when there are more. The names are the source's form ("Carberry J"),
 * unchanged: reformatting a name is a guess about which part is the surname.
 */
final class ScholarlyAuthors extends Modifier
{
    /**
     * @param  mixed  $value
     * @param  array<int, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    public function index($value, $params, $context): string
    {
        $names = [];

        foreach (\is_iterable($value) ? $value : [] as $author) {
            // A grid row arrives augmented (Values), raw (array) or, from a
            // source that only had a string, as the name itself.
            $name = match (true) {
                $author instanceof Values => $author->raw('name'),
                \is_array($author) => $author['name'] ?? null,
                \is_string($author) => $author,
                default => null,
            };

            if ($name instanceof Value) {
                $name = $name->raw();
            }

            if (\is_string($name) && \trim($name) !== '') {
                $names[] = \trim($name);
            }
        }

        $max = isset($params[0]) && \ctype_digit((string) $params[0]) ? (int) $params[0] : null;

        if ($max !== null && $max > 0 && \count($names) > $max) {
            return \implode(', ', \array_slice($names, 0, $max)).', et al.';
        }

        return \implode(', ', $names);
    }
}
