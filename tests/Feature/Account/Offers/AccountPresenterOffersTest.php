<?php

namespace Tests\Feature\Account\Offers;

use App\Domain\Account\AccountPresenter;
use App\Domain\Account\AccountVisitor;
use App\Models\AccountOffer;
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
 */
final class AccountPresenterOffersTest extends TestCase
{
    use MakesAccountOffers;
    use RefreshDatabase;

    // === CONSTANTS ===
    /** Every key an offer card carries. The renderer is built against this list. */
    private const OFFER_KEYS = [
        'id', 'placement', 'mode', 'timing', 'product', 'amount', 'currency',
        'currency_symbol', 'price_display', 'cadence', 'first_charge_at', 'heading',
        'subtext', 'image_url', 'button_text', 'html', 'source_plan', 'disclosure',
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
            $this->assertSame(49.0, $card['amount']);
            $this->assertSame('ILS', $card['currency']);
            $this->assertSame('₪', $card['currency_symbol']);
            $this->assertSame('49 ₪', $card['price_display']);
            $this->assertSame('every month', $card['cadence']);
            $this->assertSame(AccountOffer::MODE_REPLACE, $card['mode']);
            $this->assertSame(AccountOffer::TIMING_IMMEDIATE, $card['timing']);
            $this->assertSame(now()->toDateString(), $card['first_charge_at']);
            $this->assertSame('Membership 2675', $card['product']['title']);
            $this->assertStringContainsString('49 ₪', $card['disclosure']);
            $this->assertStringContainsString('saved card', $card['disclosure']);
        });
    }

    public function test_a_period_end_card_promises_the_date_the_plan_will_actually_be_born_with(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            $product = $this->makeProduct(self::PRODUCT_MONTHLY, 'Membership 2675', 49.0);
            $this->makeOffer(
                $this->makeTemplate($product, BillingFrequency::MONTHLY),
                ['replace_timing' => AccountOffer::TIMING_PERIOD_END],
            );

            $source = $this->makeSourcePlan();

            $card = app(AccountPresenter::class)->present($this->visitor($shop))['subscriptions'][0]['offers'][0];

            $this->assertSame($source->next_charge_at->toDateString(), $card['first_charge_at']);
            $this->assertStringContainsString($source->next_charge_at->toDateString(), $card['disclosure']);
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
            // wired: a merchant decides where it goes, never what it does.
            $this->assertStringContainsString(AccountOffer::BUTTON_SLOT, $html);
            $this->assertStringContainsString('class="promo"', $html);
        });
    }

    public function test_a_hostile_product_title_cannot_reopen_the_markup(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            $product = $this->makeProduct(self::PRODUCT_MONTHLY, '<img src=x onerror=alert(1)>', 49.0);
            $this->makeOffer($this->makeTemplate($product, BillingFrequency::MONTHLY), [
                'custom_html' => '<p>{{product}}</p>{{button}}',
            ]);
            $this->makeSourcePlan();

            $html = app(AccountPresenter::class)->present($this->visitor($shop))['subscriptions'][0]['offers'][0]['html'];

            // Values are escaped BEFORE substitution, so a catalog title cannot
            // close the tag the sanitizer just opened. The words survive as inert
            // TEXT — what must not survive is a tag the browser would parse.
            $this->assertStringNotContainsString('<img', $html);
            $this->assertStringContainsString('&lt;img', $html);
            $this->assertSame('<p>&lt;img src=x onerror=alert(1)&gt;</p>'.AccountOffer::BUTTON_SLOT, $html);
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
            $this->assertSame($live['amount'], $preview['amount']);
            $this->assertSame($live['cadence'], $preview['cadence']);
            $this->assertSame($live['disclosure'], $preview['disclosure']);
        });
    }

    public function test_the_offer_copy_keys_ride_the_payload(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            $copy = app(AccountPresenter::class)->present($this->visitor($shop))['copy'];

            foreach ([
                'offer_accept', 'offer_from', 'offer_replaces', 'offer_price_label', 'offer_unavailable',
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
