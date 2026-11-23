<?php

namespace App\Domain\Insights;

use App\Models\InstallmentPayment;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every recurring subscription that has paid at least once, with the day of its FIRST
 * charge through the system and how many charges it has paid.
 *
 * The one source for "who joined when" (joins), "who passed month N" (retention) and
 * "how big was the book on day D" (net chart), so the three can never disagree.
 *
 * "Joined" is the first PAID day, not the day the row was created: an abandoned checkout
 * creates a row too, and nobody who never paid has joined. charged_at is the moment the
 * money moved; created_at stands in only where an old row never recorded it — updated_at
 * is never used, because any later touch of the row would move the "join" with it.
 *
 * Tenant-scoped through the models' BelongsToShop scope; never names a shop_id.
 */
final class PaidSubscriptions
{
    // === CONSTANTS ===
    private const FIRST_PAID = 'MIN(COALESCE(installment_payments.charged_at, installment_payments.created_at))';

    /**
     * @param  bool  $includeImported  false = only people who joined through the site (the joins
     *                                 count); true = everyone the system has charged (retention)
     * @return list<array{plan: int, day: string, paid: int, imported: bool, product: string, name: string}>
     */
    public function all(bool $includeImported): array
    {
        $planColumns = ['id', 'shop_id', 'meta', 'customer_name', 'customer_email', 'external_product_id', 'shopify_product_id'];

        return InstallmentPayment::query()
            ->where('installment_payments.status', PaymentStatus::SUCCEEDED->value)
            ->whereHas('plan', function (Builder $plan) use ($includeImported): void {
                $plan->where('plan_kind', PlanKind::RECURRING->value);
                if (! $includeImported) {
                    $plan->whereNull('meta->import');
                }
            })
            ->groupBy('installment_payments.plan_id')
            ->selectRaw('installment_payments.plan_id as plan_id, '.self::FIRST_PAID.' as first_paid, COUNT(*) as paid')
            // What they subscribed to and who they are — the joins screen lists both.
            ->with(['plan' => fn ($q) => $q->select($planColumns)->with('product')])
            ->get()
            ->map(fn (InstallmentPayment $row): array => [
                'plan' => (int) $row->plan_id,
                'day' => (string) InsightsClock::day($row->first_paid),
                'paid' => (int) $row->paid,
                'imported' => data_get($row->plan?->meta, 'import') !== null,
                'product' => trim((string) ($row->plan?->productTitle() ?? data_get($row->plan?->meta, 'import.plan_name') ?? '')),
                'name' => (string) ($row->plan?->customerLabel() ?? ''),
            ])
            ->values()
            ->all();
    }
}
