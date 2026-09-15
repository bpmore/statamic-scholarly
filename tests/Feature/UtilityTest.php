<?php

declare(strict_types=1);

use Bpmore\Scholarly\Fetching\FetchForResearcher;
use Bpmore\Scholarly\Notifications\NotifyVerifiers;
use Bpmore\Scholarly\Publications\PublicationsCollection;
use Bpmore\Scholarly\Utility\StatusReport;
use Illuminate\Support\Str;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Facades\Utility;

/*
 * The page under Tools. It reads what the last run wrote and what config
 * says, and every claim on it has to keep the product's distinctions: a
 * researcher with no identifier is not one with no publications, a partial
 * run is not an empty one, and a source that is off did not look.
 *
 * TestCase prevents stray HTTP requests, so a render that fetched anything
 * would fail here rather than quietly hit ORCID or PubMed.
 */

const UTILITY_PUBS = 'research_outputs';

beforeEach(function () {
    config()->set('statamic-scholarly.researchers.collection', 'faculty');
    config()->set('statamic-scholarly.researchers.fields', ['orcid' => 'orcid_id', 'pubmed_author' => 'pubmed_author_id']);
    config()->set('statamic-scholarly.publications.collection', UTILITY_PUBS);
    config()->set('statamic-scholarly.notifications.fallback', null);
    config()->set('statamic-scholarly.ncbi.email', 'ops@example.edu');

    Collection::make('faculty')->save();
    app(PublicationsCollection::class)->ensure();

    $this->super = User::make()->email('super@example.test')->makeSuper();
    $this->super->save();
});

afterEach(function () {
    // Users are files under the fixture folder; leave nothing behind. Roles
    // come from setTestRoles() and live in memory only.
    User::all()->each->delete();
});

function utilityResearcher(string $title, array $data = []): \Statamic\Contracts\Entries\Entry
{
    $entry = Entry::make()->collection('faculty')->slug(Str::slug($title))->data(['title' => $title] + $data);
    $entry->save();

    return $entry;
}

function utilityCandidate(array $researcherIds, array $extra = []): \Statamic\Contracts\Entries\Entry
{
    $slug = (string) Str::uuid();
    $entry = Entry::make()->collection(UTILITY_PUBS)->slug($slug)->data([
        'title' => 'Candidate '.$slug,
        'verification_status' => 'candidate',
        'researchers' => $researcherIds,
        ...$extra,
    ]);
    $entry->save();

    return $entry;
}

