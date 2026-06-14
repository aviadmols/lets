<?php

namespace App\Modules\PayPlusShopifyInstallments\Exceptions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Thrown when transitionTo() is asked for a transition not in the model's
 * ALLOWED table. Fail loud — an illegal state change must never silently
 * succeed (it would corrupt the money state machine).
 */
final class IllegalTransitionException extends RuntimeException
{
    public function __construct(Model $model, BackedEnum $from, BackedEnum $to)
    {
        parent::__construct(sprintf(
            'Illegal transition on %s#%s: %s → %s.',
            $model::class,
            (string) $model->getKey(),
            $from->value,
            $to->value,
        ));
    }
}
