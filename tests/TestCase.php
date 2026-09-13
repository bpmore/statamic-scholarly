<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Tests;

use Bpmore\Scholarly\ServiceProvider;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/**
 * Statamic's own addon test case, not a hand-rolled Testbench one.
 *
 * The difference matters: `AddonServiceProvider::bootConfig()` finds the addon
 * through the Composer manifest, and under Testbench this package is not in
 * `vendor/composer/installed.json`. A plain Testbench case booted cleanly and
 * merged no config at all, so `config('statamic-scholarly')` read null and
 * every binding fell back to its defaults. `AddonTestCase` writes the addon
 * into the manifest by hand from composer.json.
 */
abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;
}
