<?php

namespace App\Domain\Import;

use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\Product;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Brings a store's existing subscribers in from a spreadsheet.
 *
 * The whole file is read and judged BEFORE a single row is written. That is not a
 * nicety: a half-applied migration leaves a store where some members are live and
 * some are not, with no way to tell which without reading the file by hand. So
 * `import()` runs the same scan the merchant already saw as a dry run, and if one
 * row is bad it writes nothing and says why.
 *
 * Three laws it does not bend:
 *
 *  - Money. An imported plan is not scheduled unless the merchant asked for it
 *    (ImportOptions::$startCharging) or the file names a next_charge_at itself. No
 *    ledger rows are invented for the old system's history — those charges were
 *    made by another system and recording them here would be claiming money we
 *    never moved. They are kept in the plan's meta, where they read as history.
 *  - Tenancy. Every write happens inside Tenant::run($shop); the shop is the
 *    caller's, never anything the file says.
 *  - State. A status change on an existing plan goes through the guarded machine,
 *    and an illegal move (cancelled → active) is caught in the SCAN so the commit
 *    never meets one.
 *
 * Scale: one row in memory at a time, one transaction per CHUNK rows, and the
 * per-chunk lookups (existing plans, catalog products) are batched — a fifty
 * thousand row file costs fifty thousand rows of work, not fifty thousand queries.
 */
final class SubscriptionImporter
{
    // === CONSTANTS ===
    /** Timeline kinds this module writes. */
    public const KIND_IMPORTED = 'subscription_imported';

    public const KIND_IMPORT_UPDATED = 'subscription_import_updated';

    /** meta key holding everything the file said, for the audit trail. */
    public const META_IMPORT = 'import';

    /** The consent terms version recorded when the source system's own is unknown. */
    public const CONSENT_VERSION_MIGRATED = 'migrated';

    /**
     * Scan the file and report, writing nothing at all. This is what the merchant
     * reads before deciding.
     */
    public function dryRun(Shop $shop, string $path, ImportOptions $options): ImportReport
    {
        return $this->run($shop, $path, $options, write: false);
    }

    /**
     * Scan, and only if EVERY row is clean, write. A file with one bad row imports
     * nothing — reported back as an aborted run, not as a partial success.
     */
    public function import(Shop $shop, string $path, ImportOptions $options): ImportReport
    {
        $scan = $this->dryRun($shop, $path, $options);

        if (! $scan->isClean()) {
            $scan->abort($scan->abortReason ?? __('import.abort.invalid_rows', ['count' => $scan->invalid]));

            return $scan;
        }

        return $this->run($shop, $path, $options, write: true);
    }

