<?php

namespace Database\Seeders;

use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowBranch;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellFlowTrigger;
use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Models\ActivityEvent;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * DEV-ONLY demo data so the re-skinned admin renders real rows locally without a
 * Shopify session. Creates an admin login, a demo Shop (bound as the tenant),
 * and sample installments + recurring plans with payments, ledger rows, and
 * timeline events — enough to exercise every screen + both plan_kinds.
 *
 * Idempotent (firstOrCreate). Run with: php artisan db:seed --class=DemoShopSeeder
 * Then set APP_DEV_TENANT=true so BindDevTenant binds this shop in the panel.
 *
 * NEVER run in production — it writes fixture data + a known password.
 */
class DemoShopSeeder extends Seeder
{
    // === CONSTANTS ===
    public const ADMIN_EMAIL = 'admin@payplus.test';
    public const ADMIN_PASSWORD = 'password';
    public const SHOP_DOMAIN = 'demo-shop.myshopify.com';
    public const CUSTOMER_A = 'cust_1001';
    public const CUSTOMER_B = 'cust_2002';

    public function run(): void
    {
        $shop = Shop::firstOrCreate(
            ['shopify_domain' => self::SHOP_DOMAIN],
            ['name' => 'Demo Store', 'status' => Shop::STATUS_ACTIVE, 'plan' => 'growth'],
        );

        // The demo admin is a MERCHANT user bound to the demo shop (shop_id) — the
        // production-correct link BindTenantFromUser reads. Without it a shopless
        // user is (correctly) denied 403.
        $admin = User::firstOrCreate(
            ['email' => self::ADMIN_EMAIL],
            ['name' => 'Demo Admin', 'password' => Hash::make(self::ADMIN_PASSWORD)],
        );
        if ($admin->shop_id !== $shop->id) {
            $admin->forceFill(['shop_id' => $shop->id])->save();
        }

        // Give the demo shop PayPlus creds so the connection badge reads "connected"
        // and the dashboard leaves first-run. (Fake values — never charges.)
        if (! $shop->hasPayplusConnection()) {
            $shop->payplus_credentials = [
                'api_key' => 'demo_api_key',
                'secret_key' => 'demo_secret_key',
                'terminal_uid' => 'demo_terminal',
                'cashier_uid' => 'demo_cashier',
                'payment_page_uid' => 'demo_page',
                'webhook_secret' => 'demo_webhook',
                'base_url' => config('payplus.base_url'),
            ];
            $shop->save();
        }

        // Bind the tenant so BelongsToShop auto-stamps shop_id on every row.
        Tenant::run($shop, function () use ($shop): void {
            if (! InstallmentPlan::query()->exists()) {
                $this->seedInstallmentsPlan($shop, self::CUSTOMER_A);
                $this->seedRecurringPlan($shop, self::CUSTOMER_B);
                $this->seedFailedInstallments($shop, self::CUSTOMER_A);
            }

            if (! UpsellFlow::query()->exists()) {
                $this->seedUpsellFlows($shop);
            }
        });
    }

