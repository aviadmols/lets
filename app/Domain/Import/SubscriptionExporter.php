<?php

namespace App\Domain\Import;

use App\Models\InstallmentPayment;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The store's subscriptions, as the spreadsheet the importer reads back.
 *
 * The columns are SubscriptionCsvSchema::COLUMNS in order, so a merchant exports,
 * changes a price or a next charge date in Excel, and imports the same file with
 * nothing renamed. `public_id` is what makes that a round trip rather than a
 * duplicate: it is this system's own key, so the import updates the plan it came
 * from even if the merchant edited the membership id.
 *
 * One column is deliberately blank. `card_token` is a credential — exporting a
 * store's saved tokens into a file that lands in a Downloads folder and gets
 * mailed around is a breach with a spreadsheet icon. The card's brand and last
 * four go out so the merchant can SEE which card is on file, and the importer's
 * merge law (an empty cell changes nothing) means re-importing the export leaves
 * every vaulted token exactly as it was.
 *
 * Memory is flat: plans are streamed in chunks and each chunk is written and
 * dropped, so a store with tens of thousands of subscribers exports in one pass.
 */
final class SubscriptionExporter
{
    // === CONSTANTS ===
    /** Unambiguous, and the first format the importer tries — a clean round trip. */
    public const DATE_FORMAT = 'Y-m-d H:i:s';

    /** Plans loaded (and written) per pass. */
    public const CHUNK = 500;

    /** RFC-4180 CSV, matching GiftListExporter. */
    private const SEPARATOR = ',';

    private const ENCLOSURE = '"';

    private const ESCAPE = '';

