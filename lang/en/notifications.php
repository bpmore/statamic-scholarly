<?php

declare(strict_types=1);

return [
    'subject' => ':count candidate publication awaiting review|:count candidate publications awaiting review',
    'intro' => 'A fetch found :count publication that may belong to a researcher you verify for. It is a candidate until you confirm it; a name match is an estimate, not a claim.|A fetch found :count publications that may belong to researchers you verify for. They are candidates until you confirm them; a name match is an estimate, not a claim.',
    'researcher_line' => ':name: :count new candidate|:name: :count new candidates',
    'partial' => 'The last fetch for this researcher was PARTIAL, so this list may be incomplete: :summary',
    'action' => 'Review candidates',
    'outro' => 'Confirm, reject or defer each one in the control panel. A candidate appears on a public profile only when a person confirms it, or when the score is certain enough to approve it on its own; approved-on-score entries say so and can be rejected.',
];
