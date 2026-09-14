<?php

declare(strict_types=1);

use Bpmore\Scholarly\Fetching\FetchForResearcher;
use Bpmore\Scholarly\Notifications\CandidatesAwaitingReview;
use Bpmore\Scholarly\Notifications\NotifyVerifiers;
use Bpmore\Scholarly\Publications\PublicationsCollection;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

const NOTIFY_PUBS = 'research_outputs';


function verifierUser(string $id): \Statamic\Contracts\Auth\User
{
    $user = User::make()->id($id)->email($id.'@example.edu');
    $user->save();

    return $user;
}

function notifyResearcher(string $title, ?string $verifierId = null, array $lastRun = []): \Statamic\Contracts\Entries\Entry
{
    $entry = Entry::make()->collection('faculty')->slug(Str::slug($title))->data(array_filter([
        'title' => $title,
        NotifyVerifiers::VERIFIER_FIELD => $verifierId,
        FetchForResearcher::LAST_RUN_FIELD => $lastRun ?: null,
    ]));
    $entry->save();

    return $entry;
}

function waiting(array $researcherIds, array $extra = []): \Statamic\Contracts\Entries\Entry
{
    $slug = (string) Str::uuid();
    $entry = Entry::make()->collection(NOTIFY_PUBS)->slug($slug)->data([
        'title' => 'Candidate '.$slug,
        'verification_status' => 'candidate',
        'researchers' => $researcherIds,
        ...$extra,
    ]);
    $entry->save();

    return $entry;
}

function notifier(bool $digest = true, ?string $fallback = null): NotifyVerifiers
{
    return new NotifyVerifiers(app(PublicationsCollection::class), $digest, $fallback, ['mail'], 'https://example.test/cp/review');
}

/**
 * Notification, run against the Stache and against the Eloquent driver alike.
 *
 * @return array<string, Closure(): void>
 */
function notifyCasesSetup(): void
{
    config()->set('statamic-scholarly.researchers.collection', 'faculty');
    config()->set('statamic-scholarly.publications.collection', NOTIFY_PUBS);
    config()->set('statamic-scholarly.notifications.fallback', null);
    Collection::make('faculty')->save();
    app(PublicationsCollection::class)->ensure();
    Notification::fake();
}

