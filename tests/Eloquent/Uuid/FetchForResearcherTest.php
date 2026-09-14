<?php

declare(strict_types=1);

require_once __DIR__.'/../../Cases/PipelineCases.php';

beforeEach(fn () => pipelineCasesSetup());

foreach (pipelineCases() as $name => $body) {
    it($name, $body);
}
