<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Publications;

use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

/**
 * The collection this addon owns, named by config, created when first needed.
 *
 * It never adopts a host's existing collection: if one with this handle
 * exists it is used as is, and the host is responsible for its blueprint
 * carrying the fields below. What the addon writes is recorded in the
 * blueprint it ships, so a host that renamed a field sees the mismatch in the
 * control panel rather than in a silently empty tag.
 */
final class PublicationsCollection
{
    public const string BLUEPRINT = 'publication';

    public const array STATUSES = ['candidate', 'approved', 'rejected'];

    public function __construct(
        private readonly string $handle,
        private readonly string $researcherCollection,
    ) {}

    public function handle(): string
    {
        return $this->handle;
    }

    /**
     * Create the collection and its blueprint if either is missing.
     *
     * Idempotent, and called by the install command and by every fetch. A
     * missing collection at two in the morning should be created, not turned
     * into a night of failed jobs.
     */
    public function ensure(): void
    {
        if (Collection::findByHandle($this->handle) === null) {
            Collection::make($this->handle)
                ->title('Publications')
                ->save();
        }

        if (Blueprint::find('collections.'.$this->handle.'.'.self::BLUEPRINT) === null) {
            Blueprint::make(self::BLUEPRINT)
                ->setNamespace('collections.'.$this->handle)
                ->setContents($this->blueprintContents())
                ->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function blueprintContents(): array
    {
        $field = static fn (string $handle, array $config): array => ['handle' => $handle, 'field' => $config];

        return [
            'title' => 'Publication',
            'tabs' => [
                'main' => [
                    'display' => 'Main',
                    'sections' => [
                        ['display' => 'Bibliographic', 'fields' => [
                            $field('title', ['type' => 'text', 'display' => 'Title', 'validate' => ['required']]),
                            $field('journal', ['type' => 'text', 'display' => 'Journal']),
                            $field('publication_date', ['type' => 'text', 'display' => 'Publication date', 'instructions' => 'As precise as the source had it: a year, a month, or a day.']),
                            $field('volume', ['type' => 'text', 'display' => 'Volume', 'width' => 33]),
                            $field('issue', ['type' => 'text', 'display' => 'Issue', 'width' => 33]),
                            $field('pages', ['type' => 'text', 'display' => 'Pages', 'width' => 33]),
                            $field('abstract', ['type' => 'textarea', 'display' => 'Abstract']),
                            $field('authors', ['type' => 'grid', 'display' => 'Authors', 'fields' => [
                                $field('name', ['type' => 'text', 'display' => 'Name']),
                                $field('affiliation', ['type' => 'text', 'display' => 'Affiliation']),
                                $field('orcid', ['type' => 'text', 'display' => 'ORCID iD']),
                            ]]),
                        ]],
                        ['display' => 'Identifiers', 'fields' => [
                            $field('doi', ['type' => 'text', 'display' => 'DOI', 'width' => 50]),
                            $field('pmid', ['type' => 'text', 'display' => 'PMID', 'width' => 50]),
                            $field('external_urls', ['type' => 'list', 'display' => 'External URLs']),
                            $field('source_apis', ['type' => 'list', 'display' => 'Reported by', 'instructions' => 'Which sources returned this paper. A name search is an estimate, not a claim.']),
                        ]],
                    ],
                ],
                'sidebar' => [
                    'display' => 'Verification',
                    'sections' => [
                        ['fields' => [
                            $field('researchers', ['type' => 'entries', 'display' => 'Researchers', 'collections' => [$this->researcherCollection], 'validate' => ['required'], 'instructions' => 'Approval is for every name listed. Adding a name by hand is confirming the paper for them.']),
                            $field('verification_status', ['type' => 'select', 'display' => 'Status', 'default' => 'candidate', 'options' => [
                                'candidate' => 'Candidate (not yet confirmed)',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                            ], 'validate' => ['required']]),
                            $field('verified_by', ['type' => 'users', 'display' => 'Verified by', 'max_items' => 1]),
                            $field('verified_at', ['type' => 'date', 'display' => 'Verified at', 'time_enabled' => true]),
                            $field('possible_duplicates', ['type' => 'entries', 'display' => 'Possible duplicates', 'collections' => [$this->handle], 'instructions' => 'Same title, different identifiers. Not merged: an erratum and its paper share a title.']),
                            $field('fetched_at', ['type' => 'date', 'display' => 'Last fetched', 'time_enabled' => true, 'read_only' => true]),
                        ]],
                    ],
                ],
            ],
        ];
    }
}