function notifyCases(): array
{
    return [
        'sends one digest per verifier covering every researcher they verify for, then stamps the candidates' => function (): void {
        $v1 = verifierUser('v1');
        $v2 = verifierUser('v2');
        $ada = notifyResearcher('Ada', 'v1');
        $bob = notifyResearcher('Bob', 'v1');
        $cy = notifyResearcher('Cy', 'v2');

        waiting([$ada->id()]);
        waiting([$ada->id()]);
        $shared = waiting([$bob->id(), $cy->id()]);

        $report = notifier()();

        expect($report->sent)->toBe(2)
            ->and($report->stamped)->toBe(3)
            ->and($report->unrouted)->toBe([]);

        Notification::assertSentTo($v1, CandidatesAwaitingReview::class, function (CandidatesAwaitingReview $n): bool {
            $byName = collect($n->items)->keyBy->researcherName;

            return $byName['Ada']->waiting === 2 && $byName['Bob']->waiting === 1 && $n->totalWaiting() === 3;
        });
        Notification::assertSentTo($v2, CandidatesAwaitingReview::class, fn (CandidatesAwaitingReview $n) => count($n->items) === 1 && $n->items[0]->researcherName === 'Cy');
        Notification::assertCount(2);

        expect(Entry::find($shared->id())->get('notified_at'))->not->toBeNull();

        // Nothing is mentioned twice.
        $again = notifier()();

        expect($again->sent)->toBe(0);
        Notification::assertCount(2);
        },

        'sends one mail per researcher when digest is off' => function (): void {
        $v1 = verifierUser('v1');
        $ada = notifyResearcher('Ada', 'v1');
        $bob = notifyResearcher('Bob', 'v1');
        waiting([$ada->id()]);
        waiting([$bob->id()]);

        $report = notifier(digest: false)();

        expect($report->sent)->toBe(2);
        Notification::assertSentToTimes($v1, CandidatesAwaitingReview::class, 2);
        },

        'leaves deferred candidates out, and approved or rejected ones' => function (): void {
        verifierUser('v1');
        $ada = notifyResearcher('Ada', 'v1');
        waiting([$ada->id()], ['deferred_at' => '2026-01-01T00:00:00+00:00']);
        waiting([$ada->id()], ['verification_status' => 'approved']);
        waiting([$ada->id()], ['verification_status' => 'rejected']);

        $report = notifier()();

        expect($report->sent)->toBe(0);
        Notification::assertNothingSent();
        },

        'falls back to the configured address when a researcher has no verifier' => function (): void {
        $ada = notifyResearcher('Ada');
        waiting([$ada->id()]);

        $report = notifier(fallback: 'review@example.edu')();

        expect($report->sent)->toBe(1)
            ->and($report->unrouted)->toBe([]);

        Notification::assertSentOnDemand(CandidatesAwaitingReview::class, fn ($n, $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'review@example.edu');
        },

        'tells nobody silently: an unrouted researcher is reported, logged, and left unstamped' => function (): void {
        Log::spy();
        $ada = notifyResearcher('Ada');
        $candidate = waiting([$ada->id()]);

        $report = notifier()();

        expect($report->sent)->toBe(0)
            ->and($report->stamped)->toBe(0)
            ->and($report->unrouted)->toHaveCount(1)
            ->and($report->unrouted[0]->researcherName)->toBe('Ada')
            ->and(str_contains($report->summary(), 'NOBODY TOLD'))->toBeTrue()
            ->and(Entry::find($candidate->id())->get('notified_at'))->toBeNull();

        Notification::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
        },

        'says in the mail that a researcher\'s last run was partial, and never calls a candidate a publication' => function (): void {
        $v1 = verifierUser('v1');
        $ada = notifyResearcher('Ada', 'v1', ['at' => '2026-09-14T00:00:00+00:00', 'complete' => false, 'summary' => 'PARTIAL: failed pubmed: transport failed (HTTP 503)']);
        $bob = notifyResearcher('Bob', 'v1', ['at' => '2026-09-14T00:00:00+00:00', 'complete' => true, 'summary' => 'complete']);
        waiting([$ada->id()]);
        waiting([$bob->id()]);

        notifier()();

        Notification::assertSentTo($v1, CandidatesAwaitingReview::class, function (CandidatesAwaitingReview $n) use ($v1): bool {
            $mail = $n->toMail($v1);
            $text = $mail->subject.' '.implode(' ', array_map(fn ($l) => (string) $l, [...$mail->introLines, ...$mail->outroLines]));

            expect($mail->subject)->toBe('2 candidate publications awaiting review')
                ->and(str_contains($text, 'Ada: 1 new candidate'))->toBeTrue()
                ->and(str_contains($text, 'PARTIAL'))->toBeTrue()
                ->and(str_contains($text, 'HTTP 503'))->toBeTrue()
                ->and(str_contains($text, 'Bob: 1 new candidate'))->toBeTrue()
                ->and(preg_match('/\bnew publications?\b/i', $text))->toBe(0, 'a candidate is never called a publication')
                ->and($mail->actionUrl)->toBe('https://example.test/cp/review');

            return true;
        });
        },

        'runs from the command and fails loudly when somebody could not be told' => function (): void {
        verifierUser('v1');
        $ada = notifyResearcher('Ada', 'v1');
        $nobody = notifyResearcher('Nobody');
        waiting([$ada->id()]);
        waiting([$nobody->id()]);

        $this->artisan('scholarly:notify')->expectsOutputToContain('NOBODY TOLD')->assertFailed();

        Notification::assertCount(1);
        }
    ];
}

/**
 * The webhook channel and the nav count, across the same drivers.
 *
 * @return array<string, Closure(): void>
 */
function webhookCases(): array
{
    return [
        'sends on the webhook channel too when one is configured, and posts a signed digest' => function (): void {
            config()->set('statamic-scholarly.notifications.channels', ['mail', 'webhook']);
            config()->set('statamic-scholarly.notifications.webhook_url', 'https://hooks.example.test/scholarly');
            config()->set('statamic-scholarly.notifications.webhook_secret', 'shh');

            $verifier = verifierUser('v1');
            $ada = notifyResearcher('Ada', 'v1', ['at' => '2026-09-14T00:00:00+00:00', 'complete' => false, 'summary' => 'PARTIAL: failed pubmed']);
            waiting([$ada->id()]);
            waiting([$ada->id()]);

            app(\Bpmore\Scholarly\Notifications\NotifyVerifiers::class)();

            $sent = null;
            Notification::assertSentTo($verifier, CandidatesAwaitingReview::class, function (CandidatesAwaitingReview $n, array $channels) use (&$sent): bool {
                $sent = $n;

                return $channels === ['mail', 'webhook'];
            });

            // The channel itself, against a faked endpoint.
            \Illuminate\Support\Facades\Http::fake(['hooks.example.test/*' => \Illuminate\Support\Facades\Http::response('', 204)]);
            (new \Bpmore\Scholarly\Notifications\WebhookChannel('https://hooks.example.test/scholarly', 'shh'))->send($verifier, $sent);

            \Illuminate\Support\Facades\Http::assertSentCount(1);
            \Illuminate\Support\Facades\Http::assertSent(function (\Illuminate\Http\Client\Request $r): bool {
                $body = json_decode($r->body(), true);
                $expected = 'sha256='.hash_hmac('sha256', $r->body(), 'shh');

                return $r->url() === 'https://hooks.example.test/scholarly'
                    && $r->hasHeader(\Bpmore\Scholarly\Notifications\WebhookChannel::SIGNATURE_HEADER, $expected)
                    && $body['event'] === 'candidates_awaiting_review'
                    && $body['recipient'] === 'v1@example.edu'
                    && $body['total_waiting'] === 2
                    && $body['researchers'][0]['name'] === 'Ada'
                    && $body['researchers'][0]['last_run_complete'] === false
                    && str_contains($body['review_url'], '/cp/collections/');
            });
        },

        'drops the webhook channel when no url is configured, and an outage is logged rather than thrown' => function (): void {
            config()->set('statamic-scholarly.notifications.channels', ['mail', 'webhook']);
            config()->set('statamic-scholarly.notifications.webhook_url', null);

            $verifier = verifierUser('v1');
            $ada = notifyResearcher('Ada', 'v1');
            waiting([$ada->id()]);

            app(\Bpmore\Scholarly\Notifications\NotifyVerifiers::class)();

            Notification::assertSentTo($verifier, CandidatesAwaitingReview::class, fn ($n, array $channels) => $channels === ['mail']);

            \Illuminate\Support\Facades\Http::fake(['down.example.test/*' => \Illuminate\Support\Facades\Http::response('', 503)]);
            \Illuminate\Support\Facades\Log::spy();
            $notification = new CandidatesAwaitingReview([], 'https://x.test/cp');

            (new \Bpmore\Scholarly\Notifications\WebhookChannel('https://down.example.test/hook', null))->send($verifier, $notification);

            \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'webhook'))->once();
            \Illuminate\Support\Facades\Http::assertSent(fn (\Illuminate\Http\Client\Request $r) => ! $r->hasHeader(\Bpmore\Scholarly\Notifications\WebhookChannel::SIGNATURE_HEADER));
        },

        'counts the candidates a verifier would see, for the nav item' => function (): void {
            $ada = notifyResearcher('Ada');
            waiting([$ada->id()]);
            waiting([$ada->id()]);
            waiting([$ada->id()], ['discarded_at' => '2026-01-01T00:00:00+00:00']);
            waiting([$ada->id()], ['verification_status' => 'approved']);

            expect(app(\Bpmore\Scholarly\Verification\WaitingCount::class)())->toBe(2, 'set aside and approved are not waiting');
        },
    ];
}