    /**
     * Demo post-purchase upsell flows — populates the Overview KPIs (last 30
     * days), the "Your flows" table (active + inactive), the Flow Builder graph
     * (trigger → offer → accept/decline → next offer), and the Activity feed.
     */
    private function seedUpsellFlows(Shop $shop): void
    {
        // --- Flow 1 (active, priority 1): a 2-step chain (add-on → bundle). ---
        $flow = UpsellFlow::query()->create([
            'name' => 'Summer add-on boost',
            'priority' => 1,
        ]);
        $flow->forceFill(['status' => 'active'])->save();

        UpsellFlowTrigger::query()->create([
            'flow_id' => $flow->id,
            'match_type' => UpsellFlowTrigger::MATCH_ANY_PRODUCT,
        ]);
        UpsellFlowTrigger::query()->create([
            'flow_id' => $flow->id,
            'match_type' => UpsellFlowTrigger::MATCH_MIN_ORDER_VALUE,
            'min_order_value' => 150,
        ]);

        $offer1 = UpsellFlowOffer::query()->create([
            'flow_id' => $flow->id,
            'offer_product_gid' => 'gid://shopify/Product/1001',
            'offer_variant_gid' => 'gid://shopify/ProductVariant/2001',
            'offer_title' => 'Travel pouch',
            'base_price' => 79,
            'discount_type' => UpsellFlowOffer::DISCOUNT_PERCENT,
            'discount_value' => 20,
            'headline' => 'Complete your kit with a travel pouch',
            'accept_cta' => 'Add to my order',
            'decline_cta' => 'No thanks',
            'position' => 0,
        ]);
        $offer2 = UpsellFlowOffer::query()->create([
            'flow_id' => $flow->id,
            'offer_product_gid' => 'gid://shopify/Product/1002',
            'offer_variant_gid' => 'gid://shopify/ProductVariant/2002',
            'offer_title' => 'Care bundle',
            'base_price' => 49,
            'discount_type' => UpsellFlowOffer::DISCOUNT_FIXED,
            'discount_value' => 10,
            'headline' => 'Keep it fresh — add the care bundle',
            'accept_cta' => 'Yes, add it',
            'decline_cta' => 'Maybe later',
            'position' => 1,
        ]);
        // Accept on offer 1 → offer 2; decline → end.
        UpsellFlowBranch::query()->create([
            'flow_id' => $flow->id,
            'from_offer_id' => $offer1->id,
            'on_accept_next_offer_id' => $offer2->id,
            'on_decline_next_offer_id' => null,
        ]);
        UpsellFlowBranch::query()->create([
            'flow_id' => $flow->id,
            'from_offer_id' => $offer2->id,
            'on_accept_next_offer_id' => null,
            'on_decline_next_offer_id' => null,
        ]);

        // Funnel events across the last 30 days (drives KPIs + the chart + feed).
        $this->seedFunnel($shop, $flow->id, $offer1->id, impressions: 220, accepted: 70, charged: 64, revenue: 63.2);
        $this->seedFunnel($shop, $flow->id, $offer2->id, impressions: 64, accepted: 22, charged: 20, revenue: 39.0);

        // --- Flow 2 (active, priority 2): single-offer collection trigger. ---
        $flow2 = UpsellFlow::query()->create([
            'name' => 'Coffee lovers cross-sell',
            'priority' => 2,
        ]);
        $flow2->forceFill(['status' => 'active'])->save();

        UpsellFlowTrigger::query()->create([
            'flow_id' => $flow2->id,
            'match_type' => UpsellFlowTrigger::MATCH_COLLECTION,
            'shopify_collection_gid' => 'gid://shopify/Collection/3001',
        ]);
        $offer3 = UpsellFlowOffer::query()->create([
            'flow_id' => $flow2->id,
            'offer_product_gid' => 'gid://shopify/Product/1003',
            'offer_variant_gid' => 'gid://shopify/ProductVariant/2003',
            'offer_title' => 'Reusable cup',
            'base_price' => 39,
            'discount_type' => UpsellFlowOffer::DISCOUNT_NONE,
            'discount_value' => 0,
            'headline' => 'Grab a matching reusable cup',
            'accept_cta' => 'Add to my order',
            'decline_cta' => 'No thanks',
            'position' => 0,
        ]);
        UpsellFlowBranch::query()->create([
            'flow_id' => $flow2->id,
            'from_offer_id' => $offer3->id,
            'on_accept_next_offer_id' => null,
            'on_decline_next_offer_id' => null,
        ]);
        $this->seedFunnel($shop, $flow2->id, $offer3->id, impressions: 140, accepted: 31, charged: 28, revenue: 39.0);

        // --- Flow 3 (draft/inactive): shows in the Inactive sub-tab, no events. ---
        $draft = UpsellFlow::query()->create([
            'name' => 'Holiday gift wrap (draft)',
            'priority' => 3,
        ]);
        // stays in default 'draft' status.
        UpsellFlowOffer::query()->create([
            'flow_id' => $draft->id,
            'offer_product_gid' => 'gid://shopify/Product/1004',
            'offer_variant_gid' => 'gid://shopify/ProductVariant/2004',
            'offer_title' => 'Gift wrap',
            'base_price' => 15,
            'discount_type' => UpsellFlowOffer::DISCOUNT_NONE,
            'discount_value' => 0,
            // headline intentionally blank → demonstrates the invalid-node ring.
            'accept_cta' => 'Add gift wrap',
            'position' => 0,
        ]);
    }

    /**
     * Append a realistic funnel for one offer: N impressions, then a subset
     * accepted, then charges (success + a couple of failures), spread across the
     * last 30 days so revenueByDay() yields a chart.
     */
    private function seedFunnel(Shop $shop, int $flowId, int $offerId, int $impressions, int $accepted, int $charged, float $revenue): void
    {
        $base = ['shop_id' => $shop->id, 'flow_id' => $flowId, 'offer_id' => $offerId, 'currency' => 'ILS'];

        for ($i = 0; $i < $impressions; $i++) {
            $when = now()->subDays(random_int(0, 29))->subMinutes(random_int(0, 1440));
            $this->offerEvent($base, 'impression', $when);
        }
        for ($i = 0; $i < $accepted; $i++) {
            $when = now()->subDays(random_int(0, 29));
            $this->offerEvent($base, 'accepted', $when);
        }
        for ($i = 0; $i < $charged; $i++) {
            $when = now()->subDays(random_int(0, 29));
            $this->offerEvent(array_merge($base, [
                'revenue_amount' => $revenue,
                'parent_order_id' => 'ord_' . random_int(40000, 49999),
                'customer_ref' => 'cust_' . random_int(1000, 9999),
            ]), 'charge_succeeded', $when);
        }
        // A few charge failures (drives the red Activity badge + charge-success rate).
        $failures = max(1, $accepted - $charged);
        for ($i = 0; $i < $failures; $i++) {
            $when = now()->subDays(random_int(0, 29));
            $this->offerEvent(array_merge($base, [
                'parent_order_id' => 'ord_' . random_int(40000, 49999),
                'customer_ref' => 'cust_' . random_int(1000, 9999),
            ]), 'charge_failed', $when);
        }
    }