    /** One streaming pass over the file. */
    private function run(Shop $shop, string $path, ImportOptions $options, bool $write): ImportReport
    {
        $report = new ImportReport($write ? ImportReport::MODE_COMMIT : ImportReport::MODE_DRY_RUN);
        $normalizer = new SubscriptionRowNormalizer($options);
        $reader = new CsvReader($path);

        try {
            $reader->inspect();
        } catch (RuntimeException $e) {
            $report->abort($e->getMessage());

            return $report;
        }

        $present = $reader->presentColumns();
        $report->unknownHeaders = $reader->unknownHeaders();

        foreach (SubscriptionCsvSchema::REQUIRED_HEADERS as $required) {
            if (! in_array($required, $present, true) && ! in_array('public_id', $present, true)) {
                $report->abort(__('import.abort.missing_header', ['column' => $required]));

                return $report;
            }
        }

        // Keys already seen in THIS file. Two rows claiming the same membership are
        // a merchant mistake that would otherwise apply twice, last one winning,
        // with no trace of the first.
        $seen = [];
        $buffer = [];

        return Tenant::run($shop, function () use ($shop, $reader, $normalizer, $options, $write, $report, $present, &$seen, &$buffer): ImportReport {
            foreach ($reader->rows() as $raw) {
                $report->rows++;
                $buffer[] = $normalizer->normalize($raw['line'], $raw['values']);

                if (count($buffer) >= SubscriptionCsvSchema::CHUNK) {
                    $this->flush($shop, $buffer, $normalizer, $options, $present, $report, $seen, $write);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                $this->flush($shop, $buffer, $normalizer, $options, $present, $report, $seen, $write);
                $buffer = [];
            }

            if ($report->rows === 0) {
                $report->abort(__('import.abort.no_rows'));
            }

            return $report;
        });
    }

    /**
     * Judge (and optionally write) one chunk. Everything that needs the database —
     * the existing plans, the catalog check — is asked once for the whole chunk.
     *
     * @param  list<NormalizedRow>  $rows
     * @param  list<string>  $present
     * @param  array<string, int>  $seen
     */
    private function flush(
        Shop $shop,
        array $rows,
        SubscriptionRowNormalizer $normalizer,
        ImportOptions $options,
        array $present,
        ImportReport $report,
        array &$seen,
        bool $write,
    ): void {
        $existing = $this->existingPlans($rows);
        $catalog = $this->knownProductIds($rows);

        $writable = [];

        foreach ($rows as $row) {
            $plan = $this->matchExisting($row, $existing);
            $row = $this->judge($row, $plan, $normalizer, $options, $catalog, $seen);

            $key = $row->key();
            $seen[$key] ??= $row->line;

            foreach ($row->warnings as $warning) {
                $report->addWarning($row->line, $key, $warning);
            }

            if (! $row->isValid()) {
                $report->invalid++;
                foreach ($row->errors as $error) {
                    $report->addError($row->line, $key, $error);
                }

                continue;
            }

            $report->valid++;
            $plan === null ? $report->creates++ : $report->updates++;

            $this->tallyMoney($row, $plan, $options, $present, $report);

            if ($row->filled('card_token')) {
                $report->tokens++;
            }

            if ($options->writeConsent && $normalizer->wouldCharge($row)) {
                $report->consents++;
            }

            $writable[] = [$row, $plan];
        }

        if (! $write || $writable === []) {
            return;
        }

        DB::transaction(function () use ($shop, $writable, $options, $present, $report): void {
            foreach ($writable as [$row, $plan]) {
                $this->persist($shop, $row, $plan, $options, $present);
                $report->written++;
            }
        });
    }

    /**
     * Everything wrong with this row that only the database (or the rest of the
     * file) can know: duplicates, illegal state moves, unknown products, and the
     * fields a CREATE cannot do without.
     *
     * @param  array<string, bool>  $catalog
     * @param  array<string, int>  $seen
     */
    private function judge(
        NormalizedRow $row,
        ?InstallmentPlan $plan,
        SubscriptionRowNormalizer $normalizer,
        ImportOptions $options,
        array $catalog,
        array $seen,
    ): NormalizedRow {
        $errors = [];
        $warnings = [];

        $key = $row->key();
        if (isset($seen[$key])) {
            $errors[] = __('import.error.duplicate', ['line' => $seen[$key]]);
        }

        if ($plan === null) {
            // A row naming a public_id we do not have is pointing at a plan from
            // another store or a deleted one — creating a fresh subscription from
            // it would silently duplicate a real customer's billing.
            if ($row->filled('public_id')) {
                $errors[] = __('import.error.unknown_public_id', ['value' => (string) $row->get('public_id')]);
            } else {
                [$createErrors, $createWarnings] = $normalizer->createRequirements($row);
                $errors = [...$errors, ...$createErrors];
                $warnings = [...$warnings, ...$createWarnings];
            }
        } else {
            if ($row->filled('plan_kind') && $row->get('plan_kind') !== $plan->plan_kind) {
                $errors[] = __('import.error.kind_change', [
                    'from' => $plan->plan_kind->value,
                    'to' => $row->get('plan_kind')->value,
                ]);
            }

            // The guarded machine is the law for a live plan. Finding the illegal
            // move HERE is the point of the dry run — the commit must never meet one.
            $target = $row->get('status');
            if ($target instanceof PlanStatus && $target !== $plan->status) {
                $legal = array_map(
                    fn (PlanStatus $s): string => $s->value,
                    PlanStatus::allowed()[$plan->status->value] ?? [],
                );

                if (! in_array($target->value, $legal, true)) {
                    $errors[] = __('import.error.illegal_transition', [
                        'from' => $plan->status->value,
                        'to' => $target->value,
                    ]);
                }
            }
        }

        $productId = $row->get('external_product_id');
        if ($productId !== null && ! isset($catalog[(string) $productId])) {
            // Not fatal — the catalog may simply not be synced yet — but a plan
            // pointing at a product this store does not have will fail to build a
            // cycle order later, and that is a charge that half-happens.
            $warnings[] = __('import.warn.unknown_product', ['value' => (string) $productId]);
        }

        return $row->with($errors, $warnings);
    }

    /**
     * Write one row. Only ever called for a row the scan already cleared, inside
     * the chunk's transaction and the caller's tenant binding.
     *
     * @param  list<string>  $present
     */
    private function persist(Shop $shop, NormalizedRow $row, ?InstallmentPlan $plan, ImportOptions $options, array $present): void
    {
        $creating = $plan === null;
        $plan ??= new InstallmentPlan;

        $attributes = $this->attributes($row, $plan, $options, $creating);

        // next_charge_at is money, so it has its own rule (see scheduleFor()) and is
        // only ever set when that rule produced an instruction.
        $schedule = $this->scheduleFor($row, $plan, $options, $present, $creating);
        if ($schedule !== false) {
            $attributes['next_charge_at'] = $schedule;
        }

        $attributes['meta'] = $this->mergedMeta($row, $plan, $options);

        $plan->fill($attributes);

        if ($creating) {
            $plan->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'status' => ($row->get('status') ?? PlanStatus::DRAFT)->value,
            ]);
        }

