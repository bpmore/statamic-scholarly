<?php

declare(strict_types=1);

/*
 * The unit suite is deliberately framework free: the de-duplication, the
 * identifier formats, the field map and the NCBI query builder all run without
 * booting anything. That is what lets them be exercised in a plain PHP process
 * when a full install is not available.
 */

uses(Bpmore\Scholarly\Tests\TestCase::class)->in('Feature');
uses(Bpmore\Scholarly\Tests\EloquentTestCase::class)->in('Eloquent/Uuid');
uses(Bpmore\Scholarly\Tests\EloquentIntTestCase::class)->in('Eloquent/Int');