    /** @param array<string, mixed> $base */
    private function offerEvent(array $base, string $type, \Illuminate\Support\Carbon $when): void
    {
        UpsellOfferEvent::query()->create(array_merge($base, [
            'event_type' => $type,
            'occurred_at' => $when,
            'created_at' => $when,
        ]));
    }

    private function seedInstallmentsPlan(Shop $shop, string $customer): void
    {
        $plan = new InstallmentPlan();
        $plan->forceFill([
            'shop_id' => $shop->id,
            'shopify_customer_id' => $customer,
            'plan_kind' => 'installments',
            'status' => 'active',
            'total_amount' => 1200,
            'total_charged' => 600,
            'currency' => 'ILS',
            'next_charge_at' => now()->addDays(12),
        ])->save();

        foreach ([['deposit', 1, 'succeeded'], ['installment', 2, 'succeeded'], ['installment', 3, 'pending'], ['installment', 4, 'pending']] as [$type, $seq, $status]) {
            $payment = new InstallmentPayment();
            $payment->forceFill([
                'shop_id' => $shop->id,
                'plan_id' => $plan->id,
                'payment_type' => $type,
                'sequence' => $seq,
                'amount' => 300,
                'currency' => 'ILS',
                'status' => $status,
                'charged_at' => $status === 'succeeded' ? now()->subDays(30 - $seq * 5) : null,
            ])->save();

            if ($status === 'succeeded') {
                $this->ledger($shop, $plan, $customer, $type, 300, 'succeeded', "installment:{$shop->id}:{$plan->id}:{$seq}");
                $this->event($shop, $plan, 'charge_succeeded', ['amount' => 300, 'sequence' => $seq]);
            }
        }

        $this->event($shop, $plan, 'plan_created', []);
    }

    private function seedRecurringPlan(Shop $shop, string $customer): void
    {
        $plan = new InstallmentPlan();
        $plan->forceFill([
            'shop_id' => $shop->id,
            'shopify_customer_id' => $customer,
            'plan_kind' => 'recurring',
            'status' => 'active',
            'installment_amount' => 89,
            'interval_count' => 30,
            'currency' => 'ILS',
            'next_charge_at' => now()->addDays(20),
        ])->save();

        $this->ledger($shop, $plan, $customer, 'recurring', 89, 'succeeded', "recurring:{$shop->id}:{$plan->id}:" . now()->subMonth()->toDateString());
        $this->ledger($shop, $plan, $customer, 'upsell', 49, 'succeeded', "upsell:{$shop->id}:1:1:ord1:{$customer}");
        $this->event($shop, $plan, 'plan_created', []);
        $this->event($shop, $plan, 'charge_succeeded', ['amount' => 89, 'context' => 'recurring']);
    }

    private function seedFailedInstallments(Shop $shop, string $customer): void
    {
        $plan = new InstallmentPlan();
        $plan->forceFill([
            'shop_id' => $shop->id,
            'shopify_customer_id' => $customer,
            'plan_kind' => 'installments',
            'status' => 'failed',
            'total_amount' => 600,
            'total_charged' => 0,
            'currency' => 'ILS',
        ])->save();

        $this->ledger($shop, $plan, $customer, 'installment', 300, 'failed', "installment:{$shop->id}:{$plan->id}:1");
        $this->event($shop, $plan, 'charge_failed', ['amount' => 300, 'reason' => 'declined']);
    }

    private function ledger(Shop $shop, InstallmentPlan $plan, string $customer, string $context, float $amount, string $status, string $key): void
    {
        $row = new PaymentLedger();
        $row->forceFill([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'shopify_customer_id' => $customer,
            'charge_context' => $context,
            'idempotency_key' => $key,
            'payplus_transaction_uid' => $status === 'succeeded' ? 'tx_' . substr(md5($key), 0, 12) : null,
            'amount' => $amount,
            'currency' => 'ILS',
            'status' => $status,
        ])->save();
    }

    private function event(Shop $shop, InstallmentPlan $plan, string $kind, array $details): void
    {
        ActivityEvent::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'actor' => ActivityEvent::ACTOR_SYSTEM,
            'kind' => $kind,
            'details' => $details,
            'created_at' => now()->subHours(random_int(1, 240)),
        ]);
    }
}