function scholarlyPage(): string
{
    $response = test()->actingAs(test()->super)->get(cp_route('utilities.index').'/scholarly')->assertOk()->getContent();
    preg_match('/data-page="([^"]+)"/', $response, $m);
    $page = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);

    return html_entity_decode((string) $page['props']['html'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Statamic hands the utility's HTML to Vue as a template, where an unclosed
 * tag is a compile error that shows as a blank page and never as a server
 * exception. Vue's template syntax is close enough to XML that parsing the
 * markup as XML, with bare boolean attributes given a value, catches it.
 */
function assertVueTemplateIsWellFormed(string $html): void
{
    $xml = preg_replace_callback('/<[a-z][\w-]*(\s[^<>]*)?\/?>/i', function ($m) {
        return preg_replace_callback(
            '/(\s)([a-z][\w:@.-]*)(="[^"]*")?/i',
            fn ($a) => $a[1].$a[2].($a[3] ?? '="'.$a[2].'"'),
            $m[0],
        );
    }, $html);
    $xml = str_replace('&', '&amp;', (string) $xml);

    $previous = libxml_use_internal_errors(true);
    $ok = simplexml_load_string('<root>'.$xml.'</root>') !== false;
    $errors = array_map(fn ($e) => trim($e->message).' (line '.$e->line.')', libxml_get_errors());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    expect($ok)->toBeTrue('the template is not well formed, so Vue would not compile it: '.implode('; ', $errors));
}

it('is registered under Tools with an icon Statamic can draw', function () {
    $utility = Utility::find('scholarly');

    expect($utility)->not->toBeNull()
        ->and($utility->icon())->toStartWith('<svg');
});

it('renders for a super user, on an empty site and a populated one, as markup Vue can compile', function () {
    $empty = scholarlyPage();
    assertVueTemplateIsWellFormed($empty);
    expect($empty)->toContain('No entries in the researchers collection yet');

    $ada = utilityResearcher('Ada', ['orcid_id' => '0000-0002-1825-0097', FetchForResearcher::LAST_RUN_FIELD => ['at' => '2026-09-01T03:00:00+00:00', 'complete' => true, 'summary' => '2 candidates']]);
    utilityCandidate([(string) $ada->id()]);

    $full = scholarlyPage();
    assertVueTemplateIsWellFormed($full);
    expect($full)->toContain('Ada')->toContain('1 waiting')->toContain('2 candidates');
});

it('refuses a user without the utility permission, and admits one with it', function () {
    // Two users on one site is a Pro feature, and the CP middleware counts
    // them before the permission check gets a say. Pro for this test only.
    config()->set('statamic.editions.pro', true);

    $this->setTestRoles(['editor' => ['access cp'], 'reader' => ['access cp', 'access scholarly utility']]);

    $editor = User::make()->email('editor@example.test')->assignRole('editor');
    $editor->save();
    $reader = User::make()->email('reader@example.test')->assignRole('reader');
    $reader->save();

    // Statamic answers a browser's forbidden CP request with a redirect to
    // the dashboard and a toast, not a bare 403. That is the refusal.
    $this->actingAs($editor)->get(cp_route('utilities.index').'/scholarly')->assertRedirect(cp_route('index'));
    $this->actingAs($reader)->get(cp_route('utilities.index').'/scholarly')->assertOk();
});

it('keeps a researcher with no identifier apart from one with no publications', function () {
    utilityResearcher('Ada', ['orcid_id' => '0000-0002-1825-0097']);
    utilityResearcher('Bob');

    $report = app(StatusReport::class)();

    expect($report['researchers']['total'])->toBe(2)
        ->and($report['researchers']['without_identifiers'])->toBe(1)
        ->and($report['researchers']['rows'][1]['title'])->toBe('Bob')
        ->and($report['researchers']['rows'][1]['identifiers'])->toBe([]);
});

it('reports a partial last run as partial, and no run as never', function () {
    utilityResearcher('Ada', [FetchForResearcher::LAST_RUN_FIELD => ['at' => '2026-09-01T03:00:00+00:00', 'complete' => false, 'summary' => 'PARTIAL: orcid could not answer']]);
    utilityResearcher('Bob');

    $report = app(StatusReport::class)();

    expect($report['researchers']['partial'])->toBe(1)
        ->and($report['researchers']['never_fetched'])->toBe(1)
        ->and($report['researchers']['rows'][0]['last_run']['complete'])->toBeFalse()
        ->and($report['researchers']['rows'][1]['last_run'])->toBeNull();
});

it('counts candidates waiting per researcher, leaving out the set aside', function () {
    $ada = utilityResearcher('Ada');
    $bob = utilityResearcher('Bob');
    utilityCandidate([(string) $ada->id()]);
    utilityCandidate([(string) $ada->id(), (string) $bob->id()]);
    utilityCandidate([(string) $bob->id()], ['discarded_at' => '2026-09-01T03:00:00+00:00']);
    utilityCandidate([(string) $bob->id()], ['deferred_at' => '2026-09-02T03:00:00+00:00']);

    $report = app(StatusReport::class)();

    expect($report['queue']['waiting'])->toBe(3)
        ->and($report['queue']['set_aside'])->toBe(1)
        ->and($report['queue']['deferred'])->toBe(1)
        ->and($report['researchers']['rows'][0]['waiting'])->toBe(2)
        ->and($report['researchers']['rows'][1]['waiting'])->toBe(2);
});

it('names the researchers whose waiting candidates have nobody to be told', function () {
    // The same rule NotifyVerifiers applies: a verifier on the entry, then
    // the fallback address, then nobody. Nobody is the one worth a red
    // badge, because those candidates sit until somebody happens to look.
    $verifier = User::make()->id('v1')->email('v1@example.edu');
    $verifier->save();

    $ada = utilityResearcher('Ada', [NotifyVerifiers::VERIFIER_FIELD => 'v1']);
    $bob = utilityResearcher('Bob');
    $cy = utilityResearcher('Cy');
    utilityCandidate([(string) $ada->id()]);
    utilityCandidate([(string) $bob->id()]);

    $report = app(StatusReport::class)();

    expect($report['researchers']['nobody_to_tell'])->toBe(1)
        ->and($report['researchers']['rows'][0]['verifier'])->toBe('v1@example.edu')
        ->and($report['researchers']['rows'][1]['verifier'])->toBeNull()
        ->and($report['researchers']['rows'][2]['waiting'])->toBe(0);

    config()->set('statamic-scholarly.notifications.fallback', 'research@example.edu');

    expect(app(StatusReport::class)()['researchers']['nobody_to_tell'])->toBe(0);
});

it('says why a shipped source will not run, and that a config-only source has no code', function () {
    config()->set('statamic-scholarly.researchers.fields', ['orcid' => 'orcid_id']);
    config()->set('statamic-scholarly.sources.pubmed.enabled', true);
    config()->set('statamic-scholarly.sources.scopus.enabled', true);

    $sources = app(StatusReport::class)()['sources'];
    $byKey = array_column($sources['registered'], null, 'key');

    expect($byKey['orcid']['configured'])->toBeTrue()
        ->and($byKey['orcid']['needs'])->toBeNull()
        ->and($byKey['pubmed']['configured'])->toBeFalse()
        ->and($byKey['pubmed']['needs'])->toContain('pubmed_author');

    $unregistered = array_column($sources['unregistered'], null, 'key');

    expect($unregistered['scopus']['enabled'])->toBeTrue()
        ->and(in_array('scopus', array_keys($byKey), true))->toBeFalse('scopus has no source class and must not be listed as one');

    config()->set('statamic-scholarly.sources.orcid.enabled', false);

    expect(array_column(app(StatusReport::class)()['sources']['registered'], null, 'key')['orcid']['needs'])->toBe('disabled in config');
});

it('shows whether NCBI has an email and whether a key is set, never the key itself', function () {
    config()->set('statamic-scholarly.ncbi.email', '');
    config()->set('statamic-scholarly.ncbi.api_key', 'abc123secret');

    $report = app(StatusReport::class)();

    expect($report['ncbi']['email'])->toBeNull()
        ->and($report['ncbi']['api_key'])->toBeTrue();

    $html = scholarlyPage();

    expect($html)->toContain('not set')
        ->and(str_contains($html, 'abc123secret'))->toBeFalse('the API key was printed on the page');
});

it('reports the shipped prior as uncalibrated, and the fitted model when there is one', function () {
    $before = app(StatusReport::class)()['scoring'];

    expect($before['calibrated'])->toBeFalse()
        ->and($before['auto_approve'])->toBeTrue()
        ->and($before['threshold'])->toBe(0.90);

    $model = new \Bpmore\Scholarly\Scoring\LogisticModel(\Bpmore\Scholarly\Scoring\LogisticModel::prior()->weights(), 'calibrated', ['fitted_at' => '2026-09-10T01:00:00+00:00', 'positives' => 20, 'negatives' => 15]);
    app(\Bpmore\Scholarly\Scoring\ModelStore::class)->save($model);

    $after = app(StatusReport::class)()['scoring'];

    expect($after['calibrated'])->toBeTrue()
        ->and($after['positives'])->toBe(20)
        ->and($after['fitted_at'])->toBe('2026-09-10T01:00:00+00:00');
});

it('never prints the webhook secret, and says when webhook is listed with no URL', function () {
    config()->set('statamic-scholarly.notifications.channels', ['mail', 'webhook']);
    config()->set('statamic-scholarly.notifications.webhook_url', null);
    config()->set('statamic-scholarly.notifications.webhook_secret', 'hush-hush');

    $n = app(StatusReport::class)()['notifications'];

    expect($n['webhook'])->toBeFalse()
        ->and($n['webhook_dropped'])->toBeTrue()
        ->and(str_contains(scholarlyPage(), 'hush-hush'))->toBeFalse('the webhook secret was printed on the page');
});
