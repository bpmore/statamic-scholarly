<?php

declare(strict_types=1);

/*
 * The unit suite is deliberately framework free: the de-duplication, the
 * identifier formats, the field map and the NCBI query builder all run without
 * booting anything. That is what lets them be exercised in a plain PHP process
 * when a full install is not available.
 */

uses(Bpmore\Scholarly\Tests\TestCase::class)->in('Feature');
/*
 * The Eloquent driver for Statamic 6 needs PHP 8.3, while this addon runs on
 * 8.2. The 8.2 job removes the driver and runs with --exclude-group=eloquent,
 * so 8.2 is tested on the Stache only. That is the honest shape: a host on
 * 8.2 cannot install the driver either.
 */
uses(Bpmore\Scholarly\Tests\EloquentTestCase::class)->group('eloquent')->in('Eloquent/Uuid');
uses(Bpmore\Scholarly\Tests\EloquentIntTestCase::class)->group('eloquent')->in('Eloquent/Int');
