<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Explicit, audited consent to charge a saved PayPlus token in the future.
 * Required before any installments/recurring/upsell charge. Tenant-scoped.
 */
class CustomerConsent extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    public const CONTEXT_INSTALLMENTS = 'installments';

    public const CONTEXT_RECURRING = 'recurring';

    public const CONTEXT_UPSELL = 'upsell';

    /**
     * A customer reference that names nobody: every WooCommerce guest is "0".
     * A customer-wide consent can never be matched on it, or one guest's consent
     * would unblock every other guest's subscription.
     */
    public const NON_IDENTIFYING_REFS = ['0'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'consented_amount' => 'decimal:2',
            'consented_interval' => 'integer',
        ];
    }

    /**
     * A consent recorded by ANY path lifts the "no consent" stop on the plans it
     * covers, so the scheduler asks for their money again on its next run. One
     * place, so no consent writer has to remember to do it.
     *
     * The fast path only. A consent that arrives some other way (firstOrCreate
     * finding a row, a customer-wide row that starts matching later, a raw
     * insert) is caught by the scheduler's daily re-check of stopped plans
     * (DispatchDuePlansCommand), so no plan stays stopped once it has consent.
     */
    protected static function booted(): void
    {
        static::created(static function (self $consent): void {
            // Never allowed to fail the consent write itself: the consent row is
            // the record that matters, and a missed clear heals within a day.
            // Its own (nested) transaction: inside a writer's transaction that is a
            // savepoint, so on Postgres a failed UPDATE rolls back only the clear,
            // not the whole transaction the consent row is being written in.
            try {
                DB::transaction(static fn () => self::liftStopFor($consent));
            } catch (\Throwable $e) {
                Log::warning('consent.lift_stop_failed', [
                    'consent_id' => $consent->getKey(),
                    'shop_id' => $consent->shop_id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    private static function liftStopFor(self $consent): void
    {
        // Scoped by the consent row's OWN shop, not by whatever tenant happens to
        // be bound: a writer running outside Tenant::run must still lift it.
        $plans = InstallmentPlan::acrossAllTenants()
            ->where('shop_id', (int) $consent->shop_id)
            ->whereNotNull('consent_blocked_at');

        if ($consent->plan_id !== null) {
            $plans->whereKey((int) $consent->plan_id)->update(['consent_blocked_at' => null]);

            return;
        }

        $ref = trim((string) ($consent->shopify_customer_id ?? ''));
        $hasRef = $ref !== '' && ! in_array($ref, self::NON_IDENTIFYING_REFS, true);

        if ($consent->customer_id === null && ! $hasRef) {
            return;
        }

        // A customer-wide row covers only the plans of the kind it is for.
        match ((string) $consent->consent_context) {
            self::CONTEXT_RECURRING => $plans->where('plan_kind', PlanKind::RECURRING->value),
            self::CONTEXT_INSTALLMENTS => $plans->where('plan_kind', '!=', PlanKind::RECURRING->value),
            default => $plans->whereRaw('1 = 0'),
        };

        $plans->where(function ($q) use ($consent, $hasRef, $ref): void {
            if ($consent->customer_id !== null) {
                $q->orWhere('customer_id', $consent->customer_id);
            }
            if ($hasRef) {
                $q->orWhere('shopify_customer_id', $ref);
            }
        })->update(['consent_blocked_at' => null]);
    }
}
