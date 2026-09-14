<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Commands;

use Bpmore\Scholarly\Notifications\NotifyVerifiers;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

/**
 * `scholarly:notify`: tell verifiers about candidates nobody has been told
 * about yet. Safe to run as often as you like; each candidate is mentioned
 * once.
 */
final class Notify extends Command
{
    use RunsInPlease;

    protected $signature = 'scholarly:notify';

    protected $description = 'Mail verifiers about candidate publications not yet mentioned to anyone';

    public function handle(NotifyVerifiers $notify): int
    {
        $report = $notify();

        if ($report->unrouted === []) {
            $this->info($report->summary());

            return self::SUCCESS;
        }

        $this->warn($report->summary());

        return self::FAILURE;
    }
}