        $plan->save();

        // An existing plan's status moves through the guarded machine, never by
        // assignment — that is what writes the Timeline row and enforces the rules.
        $target = $row->get('status');
        if (! $creating && $target instanceof PlanStatus && $target !== $plan->status) {
            $plan->transitionTo($target, ['source' => ImportOptions::SOURCE]);
        }

        $this->vaultCard($row, $plan);

        if ($options->writeConsent) {
            $this->transcribeConsent($shop, $row, $plan);
        }

        Timeline::record(
            kind: $creating ? self::KIND_IMPORTED : self::KIND_IMPORT_UPDATED,
            details: [
                'line' => $row->line,
                'membership_id' => $row->get('import_key'),
                'file' => $options->filename,
                'next_charge_at' => $plan->next_charge_at?->toIso8601String(),
            ],
            planId: $plan->getKey(),
            shopId: (int) $shop->getKey(),
        );
    }

    /**
     * The plan columns this row instructs us to set. An absent or empty cell is
     * not an instruction — on an update it leaves the stored value alone, which is
     * what makes "export, fix one price in Excel, import" safe.
     *
     * @return array<string, mixed>
     */
    private function attributes(NormalizedRow $row, InstallmentPlan $plan, ImportOptions $options, bool $creating): array
    {
        $attributes = [];

        $set = function (string $column, string $key, ?string $guard = null) use (&$attributes, $row): void {
            if ($row->filled($guard ?? $key) && $row->get($key) !== null) {
                $attributes[$column] = $row->get($key);
            }
        };

        $set('import_key', 'import_key', 'membership_id');
        $set('customer_name', 'customer_name', 'first_name');
        $set('customer_email', 'customer_email', 'email');
        $set('customer_phone', 'customer_phone', 'phone');
        $set('external_product_id', 'external_product_id', 'product_id');
        $set('external_variant_id', 'external_variant_id', 'variant_id');
        $set('installment_amount', 'installment_amount', 'plan_amount');
        $set('regular_amount', 'regular_amount');
        $set('currency', 'currency');
        $set('interval_count', 'interval_count');

        // last_name alone should still update the name (the guard above only looks
        // at first_name), and person_id fills BOTH id columns the rails read.
        if ($row->filled('last_name') && $row->get('customer_name') !== null) {
            $attributes['customer_name'] = $row->get('customer_name');
        }

        if ($row->filled('person_id')) {
            $attributes['external_customer_id'] = $row->get('external_customer_id');
            $attributes['shopify_customer_id'] = $row->get('external_customer_id');
        }

        if ($row->filled('cycle') && $row->get('billing_frequency') !== null) {
            $attributes['billing_frequency'] = $row->get('billing_frequency')->value;
        }

        if ($row->filled('plan_kind')) {
            $attributes['plan_kind'] = $row->get('plan_kind')->value;
        }

        // A recurring plan has no balance to pay down, so its total mirrors the
        // cycle amount; an installments plan's total is a real number the file must
        // carry (createRequirements enforces that).
        $kind = $attributes['plan_kind'] ?? $plan->plan_kind?->value ?? SubscriptionCsvSchema::DEFAULT_PLAN_KIND;

        if ($row->filled('total_amount')) {
            $attributes['total_amount'] = $row->get('total_amount');
        } elseif ($kind === PlanKind::RECURRING->value && isset($attributes['installment_amount'])) {
            $attributes['total_amount'] = $attributes['installment_amount'];
        }

        if ($creating) {
            $attributes['public_id'] = (string) Str::ulid();
            // Written explicitly, not left to the column default: the table defaults
            // to `installments`, so a file with no plan_kind column would create
            // recurring memberships that the engine treats as a balance to pay down.
            $attributes['plan_kind'] = $kind;
            $attributes['charge_context'] = $kind === PlanKind::RECURRING->value ? 'recurring' : 'installment';
            $attributes['requires_manual_payment'] = false;
            $attributes['currency'] ??= $options->defaultCurrency;
            $attributes['total_charged'] = 0;

            // A --default-product id arrives with no column behind it, so the
            // filled() guard above never sees it; a create takes the resolved value
            // whatever its source. Both id pairs are written because the two rails
            // read different ones (InstallmentPlan::externalProductId()).
            $attributes['external_product_id'] ??= $row->get('external_product_id');
            $attributes['external_variant_id'] ??= $row->get('external_variant_id');
            $attributes['shopify_product_id'] = $attributes['external_product_id'] ?? null;
            $attributes['shopify_variant_id'] = $attributes['external_variant_id'] ?? null;

            // The old system's lifetime total belongs on an INSTALLMENTS plan,
            // where it is the balance already paid down. On an open-ended
            // recurring plan there is nothing to pay down, so carrying it into
            // total_charged would only make the money columns lie.
            if ($kind === PlanKind::INSTALLMENTS->value && $row->get('total_charged') !== null) {
                $attributes['total_charged'] = $row->get('total_charged');
            }

            if ($row->get('membership_created_at') !== null) {
                $attributes['created_at'] = $row->get('membership_created_at');
            }
        }

        return $attributes;
    }

    /**
     * When this plan next bills — or `false` for "do not touch it".
     *
     * The file's own next_charge_at column always wins: that is our export coming
     * back with a merchant's decision in it. Otherwise a date is only derived when
     * the run was explicitly asked to start charging, and only for a subscription
     * that is actually meant to keep billing. Everything else lands unscheduled,
     * so importing a store's history never surprises its customers with a charge.
     *
     * @param  list<string>  $present
     */
    private function scheduleFor(NormalizedRow $row, InstallmentPlan $plan, ImportOptions $options, array $present, bool $creating): CarbonImmutable|null|false
    {
        if (in_array('next_charge_at', $present, true) && $row->filled('next_charge_at')) {
            return $row->get('next_charge_at');
        }

        if (! $options->startCharging) {
            // On a create there is nothing to preserve, so it starts unscheduled.
            return $creating ? null : false;
        }

        $status = $row->get('status') ?? $plan->status;
        if ($status !== PlanStatus::ACTIVE || $row->get('auto_renew') === false) {
            return $creating ? null : false;
        }

        foreach (['trial_ends_at', 'current_period_end', 'expires_at'] as $column) {
            $candidate = $row->get($column);

            if ($candidate instanceof CarbonImmutable) {
                return $candidate;
            }
        }

        // No period end in the file: bill one cycle on from where the period began.
        $frequency = $row->get('billing_frequency') ?? $plan->billing_frequency;
        $base = $row->get('current_period_start') ?? $row->get('starts_at');

        if ($frequency === null || ! $base instanceof CarbonImmutable) {
            return $creating ? null : false;
        }

        return CarbonImmutable::parse($frequency->addTo($base, (int) ($row->get('interval_count') ?: 1)));
    }

    /**
     * Everything the file said, kept beside the plan. The old system's charge
     * counts and totals live HERE and only here — they are history, not money this
     * system moved, and the payment ledger is reserved for the second kind.
     *
     * @return array<string, mixed>
     */
    private function mergedMeta(NormalizedRow $row, InstallmentPlan $plan, ImportOptions $options): array
    {
        $meta = (array) ($plan->meta ?? []);
        $import = (array) ($meta[self::META_IMPORT] ?? []);

        $title = $row->get('item_title');
        if ($title !== null) {
            $meta[InstallmentPlan::META_ITEM_TITLE] = $title;
        }

        foreach ([
            'membership_id' => $row->get('import_key'),
            'person_id' => $row->get('external_customer_id'),
            'national_id' => $row->get('national_id'),
            'plan_name' => $row->get('plan_name'),
            'product_desc' => $row->get('product_desc'),
            'auto_renew' => $row->get('auto_renew'),
            'cancellation_reason' => $row->get('cancellation_reason'),
            'recurring_payment_id' => $row->get('recurring_payment_id'),
            'recurring_status' => $row->get('recurring_status'),
        ] as $key => $value) {
            if ($value !== null) {
                $import[$key] = $value;
            }
        }

        foreach ([
            'starts_at', 'expires_at', 'trial_ends_at', 'cancelled_at',
            'current_period_start', 'current_period_end',
            'recurring_canceled_at', 'recurring_ended_at',
        ] as $column) {
            $value = $row->get($column);

            if ($value instanceof CarbonImmutable) {
                $import[$column] = $value->toIso8601String();
            }
        }

        if ($row->get('address') !== []) {
            $import['address'] = $row->get('address');
        }

        if ($row->get('history') !== []) {
            $import['history'] = $row->get('history');
        }

        $import['source'] = ImportOptions::SOURCE;
        $import['file'] = $options->filename;
        $import['imported_at'] = CarbonImmutable::now()->toIso8601String();

        $meta[self::META_IMPORT] = $import;

        return $meta;
    }

    /**
     * Put the migrated card in the vault. The plan's own payment method is updated
     * in place when it has one, so re-importing the same file does not leave a
     * store with five copies of one customer's card.
     */
    private function vaultCard(NormalizedRow $row, InstallmentPlan $plan): void
    {
        if (! $row->filled('card_token') && ! $row->filled('last_4_digits')) {
            return;
        }

        $fields = array_filter([
            'payplus_card_token_uid' => $row->get('card_token'),
            'payplus_customer_uid' => $row->get('payplus_customer_uid'),
            'payplus_token_reference' => $row->get('recurring_token'),
            'card_brand' => $row->get('card_brand'),
            'card_last_four' => $row->get('card_last_four'),
            'exp_month' => $row->get('exp_month'),
            'exp_year' => $row->get('exp_year'),
        ], fn ($v): bool => $v !== null);

        if ($fields === []) {
            return;
        }

        $method = $plan->payment_method_id !== null
            ? InstallmentPaymentMethod::query()->find($plan->payment_method_id)
            : null;

        if ($method === null) {
            $method = new InstallmentPaymentMethod;
            $method->forceFill([
                'shop_id' => $plan->shop_id,
                'customer_id' => $plan->customer_id,
                'shopify_customer_id' => $plan->shopify_customer_id,
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);
        }

        $method->fill($fields)->save();

        if ($plan->payment_method_id !== $method->getKey()) {
            $plan->forceFill(['payment_method_id' => $method->getKey()])->save();
        }
    }

    /**
     * Transcribe the consent the customer gave in the system they signed up in.
     *
     * Without a consent row the charge engine's gate fails closed and an imported
     * subscriber is never billed again — so a migration that skipped this would
     * look successful and quietly stop the merchant's revenue. The row is marked
     * `migrated` and dated from the file's own start date, so a later dispute
     * points at the source system rather than pretending consent was given here.
     */
    private function transcribeConsent(Shop $shop, NormalizedRow $row, InstallmentPlan $plan): void
    {
        $isRecurring = $plan->plan_kind === PlanKind::RECURRING;
        $settings = MerchantBillingSettings::current();

        $acceptedAt = $row->get('starts_at')
            ?? $row->get('membership_created_at')
            ?? CarbonImmutable::now();

        CustomerConsent::query()->firstOrCreate(
            [
                'shop_id' => (int) $shop->getKey(),
                'plan_id' => $plan->getKey(),
                'consent_context' => $isRecurring
                    ? CustomerConsent::CONTEXT_RECURRING
                    : CustomerConsent::CONTEXT_INSTALLMENTS,
            ],
            [
                'customer_id' => $plan->customer_id,
                'shopify_customer_id' => $plan->shopify_customer_id,
                'customer_email' => $plan->customer_email,
                'accepted_at' => $acceptedAt,
                'accepted_terms_version' => self::CONSENT_VERSION_MIGRATED.':'.$settings->termsVersion(),
                'cancellation_policy_snapshot' => $settings->cancellationPolicyText(),
                'billing_amount_description' => sprintf(
                    '%s %s per billing cycle',
                    (string) $plan->installment_amount,
                    (string) $plan->currency,
                ),
                'billing_frequency_description' => (string) ($plan->billing_frequency?->value ?? ''),
            ],
        );
    }

    /**
     * The plans this chunk's rows already refer to, in one query per key kind.
     *
     * @param  list<NormalizedRow>  $rows
     * @return array{public: array<string, InstallmentPlan>, import: array<string, InstallmentPlan>}
     */
    private function existingPlans(array $rows): array
    {
        $publicIds = [];
        $importKeys = [];

        foreach ($rows as $row) {
            $publicId = $row->get('public_id');
            $importKey = $row->get('import_key');

            if ($publicId !== null) {
                $publicIds[] = (string) $publicId;
            }
            if ($importKey !== null) {
                $importKeys[] = (string) $importKey;
            }
        }

        $byPublic = $publicIds === []
            ? collect()
            : InstallmentPlan::query()->whereIn('public_id', array_unique($publicIds))->get()->keyBy('public_id');

        $byImport = $importKeys === []
            ? collect()
            : InstallmentPlan::query()->whereIn('import_key', array_unique($importKeys))->get()->keyBy('import_key');

        return ['public' => $byPublic->all(), 'import' => $byImport->all()];
    }

    /** @param array{public: array<string, InstallmentPlan>, import: array<string, InstallmentPlan>} $existing */
    private function matchExisting(NormalizedRow $row, array $existing): ?InstallmentPlan
    {
        $publicId = $row->get('public_id');

        if ($publicId !== null) {
            return $existing['public'][(string) $publicId] ?? null;
        }

        $importKey = $row->get('import_key');

        return $importKey !== null ? ($existing['import'][(string) $importKey] ?? null) : null;
    }

    /**
     * Which of this chunk's product ids the store's synced catalog actually knows.
     *
     * @param  list<NormalizedRow>  $rows
     * @return array<string, bool>
     */
    private function knownProductIds(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $id = $row->get('external_product_id');

            if ($id !== null) {
                $ids[(string) $id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        try {
            return Product::query()
                ->whereIn('external_id', array_keys($ids))
                ->pluck('external_id')
                ->mapWithKeys(fn ($id): array => [(string) $id => true])
                ->all();
        } catch (Throwable) {
            // The catalog check is advisory; never let it fail an import.
            return $ids;
        }
    }

    /**
     * How much this file, as committed, puts on the calendar in the next month —
     * the one figure a merchant needs before pressing the button.
     *
     * @param  list<string>  $present
     */
    private function tallyMoney(NormalizedRow $row, ?InstallmentPlan $plan, ImportOptions $options, array $present, ImportReport $report): void
    {
        $schedule = $this->scheduleFor($row, $plan ?? new InstallmentPlan, $options, $present, $plan === null);

        if (! $schedule instanceof CarbonImmutable) {
            return;
        }

        $report->scheduled++;

        $amount = $row->get('installment_amount') ?? (float) ($plan?->installment_amount ?? 0);

        if ($schedule->lte(CarbonImmutable::now()->addDays(ImportReport::HORIZON_DAYS))) {
            $report->moneyInHorizon += (float) $amount;
        }
    }
}
