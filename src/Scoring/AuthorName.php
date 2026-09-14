<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Scoring;

/**
 * A person's name reduced to what the sources agree on: a surname and
 * initials.
 *
 * PubMed writes "Carberry J", ORCID writes "Carberry, J.S." or
 * "Josiah Carberry", a researcher entry says "Josiah S. Carberry". None of
 * those is more right than the others, and comparing them as strings
 * matches nothing. Framework free, and deliberately blunt: it never
 * decides which of two "J. Carberry"s is which. That is what the other
 * signals are for.
 */
final readonly class AuthorName
{
    /**
     * @param  string  $surname  Lower case, diacritics folded, particles kept ("van der berg")
     * @param  string  $initials  Lower case, no punctuation ("js")
     */
    private function __construct(
        public string $surname,
        public string $initials,
    ) {}

    public static function parse(string $raw): ?self
    {
        $raw = \trim(\preg_replace('/\s+/u', ' ', $raw) ?? $raw);

        if ($raw === '') {
            return null;
        }

        // "Surname, Given Names" or "Surname, G.N."
        if (\str_contains($raw, ',')) {
            [$surname, $given] = \array_map('trim', \explode(',', $raw, 2));

            return self::make($surname, $given);
        }

        $parts = \explode(' ', $raw);

        if (\count($parts) === 1) {
            return self::make($parts[0], '');
        }

        $last = $parts[\count($parts) - 1];

        // PubMed form: the last token is a run of capitals with no vowels
        // longer than three letters, "Carberry JS", "de Vries AB". A given
        // name is never written that way, so this is not ambiguous.
        if (\preg_match('/^[A-Z]{1,3}$/', $last) === 1) {
            return self::make(\implode(' ', \array_slice($parts, 0, -1)), $last);
        }

        // Natural order: "Josiah S. Carberry", "Jan de Vries". Particles
        // (de, van, der, von, da, di, le, la) belong to the surname.
        $surnameStart = \count($parts) - 1;

        while ($surnameStart > 1 && self::isParticle($parts[$surnameStart - 1])) {
            $surnameStart--;
        }

        return self::make(
            \implode(' ', \array_slice($parts, $surnameStart)),
            \implode(' ', \array_slice($parts, 0, $surnameStart)),
        );
    }

    /**
     * Whether two names could be the same person. Never "are".
     *
     * Full: same surname and every initial the shorter side has agrees in
     * order. Surname: same surname, initials disagree or one side has none.
     */
    public function matches(self $other): NameMatch
    {
        if ($this->surname !== $other->surname) {
            return NameMatch::None;
        }

        if ($this->initials === '' || $other->initials === '') {
            return NameMatch::Surname;
        }

        $shorter = \strlen($this->initials) <= \strlen($other->initials) ? $this->initials : $other->initials;
        $longer = $shorter === $this->initials ? $other->initials : $this->initials;

        return \str_starts_with($longer, $shorter) ? NameMatch::Full : NameMatch::Surname;
    }

    private static function make(string $surname, string $given): ?self
    {
        $surname = self::fold($surname);

        if ($surname === '') {
            return null;
        }

        $initials = '';

        foreach (\preg_split('/[\s.\-]+/u', self::fold($given)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }

            // A run of capitals in the source ("JS") is initials already;
            // after folding it is "js" and each letter is one initial. A
            // word ("josiah") gives its first letter.
            $initials .= \strlen($token) <= 3 && \preg_match('/^[a-z]+$/', $token) === 1 && \mb_strtoupper($given) === $given
                ? $token
                : \mb_substr($token, 0, 1);
        }

        return new self($surname, $initials);
    }

    private static function fold(string $value): string
    {
        $value = \mb_strtolower(\trim($value));

        if (\class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_D) ?: $value;
            $value = \preg_replace('/\p{Mn}+/u', '', $value) ?? $value;
        }

        $value = \preg_replace('/[^\p{L}\s]/u', '', $value) ?? $value;

        return \trim(\preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private static function isParticle(string $token): bool
    {
        return \in_array(\mb_strtolower($token), ['de', 'van', 'der', 'den', 'von', 'da', 'di', 'del', 'della', 'le', 'la', 'du', 'dos', 'das', 'al', 'el', 'bin', 'ibn', 'mc', 'mac', 'st'], true);
    }
}
