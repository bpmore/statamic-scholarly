<?php

declare(strict_types=1);

require_once __DIR__.'/../../Cases/ImportCases.php';

beforeEach(fn () => importCasesSetup());

foreach (importCases() as $name => $body) {
    it($name, $body);
}
