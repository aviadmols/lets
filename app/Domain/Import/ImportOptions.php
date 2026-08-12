<?php

namespace App\Domain\Import;

/**
 * The knobs a migration run is allowed to have, in one immutable object.
 *
 * The one that matters is `startCharging`. Importing a file makes a store's
 * subscriptions real; scheduling them makes them CHARGE. Those are two different
 * decisions and a merchant should be able to take the first without discovering
 * the second the next morning, so the default is off: imported plans land with no
 * next_charge_at and the scheduler never looks at them until the merchant says so.
 *
 * The exception, and it is the important one, is a file that carries an explicit
 * `next_charge_at` column — that is our own export coming back, edited on purpose,
 * and a merchant who typed a date into that column has said what they want.
 */
final class ImportOptions
{
    // === CONSTANTS ===
    /** The `source` recorded on every plan this importer touches. */
    public const SOURCE = 'csv_import';

    public function __construct(
        /** Schedule imported plans from the file's period dates (money-affecting; off by default). */
        public readonly bool $startCharging = false,

        /** Currency for rows whose file carries no currency column. */
        public readonly string $defaultCurrency = SubscriptionCsvSchema::DEFAULT_CURRENCY,

        /** Platform product id used for rows with no product_id of their own. */
        public readonly ?string $defaultProductId = null,

        /** Platform variant id used for rows with no variant_id of their own. */
        public readonly ?string $defaultVariantId = null,

        /** A single date format (e.g. 'm/d/Y') instead of the schema's ordered guesses. */
        public readonly ?string $dateFormat = null,

        /** The timezone the file's naive dates are written in. */
        public readonly ?string $timezone = null,

        /** Transcribe a customer_consents row per plan (without one, nothing can ever charge). */
        public readonly bool $writeConsent = true,

        /** Where the file came from — recorded in the plan's meta for the audit trail. */
        public readonly ?string $filename = null,
    ) {}

    public function timezone(): string
    {
        return $this->timezone ?: (string) config('app.timezone', 'UTC');
    }
}
