<?php

namespace Tests\Feature\Account\Offers;

use App\Domain\Account\AccountPresenter;
use App\Domain\Account\AccountVisitor;
use App\Models\AccountOffer;
use App\Models\AccountOfferTarget;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The offer payload, and the rule that keeps it honest: ONE shape, TWO callers.
 *
 * `present()` draws the live page and `sample()` draws the merchant's preview.
 * A field the preview cannot fabricate is a field the live page should not be
 * inventing, so the two are asserted to emit the same keys — and the preview is
 * asserted to show the merchant's REAL offers, because a merchant who cannot see
 * the offer they just wrote concludes it is broken and writes it again.
 *
 * The shape is split in two on purpose: the PROMOTION at the top level, and the
 * things it sells in `targets`. The renderer is built against exactly these two
 * key lists.
 */
final class AccountPresenterOffersTest extends TestCase
{
    use MakesAccountOffers;
    use RefreshDatabase;

    // === CONSTANTS ===
    /** Every key an offer card carries. */
    private const OFFER_KEYS = [
        'id', 'placement', 'heading', 'subtext', 'image_url', 'html', 'source_plan', 'targets',
    ];

    /** Every key ONE target carries, in payload order. */
    private const TARGET_KEYS = [
        'key', 'index', 'kind', 'mode', 'timing', 'fulfilment', 'product', 'quantity',
        'amount', 'currency', 'currency_symbol', 'price_display', 'cadence',
        'first_charge_at', 'next_order_at', 'button_text', 'disclosure',
    ];

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_logged_out_visitor_gets_both_slots_present_and_empty(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            $model = app(AccountPresenter::class)->present(AccountVisitor::make(
                shop: $shop,
                customerRef: null,
                source: AccountVisitor::SOURCE_WOOCOMMERCE,
            ));

            // An offer is taken from a subscription on a saved card; we know
            // neither for somebody we have not identified.
            $this->assertSame([], $model['offers']);
            $this->assertSame([], $model['rail_offers']);
        });
    }

    public function test_the_three_placements_land_in_the_three_slots(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            $product = $this->makeProduct(self::PRODUCT_MONTHLY, 'Membership 2675', 49.0);
            $template = $this->makeTemplate($product, BillingFrequency::MONTHLY);

            $this->makeOffer($template, ['placement' => AccountOffer::PLACEMENT_TOP, 'name' => 'Top']);
            $this->makeOffer($template, ['placement' => AccountOffer::PLACEMENT_RAIL, 'name' => 'Rail']);
            $this->makeOffer($template, ['placement' => AccountOffer::PLACEMENT_PLAN, 'name' => 'Under the card']);

            $source = $this->makeSourcePlan();

            $model = app(AccountPresenter::class)->present($this->visitor($shop));

            $this->assertCount(1, $model['offers']);
            $this->assertCount(1, $model['rail_offers']);
            $this->assertCount(1, $model['subscriptions'][0]['offers']);

            $card = $model['subscriptions'][0]['offers'][0];
            $this->assertSame(self::OFFER_KEYS, array_keys($card));
            $this->assertSame((string) $source->public_id, $card['source_plan']);

            $this->assertCount(1, $card['targets']);
            $target = $card['targets'][0];
            $this->assertSame(self::TARGET_KEYS, array_keys($target));
            $this->assertSame(1, $target['index']);
            $this->assertSame(AccountOfferTarget::KIND_SUBSCRIPTION, $target['kind']);
            $this->assertSame(49.0, $target['amount']);
            $this->assertSame('ILS', $target['currency']);
            $this->assertSame('₪', $target['currency_symbol']);
            $this->assertSame('49 ₪', $target['price_display']);
            $this->assertSame('every month', $target['cadence']);
            $this->assertSame(AccountOfferTarget::MODE_REPLACE, $target['mode']);
            $this->assertSame(AccountOfferTarget::TIMING_IMMEDIATE, $target['timing']);
            $this->assertNull($target['fulfilment'], 'a subscription has no fulfilment to report');
            $this->assertSame(now()->toDateString(), $target['first_charge_at']);
            $this->assertNull($target['next_order_at']);
            $this->assertSame('Membership 2675', $target['product']['title']);
            $this->assertStringContainsString('49 ₪', $target['disclosure']);
            $this->assertStringContainsString('saved card', $target['disclosure']);
        });
    }

    /**
     * The headline of this change: ONE card, several things to buy, in the order
     * the merchant arranged them, each with its own price, its own button text
     * and its own sentence about what happens.
     */
    public function test_one_offer_presents_several_targets_in_order(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            $monthly = $this->makeProduct(self::PRODUCT_MONTHLY, 'Membership 2675', 49.0);
            $mug = $this->makeProduct('4242', 'Club mug', 39.0);

            $offer = $this->makeOffer(null, ['placement' => AccountOffer::PLACEMENT_PLAN]);
            $this->addTarget($offer, [
                'product_subscription_plan_id' => $this->makeTemplate($monthly, BillingFrequency::MONTHLY)->getKey(),
                'token_key' => 'monthly',
                'button_text' => 'Switch to monthly',
            ]);
            $this->addTarget($offer, [
                'kind' => AccountOfferTarget::KIND_ONE_TIME,
                'external_product_id' => '4242',
                'quantity' => 2,
                'fulfilment' => AccountOfferTarget::FULFILMENT_NEXT_ORDER,
                'button_text' => 'Add to my next box',
            ]);

            $source = $this->makeSourcePlan();

            $card = app(AccountPresenter::class)->present($this->visitor($shop))['subscriptions'][0]['offers'][0];

            $this->assertCount(2, $card['targets']);
            [$first, $second] = $card['targets'];

            $this->assertSame(self::TARGET_KEYS, array_keys($first));
            $this->assertSame(self::TARGET_KEYS, array_keys($second));

            // The subscription target: priced from the template, cadence and all.
            $this->assertSame('monthly', $first['key'], 'the merchant slug is the stable key');
            $this->assertSame(1, $first['index']);
            $this->assertSame(AccountOfferTarget::KIND_SUBSCRIPTION, $first['kind']);
            $this->assertSame(49.0, $first['amount']);
            $this->assertSame(1, $first['quantity']);
            $this->assertSame('every month', $first['cadence']);
            $this->assertSame('Switch to monthly', $first['button_text']);

            // The one-time target: priced from the catalog x quantity, no cadence,
            // and riding the source subscription's own renewal date.
            $this->assertSame('4242', $second['key'], 'no slug, so the product id addresses it');
            $this->assertSame(2, $second['index']);
            $this->assertSame(AccountOfferTarget::KIND_ONE_TIME, $second['kind']);
            $this->assertSame(AccountOfferTarget::MODE_ADD, $second['mode'], 'a mug replaces nothing');
            $this->assertNull($second['timing']);
            $this->assertSame(AccountOfferTarget::FULFILMENT_NEXT_ORDER, $second['fulfilment']);
            $this->assertSame(2, $second['quantity']);
            $this->assertSame(78.0, $second['amount'], '39 x 2, priced by the catalog');
            $this->assertNull($second['cadence']);
            $this->assertNull($second['first_charge_at']);
            $this->assertSame($source->next_charge_at->toDateString(), $second['next_order_at']);
            $this->assertStringContainsString($source->next_charge_at->toDateString(), $second['disclosure']);
            $this->assertStringContainsString('Nothing is charged today', $second['disclosure']);
        });
    }

    public function test_a_buy_now_target_says_the_money_moves_now(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            $this->makeProduct('4242', 'Club mug', 39.0);
            $offer = $this->makeOffer();
            $this->addTarget($offer, [
                'kind' => AccountOfferTarget::KIND_ONE_TIME,
                'external_product_id' => '4242',
                'fulfilment' => AccountOfferTarget::FULFILMENT_IMMEDIATE,
            ]);
            $this->makeSourcePlan();

            $target = app(AccountPresenter::class)
                ->present($this->visitor($shop))['subscriptions'][0]['offers'][0]['targets'][0];

            $this->assertSame(AccountOfferTarget::FULFILMENT_IMMEDIATE, $target['fulfilment']);
            $this->assertNull($target['next_order_at']);
            $this->assertStringContainsString('39 ₪', $target['disclosure']);
            $this->assertStringContainsString('now', $target['disclosure']);
        });
    }

    public function test_a_period_end_card_promises_the_date_the_plan_will_actually_be_born_with(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            $product = $this->makeProduct(self::PRODUCT_MONTHLY, 'Membership 2675', 49.0);
            $this->makeOffer(
                $this->makeTemplate($product, BillingFrequency::MONTHLY),
                ['replace_timing' => AccountOfferTarget::TIMING_PERIOD_END],
            );

            $source = $this->makeSourcePlan();

            $target = app(AccountPresenter::class)
                ->present($this->visitor($shop))['subscriptions'][0]['offers'][0]['targets'][0];

            $this->assertSame($source->next_charge_at->toDateString(), $target['first_charge_at']);
            $this->assertStringContainsString($source->next_charge_at->toDateString(), $target['disclosure']);
        });
    }

    public function test_custom_html_arrives_sanitised_tokenised_and_with_a_button_slot(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            $product = $this->makeProduct(self::PRODUCT_MONTHLY, 'Membership 2675', 49.0);
            $this->makeOffer($this->makeTemplate($product, BillingFrequency::MONTHLY), [
                'heading' => 'Go monthly',
                'custom_html' => '<div class="promo" onclick="steal()">'
                    .'<h3>{{heading}}</h3><p>{{product}} — {{price}} {{cadence}}</p>'
                    .'<script>alert(1)</script>{{button}}</div>',
                'token_key' => 'monthly',
            ]);

            $this->makeSourcePlan();

            $html = app(AccountPresenter::class)->present($this->visitor($shop))['subscriptions'][0]['offers'][0]['html'];

            // The sanitizer ran, on the server, before the string was ever sent.
            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringNotContainsString('alert(1)', $html);
            $this->assertStringNotContainsString('onclick', $html);

            // Tokens are filled by strtr — never by a template engine.
            $this->assertStringContainsString('Go monthly', $html);
            $this->assertStringContainsString('Membership 2675', $html);
            $this->assertStringContainsString('49 ₪', $html);
            $this->assertStringContainsString('every month', $html);
            $this->assertStringNotContainsString('{{', $html);

            // The button is a sentinel the renderer replaces with a control IT
            // wired, carrying the key the click has to post back.
            $this->assertStringContainsString('<span class="la-offer__slot" data-target="monthly"></span>', $html);
            $this->assertStringContainsString('class="promo"', $html);
        });
    }

    /** Several buttons in one block, each wired to the target it names. */
    public function test_each_button_token_becomes_the_slot_of_the_target_it_names(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            $monthly = $this->makeProduct(self::PRODUCT_MONTHLY, 'Membership 2675', 49.0);
            $this->makeProduct('4242', 'Club mug', 39.0);

            $offer = $this->makeOffer(null, [
                'custom_html' => '<p>Switch {{button_monthly}}</p><p>Mug {{button_4242}}</p><p>Again {{button_1}}</p>',
            ]);
            $this->addTarget($offer, [
                'product_subscription_plan_id' => $this->makeTemplate($monthly, BillingFrequency::MONTHLY)->getKey(),
                'token_key' => 'monthly',
            ]);
            $this->addTarget($offer, [
                'kind' => AccountOfferTarget::KIND_ONE_TIME,
                'external_product_id' => '4242',
            ]);

            $this->makeSourcePlan();

            $html = app(AccountPresenter::class)->present($this->visitor($shop))['subscriptions'][0]['offers'][0]['html'];

            $this->assertStringContainsString('<span class="la-offer__slot" data-target="monthly"></span>', $html);
            $this->assertStringContainsString('<span class="la-offer__slot" data-target="4242"></span>', $html);
            // {{button_1}} is the FIRST target by position — the same one the slug
            // names, so the block simply carries its button twice.
            $this->assertSame(2, substr_count($html, 'data-target="monthly"'));
            $this->assertStringNotContainsString('{{', $html);
        });
    }

    public function test_a_hostile_product_title_cannot_reopen_the_markup(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            $product = $this->makeProduct(self::PRODUCT_MONTHLY, '<img src=x onerror=alert(1)>', 49.0);
            $this->makeOffer($this->makeTemplate($product, BillingFrequency::MONTHLY), [
                'custom_html' => '<p>{{product}}</p>{{button}}',
                'token_key' => 'go',
            ]);
            $this->makeSourcePlan();

            $html = app(AccountPresenter::class)->present($this->visitor($shop))['subscriptions'][0]['offers'][0]['html'];

            // Values are escaped BEFORE substitution, so a catalog title cannot
            // close the tag the sanitizer just opened. The words survive as inert
            // TEXT — what must not survive is a tag the browser would parse.
            $this->assertStringNotContainsString('<img', $html);
            $this->assertStringContainsString('&lt;img', $html);
            $this->assertSame(
                '<p>&lt;img src=x onerror=alert(1)&gt;</p><span class="la-offer__slot" data-target="go"></span>',
                $html,
            );
        });
    }

    public function test_the_preview_shows_the_merchants_real_offers_against_the_sample_card(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function (): void {
            $product = $this->makeProduct(self::PRODUCT_MONTHLY, 'Membership 2675', 49.0);
            $template = $this->makeTemplate($product, BillingFrequency::MONTHLY);

            // Audience nobody matches, and a shop that cannot charge: the preview
            // ignores both, exactly as it ignores banner audiences.
            MerchantBillingSettings::current()->forceFill(['live_charging_enabled' => false])->save();
            $this->makeOffer($template, [
                'placement' => AccountOffer::PLACEMENT_TOP,
                'audience' => ['product_ids' => ['no-such-product']],
            ]);
            $this->makeOffer($template, ['placement' => AccountOffer::PLACEMENT_PLAN]);

            $model = app(AccountPresenter::class)->sample();

            $this->assertCount(1, $model['offers']);
            $this->assertCount(1, $model['subscriptions'][0]['offers']);
            $this->assertSame([], $model['rail_offers']);

            $card = $model['subscriptions'][0]['offers'][0];
            $this->assertSame(self::OFFER_KEYS, array_keys($card));
            $this->assertSame(self::TARGET_KEYS, array_keys($card['targets'][0]));
            $this->assertSame('SAMPLE-1', $card['source_plan'], 'The preview has no real plan to take it from.');
        });
    }

    public function test_present_and_sample_emit_the_same_offer_shape(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            $product = $this->makeProduct(self::PRODUCT_MONTHLY, 'Membership 2675', 49.0);
            $this->makeOffer($this->makeTemplate($product, BillingFrequency::MONTHLY), [
                'placement' => AccountOffer::PLACEMENT_TOP,
            ]);
            $this->makeSourcePlan();

            $presenter = app(AccountPresenter::class);
            $live = $presenter->present($this->visitor($shop))['offers'][0];
            $preview = $presenter->sample()['offers'][0];

            $this->assertSame(array_keys($live), array_keys($preview));
            $this->assertSame(array_keys($live['targets'][0]), array_keys($preview['targets'][0]));
            $this->assertSame($live['targets'][0]['amount'], $preview['targets'][0]['amount']);
            $this->assertSame($live['targets'][0]['cadence'], $preview['targets'][0]['cadence']);
            $this->assertSame($live['targets'][0]['disclosure'], $preview['targets'][0]['disclosure']);
        });
    }

    public function test_the_offer_copy_keys_ride_the_payload(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            $copy = app(AccountPresenter::class)->present($this->visitor($shop))['copy'];

            foreach ([
                'offer_accept', 'offer_from', 'offer_replaces', 'offer_price_label', 'offer_unavailable',
                'offer_buy_now', 'offer_one_time', 'offer_add_to_next',
                'result_accept_offer', 'result_accept_offer_unavailable', 'result_accept_offer_charge_failed',
                'result_accept_offer_not_eligible', 'result_accept_offer_changed',
            ] as $key) {
                $this->assertArrayHasKey($key, $copy);
                // Resolved, not a raw translation key left to leak onto the page.
                $this->assertStringNotContainsString('account.', (string) $copy[$key], $key);
            }
        });
    }

    private function visitor(Shop $shop): AccountVisitor
    {
        return AccountVisitor::make(
            shop: $shop,
            customerRef: self::MEMBER_REF,
            source: AccountVisitor::SOURCE_WOOCOMMERCE,
            email: self::MEMBER_EMAIL,
        );
    }
}
