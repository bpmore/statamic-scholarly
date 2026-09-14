<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Commands;

use Bpmore\Scholarly\Publications\PublicationsCollection;
use Bpmore\Scholarly\Researchers\ResearcherSummary;
use Statamic\Facades\Fieldset;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

/**
 * `scholarly:install`: create the publications collection and its blueprint,
 * and the fieldset a host imports into its researcher blueprint.
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

    public function handle(PublicationsCollection $collection, ResearcherSummary $summary): int
    {
        $collection->ensure();

        $this->info(\sprintf('Collection "%s" and its "%s" blueprint are in place.', $collection->handle(), PublicationsCollection::BLUEPRINT));

        if (Fieldset::find(ResearcherSummary::FIELDSET) === null) {
            Fieldset::make(ResearcherSummary::FIELDSET)->setContents($summary->fieldsetContents())->save();
            $this->info(\sprintf('Fieldset "%s" written. Import it into your researcher blueprint to show the verifier, recent publications, waiting candidates and last fetch on each researcher.', ResearcherSummary::FIELDSET));
        } else {
            $this->line(\sprintf('Fieldset "%s" already exists and was left alone.', ResearcherSummary::FIELDSET));
        }

        return self::SUCCESS;
    }
}
