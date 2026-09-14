<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Actions;

use Bpmore\Scholarly\Verification\Verifier;
use Statamic\Actions\Action;
use Statamic\Facades\User;

use function Statamic\trans as __;
use function Statamic\trans_choice;

/**
 * "Defer": still a candidate, but out of the current notification batch.
 */
final class Defer extends Action
{
    protected $icon = 'clock';

    protected $confirm = false;

    public function __construct(private readonly Verifier $verifier)
    {
        parent::__construct();
    }

    public static function title()
    {
        return __('Defer publication');
    }

    public function visibleTo($item)
    {
        return $this->verifier->isPublication($item)
            && $item->get('verification_status') === 'candidate'
            && $item->get('deferred_at') === null;
    }

    public function authorize($user, $item)
    {
        return $user->can(Verifier::PERMISSION);
    }

    public function buttonText()
    {
        /** @translation */
        return 'Defer|Defer :count publications';
    }

    public function run($items, $values)
    {
        $user = User::current();

        foreach ($items as $entry) {
            $this->verifier->defer($entry, $user);
        }

        return trans_choice('Publication deferred|:count publications deferred', $items->count());
    }
}
