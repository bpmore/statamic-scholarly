<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Scoring;

enum NameMatch: string
{
    case Full = 'full';
    case Surname = 'surname';
    case None = 'none';
}
