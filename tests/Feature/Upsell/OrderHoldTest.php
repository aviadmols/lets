<?php

namespace Tests\Feature\Upsell;

use App\Domain\Upsell\Holds\OrderHoldService;
use App\Domain\Upsell\Models\UpsellOrderHold;
use App\Domain\Upsell\Models\UpsellSetting;
use App\Mail\OrderUpdatedMail;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The add-on window: hold a paid order briefly so the shopper can still add to
 * it, then let it go.
 *
 * The exclusions are the load-bearing part, and one of them is a scar: releasing
 * an INSTALLMENTS order on an upsell timer ships goods nobody has finished
 * paying for. Each never-hold kind gets its own test, by name.
 */
final class OrderHoldTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private OrderHoldService $holds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'holds.myshopify.com',
            'name' => 'Holds',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);
        // Without a token the client factory throws and every hold decision
        // becomes platform_refused — which would hide the exclusions under a
        // fail-closed that happens to look the same.
        $this->shop->forceFill(['shopify_access_token' => 'shpat_test'])->save();
        $this->shop->refresh();
        Tenant::set($this->shop);

        $this->holds = app(OrderHoldService::class);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === The switch ===

    public function test_a_shop_that_never_opted_in_holds_nothing(): void
    {
        // Holding a paid order costs the merchant a later dispatch, so it is
        // never a side effect of installing the app.
        $settings = UpsellSetting::current();

        $this->assertSame(0, $settings->holdWindowMinutes());
        $this->assertFalse($settings->holdEnabled());
        $this->assertNull($this->holds->hold($this->shop, '900', $settings));
        $this->assertSame(0, UpsellOrderHold::query()->count());
    }

    public function test_a_typo_cannot_park_an_order_for_a_week(): void
    {
        $settings = UpsellSetting::current();
        $settings->forceFill(['hold_window_minutes' => 99999])->save();

        $this->assertSame(UpsellSetting::MAX_HOLD_MINUTES, $settings->refresh()->holdWindowMinutes());
    }

    // === Exclusions ===

    public function test_an_installments_order_under_fulfillment_lock_is_never_held(): void
    {
        $this->fakeShopifyOrder(metafields: [['key' => 'fulfillment_lock', 'value' => 'true']]);

        $hold = $this->holds->hold($this->shop, '900', $this->enabled());

        // THE SCAR. That hold means "not paid for yet" and is released only at
        // full payment; letting an upsell timer release it ships unpaid goods.
        $this->assertSame(UpsellOrderHold::STATUS_SKIPPED, $hold->status);
        $this->assertSame(UpsellOrderHold::SKIP_INSTALLMENTS_HOLD, $hold->skip_reason);
    }

    public function test_the_lock_is_read_from_the_metafield_not_the_tag(): void
    {
        // A merchant can edit tags by hand; the metafield is the engine's own
        // record, so a stripped tag must not make an unpaid order releasable.
        $this->fakeShopifyOrder(tags: 'something-else', metafields: [['key' => 'fulfillment_lock', 'value' => 'true']]);

        $hold = $this->holds->hold($this->shop, '900', $this->enabled());

        $this->assertSame(UpsellOrderHold::SKIP_INSTALLMENTS_HOLD, $hold->skip_reason);
    }

    public function test_a_gift_order_is_never_held(): void
    {
        // Given, not sold. There is no upsell to wait for.
        $this->assertExcluded('lets-gift', UpsellOrderHold::SKIP_GIFT);
    }

    public function test_a_recurring_cycle_order_is_never_held(): void
    {
        // We generated it on a schedule; nobody is sitting on a thank-you page.
        $this->assertExcluded('subscription-recurring', UpsellOrderHold::SKIP_RECURRING);
    }

    public function test_an_upsell_child_order_is_never_held(): void
    {
        // Holding the product of an upsell to wait for an upsell is a loop.
        $this->assertExcluded('upsell-child', UpsellOrderHold::SKIP_UPSELL_CHILD);
    }

    /**
     * One tag per test, not a loop: Http::fake() merges stubs and the first
     * match wins, so re-faking inside a loop silently replays the first order.
     */
    private function assertExcluded(string $tag, string $expectedReason): void
    {
        $this->fakeShopifyOrder(tags: $tag);

        $hold = $this->holds->hold($this->shop, '900', $this->enabled());

        $this->assertSame(UpsellOrderHold::STATUS_SKIPPED, $hold->status);
        $this->assertSame($expectedReason, $hold->skip_reason, "[{$tag}] was not excluded.");
    }

    public function test_an_unreadable_order_fails_closed(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $hold = $this->holds->hold($this->shop, '900', $this->enabled());

        // A missed upsell window costs a possible sale; holding an installments
        // order by mistake ships unpaid goods. Fail closed.
        $this->assertSame(UpsellOrderHold::STATUS_SKIPPED, $hold->status);
        $this->assertSame(UpsellOrderHold::SKIP_PLATFORM_REFUSED, $hold->skip_reason);
    }

    // === Idempotency ===

    public function test_a_second_impression_does_not_extend_the_window(): void
    {
        $this->fakeShopifyOrder();

        $first = $this->holds->hold($this->shop, '900', $this->enabled());
        $again = $this->holds->hold($this->shop, '900', $this->enabled());

        // A shopper reloading the thank-you page must not be able to keep their
        // own order in the warehouse.
        $this->assertSame($first->getKey(), $again->getKey());
        $this->assertSame(1, UpsellOrderHold::query()->count());
        $this->assertSame($first->release_at->toIso8601String(), $again->release_at->toIso8601String());
    }

    public function test_releasing_twice_is_a_no_op(): void
    {
        $hold = $this->heldRow();

        $this->assertTrue($this->holds->release($this->shop, $hold));
        $this->assertTrue($this->holds->release($this->shop, $hold->refresh()));

        $this->assertSame(UpsellOrderHold::STATUS_RELEASED, $hold->refresh()->status);
    }

    // === The scanner ===

    public function test_only_orders_whose_window_has_closed_are_released(): void
    {
        $due = $this->heldRow(orderId: '900', releaseAt: now()->subMinute());
        $waiting = $this->heldRow(orderId: '901', releaseAt: now()->addMinutes(15));

        $this->artisan('upsell:release-holds')->assertSuccessful();

        $this->assertSame(UpsellOrderHold::STATUS_RELEASED, $due->refresh()->status);
        $this->assertSame(UpsellOrderHold::STATUS_HELD, $waiting->refresh()->status);
    }

    public function test_a_shortened_window_takes_effect_on_the_next_pass(): void
    {
        $hold = $this->heldRow(releaseAt: now()->addHour());

        $this->artisan('upsell:release-holds');
        $this->assertSame(UpsellOrderHold::STATUS_HELD, $hold->refresh()->status);

        // The row IS the hold, so re-timing it is a write — not a queue message
        // that cannot be recalled.
        $hold->forceFill(['release_at' => now()->subMinute()])->save();

        $this->artisan('upsell:release-holds');
        $this->assertSame(UpsellOrderHold::STATUS_RELEASED, $hold->refresh()->status);
    }

    // === The email ===

    public function test_a_shopper_who_added_nothing_is_never_emailed(): void
    {
        $this->heldRow(releaseAt: now()->subMinute());

        $this->artisan('upsell:release-holds');

        // The store still sends its own confirmation; a second "here is your
        // order" for an order nobody changed is noise.
        Mail::assertNotSent(OrderUpdatedMail::class);
    }

    public function test_a_shopper_who_added_something_is_emailed_once(): void
    {
        $hold = $this->heldRow(releaseAt: now()->subMinute(), added: [[
            'name' => 'Extra beans',
            'quantity' => 2,
            'price' => 30.0,
            'currency' => 'ILS',
            'customer_email' => 'dana@example.com',
        ]]);

        $this->artisan('upsell:release-holds');

        Mail::assertSent(OrderUpdatedMail::class, static fn (OrderUpdatedMail $m): bool => $m->hasTo('dana@example.com'));
        $this->assertNotNull($hold->refresh()->notified_at);

        // A second scanner pass must not re-send: the row records that it went.
        Mail::fake();
        $this->artisan('upsell:release-holds');
        Mail::assertNotSent(OrderUpdatedMail::class);
    }

    public function test_the_merchant_can_turn_the_email_off(): void
    {
        UpsellSetting::current()->forceFill([
            'hold_window_minutes' => 20,
            'hold_notify' => false,
        ])->save();

        $this->heldRow(releaseAt: now()->subMinute(), added: [[
            'name' => 'Extra beans', 'quantity' => 1, 'price' => 30.0,
            'customer_email' => 'dana@example.com',
        ]]);

        $this->artisan('upsell:release-holds');

        Mail::assertNotSent(OrderUpdatedMail::class);
    }

    public function test_the_items_table_is_a_pre_rendered_escaped_scalar(): void
    {
        $hold = $this->heldRow(releaseAt: now()->subMinute(), added: [[
            'name' => '<script>alert(1)</script>Beans',
            'quantity' => 1,
            'price' => 30.0,
            'customer_email' => 'dana@example.com',
        ]]);

        $this->artisan('upsell:release-holds');

        Mail::assertSent(OrderUpdatedMail::class, function (OrderUpdatedMail $mail): bool {
            // A product title is store data, not our markup. The table is built
            // in PHP precisely so strtr never has to substitute a collection —
            // that is the wall that keeps merchant HTML away from a compiler.
            $this->assertStringNotContainsString('<script>', $mail->itemsTable);
            $this->assertStringContainsString('&lt;script&gt;', $mail->itemsTable);
            $this->assertStringContainsString('Beans', $mail->itemsTable);

            return true;
        });

        $this->assertNotNull($hold->refresh()->notified_at);
    }

    public function test_the_order_updated_template_is_editable_like_the_others(): void
    {
        $settings = MerchantMailSettings::current();

        // It belongs to the same list the settings screen walks, so a merchant
        // can reword it without us shipping a special case for it.
        $this->assertContains(MerchantMailSettings::TEMPLATE_ORDER_UPDATED, MerchantMailSettings::TEMPLATES);
        $this->assertNull($settings->customBody(MerchantMailSettings::TEMPLATE_ORDER_UPDATED));
    }

    // === Fixtures ===

    private function enabled(): UpsellSetting
    {
        $settings = UpsellSetting::current();
        $settings->forceFill(['hold_window_minutes' => 20, 'enabled' => true])->save();

        return $settings->refresh();
    }

    /** A row already in the held state, without touching Shopify. */
    private function heldRow(string $orderId = '900', ?\DateTimeInterface $releaseAt = null, array $added = []): UpsellOrderHold
    {
        $this->enabled();

        $hold = new UpsellOrderHold;
        $hold->forceFill([
            'shop_id' => $this->shop->getKey(),
            'platform' => UpsellOrderHold::PLATFORM_WOOCOMMERCE, // no Shopify calls
            'external_order_id' => $orderId,
            'status' => UpsellOrderHold::STATUS_HELD,
            'release_at' => $releaseAt ?? now()->addMinutes(20),
            'hold_refs' => [],
            'added_items' => $added,
        ])->save();

        return $hold;
    }

    /** @param array<int, array<string, string>> $metafields */
    private function fakeShopifyOrder(string $tags = '', array $metafields = []): void
    {
        Http::fake([
            '*/orders/*/fulfillment_orders.json' => Http::response(['fulfillment_orders' => []]),
            '*' => Http::response([
                'order' => ['id' => 900, 'tags' => $tags, 'metafields' => $metafields],
                'orders' => [['id' => 900, 'tags' => $tags, 'metafields' => $metafields]],
                'metafields' => $metafields,
            ]),
        ]);
    }
}
