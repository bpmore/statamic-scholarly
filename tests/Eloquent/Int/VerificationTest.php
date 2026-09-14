<?php

declare(strict_types=1);

require_once __DIR__.'/../../Cases/VerificationCases.php';

beforeEach(function (): void {
    verificationCasesSetup();
    $this->setTestRoles(['verifier' => [\Bpmore\Scholarly\Verification\Verifier::PERMISSION], 'author' => ['access cp']]);
});

foreach (verificationCases() as $name => $body) {
    it($name, $body);
}
