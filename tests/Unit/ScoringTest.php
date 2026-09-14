<?php

declare(strict_types=1);

use Bpmore\Scholarly\Publications\CandidatePublication;
use Bpmore\Scholarly\Scoring\Fingerprint;
use Bpmore\Scholarly\Scoring\NameMatch;
use Bpmore\Scholarly\Scoring\PriorModel;
use Bpmore\Scholarly\Scoring\Signals;

function carberryFingerprint(): Fingerprint
{
    return Fingerprint::start(['Jaclyn Carberry', 'Carberry J'], '0000-0002-4184-0714')
        ->withPaper([
            ['name' => 'Berry C', 'affiliation' => 'Golden Jubilee', 'orcid' => null],
            ['name' => 'Carberry J', 'affiliation' => 'British Heart Foundation Glasgow', 'orcid' => '0000-0002-4184-0714'],
            ['name' => 'Dharmakumar R', 'affiliation' => null, 'orcid' => null],
        ], 'Circulation')
        ->withPaper([
            ['name' => 'Lechner I', 'affiliation' => null, 'orcid' => null],
            ['name' => 'Carberry J', 'affiliation' => 'British Heart Foundation Glasgow', 'orcid' => null],
            ['name' => 'Berry C', 'affiliation' => null, 'orcid' => null],
        ], 'Eur Heart J');
}

function scoredCandidate(array $authors, ?string $journal = null, string $source = 'pubmed'): CandidatePublication
{
    return new CandidatePublication($source, title: 'T', journal: $journal, authors: $authors);
}

it('builds the fingerprint from certain papers: co-authors, own affiliations, journals', function (): void {
    $f = carberryFingerprint();

    expect($f->certainPapers)->toBe(2)
        ->and($f->coauthors)->toBe(['berry|c' => 2, 'dharmakumar|r' => 1, 'lechner|i' => 1])
        ->and($f->affiliations)->toBe(['british heart foundation glasgow'])
        ->and($f->journals)->toBe(['circulation' => 1, 'eur heart j' => 1]);
});

it('anchors on the researcher\'s own ORCID iD on an author line, or on an ORCID-claimed source', function (): void {
    $f = carberryFingerprint();

    $byId = Signals::compute(scoredCandidate([['name' => 'Carberry J', 'orcid' => '0000-0002-4184-0714']]), $f, [], null);
    $byClaim = Signals::compute(scoredCandidate([['name' => 'Someone Else']], source: 'orcid'), $f, [], null);
    $namesake = Signals::compute(scoredCandidate([['name' => 'Carberry J', 'orcid' => '0000-0001-0000-0001']]), $f, [], null);

    expect($byId->anchored)->toBeTrue()
        ->and($byClaim->anchored)->toBeTrue()
        ->and($namesake->anchored)->toBeFalse('a different iD on the same name is a different person');
});

it('measures co-author overlap against the other author lines only', function (): void {
    $f = carberryFingerprint();

    $s = Signals::compute(scoredCandidate([
        ['name' => 'Berry C'],
        ['name' => 'Carberry J'],
        ['name' => 'Nobody X'],
        ['name' => 'Lechner I'],
    ], 'Circulation'), $f, [], 63);

    expect($s->nameMatch)->toBe(NameMatch::Full)
        ->and($s->coauthorOverlap)->toBe(2 / 3)
        ->and($s->sharedCoauthors)->toBe(2)
        ->and($s->journalSeen)->toBeTrue()
        ->and($s->namePrevalence)->toBe(63)
        ->and($s->affiliationMatch)->toBeFalse('no affiliation on the line');
});

it('matches affiliation against the site patterns and against affiliations seen on certain papers', function (): void {
    $f = carberryFingerprint();
    $line = [['name' => 'Carberry J', 'affiliation' => 'BHF Glasgow Cardiovascular Research Centre, University of Glasgow']];

    expect(Signals::compute(scoredCandidate($line), $f, ['university of glasgow'], null)->affiliationMatch)->toBeTrue()
        ->and(Signals::compute(scoredCandidate($line), $f, ['University of Somewhere Else'], null)->affiliationMatch)->toBeFalse()
        ->and(Signals::compute(scoredCandidate([['name' => 'Carberry J', 'affiliation' => 'British Heart Foundation Glasgow']]), $f, [], null)->affiliationMatch)->toBeTrue('seen on a certain paper');
});

it('scores nothing for a paper where no author line matches the name', function (): void {
    $f = carberryFingerprint();
    $s = Signals::compute(scoredCandidate([['name' => 'Berry C'], ['name' => 'Lechner I']], 'Circulation'), $f, ['glasgow'], 5);

    expect($s->nameMatch)->toBe(NameMatch::None)
        ->and((new PriorModel)->score($s))->toBe(0.0);
});

it('orders the prior the way the spec expects: co-authors above affiliation, common names below rare ones', function (): void {
    $f = carberryFingerprint();
    $model = new PriorModel;

    $bare = $model->score(Signals::compute(scoredCandidate([['name' => 'Carberry J']]), $f, [], null));
    $withAffiliation = $model->score(Signals::compute(scoredCandidate([['name' => 'Carberry J', 'affiliation' => 'British Heart Foundation Glasgow']]), $f, [], null));
    $withCoauthors = $model->score(Signals::compute(scoredCandidate([['name' => 'Carberry J'], ['name' => 'Berry C'], ['name' => 'Lechner I']]), $f, [], null));
    $rare = $model->score(Signals::compute(scoredCandidate([['name' => 'Carberry J']]), $f, [], 8));
    $common = $model->score(Signals::compute(scoredCandidate([['name' => 'Carberry J']]), $f, [], 80000));
    $surnameOnly = $model->score(Signals::compute(scoredCandidate([['name' => 'Carberry']]), $f, [], null));

    expect($withCoauthors)->toBeGreaterThan($withAffiliation)
        ->and($withAffiliation)->toBeGreaterThan($bare)
        ->and($bare)->toBeGreaterThan($surnameOnly)
        ->and($rare)->toBeGreaterThan($common)
        ->and($bare)->toBeLessThan(0.5, 'a bare name match alone is not likely, on a prior')
        ->and($model->state())->toBe('uncalibrated');

    foreach ([$bare, $withAffiliation, $withCoauthors, $rare, $common] as $score) {
        expect($score)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
    }
});
