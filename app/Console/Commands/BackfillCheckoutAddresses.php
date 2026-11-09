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
 * FILL-ONLY and safe to re-run: a plan that already holds an address (an import's,
 * an admin's correction, or a previous run's) is skipped. Plans with no WooCommerce
 * order behind them (imported members, payment-page deposits) are not touched.
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

            $rows[] = [$shop->getKey(), $shop->name, ...$this->backfillShop($shop, $capture, $dryRun)];
        }

        $this->table(['shop', 'name', 'missing', 'filled', 'order has no address', 'order not found'], $rows);
        $this->info($dryRun ? 'Dry run — nothing was written.' : 'Done.');

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} missing, filled, no address on the order, order not found */
    private function backfillShop(Shop $shop, CheckoutAddressCapture $capture, bool $dryRun): array
    {
        return Tenant::run($shop, function () use ($shop, $capture, $dryRun): array {
            $missing = $filled = $noAddress = $notFound = 0;
            $client = WooClientFactory::for($shop);

            InstallmentPlan::query()
                ->where(fn ($q) => $q->whereNotNull('external_order_id')->orWhereNotNull('shopify_order_id'))
                ->chunkById(self::CHUNK, function (Collection $plans) use ($shop, $client, $capture, $dryRun, &$missing, &$filled, &$noAddress, &$notFound): void {
                    // Only a plan with no address AND a real (numeric) Woo order id —
                    // a payment-page deposit's id is a PayPlus uid, not an order.
                    $todo = $plans->filter(fn (InstallmentPlan $p): bool => $p->contactAddress() === []
                        && (int) $p->externalOrderId() > 0);

                    if ($todo->isEmpty()) {
                        return;
                    }

                    $missing += $todo->count();

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

            return [$missing, $filled, $noAddress, $notFound];
        });
    }
}
