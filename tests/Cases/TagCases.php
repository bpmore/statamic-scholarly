<?php

declare(strict_types=1);

use Bpmore\Scholarly\Publications\PublicationsCollection;
use Illuminate\Support\Str;
use Statamic\Facades\Antlers;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

const TAG_PUBS = 'research_outputs';


function tagResearcher(string $slug): \Statamic\Contracts\Entries\Entry
{
    $entry = Entry::make()->collection('faculty')->slug($slug)->data(['title' => ucfirst($slug)]);
    $entry->save();

    return $entry;
}

function publication(array $data): \Statamic\Contracts\Entries\Entry
{
    $entry = Entry::make()->collection(TAG_PUBS)->slug((string) Str::uuid())->data([
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

/**
 * The publications tag, run against the Stache and against the Eloquent driver alike.
 *
 * @return array<string, Closure(): void>
 */
function tagCasesSetup(): void
{
    config()->set('statamic-scholarly.researchers.collection', 'faculty');
    config()->set('statamic-scholarly.publications.collection', TAG_PUBS);
    Collection::make('faculty')->save();
    app(PublicationsCollection::class)->ensure();
}

function tagCases(): array
{
    return [
        'returns only approved publications for the researcher, newest first by default' => function (): void {
        $ada = tagResearcher('ada');
        $bob = tagResearcher('bob');

        publication(['title' => 'Old approved', 'researchers' => [$ada->id()], 'publication_date' => '2019']);
        publication(['title' => 'New approved', 'researchers' => [$ada->id(), $bob->id()], 'publication_date' => '2024-05']);
        publication(['title' => 'Candidate', 'researchers' => [$ada->id()], 'publication_date' => '2025', 'verification_status' => 'candidate']);
        publication(['title' => 'Rejected', 'researchers' => [$ada->id()], 'publication_date' => '2025', 'verification_status' => 'rejected']);
        publication(['title' => 'Bob only', 'researchers' => [$bob->id()], 'publication_date' => '2023']);

        $out = render('{{ scholarly:publications researcher="'.$ada->id().'" }}{{ title }}|{{ /scholarly:publications }}');

        expect($out)->toBe('New approved|Old approved|');
        },

        'never exposes a candidate or a rejected entry, whatever the parameters say' => function (): void {
        $ada = tagResearcher('ada');
        publication(['title' => 'Candidate', 'researchers' => [$ada->id()], 'verification_status' => 'candidate']);

        $out = render('{{ scholarly:publications researcher="'.$ada->id().'" verification_status="candidate" status="any" }}{{ title }}{{ /scholarly:publications }}');

        expect($out)->toBe('');
        },

        'accepts a slug in the researcher collection, and defaults to the current entry' => function (): void {
        $ada = tagResearcher('ada');
        publication(['title' => 'P', 'researchers' => [$ada->id()]]);

        expect(render('{{ scholarly:publications researcher="ada" }}{{ title }}{{ /scholarly:publications }}'))->toBe('P')
            ->and(render('{{ scholarly:publications }}{{ title }}{{ /scholarly:publications }}', ['id' => $ada->id()]))->toBe('P')
            ->and(render('{{ scholarly:publications researcher="nobody" }}{{ title }}{{ /scholarly:publications }}'))->toBe('');
        },

        'filters by a year or a year range against every date precision' => function (): void {
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
        },

        'honours limit, sort, journal and as, and exposes a count' => function (): void {
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
        },

        'joins author names with the modifier, with et al. past a cap' => function (): void {
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
        }
    ];
}

/**
 * The citation modifier, the feed and its url, across the same drivers.
 *
 * @return array<string, Closure(): void>
 */
function feedCases(): array
{
    return [
        'renders a citation in each style from the loop item, plain or html' => function (): void {
            $ada = tagResearcher('ada');
            publication([
                'title' => 'Intramyocardial Hemorrhage After Myocardial Infarction', 'journal' => 'Circulation & Co',
                'publication_date' => '2025-08-12', 'volume' => '152', 'issue' => '6', 'pages' => '349-351',
                'doi' => '10.1161/CIRCULATIONAHA.125.074645', 'researchers' => [$ada->id()],
                'authors' => [['name' => 'Berry C'], ['name' => 'Carberry J'], ['name' => 'Dharmakumar R']],
            ]);

            $tpl = fn (string $args) => '{{ scholarly:publications researcher="ada" }}{{ id | scholarly_citation:'.$args.' }}{{ /scholarly:publications }}';

            expect(render($tpl('ama')))->toBe('Berry C, Carberry J, Dharmakumar R. Intramyocardial Hemorrhage After Myocardial Infarction. Circulation & Co. 2025;152(6):349-351. doi:10.1161/CIRCULATIONAHA.125.074645')
                ->and(render($tpl('vancouver')))->toBe('Berry C, Carberry J, Dharmakumar R. Intramyocardial Hemorrhage After Myocardial Infarction. Circulation & Co. 2025 Aug 12;152(6):349-351. doi: 10.1161/CIRCULATIONAHA.125.074645')
                ->and(render($tpl('apa')))->toBe('Berry, C., Carberry, J., & Dharmakumar, R. (2025). Intramyocardial Hemorrhage After Myocardial Infarction. Circulation & Co, 152(6), 349-351. https://doi.org/10.1161/CIRCULATIONAHA.125.074645')
                ->and(render($tpl('apa:html')))->toBe('Berry, C., Carberry, J., &amp; Dharmakumar, R. (2025). Intramyocardial Hemorrhage After Myocardial Infarction. <i>Circulation &amp; Co</i>, 152(6), 349-351. https://doi.org/10.1161/CIRCULATIONAHA.125.074645');
        },

        'serves the feed as json with the same approved-only rule, and the tag knows its url' => function (): void {
            $ada = tagResearcher('ada');
            publication(['title' => 'Old', 'researchers' => [$ada->id()], 'publication_date' => '2019', 'doi' => '10.1000/old']);
            publication(['title' => 'New', 'researchers' => [$ada->id()], 'publication_date' => '2024-05', 'pmid' => '7']);
            publication(['title' => 'Candidate', 'researchers' => [$ada->id()], 'publication_date' => '2025', 'verification_status' => 'candidate']);

            $url = render('{{ scholarly:feed_url researcher="ada" }}');

            expect($url)->toEndWith('/!/statamic-scholarly/feed/'.$ada->id())
                ->and(render('{{ scholarly:feed_url researcher="nobody" }}'))->toBe('');

            $response = $this->getJson($url);
            $response->assertOk();
            $json = $response->json();

            expect($json['status'])->toBe('approved')
                ->and($json['count'])->toBe(2)
                ->and($json['researcher']['title'])->toBe('Ada')
                ->and(array_column($json['publications'], 'title'))->toBe(['New', 'Old'])
                ->and($json['publications'][0]['pmid'])->toBe('7')
                ->and($json['publications'][0]['authors'])->toBe([]);

            expect($this->getJson($url.'?year=2019')->json('count'))->toBe(1)
                ->and($this->getJson($url.'?limit=1')->json('publications.0.title'))->toBe('New')
                ->and($this->getJson('/!/statamic-scholarly/feed/ada')->json('count'))->toBe(2, 'a slug works too');

            $this->getJson('/!/statamic-scholarly/feed/nobody')->assertNotFound();
        },
    ];
}

/**
 * @return array<string, Closure(): void>
 */
function readinessCases(): array
{
    return [
        'keeps an approved draft out of every public list and count' => function (): void {
            $ada = tagResearcher('ada');
            publication(['title' => 'Public paper', 'researchers' => [$ada->id()]]);
            $draft = publication(['title' => 'Private draft paper', 'researchers' => [$ada->id()]]);
            $draft->published(false)->save();

            expect($draft->status())->toBe('draft')
                ->and(render('{{ scholarly:publications researcher="ada" status="any" }}{{ title }}{{ /scholarly:publications }}'))->toBe('Public paper')
                ->and(render('{{ scholarly:publications_count researcher="ada" }}'))->toBe('1');

            $response = $this->getJson('/!/statamic-scholarly/feed/ada');
            $response->assertOk()->assertJsonCount(1, 'publications');
            expect($response->json('publications.0.title'))->toBe('Public paper');
        },

        'does not expose an unpublished researcher by id slug or current-entry context' => function (): void {
            $ada = tagResearcher('ada');
            publication(['title' => 'A private researcher paper', 'researchers' => [$ada->id()]]);
            $ada->published(false)->save();

            expect($ada->status())->toBe('draft');

            foreach (['ada', (string) $ada->id()] as $given) {
                $this->getJson('/!/statamic-scholarly/feed/'.$given)->assertNotFound();
                expect(render('{{ scholarly:publications researcher="'.$given.'" }}{{ title }}{{ /scholarly:publications }}'))->toBe('')
                    ->and(render('{{ scholarly:publications_count researcher="'.$given.'" }}'))->toBe('0')
                    ->and(render('{{ scholarly:feed_url researcher="'.$given.'" }}'))->toBe('');
            }

            expect(render('{{ scholarly:publications }}{{ title }}{{ /scholarly:publications }}', ['id' => $ada->id()]))->toBe('')
                ->and(render('{{ scholarly:publications_count }}', ['id' => $ada->id()]))->toBe('0')
                ->and(render('{{ scholarly:feed_url }}', ['id' => $ada->id()]))->toBe('');
        },

        'keeps scheduled approved papers private until their collection permits publication' => function (): void {
            $ada = tagResearcher('ada');
            Collection::findByHandle(TAG_PUBS)->dated(true)->futureDateBehavior('private')->save();

            foreach (['Public paper' => now()->subDay(), 'Scheduled paper' => now()->addDay()] as $title => $date) {
                Entry::make()->collection(TAG_PUBS)->slug((string) Str::uuid())->date($date)
                    ->data(['title' => $title, 'verification_status' => 'approved', 'researchers' => [$ada->id()]])->save();
            }

            expect(render('{{ scholarly:publications researcher="ada" }}{{ title }}{{ /scholarly:publications }}'))->toBe('Public paper')
                ->and(render('{{ scholarly:publications_count researcher="ada" }}'))->toBe('1');
            $this->getJson('/!/statamic-scholarly/feed/ada')->assertOk()->assertJsonCount(1, 'publications');
        },

        'keeps expired approved papers out of the public list count and feed' => function (): void {
            $ada = tagResearcher('ada');
            Collection::findByHandle(TAG_PUBS)->dated(true)->pastDateBehavior('private')->futureDateBehavior('public')->save();

            foreach (['Public paper' => now()->addDay(), 'Expired paper' => now()->subDay()] as $title => $date) {
                $paper = Entry::make()->collection(TAG_PUBS)->slug((string) Str::uuid())->date($date)
                    ->data(['title' => $title, 'verification_status' => 'approved', 'researchers' => [$ada->id()]]);
                $paper->save();
                expect($paper->status())->toBe($title === 'Expired paper' ? 'expired' : 'published');
            }

            expect(render('{{ scholarly:publications researcher="ada" }}{{ title }}{{ /scholarly:publications }}'))->toBe('Public paper')
                ->and(render('{{ scholarly:publications_count researcher="ada" }}'))->toBe('1');
            $response = $this->getJson('/!/statamic-scholarly/feed/ada');
            $response->assertOk()->assertJsonCount(1, 'publications');
            expect($response->json('publications.0.title'))->toBe('Public paper');
        },

        'answers 404 from the feed when a site turns it off' => function (): void {
            $ada = tagResearcher('ada');
            publication(['title' => 'P', 'researchers' => [$ada->id()]]);
            config()->set('statamic-scholarly.feed.enabled', false);

            $this->getJson('/!/statamic-scholarly/feed/ada')->assertNotFound();

            config()->set('statamic-scholarly.feed.enabled', true);

            expect($this->getJson('/!/statamic-scholarly/feed/ada')->json('count'))->toBe(1);
        },
    ];
}
