<?php

declare(strict_types=1);

require_once __DIR__.'/../../Cases/CommandCases.php';

beforeEach(fn () => commandCasesSetup());

foreach (commandCases() as $name => $body) {
    it($name, $body);
}