    /**
     * A browser download that starts before the last row is read.
     *
     * The optional query is what lets the SUBSCRIPTIONS SCREEN export exactly
     * what it is showing — its tab, its filters, its search — instead of the
     * whole book. Omitted, it exports everything, which is what the import
     * screen wants.
     *
     * @param  Builder<InstallmentPlan>|null  $query
     */
    public function download(Shop $shop, ?Builder $query = null): StreamedResponse
    {
        $filename = 'subscriptions-'.$shop->getKey().'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(
            function () use ($shop, $query): void {
                $handle = fopen('php://output', 'w');
                $this->write($shop, $handle, $query);
                fclose($handle);
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /** Write the export to a path on disk (the CLI's route). Returns rows written. */
    public function toFile(Shop $shop, string $path): int
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new RuntimeException("Cannot write export file [{$path}].");
        }

        try {
            return $this->write($shop, $handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return int rows written
     */
    public function write(Shop $shop, $handle, ?Builder $query = null): int
    {
        fwrite($handle, SubscriptionCsvSchema::BOM);
        $this->put($handle, SubscriptionCsvSchema::COLUMNS);

        return (int) Tenant::run($shop, function () use ($handle, $query): int {
            $written = 0;

            ($query ?? InstallmentPlan::query())
                ->reorder()
                ->orderBy('id')
                ->chunkById(self::CHUNK, function (Collection $plans) use ($handle, &$written): void {
                    $methods = $this->methodsFor($plans);
                    $history = $this->historyFor($plans);

                    foreach ($plans as $plan) {
                        $this->put($handle, $this->line(
                            $plan,
                            $methods[$plan->payment_method_id] ?? null,
                            $history[$plan->getKey()] ?? [],
                        ));
                        $written++;
                    }
                });

            return $written;
        });
    }

    /**
     * @param  array<string, mixed>  $history
     * @return list<string>
     */
    private function line(InstallmentPlan $plan, ?InstallmentPaymentMethod $method, array $history): array
    {
        $import = (array) (($plan->meta ?? [])[SubscriptionImporter::META_IMPORT] ?? []);
        // The merged view (admin edit wins over the imported copy) — an address a
        // merchant corrected on the detail page must ride the next export.
        $address = $plan->contactAddress();
        [$first, $last] = $this->splitName((string) ($plan->customer_name ?? ''));

        $cancelled = $plan->status === PlanStatus::CANCELLED;

        $row = [
            'public_id' => (string) $plan->public_id,
            'membership_id' => (string) ($plan->import_key ?? $import['membership_id'] ?? ''),
            'person_id' => (string) ($plan->externalCustomerId() ?? ''),

            'first_name' => $first,
            'last_name' => $last,
            'national_id' => (string) ($import['national_id'] ?? ''),
            'email' => (string) ($plan->customer_email ?? ''),
            'phone' => (string) ($plan->customer_phone ?? ''),
            'street' => (string) ($address['street'] ?? ''),
            'building_number' => (string) ($address['building_number'] ?? ''),
            'apartment_number' => (string) ($address['apartment_number'] ?? ''),
            'city' => (string) ($address['city'] ?? ''),
            'zip_code' => (string) ($address['zip_code'] ?? ''),
            'country' => (string) ($address['country'] ?? ''),

            'product_id' => (string) ($plan->externalProductId() ?? ''),
            'variant_id' => (string) ($plan->external_variant_id ?? $plan->shopify_variant_id ?? ''),
            'plan_name' => (string) ($import['plan_name'] ?? ''),
            'product_desc' => (string) ($plan->productTitle() ?? ''),

            'plan_kind' => (string) ($plan->plan_kind?->value ?? ''),
            'cycle' => (string) ($plan->billing_frequency?->value ?? ''),
            'interval_count' => (string) ($plan->interval_count ?? 1),
            'plan_amount' => $this->money($plan->installment_amount),
            'regular_amount' => $this->money($plan->regular_amount),
            'total_amount' => $this->money($plan->total_amount),
            'currency' => (string) $plan->currency,

            'status' => (string) ($plan->status?->value ?? ''),
            'auto_renew' => $this->flag($import['auto_renew'] ?? ($plan->next_charge_at !== null)),
            'starts_at' => $this->date($import['starts_at'] ?? $plan->created_at),
            'expires_at' => $this->date($import['expires_at'] ?? null),
            'trial_ends_at' => $this->date($import['trial_ends_at'] ?? null),
            'next_charge_at' => $this->date($plan->next_charge_at),
            'cancelled_at' => $this->date($import['cancelled_at'] ?? null),
            'cancellation_reason' => (string) ($import['cancellation_reason'] ?? ''),
            'is_cancelled' => $this->flag($cancelled),

            'recurring_payment_id' => (string) ($import['recurring_payment_id'] ?? ''),
            // A credential. Blank on the way out; unchanged on the way back in.
            'recurring_token' => '',
            'recurring_status' => (string) ($import['recurring_status'] ?? ''),
            'current_period_start' => $this->date($import['current_period_start'] ?? null),
            'current_period_end' => $this->date($import['current_period_end'] ?? null),
            'recurring_canceled_at' => $this->date($import['recurring_canceled_at'] ?? null),
            'recurring_ended_at' => $this->date($import['recurring_ended_at'] ?? null),

            // Never exported — see the class docblock.
            'card_token' => '',
            'payplus_customer_uid' => '',
            'card_brand' => (string) ($method?->card_brand ?? ''),
            'last_4_digits' => (string) ($method?->card_last_four ?? ''),
            'exp_month' => (string) ($method?->exp_month ?? ''),
            'exp_year' => (string) ($method?->exp_year ?? ''),

            'charges_succeeded' => (string) ($history['succeeded'] ?? $this->legacy($import, 'charges_succeeded')),
            'charges_failed' => (string) ($history['failed'] ?? $this->legacy($import, 'charges_failed')),
            'refunds' => (string) ($history['refunded'] ?? $this->legacy($import, 'refunds')),
            'total_charged' => $this->money($plan->total_charged),
            'total_refunded' => (string) $this->legacy($import, 'total_refunded'),
            'first_charge_at' => $this->date($history['first_charge_at'] ?? ($import['history']['first_charge_at'] ?? null)),
            'last_charge_at' => $this->date($history['last_charge_at'] ?? ($import['history']['last_charge_at'] ?? null)),
            'membership_created_at' => $this->date($plan->created_at),
        ];

        // Emit in schema order, so the header and the data can never drift apart.
        return array_map(fn (string $column): string => (string) ($row[$column] ?? ''), SubscriptionCsvSchema::COLUMNS);
    }

    /**
     * The vault rows for this chunk's plans, in one query.
     *
     * @param  Collection<int, InstallmentPlan>  $plans
     * @return array<int, InstallmentPaymentMethod>
     */
    private function methodsFor(Collection $plans): array
    {
        $ids = $plans->pluck('payment_method_id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return InstallmentPaymentMethod::query()->whereKey($ids)->get()->keyBy('id')->all();
    }

    /**
     * Charge history from OUR ledger of payment slots, one grouped query per chunk
     * — the counts a merchant sees in the app, not a number copied from the file
     * they imported months ago.
     *
     * @param  Collection<int, InstallmentPlan>  $plans
     * @return array<int, array<string, mixed>>
     */
    private function historyFor(Collection $plans): array
    {
        $ids = $plans->modelKeys();

        if ($ids === []) {
            return [];
        }

        return InstallmentPayment::query()
            ->selectRaw('plan_id, status, COUNT(*) as slots, MIN(charged_at) as first_charge_at, MAX(charged_at) as last_charge_at')
            ->whereIn('plan_id', $ids)
            ->groupBy('plan_id', 'status')
            ->get()
            ->groupBy('plan_id')
            ->map(function (Collection $rows): array {
                // status is cast to the PaymentStatus enum, so key on its VALUE —
                // an enum cannot be stringified and would fatal the export.
                $by = $rows->keyBy(fn ($r): string => $r->status instanceof PaymentStatus
                    ? $r->status->value
                    : (string) $r->status);
                $charged = $rows->whereNotNull('first_charge_at');

                return [
                    'succeeded' => (int) ($by[PaymentStatus::SUCCEEDED->value]->slots ?? 0),
                    'failed' => (int) ($by[PaymentStatus::FAILED->value]->slots ?? 0),
                    'refunded' => (int) ($by[PaymentStatus::REFUNDED->value]->slots ?? 0),
                    'first_charge_at' => $charged->min('first_charge_at'),
                    'last_charge_at' => $charged->max('last_charge_at'),
                ];
            })
            ->all();
    }

    /** @param array<string, mixed> $import */
    private function legacy(array $import, string $key): string
    {
        $history = (array) ($import['history'] ?? []);

        return (string) ($history[$key] ?? '');
    }

    /** "Ariel Ben David" → ["Ariel", "Ben David"]. One word is a first name. */
    private function splitName(string $name): array
    {
        $name = trim($name);

        if ($name === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $name, 2) ?: [$name];

        return [$parts[0], $parts[1] ?? ''];
    }

    private function money(mixed $value): string
    {
        return $value === null ? '' : number_format((float) $value, 2, '.', '');
    }

    private function flag(mixed $value): string
    {
        return $value ? '1' : '0';
    }

    private function date(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse($value)->format(self::DATE_FORMAT);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param  resource  $handle
     * @param  list<string>  $fields
     */
    private function put($handle, array $fields): void
    {
        fputcsv($handle, $fields, self::SEPARATOR, self::ENCLOSURE, self::ESCAPE);
    }
}
