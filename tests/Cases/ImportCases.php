<?php

declare(strict_types=1);

use Bpmore\Scholarly\Publications\PublicationsCollection;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

const IMPORT_PUBS = 'research_outputs';

function importResearcher(): \Statamic\Contracts\Entries\Entry
{
    $entry = Entry::make()->collection('faculty')->slug('josiah-carberry')->data(['title' => 'Josiah Carberry', 'ncbi_author_query' => 'Carberry J']);
    $entry->save();

    return $entry;
}

function importedFor(\Statamic\Contracts\Entries\Entry $researcher): \Illuminate\Support\Collection
{
    return Entry::query()->where('collection', IMPORT_PUBS)->get()
        ->filter(fn ($e) => in_array((string) $researcher->id(), array_map('strval', (array) $e->get('researchers')), true))
        ->keyBy(fn ($e) => $e->get('title'));
}

/**
 * Importing, run against the Stache and against the Eloquent driver alike.
 *
 * @return array<string, Closure(): void>
 */
function importCasesSetup(): void
{
    config()->set('statamic-scholarly.researchers.collection', 'faculty');
    config()->set('statamic-scholarly.researchers.fields', ['pubmed_author' => 'ncbi_author_query']);
    config()->set('statamic-scholarly.publications.collection', IMPORT_PUBS);
    Collection::make('faculty')->save();
    app(PublicationsCollection::class)->ensure();
}

function importCases(): array
{
    $fixture = fn (string $name): string => dirname(__DIR__).'/__fixtures__/import/'.$name;

    return [
        'imports a BibTeX file as scored candidates for the researcher, matching what the collection already has' => function () use ($fixture): void {
            $researcher = importResearcher();

            // One of the file's papers is already here from a fetch.
            $existing = Entry::make()->collection(IMPORT_PUBS)->slug('from-orcid')->data([
                'title' => 'A Methodology for the Emulation of Architecture', 'doi' => '10.5555/12345680',
                'verification_status' => 'candidate', 'researchers' => [(string) $researcher->id()], 'source_apis' => ['orcid'],
            ]);
            $existing->save();

            $this->artisan('scholarly:import', ['file' => $fixture('scholar-export.bib'), 'researcher' => 'josiah-carberry'])
                ->expectsOutputToContain('Skipped untitled: no title and no identifier')
                ->expectsOutputToContain('Josiah Carberry: 4 record(s) read, 1 skipped; 3 created, 1 refreshed, 0 approved kept, 0 rejected suppressed')
                ->assertSuccessful();

            $entries = importedFor($researcher);

            expect($entries)->toHaveCount(4)
                ->and($entries['Developing Thin Clients Using Amphibious Epistemologies']->get('verification_status'))->toBe('candidate')
                ->and($entries['Developing Thin Clients Using Amphibious Epistemologies']->get('source_apis'))->toBe(['import'])
                ->and($entries['Developing Thin Clients Using Amphibious Epistemologies']->get('confidence_state'))->toBe('uncalibrated', 'imports are scored like everything else')
                ->and($entries['A Methodology for the Emulation of Architecture']->get('source_apis'))->toBe(['orcid', 'import'], 'matched by DOI and joined, not duplicated')
                ->and($entries['A Methodology for the Emulation of Architecture']->get('abstract'))->toStartWith('We describe a methodology');
        },

        'imports a CSV and confirms on the way in as a named person, leaving a rejection alone' => function () use ($fixture): void {
            $researcher = importResearcher();
            $user = User::make()->id('jc')->email('jc@example.edu');
            $user->save();

            $rejected = Entry::make()->collection(IMPORT_PUBS)->slug('rejected-before')->data([
                'title' => 'Intramyocardial Hemorrhage After Myocardial Infarction', 'pmid' => '40788977',
                'verification_status' => 'rejected', 'researchers' => [(string) $researcher->id()],
            ]);
            $rejected->save();

            $this->artisan('scholarly:import', ['file' => $fixture('hand-kept.csv'), 'researcher' => (string) $researcher->id(), '--confirm-as' => 'jc@example.edu'])
                ->expectsOutputToContain('2 created, 0 refreshed, 0 approved kept, 1 rejected suppressed; 2 confirmed as jc@example.edu')
                ->assertSuccessful();

            $entries = importedFor($researcher);

            expect($entries['Untitled row without anything']->get('verification_status'))->toBe('approved')
                ->and($entries['Untitled row without anything']->get('verified_by'))->toBe('jc')
                ->and($entries['Untitled row without anything']->get('auto_approved'))->toBeNull('a person confirmed it, not the score')
                ->and(Entry::find($rejected->id())->get('verification_status'))->toBe('rejected');
        },

        'refuses a missing file, an unknown researcher, an unknown confirmer, and a file with nothing usable' => function () use ($fixture): void {
            importResearcher();

            $this->artisan('scholarly:import', ['file' => '/nowhere/x.bib', 'researcher' => 'josiah-carberry'])->assertFailed();
            $this->artisan('scholarly:import', ['file' => $fixture('scholar-export.bib'), 'researcher' => 'nobody'])->assertFailed();
            $this->artisan('scholarly:import', ['file' => $fixture('scholar-export.bib'), 'researcher' => 'josiah-carberry', '--confirm-as' => 'ghost@example.edu'])->assertFailed();

            $empty = tempnam(sys_get_temp_dir(), 'bib');
            file_put_contents($empty, '@book{nothing, author={X}}');
            $this->artisan('scholarly:import', ['file' => $empty, 'researcher' => 'josiah-carberry', '--format' => 'bibtex'])
                ->expectsOutputToContain('Nothing in the file could be imported.')
                ->assertFailed();
            unlink($empty);

            expect(Entry::query()->where('collection', IMPORT_PUBS)->count())->toBe(0);
        },
    ];
}
