<?php

namespace Tests\Feature\WooCommerce;

use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\ChargeContext;
use App\Services\WooCommerce\Orders\WooCommerceOrderStrategy;
use App\Services\WooCommerce\WooCustomerResolver;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Account\Offers\MakesAccountOffers;
use Tests\TestCase;

/**
 * An order LETS creates must belong to the shopper it is for.
 *
 * Every order the engine wrote used to be a GUEST order — the payload never
 * named a customer — so a subscriber's own renewals were missing from My
 * Account and from their lifetime value on a store that knew exactly who they
 * were. The plan's reference answers when it is a WordPress user id; when it is
 * a guest checkout's `0` or an imported member's UUID, the STORE is asked by
 * email, because those columns may never be re-pointed (the consent gate
 * matches on them).
 */
final class OrderCustomerLinkTest extends TestCase
{
    use MakesAccountOffers;
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    public array $orders = [];

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_numeric_plan_reference_owns_the_cycle_order(): void
    {
        $shop = $this->connectedShop('link-ref.example.com');

        $order = $this->materialize($shop, ['external_customer_id' => '4', 'shopify_customer_id' => '4']);

        $this->assertSame(4, (int) $order['customer_id']);
        // The store was never asked: the plan already knew.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/customers?'));
    }

    public function test_a_guest_checkout_is_matched_to_the_account_they_opened_later(): void
    {
        $shop = $this->connectedShop('link-guest.example.com', customerLookupId: 12);

        // The imported/guest shape: reference `0`, identity carried by email.
        $order = $this->materialize($shop, ['external_customer_id' => '0', 'shopify_customer_id' => '0']);

        $this->assertSame(12, (int) $order['customer_id']);
    }

    public function test_an_imported_uuid_reference_is_matched_by_email_and_remembered(): void
    {
        $shop = $this->connectedShop('link-uuid.example.com', customerLookupId: 77);

        $plan = Tenant::run($shop, fn () => $this->makeSourcePlan([
            'external_customer_id' => 'b3f1c0de-0000-4000-8000-000000000001',
            'shopify_customer_id' => 'b3f1c0de-0000-4000-8000-000000000001',
        ]));

        Tenant::run($shop, fn () => (new WooCommerceOrderStrategy)
            ->materialize($plan, ChargeContext::RECURRING, false));

        $this->assertSame(77, (int) $this->lastOrder()['customer_id']);

        // Remembered, so the lookup is once per subscriber and not once per cycle.
        $this->assertSame(77, (int) ($plan->fresh()->meta[WooCustomerResolver::META_WC_CUSTOMER_ID] ?? 0));

        $lookups = 0;
        Http::assertSent(function ($request) use (&$lookups): bool {
            if (str_contains($request->url(), '/customers?')) {
                $lookups++;
            }

            return true;
        });

        Tenant::run($shop, fn () => (new WooCommerceOrderStrategy)
            ->materialize($plan->fresh(), ChargeContext::RECURRING, false));

        $after = 0;
        Http::assertSent(function ($request) use (&$after): bool {
            if (str_contains($request->url(), '/customers?')) {
                $after++;
            }

            return true;
        });

        $this->assertSame($lookups, $after, 'the store was asked again for an answer already known');
    }

    public function test_a_shopper_the_store_does_not_know_stays_a_guest(): void
    {
        $shop = $this->connectedShop('link-none.example.com', customerLookupId: null);

        $order = $this->materialize($shop, ['external_customer_id' => '0', 'shopify_customer_id' => '0']);

        $this->assertSame(WooCustomerResolver::GUEST, (int) $order['customer_id']);

        // A zero is NEVER cached: today's guest is next month's account holder.
        $plan = Tenant::run($shop, fn () => InstallmentPlan::query()->latest('id')->first());
        $this->assertArrayNotHasKey(WooCustomerResolver::META_WC_CUSTOMER_ID, (array) ($plan->meta ?? []));
    }

    // === Helpers ===

    /** @param array<string, mixed> $planAttributes @return array<string, mixed> */
    private function materialize(Shop $shop, array $planAttributes): array
    {
        $plan = Tenant::run($shop, fn () => $this->makeSourcePlan($planAttributes));

        Tenant::run($shop, fn () => (new WooCommerceOrderStrategy)
            ->materialize($plan, ChargeContext::RECURRING, false));

        return $this->lastOrder();
    }

    /** @return array<string, mixed> */
    private function lastOrder(): array
    {
        $this->assertNotEmpty($this->orders, 'no WC order was created');

        return (array) end($this->orders);
    }

    private function connectedShop(string $domain, ?int $customerLookupId = null): Shop
    {
        $shop = $this->makeShop($domain);
        $shop->forceFill([
            'woocommerce_domain' => 'wc.example.com',
            'woocommerce_credentials' => [
                'base_url' => 'https://wc.example.com',
                'consumer_key' => 'ck_x',
                'consumer_secret' => 'cs_x',
            ],
        ])->save();

        $test = $this;
        Http::fake([
            '*/wp-json/wc/v3/customers*' => Http::response(
                $customerLookupId === null ? [] : [['id' => $customerLookupId]],
                200
            ),
            '*/wp-json/wc/v3/orders' => function ($request) use ($test) {
                $test->orders[] = (array) $request->data();

                return Http::response(['id' => 5000 + count($test->orders)], 201);
            },
            '*' => Http::response(['id' => 1], 200),
        ]);

        return $shop->refresh();
    }
}
