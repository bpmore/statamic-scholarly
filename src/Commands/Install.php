<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Commands;

use Bpmore\Scholarly\Publications\PublicationsCollection;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

/**
 * `scholarly:install`: create the publications collection and its blueprint.
 *
 * The fetch does this too when it first runs. The command exists so the
 * collection can be seen, and its blueprint adjusted, before anything is
 * written into it.
 */
final class Install extends Command
{
    use RunsInPlease;

    protected $signature = 'scholarly:install';

    protected $description = 'Create the publications collection this addon owns, if it does not exist';

    public function handle(PublicationsCollection $collection): int
    {
        $collection->ensure();

        $this->info(\sprintf('Collection "%s" and its "%s" blueprint are in place.', $collection->handle(), PublicationsCollection::BLUEPRINT));

        return self::SUCCESS;
    }
}
