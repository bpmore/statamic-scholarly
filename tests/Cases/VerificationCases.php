<?php

declare(strict_types=1);

use Bpmore\Scholarly\Actions\Confirm;
use Bpmore\Scholarly\Actions\Defer;
use Bpmore\Scholarly\Actions\Reject;
use Bpmore\Scholarly\Publications\PublicationsCollection;
use Bpmore\Scholarly\Verification\Verifier;
use Statamic\Facades\Action;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Permission;
use Statamic\Facades\User;

/*
 * The owned collection is under a non-default handle, as everywhere else.
 * A `pages` entry exists too, so "only the owned collection" can be shown
 * rather than assumed.
 */
const PUBS = 'research_outputs';


function candidate(array $extra = []): \Statamic\Contracts\Entries\Entry
{
    $entry = Entry::make()->collection(PUBS)->slug((string) \Illuminate\Support\Str::uuid())->data([
        'title' => 'A paper',
        'researchers' => ['r1'],
        'verification_status' => 'candidate',
        ...$extra,
    ]);
    $entry->save();

    return $entry;
}

function userWithRole(string $role): \Statamic\Contracts\Auth\User
{
    $user = User::make()->id('u-'.$role)->email($role.'@example.edu')->assignRole($role);
    $user->save();

    return $user;
}

/**
 * Verification, run against the Stache and against the Eloquent driver alike.
 *
 * @return array<string, Closure(): void>
 */
function verificationCasesSetup(): void
{
    config()->set('statamic-scholarly.researchers.collection', 'faculty');
    config()->set('statamic-scholarly.publications.collection', PUBS);
    Collection::make('faculty')->save();
    Collection::make('pages')->save();
    app(PublicationsCollection::class)->ensure();

}

function verificationCases(): array
{
    return [
        'registers the permission and the review nav item' => function (): void {
        $values = Permission::boot()->all()->map->value()->all();

        expect(in_array(Verifier::PERMISSION, $values, true))->toBeTrue();
        },

        'confirms a candidate, recording who and when' => function (): void {
        $entry = candidate(['deferred_at' => '2026-01-01T00:00:00+00:00']);
        $user = userWithRole('verifier');

        app(Verifier::class)->confirm($entry, $user);

        $entry = Entry::find($entry->id());

        expect($entry->get('verification_status'))->toBe('approved')
            ->and($entry->get('verified_by'))->toBe($user->id())
            ->and(strtotime($entry->get('verified_at')))->not->toBeFalse()
            ->and($entry->get('deferred_at'))->toBeNull();
        },

        'rejects with an optional reason, and a person can reverse it' => function (): void {
        $entry = candidate();
        $user = userWithRole('verifier');
        $verifier = app(Verifier::class);

        $verifier->reject($entry, $user, '  Namesake at another institution  ');

        $entry = Entry::find($entry->id());

        expect($entry->get('verification_status'))->toBe('rejected')
            ->and($entry->get('rejection_reason'))->toBe('Namesake at another institution')
            ->and($entry->get('verified_by'))->toBe($user->id());

        $verifier->confirm($entry, $user);

        expect(Entry::find($entry->id())->get('verification_status'))->toBe('approved');
        },

        'defers a candidate without changing its status, and only a candidate' => function (): void {
        $entry = candidate();
        $user = userWithRole('verifier');
        $verifier = app(Verifier::class);

        $verifier->defer($entry, $user);

        $entry = Entry::find($entry->id());

        expect($entry->get('verification_status'))->toBe('candidate')
            ->and(strtotime($entry->get('deferred_at')))->not->toBeFalse();

        $verifier->confirm($entry, $user);

        expect(static fn () => $verifier->defer(Entry::find($entry->id()), $user))->toThrow(InvalidArgumentException::class);
        },

        'refuses to touch an entry outside the owned collection' => function (): void {
        $page = Entry::make()->collection('pages')->slug('about')->data(['title' => 'About']);
        $page->save();
        $user = userWithRole('verifier');

        expect(static fn () => app(Verifier::class)->confirm($page, $user))->toThrow(InvalidArgumentException::class);

        expect(Entry::find($page->id())->get('verification_status'))->toBeNull();
        },

        'offers the actions on a candidate to a user with the permission, and not to one without' => function (): void {
        $entry = candidate();

        $this->actingAs(userWithRole('verifier'));
        $handles = Action::for($entry, ['view' => 'list'])->map->handle()->all();

        expect(in_array('confirm', $handles, true))->toBeTrue()
            ->and(in_array('reject', $handles, true))->toBeTrue()
            ->and(in_array('defer', $handles, true))->toBeTrue();

        $this->actingAs(userWithRole('author'));
        $handles = Action::for($entry, ['view' => 'list'])->map->handle()->all();

        expect(in_array('confirm', $handles, true))->toBeFalse()
            ->and(in_array('reject', $handles, true))->toBeFalse()
            ->and(in_array('defer', $handles, true))->toBeFalse();
        },

        'shows each action only where it makes sense' => function (): void {
        $this->actingAs(userWithRole('verifier'));
        $handlesFor = fn ($entry) => Action::for($entry, ['view' => 'list'])->map->handle()->all();

        $approved = candidate(['verification_status' => 'approved']);
        $rejected = candidate(['verification_status' => 'rejected']);
        $deferred = candidate(['deferred_at' => '2026-01-01T00:00:00+00:00']);
        $page = Entry::make()->collection('pages')->slug('about')->data(['title' => 'About']);
        $page->save();

        expect(in_array('confirm', $handlesFor($approved), true))->toBeFalse()
            ->and(in_array('reject', $handlesFor($approved), true))->toBeTrue()
            ->and(in_array('defer', $handlesFor($approved), true))->toBeFalse();

        expect(in_array('confirm', $handlesFor($rejected), true))->toBeTrue('a person can reverse a rejection')
            ->and(in_array('reject', $handlesFor($rejected), true))->toBeFalse();

        expect(in_array('defer', $handlesFor($deferred), true))->toBeFalse('already deferred');

        $pageHandles = $handlesFor($page);

        expect(in_array('confirm', $pageHandles, true))->toBeFalse()
            ->and(in_array('reject', $pageHandles, true))->toBeFalse()
            ->and(in_array('defer', $pageHandles, true))->toBeFalse();
        },

        'runs the actions through the verifier as the current user' => function (): void {
        $user = userWithRole('verifier');
        $this->actingAs($user);

        $a = candidate();
        $b = candidate();

        app(Confirm::class)->run(collect([$a, $b]), []);

        expect(Entry::find($a->id())->get('verification_status'))->toBe('approved')
            ->and(Entry::find($b->id())->get('verified_by'))->toBe($user->id());

        $c = candidate();
        app(Reject::class)->run(collect([$c]), ['reason' => 'Wrong person']);

        expect(Entry::find($c->id())->get('verification_status'))->toBe('rejected')
            ->and(Entry::find($c->id())->get('rejection_reason'))->toBe('Wrong person');

        $d = candidate();
        app(Defer::class)->run(collect([$d]), []);

        expect(Entry::find($d->id())->get('verification_status'))->toBe('candidate')
            ->and(Entry::find($d->id())->get('deferred_at'))->not->toBeNull();
        }
    ];
}
