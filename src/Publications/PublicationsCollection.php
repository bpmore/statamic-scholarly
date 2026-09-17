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
    public const BLUEPRINT = 'publication';

    public const STATUSES = ['candidate', 'approved', 'rejected'];

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
    /**
     * @return list<string> Field handles added to an existing blueprint
     */
    public function ensure(): array
    {
        if (Collection::findByHandle($this->handle) === null) {
            Collection::make($this->handle)
                ->title('Publications')
                ->save();
        }

        $blueprint = Blueprint::find('collections.'.$this->handle.'.'.self::BLUEPRINT);

        if ($blueprint === null) {
            Blueprint::make(self::BLUEPRINT)
                ->setNamespace('collections.'.$this->handle)
                ->setContents($this->blueprintContents())
                ->save();

            return [];
        }

        return $this->addMissingFields($blueprint);
    }

    /**
     * An upgrade that adds a field must reach a blueprint that already
     * exists, or the control panel never shows it and the host is left with
     * data it cannot see. Fields the host already has, and anything the
     * host changed about them, are left exactly as they are: only handles
     * the blueprint lacks are appended, to the tab and section they ship in.
     *
     * @return list<string> Handles added
     */
    public function addMissingFields(\Statamic\Fields\Blueprint $blueprint): array
    {
        $contents = $blueprint->contents();
        $added = [];

        foreach ($this->blueprintContents()['tabs'] as $tabHandle => $tab) {
            foreach ($tab['sections'] as $sectionIndex => $section) {
                foreach ($section['fields'] as $field) {
                    if ($blueprint->hasField($field['handle'])) {
                        continue;
                    }

                    $contents['tabs'][$tabHandle] ??= ['display' => $tab['display'] ?? \ucfirst($tabHandle), 'sections' => []];
                    $contents['tabs'][$tabHandle]['sections'][$sectionIndex] ??= ['fields' => []];
                    $contents['tabs'][$tabHandle]['sections'][$sectionIndex]['fields'][] = $field;
                    $added[] = $field['handle'];
                }
            }
        }

        if ($added !== []) {
            $blueprint->setContents($contents)->save();
        }

        return $added;
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
                            $field('title', ['type' => 'text', 'display' => 'Title', 'validate' => ['required'], 'listable' => true]),
                            $field('journal', ['type' => 'text', 'display' => 'Journal', 'listable' => true]),
                            $field('publication_date', ['type' => 'text', 'display' => 'Publication date', 'instructions' => 'As precise as the source had it: a year, a month, or a day.', 'listable' => true]),
                            $field('volume', ['type' => 'text', 'display' => 'Volume', 'width' => 33]),
                            $field('issue', ['type' => 'text', 'display' => 'Issue', 'width' => 33]),
                            $field('pages', ['type' => 'text', 'display' => 'Pages', 'width' => 33]),
                            $field('abstract', ['type' => 'textarea', 'display' => 'Abstract', 'listable' => false]),
                            $field('authors', ['type' => 'grid', 'display' => 'Authors', 'listable' => false, 'fields' => [
                                $field('name', ['type' => 'text', 'display' => 'Name']),
                                $field('affiliation', ['type' => 'text', 'display' => 'Affiliation']),
                                $field('orcid', ['type' => 'text', 'display' => 'ORCID iD']),
                            ]]),
                        ]],
                        ['display' => 'Identifiers', 'fields' => [
                            $field('doi', ['type' => 'text', 'display' => 'DOI', 'width' => 50]),
                            $field('pmid', ['type' => 'text', 'display' => 'PMID', 'width' => 50]),
                            $field('external_urls', ['type' => 'list', 'display' => 'External URLs']),
                            $field('source_apis', ['type' => 'list', 'display' => 'Reported by', 'instructions' => 'Which sources returned this paper. A name search is an estimate, not a claim.', 'listable' => true]),
                        ]],
                    ],
                ],
                'sidebar' => [
                    'display' => 'Verification',
                    'sections' => [
                        ['fields' => [
                            $field('researchers', ['type' => 'entries', 'display' => 'Researchers', 'collections' => [$this->researcherCollection], 'validate' => ['required'], 'listable' => true, 'instructions' => 'Approval is for every name listed. Adding a name by hand is confirming the paper for them.']),
                            $field('verification_status', ['type' => 'select', 'display' => 'Status', 'default' => 'candidate', 'listable' => true, 'options' => [
                                'candidate' => 'Candidate (not yet confirmed)',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                            ], 'validate' => ['required']]),
                            $field('verified_by', ['type' => 'users', 'display' => 'Verified by', 'max_items' => 1, 'listable' => true]),
                            $field('discarded_at', ['type' => 'date', 'display' => 'Set aside on score', 'time_enabled' => true, 'read_only' => true, 'listable' => true, 'instructions' => 'Below the floor on a calibrated score. Still a candidate; confirm it if the score is wrong.']),
                            $field('auto_approved', ['type' => 'toggle', 'display' => 'Approved on score', 'read_only' => true, 'listable' => true, 'instructions' => 'No person confirmed this. The score did, and "Why" says on what.']),
                            $field('verified_at', ['type' => 'date', 'display' => 'Verified at', 'time_enabled' => true]),
                            $field('rejection_reason', ['type' => 'text', 'display' => 'Rejection reason', 'if' => ['verification_status' => 'rejected']]),
                            $field('deferred_at', ['type' => 'date', 'display' => 'Deferred at', 'time_enabled' => true, 'if' => ['verification_status' => 'candidate']]),
                            $field('confidence_score', ['type' => 'float', 'display' => 'Confidence', 'read_only' => true, 'listable' => true, 'instructions' => 'Read the state first. "uncalibrated" is an ordering, not a probability. "certain" is an anchor, not a score.']),
                            $field('confidence_state', ['type' => 'text', 'display' => 'Confidence state', 'read_only' => true, 'listable' => true]),
                            $field('confidence_breakdown', ['type' => 'array', 'display' => 'Why', 'mode' => 'dynamic', 'read_only' => true, 'listable' => false]),
                            $field('possible_duplicates', ['type' => 'entries', 'display' => 'Possible duplicates', 'collections' => [$this->handle], 'instructions' => 'Same title, different identifiers. Not merged: an erratum and its paper share a title.']),
                            $field('fetched_at', ['type' => 'date', 'display' => 'Last fetched', 'time_enabled' => true, 'read_only' => true]),
                            $field('notified_at', ['type' => 'date', 'display' => 'Verifier notified at', 'time_enabled' => true, 'read_only' => true]),
                            $field('title_key', ['type' => 'text', 'display' => 'Title key', 'instructions' => 'The title as compared across entries. Written by the fetch.', 'read_only' => true, 'listable' => false]),
                        ]],
                    ],
                ],
            ],
        ];
    }
}
