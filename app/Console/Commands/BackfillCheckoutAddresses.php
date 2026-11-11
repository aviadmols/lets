<?php

namespace App\Console\Commands;

use App\Domain\Campaigns\GiftShippingAddress;
use App\Domain\Customers\CheckoutAddressCapture;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Services\WooCommerce\WooClientFactory;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Give WooCommerce checkout subscribers the address they typed at checkout.
 *
 * Until CheckoutAddressCapture, a plan bought through the store's checkout kept no
 * address of its own. For a GUEST subscriber that meant none anywhere LETS could
 * reach — a blank subscription screen and customer page, an empty
 * {customer_address}, and renewal orders with nowhere to ship. This reads each such
 * plan's origin order (in bulk, a hundred to a request) and keeps its address on
 * the plan, exactly as a new checkout now does.
 *
 * A plan born from an account-area SWITCH has no order of its own. Until the switch
 * carried the address over (InstallmentPlan::addressMetaForSuccessor), such a plan
 * lost it — so this also copies it from the plan the switch replaced, walking back
 * through earlier switches.
 *
 * FILL-ONLY and safe to re-run: a plan that already holds an address (an import's,
 * an admin's correction, or a previous run's) is skipped. Plans with neither an
 * order nor a predecessor (imported members, payment-page deposits) are not touched.
 */
final class BackfillCheckoutAddresses extends Command
{
    // === CONSTANTS ===
    protected $signature = 'lets:subscriptions:backfill-checkout-addresses
        {--shop= : limit to one shop id}
        {--dry-run : report what would be filled, write nothing}';

    protected $description = 'Keep the checkout address on WooCommerce subscriptions that have none, read from their origin order';

    /** Plans read per chunk; orders are then fetched a hundred to a request. */
    private const CHUNK = 200;

    /** How far back through switch-after-switch a plan's address is looked for. */
    private const MAX_SWITCH_HOPS = 10;

    public function handle(CheckoutAddressCapture $capture): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $shops = Shop::query()
            ->where('platform', Shop::PLATFORM_WOOCOMMERCE)
            ->when($this->option('shop'), fn ($q) => $q->whereKey((int) $this->option('shop')))
            ->get();

        $rows = [];
        foreach ($shops as $shop) {
            if (! $shop->hasWooConnection()) {
                continue;
            }

            $rows[] = [
                $shop->getKey(),
                $shop->name,
                ...$this->backfillShop($shop, $capture, $dryRun),
                $this->inheritFromSwitches($shop, $dryRun),
            ];
        }

