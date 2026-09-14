<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Statamic\Eloquent\ServiceProvider as EloquentDriver;

/**
 * The same suite, with entries and collections in a database.
 *
 * The addon promises to work under statamic/eloquent-driver, and a promise
 * about a driver nobody runs in the suite is not one. Entries use string
 * ids here, which is the driver's option for hosts that keep Statamic's
 * uuids; the writer must not depend on either choice.
 */
abstract class EloquentTestCase extends TestCase
{
    use RefreshDatabase;

    /** Uuid entries (an imported site) or auto-increment ones (a fresh install). */
    protected bool $uuidEntries = true;

    protected function getPackageProviders($app)
    {
        return [...parent::getPackageProviders($app), EloquentDriver::class];
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('statamic.eloquent-driver', require \dirname(__DIR__).'/vendor/statamic/eloquent-driver/config/eloquent-driver.php');
        $app['config']->set('statamic.eloquent-driver.entries.driver', 'eloquent');
        $app['config']->set('statamic.eloquent-driver.collections.driver', 'eloquent');

        if ($this->uuidEntries) {
            $app['config']->set('statamic.eloquent-driver.entries.model', \Statamic\Eloquent\Entries\UuidEntryModel::class);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        $migrations = \dirname(__DIR__).'/vendor/statamic/eloquent-driver/database/migrations';

        $this->loadMigrationsFrom($migrations);
        $this->loadMigrationsFrom($migrations.'/entries/'.($this->uuidEntries
            ? '2024_03_07_100000_create_entries_table_with_string_ids.php'
            : '2024_03_07_100000_create_entries_table.php'));
    }
}
