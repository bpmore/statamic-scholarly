<?php

declare(strict_types=1);

require_once __DIR__.'/../../Cases/ScoringCases.php';

beforeEach(fn () => scoringCasesSetup());

foreach ([...scoringCases(), ...calibrationCases(), ...summaryCases()] as $name => $body) {
    it($name, $body);
}
