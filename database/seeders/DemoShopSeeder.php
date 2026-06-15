<?php

namespace Database\Seeders;

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
        User::firstOrCreate(
            ['email' => self::ADMIN_EMAIL],
            ['name' => 'Demo Admin', 'password' => Hash::make(self::ADMIN_PASSWORD)],
        );

        $shop = Shop::firstOrCreate(
            ['shopify_domain' => self::SHOP_DOMAIN],
            ['name' => 'Demo Store', 'status' => Shop::STATUS_ACTIVE, 'plan' => 'growth'],
        );

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
            if (InstallmentPlan::query()->exists()) {
                return; // already seeded
            }

            $this->seedInstallmentsPlan($shop, self::CUSTOMER_A);
            $this->seedRecurringPlan($shop, self::CUSTOMER_B);
            $this->seedFailedInstallments($shop, self::CUSTOMER_A);
        });
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
