<?php

declare(strict_types=1);

require_once __DIR__.'/../../Cases/NotifyCases.php';

beforeEach(fn () => notifyCasesSetup());

foreach ([...notifyCases(), ...webhookCases()] as $name => $body) {
    it($name, $body);
}
