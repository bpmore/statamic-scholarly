<?php

declare(strict_types=1);

use Bpmore\Scholarly\Publications\PublicationsCollection;
use Illuminate\Support\Str;
use Statamic\Facades\Antlers;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

const TAG_PUBS = 'research_outputs';

beforeEach(function (): void {
    config()->set('statamic-scholarly.researchers.collection', 'faculty');
    config()->set('statamic-scholarly.publications.collection', TAG_PUBS);
    Collection::make('faculty')->save();
    app(PublicationsCollection::class)->ensure();
});

function tagResearcher(string $slug): \Statamic\Contracts\Entries\Entry
{
    $entry = Entry::make()->collection('faculty')->slug($slug)->data(['title' => ucfirst($slug)]);
    $entry->save();

    return $entry;
}

function publication(array $data): \Statamic\Contracts\Entries\Entry
{
    $id = (string) Str::uuid();
    $entry = Entry::make()->collection(TAG_PUBS)->id($id)->slug($id)->data([
        'verification_status' => 'approved',
        ...$data,
    ]);
    $entry->save();

    return $entry;
}

function render(string $template, array $context = []): string
{
    // Trusted: Antlers refuses to run tags in untrusted (user-supplied)
    // strings, which is what parse() assumes without the third argument.
    return trim((string) Antlers::parse($template, $context, trusted: true));
}

it('returns only approved publications for the researcher, newest first by default', function (): void {
    $ada = tagResearcher('ada');
    $bob = tagResearcher('bob');

    publication(['title' => 'Old approved', 'researchers' => [$ada->id()], 'publication_date' => '2019']);
    publication(['title' => 'New approved', 'researchers' => [$ada->id(), $bob->id()], 'publication_date' => '2024-05']);
    publication(['title' => 'Candidate', 'researchers' => [$ada->id()], 'publication_date' => '2025', 'verification_status' => 'candidate']);
    publication(['title' => 'Rejected', 'researchers' => [$ada->id()], 'publication_date' => '2025', 'verification_status' => 'rejected']);
    publication(['title' => 'Bob only', 'researchers' => [$bob->id()], 'publication_date' => '2023']);

    $out = render('{{ scholarly:publications researcher="'.$ada->id().'" }}{{ title }}|{{ /scholarly:publications }}');

    expect($out)->toBe('New approved|Old approved|');
});

it('never exposes a candidate or a rejected entry, whatever the parameters say', function (): void {
    $ada = tagResearcher('ada');
    publication(['title' => 'Candidate', 'researchers' => [$ada->id()], 'verification_status' => 'candidate']);

    $out = render('{{ scholarly:publications researcher="'.$ada->id().'" verification_status="candidate" status="any" }}{{ title }}{{ /scholarly:publications }}');

    expect($out)->toBe('');
});

it('accepts a slug in the researcher collection, and defaults to the current entry', function (): void {
    $ada = tagResearcher('ada');
    publication(['title' => 'P', 'researchers' => [$ada->id()]]);

    expect(render('{{ scholarly:publications researcher="ada" }}{{ title }}{{ /scholarly:publications }}'))->toBe('P')
        ->and(render('{{ scholarly:publications }}{{ title }}{{ /scholarly:publications }}', ['id' => $ada->id()]))->toBe('P')
        ->and(render('{{ scholarly:publications researcher="nobody" }}{{ title }}{{ /scholarly:publications }}'))->toBe('');
});

it('filters by a year or a year range against every date precision', function (): void {
    $ada = tagResearcher('ada');
    publication(['title' => 'y2019', 'researchers' => [$ada->id()], 'publication_date' => '2019-12-31']);
    publication(['title' => 'y2020', 'researchers' => [$ada->id()], 'publication_date' => '2020']);
    publication(['title' => 'y2022', 'researchers' => [$ada->id()], 'publication_date' => '2022-06']);
    publication(['title' => 'y2024', 'researchers' => [$ada->id()], 'publication_date' => '2024-01-01']);

    $tpl = fn (string $year) => '{{ scholarly:publications researcher="ada" year="'.$year.'" sort="publication_date:asc" }}{{ title }} {{ /scholarly:publications }}';

    expect(render($tpl('2020')))->toBe('y2020')
        ->and(render($tpl('2020:2022')))->toBe('y2020 y2022')
        ->and(render($tpl('2019:2024')))->toBe('y2019 y2020 y2022 y2024')
        ->and(render($tpl('nineteen')))->toBe('y2019 y2020 y2022 y2024');
});

it('honours limit, sort, journal and as, and exposes a count', function (): void {
    $ada = tagResearcher('ada');
    publication(['title' => 'A', 'researchers' => [$ada->id()], 'publication_date' => '2021', 'journal' => 'Circulation']);
    publication(['title' => 'B', 'researchers' => [$ada->id()], 'publication_date' => '2022', 'journal' => 'Circulation']);
    publication(['title' => 'C', 'researchers' => [$ada->id()], 'publication_date' => '2023', 'journal' => 'Nature']);

    expect(render('{{ scholarly:publications researcher="ada" limit="2" }}{{ title }}{{ /scholarly:publications }}'))->toBe('CB')
        ->and(render('{{ scholarly:publications researcher="ada" sort="title:asc" }}{{ title }}{{ /scholarly:publications }}'))->toBe('ABC')
        ->and(render('{{ scholarly:publications researcher="ada" journal="Circulation" }}{{ title }}{{ /scholarly:publications }}'))->toBe('BA')
        ->and(render('{{ scholarly:publications researcher="ada" as="pubs" }}{{ total_results }}:{{ pubs }}{{ title }}{{ /pubs }}{{ /scholarly:publications }}'))->toBe('3:CBA')
        ->and(render('{{ scholarly:publications_count researcher="ada" }}'))->toBe('3')
        ->and(render('{{ scholarly:publications_count researcher="nobody" }}'))->toBe('0');
});

it('joins author names with the modifier, with et al. past a cap', function (): void {
    $ada = tagResearcher('ada');
    publication(['title' => 'P', 'researchers' => [$ada->id()], 'authors' => [
        ['name' => 'Berry C', 'affiliation' => null, 'orcid' => null],
        ['name' => 'Carberry J', 'affiliation' => null, 'orcid' => null],
        ['name' => 'Dharmakumar R', 'affiliation' => null, 'orcid' => null],
    ]]);

    expect(render('{{ scholarly:publications researcher="ada" }}{{ authors | scholarly_authors }}{{ /scholarly:publications }}'))
        ->toBe('Berry C, Carberry J, Dharmakumar R')
        ->and(render('{{ scholarly:publications researcher="ada" }}{{ authors | scholarly_authors:2 }}{{ /scholarly:publications }}'))
        ->toBe('Berry C, Carberry J, et al.');
});
