<?php

declare(strict_types=1);

require_once __DIR__.'/../Cases/TagCases.php';

beforeEach(fn () => tagCasesSetup());

foreach ([...tagCases(), ...feedCases(), ...readinessCases()] as $name => $body) {
    it($name, $body);
}
