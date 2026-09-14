<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Actions;

use Bpmore\Scholarly\Verification\Verifier;
use Statamic\Actions\Action;
use Statamic\Facades\User;

use function Statamic\trans as __;
use function Statamic\trans_choice;

/**
 * "Reject": not this researcher's work. Durable against re-fetch.
 */
final class Reject extends Action
{
    protected $icon = 'x';

    protected $dangerous = true;

    public function __construct(private readonly Verifier $verifier)
    {
        parent::__construct();
    }

    public static function title()
    {
        return __('Reject publication');
    }

    public function visibleTo($item)
    {
        return $this->verifier->isPublication($item) && $item->get('verification_status') !== 'rejected';
    }

    public function authorize($user, $item)
    {
        return $user->can(Verifier::PERMISSION);
    }

    protected function fieldItems()
    {
        return [
            'reason' => [
                'type' => 'text',
                'display' => __('Reason'),
                'instructions' => __('Optional. Why this is not their work, for the next person who sees it.'),
            ],
        ];
    }

    public function confirmationText()
    {
        /** @translation */
        return 'Reject this publication? It will not be shown, and a fetch will never bring it back.|Reject these :count publications? They will not be shown, and a fetch will never bring them back.';
    }

    public function buttonText()
    {
        /** @translation */
        return 'Reject|Reject :count publications';
    }

    public function run($items, $values)
    {
        $user = User::current();
        $reason = \is_string($values['reason'] ?? null) ? $values['reason'] : null;

        foreach ($items as $entry) {
            $this->verifier->reject($entry, $user, $reason);
        }

        return trans_choice('Publication rejected|:count publications rejected', $items->count());
    }
}
