<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Actions;

use Bpmore\Scholarly\Verification\Verifier;
use Statamic\Actions\Action;
use Statamic\Facades\User;

use function Statamic\trans as __;
use function Statamic\trans_choice;

/**
 * "Confirm" in the entry listing and on the entry: a person says this paper
 * is the listed researchers' work.
 *
 * Statamic's own action mechanism, so it appears where every other action
 * does and needs no interface of its own.
 */
final class Confirm extends Action
{
    protected $icon = 'checkmark';

    public function __construct(private readonly Verifier $verifier)
    {
        parent::__construct();
    }

    public static function title()
    {
        return __('Confirm publication');
    }

    public function visibleTo($item)
    {
        return $this->verifier->isPublication($item) && $item->get('verification_status') !== 'approved';
    }

    public function authorize($user, $item)
    {
        return $user->can(Verifier::PERMISSION);
    }

    public function confirmationText()
    {
        /** @translation */
        return 'Confirm this publication as the listed researchers\' work? It will appear on their public profiles.|Confirm these :count publications? They will appear on their researchers\' public profiles.';
    }

    public function buttonText()
    {
        /** @translation */
        return 'Confirm|Confirm :count publications';
    }

    public function run($items, $values)
    {
        $user = User::current();

        foreach ($items as $entry) {
            $this->verifier->confirm($entry, $user);
        }

        return trans_choice('Publication confirmed|:count publications confirmed', $items->count());
    }
}