        $this->table(['shop', 'name', 'missing', 'filled', 'order has no address', 'order not found', 'courier note added', 'filled from switch'], $rows);
        $this->info($dryRun ? 'Dry run — nothing was written.' : 'Done.');

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int, 2: int, 3: int, 4: int} missing, filled, no address on the order, order not found, courier note added */
    private function backfillShop(Shop $shop, CheckoutAddressCapture $capture, bool $dryRun): array
    {
        return Tenant::run($shop, function () use ($shop, $capture, $dryRun): array {
            $missing = $filled = $noAddress = $notFound = $noted = 0;
            $client = WooClientFactory::for($shop);

            InstallmentPlan::query()
                ->where(fn ($q) => $q->whereNotNull('external_order_id')->orWhereNotNull('shopify_order_id'))
                ->chunkById(self::CHUNK, function (Collection $plans) use ($shop, $client, $capture, $dryRun, &$missing, &$filled, &$noAddress, &$notFound, &$noted): void {
                    // Only a plan with a real (numeric) Woo order id — a payment-page
                    // deposit's id is a PayPlus uid, not an order — that lacks an
                    // address, or holds one without the courier note.
                    $todo = $plans->filter(fn (InstallmentPlan $p): bool => (int) $p->externalOrderId() > 0
                        && ($p->contactAddress() === [] || $p->deliveryNote() === null));

                    if ($todo->isEmpty()) {
                        return;
                    }

                    $missing += $todo->filter(fn (InstallmentPlan $p): bool => $p->contactAddress() === [])->count();

                    try {
                        $orders = $client->fetchOrdersByIds($todo->map(fn (InstallmentPlan $p): int => (int) $p->externalOrderId())->all());
                    } catch (\Throwable $e) {
                        Log::warning('customers.checkout_address.backfill_read_failed', [
                            'shop_id' => $shop->getKey(),
                            'error' => $e->getMessage(),
                        ]);
                        $this->warn("Shop {$shop->getKey()}: could not read orders — {$e->getMessage()}");
                        $notFound += $todo->count();

                        return;
                    }

                    foreach ($todo as $plan) {
                        $order = $orders[(int) $plan->externalOrderId()] ?? null;

                        // Has an address already: only the courier note can be added.
                        if ($plan->contactAddress() !== []) {
                            $hasNote = $order !== null && isset((GiftShippingAddress::fromWooPayload($order)?->toPlanContact() ?? [])[InstallmentPlan::ADDRESS_DELIVERY_NOTE]);
                            if ($hasNote && ($dryRun || $capture->addDeliveryNote($plan, $order))) {
                                $noted++;
                            }

                            continue;
                        }

                        if ($order === null) {
                            $notFound++;

                            continue;
                        }

                        if (GiftShippingAddress::fromWooPayload($order) === null) {
                            $noAddress++;

                            continue;
                        }

                        if ($dryRun || $capture->fromWooOrder($plan, $order)) {
                            $filled++;
                        }
                    }
                });

            if ($filled > 0 && ! $dryRun) {
                Log::info('customers.checkout_address.backfilled', [
                    'shop_id' => $shop->getKey(),
                    'plans' => $filled,
                ]);
            }

            return [$missing, $filled, $noAddress, $notFound, $noted];
        });
    }

    /**
     * Plans an account-area switch created with no address: copy it from the plan
     * the switch replaced (or the one before that). Returns how many were filled
     * (or would be, on a dry run).
     */
    private function inheritFromSwitches(Shop $shop, bool $dryRun): int
    {
        return Tenant::run($shop, function () use ($shop, $dryRun): int {
            $filled = 0;

            InstallmentPlan::query()
                ->whereNull('external_order_id')
                ->whereNull('shopify_order_id')
                ->chunkById(self::CHUNK, function (Collection $plans) use ($dryRun, &$filled): void {
                    foreach ($plans as $plan) {
                        if ($plan->contactAddress() !== []) {
                            continue;
                        }

                        $inherited = $this->predecessorAddress($plan);
                        if ($inherited === []) {
                            continue;
                        }

                        if ($dryRun || $this->fillFromPredecessor($plan, $inherited)) {
                            $filled++;
                        }
                    }
                });

            if ($filled > 0 && ! $dryRun) {
                Log::info('customers.checkout_address.inherited_from_switch', [
                    'shop_id' => $shop->getKey(),
                    'plans' => $filled,
                ]);
            }

            return $filled;
        });
    }

    /**
     * The address meta of the nearest plan up the switch chain that has one.
     *
     * @return array<string, array<string, string>>
     */
    private function predecessorAddress(InstallmentPlan $plan): array
    {
        $current = $plan;
        for ($hop = 0; $hop < self::MAX_SWITCH_HOPS; $hop++) {
            $sourceId = trim((string) data_get($current->meta, InstallmentPlan::META_ACCOUNT_OFFER.'.source_plan_public_id', ''));
            if ($sourceId === '') {
                return [];
            }

            $source = InstallmentPlan::query()->where('public_id', $sourceId)->first();
            if ($source === null) {
                return [];
            }

            $meta = $source->addressMetaForSuccessor();
            if ($meta !== []) {
                return $meta;
            }

            $current = $source;
        }

        return [];
    }

    /**
     * Write it under a row lock, re-checking that the plan is still empty — the
     * same fill-only rule as CheckoutAddressCapture.
     *
     * @param  array<string, array<string, string>>  $inherited
     */
    private function fillFromPredecessor(InstallmentPlan $plan, array $inherited): bool
    {
        return DB::transaction(function () use ($plan, $inherited): bool {
            /** @var InstallmentPlan|null $fresh */
            $fresh = InstallmentPlan::query()->lockForUpdate()->find($plan->getKey());
            if ($fresh === null || $fresh->contactAddress() !== []) {
                return false;
            }

            $fresh->forceFill(['meta' => array_merge((array) ($fresh->meta ?? []), $inherited)])->save();

            return true;
        });
    }
}
