<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Tests;

use Bpmore\Scholarly\ServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Blueprint;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\FakesRoles;
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
    use FakesRoles;
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // Testbench ships no app key, and a web request through the session
        // middleware encrypts. Only the feed test makes one, but a missing
        // key throws before the request reaches anything of ours.
        $app['config']->set('app.key', 'base64:'.\base64_encode(\random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Blueprints are files, not Stache items, so the trait above does not
        // catch them. Without this a blueprint saved by one test run lands in
        // the Testbench skeleton and is still there for the next run, which
        // is how a test once passed against a blueprint from a week earlier.
        Blueprint::setDirectory($this->fakeStacheDirectory.'/blueprints');

        // A request no test faked must not reach NCBI or ORCID. Without this
        // an unfaked endpoint quietly goes to the network, and a test can
        // pass or fail on the state of a remote service.
        Http::preventStrayRequests();

        // The fitted model is a file on the local disk. A fake disk per test
        // means a model one test saved cannot calibrate the next.
        Storage::fake('local');
    }
}
