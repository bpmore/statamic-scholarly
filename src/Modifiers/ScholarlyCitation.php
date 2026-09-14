<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Modifiers;

use Bpmore\Scholarly\Citation\Formatter;
use Bpmore\Scholarly\Publications\ApprovedPublications;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Fields\Value;
use Statamic\Fields\Values;
use Statamic\Modifiers\Modifier;

/**
 * `{{ id | scholarly_citation:apa }}` inside a publications loop.
 *
 * The value is the entry's id (Antlers has no "this" for the loop item),
 * an entry, augmented values, or a record array. `html` as the second
 * argument wraps the journal in <i> and escapes the rest; without it,
 * plain text.
 */
final class ScholarlyCitation extends Modifier
{
    /**
     * @param  mixed  $value  An entry, augmented values, or a record array
     * @param  array<int, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    public function index($value, $params, $context): string
    {
        $style = \is_string($params[0] ?? null) ? $params[0] : 'ama';
        $html = \in_array($params[1] ?? null, ['html', true, 'true'], true);

        $record = self::record($value);

        if ($record === null) {
            return '';
        }

        return Formatter::format($record, $style, $html);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function record(mixed $value): ?array
    {
        if ($value instanceof Value) {
            $value = $value->value();
        }

        if (\is_string($value) || \is_int($value)) {
            $value = EntryFacade::find((string) $value);
        }

        if ($value instanceof Entry) {
            return ApprovedPublications::record($value);
        }

        if ($value instanceof Values) {
            $entry = $value->getProxiedInstance();

            if ($entry instanceof \Statamic\Data\AugmentedData) {
                $entry = $entry->getAugmented()?->getData();
            }

            return $entry instanceof Entry ? ApprovedPublications::record($entry) : $value->toRawArray();
        }

        if (\is_array($value)) {
            return $value;
        }

        return null;
    }
}
