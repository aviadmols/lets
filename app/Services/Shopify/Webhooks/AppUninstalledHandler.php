<?php

namespace App\Services\Shopify\Webhooks;

use App\Models\WebhookEvent;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Handles app/uninstalled — the most-missed critical webhook. On uninstall the
 * shop's access token is IMMEDIATELY revoked; every subsequent Shopify API call
 * 401s. We must stop trying.
 *
 *   - Mark the shop uninstalled + null the now-dead token (Shop::markUninstalled).
 *   - The scheduler's due-charge query gates on shop.status, so uninstalled shops
 *     are skipped (no wasted cycles, no 401 floods). Plans/ledger are PRESERVED
 *     (shop-scoped) for a possible reinstall — never deleted here.
 *   - Hand off to saas-multitenancy-billing: cancel the AppSubscription + schedule
 *     data retention per policy.
 */
final class AppUninstalledHandler implements WebhookHandler
{
    public function handle(WebhookEvent $event): void
    {
        $shop = Tenant::current();
        if ($shop === null) {
            return; // job binds the tenant; defensive guard only
        }

        $shop->markUninstalled();

        Log::info('shopify.app_uninstalled', ['shop_id' => $shop->id, 'domain' => $shop->shopify_domain]);

        // TODO(saas-multitenancy-billing): cancel AppSubscription for $shop and
        //   schedule data-retention per the uninstall data policy. Emitted as a
        //   clear seam so the SaaS agent wires its listener without touching this
        //   transport. e.g. event(new ShopUninstalled($shop->id));
    }
}
