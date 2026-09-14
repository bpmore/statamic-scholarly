<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "There are candidates waiting for you."
 *
 * The mail says two things and neither may be softened: these are
 * candidates, not publications, and if the run that found them could not
 * look everywhere, the mail says so per researcher. A verifier who reads
 * "3 new publications" has been told something the addon does not know.
 */
final class CandidatesAwaitingReview extends Notification
{
    /**
     * @param  list<ReviewItem>  $items
     * @param  list<string>  $channels
     */
    public function __construct(
        public readonly array $items,
        public readonly string $reviewUrl,
        private readonly array $channels = ['mail'],
    ) {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return $this->channels;
    }

    public function totalWaiting(): int
    {
        return \array_sum(\array_map(static fn (ReviewItem $i): int => $i->waiting, $this->items));
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $total = $this->totalWaiting();

        $mail = (new MailMessage)
            ->subject(\trans_choice(
                'statamic-scholarly::notifications.subject',
                $total,
                ['count' => $total],
            ))
            ->line(\trans_choice('statamic-scholarly::notifications.intro', $total, ['count' => $total]));

        foreach ($this->items as $item) {
            $mail->line(\trans_choice('statamic-scholarly::notifications.researcher_line', $item->waiting, [
                'name' => $item->researcherName,
                'count' => $item->waiting,
            ]));

            if (! $item->lastRunComplete) {
                $mail->line(\trans('statamic-scholarly::notifications.partial', [
                    'summary' => $item->lastRunSummary ?? '',
                ]));
            }
        }

        return $mail
            ->action(\trans('statamic-scholarly::notifications.action'), $this->reviewUrl)
            ->line(\trans('statamic-scholarly::notifications.outro'));
    }
}
