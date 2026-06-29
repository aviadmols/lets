<?php

namespace Tests\Feature\Platform;

use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Background-jobs (Horizon) access + queue coverage.
 *
 * The app splits jobs across named queues (TenantContext::QUEUE_*). Horizon's
 * supervisor MUST process every one of them — a supervisor bound only to ['default']
 * silently strands ChargeJob (no recurring/installment charges), the product-sync job
 * (WooCommerce "sync does nothing"), webhooks, and upsell jobs. And the /horizon
 * dashboard is gated to platform admins so the owner can watch every run.
 */
final class HorizonAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_horizon_supervisor_processes_every_app_queue(): void
    {
        $processed = config('horizon.defaults.supervisor-1.queue');

        $appQueues = [
            TenantContext::QUEUE_CHARGES,
            TenantContext::QUEUE_WEBHOOKS,
            TenantContext::QUEUE_INVOICES,
            TenantContext::QUEUE_UPSELL,
            TenantContext::QUEUE_SYNC,
            'default',
        ];

        foreach ($appQueues as $queue) {
            $this->assertContains($queue, $processed, "Horizon must process the '{$queue}' queue — a job dispatched there would never run.");
        }

        // Money first: charges ahead of the rest.
        $this->assertSame(TenantContext::QUEUE_CHARGES, $processed[0]);
    }

    public function test_horizon_dashboard_is_gated_to_platform_admins(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $this->assertTrue(Gate::forUser($admin)->allows('viewHorizon'));

        $shop = Shop::create([
            'shopify_domain' => 'm.myshopify.com', 'name' => 'M', 'status' => Shop::STATUS_ACTIVE,
        ]);
        $merchant = User::factory()->forShop($shop)->create();
        $this->assertFalse(Gate::forUser($merchant)->allows('viewHorizon'));

        // Anonymous (no user) is denied.
        $this->assertFalse(Gate::allows('viewHorizon'));
    }
}
