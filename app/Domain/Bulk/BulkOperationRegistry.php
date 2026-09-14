<?php

namespace App\Domain\Bulk;

use App\Domain\Bulk\Operations\ChangeBillingFrequency;
use App\Domain\Bulk\Operations\PauseSubscriptions;
use App\Domain\Bulk\Operations\ResumeSubscriptions;
use App\Domain\Bulk\Operations\SetNextChargeDate;
use App\Domain\Bulk\Operations\ShiftNextChargeDate;

/**
 * The verbs a bulk edit may use — ONE list, read by the screen's dropdown, by the
 * runner that resolves a stored run back into code, and by the tests that pin the
 * set.
 *
 * A whitelist rather than a discovered namespace, for the same reason the AI
 * module's patch ops are a whitelist: the key is persisted on the run row, and a
 * stored key must resolve to exactly the code that was confirmed — never to
 * whatever class happens to sit in the folder now.
 *
 * WHAT IS DELIBERATELY ABSENT IS CANCEL. Everything here is either reversible
 * (pause/resume) or a date a merchant can move again; cancelling is terminal, it
 * emails every customer, and the state machine offers no way back. A book of
 * subscriptions cancelled by a mis-set filter cannot be restored by another bulk
 * edit, so that verb stays a per-subscription decision. Adding it later is one
 * class in Operations/ and one line here — the architecture does not forbid it,
 * this list does.
 */
final class BulkOperationRegistry
{
    // === CONSTANTS ===
    /**
     * key => class. The ORDER is the order the screen's dropdown renders in:
     * dates first (what the screen was asked for), cadence next, holds last.
     *
     * @var array<string, class-string<BulkOperation>>
     */
    public const OPERATIONS = [
        SetNextChargeDate::KEY => SetNextChargeDate::class,
        ShiftNextChargeDate::KEY => ShiftNextChargeDate::class,
        ChangeBillingFrequency::KEY => ChangeBillingFrequency::class,
        PauseSubscriptions::KEY => PauseSubscriptions::class,
        ResumeSubscriptions::KEY => ResumeSubscriptions::class,
    ];

    /** Translation key prefix for the dropdown labels + helper lines. */
    public const LABEL_PREFIX = 'subscriptions.bulk.op.';

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::OPERATIONS);
    }

    public function has(string $key): bool
    {
        return isset(self::OPERATIONS[$key]);
    }

    /**
     * Resolve a key to a ready operation.
     *
     * Through the container, because a lifecycle operation needs the
     * SubscriptionLifecycleService injected — the same instance the detail page
     * uses, not a copy of its rules.
     *
     * @throws InvalidBulkEdit on a key this build does not know
     */
    public function get(string $key): BulkOperation
    {
        if (! $this->has($key)) {
            throw new InvalidBulkEdit('subscriptions.bulk.error.operation_unknown');
        }

        return app(self::OPERATIONS[$key]);
    }

    /**
     * key => translated label, for the operation dropdown.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->keys() as $key) {
            $options[$key] = __(self::LABEL_PREFIX.$this->slug($key).'.label');
        }

        return $options;
    }

    /** The helper line under the dropdown for one verb. */
    public function help(string $key): string
    {
        return __(self::LABEL_PREFIX.$this->slug($key).'.help');
    }

    /**
     * The translation slug for a key.
     *
     * The machine keys are long ("set_next_charge_date") because they live in a
     * database column forever; the lang keys are short ("set_date") because they
     * live in a file people read. This is the one place the two meet.
     */
    public function slug(string $key): string
    {
        return match ($key) {
            SetNextChargeDate::KEY => 'set_date',
            ShiftNextChargeDate::KEY => 'shift',
            ChangeBillingFrequency::KEY => 'frequency',
            PauseSubscriptions::KEY => 'pause',
            ResumeSubscriptions::KEY => 'resume',
            default => $key,
        };
    }
}
