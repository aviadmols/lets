<?php

namespace Tests\Feature\WooCommerce;

use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellFlowTrigger;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\PaymentLedger;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * THE THANK-YOU BUNDLE, AND THE OFFER'S WINDOW.
 *
 * A bundle: the merchant lists products, the shopper picks exactly N of them, and all N
 * are charged at ONE price, added to the order they just placed, and declared on one
 * document. A window: the offer can be taken for M minutes from the moment it is first
 * shown, and not a second later.
 *
 * Both are money questions, so the server decides both — the pick is checked against the
 * offer and the clock is the offer's own. The client can only ask.
 */
final class WooCommerceUpsellBundleTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const OFFER = '/api/woocommerce/upsell/offer';

    private const ACCEPT = '/api/woocommerce/upsell/accept';

    private const ORDER = 'WC-9';

    private const CUSTOMER = 'cust-9';

    public int $payplusCalls = 0;

    /** @var list<float> */
    public array $charged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private WooCommerceUpsellBundleTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $this->test->charged[] = $amount;
                $n = ++$this->test->payplusCalls;

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'txn-'.$n]],
                ]);
            }

            public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function generateLink(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }
        });

        Http::fake([
            '*/wp-json/wc/v3/orders/'.self::ORDER.'/notes' => Http::response(['id' => 1], 201),
            '*/wp-json/wc/v3/orders/'.self::ORDER => Http::response(['id' => self::ORDER], 200),
        ]);
    }

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    // === The bundle ===

    public function test_the_offer_is_a_bundle_card_with_its_products_in_the_merchants_order(): void
    {
        [, $key, $secret, , $books] = $this->bundleShop();

        $card = $this->fetchOffer($key, $secret)->assertOk()->json('offer.card.content');

        $this->assertSame(3, $card['bundle']['quantity']);
        $this->assertSame(2, $card['bundle']['columns']);
        // The merchant listed C, A, B — the slider shows them in exactly that order.
        $this->assertSame(
            [$books['C']->id, $books['A']->id, $books['B']->id],
            array_column($card['bundle']['products'], 'id'),
        );
        $this->assertSame('₪100.00', $card['price_display']);
        // The disclosure — the consent the click gives — names the bundle price, never a book's.
        $this->assertStringContainsString('₪100.00', $card['disclosure']);
    }

    public function test_a_full_pick_is_charged_once_at_the_bundle_price_and_every_book_joins_the_order(): void
    {
        Bus::fake([IssueDocumentJob::class]);
        [$shop, $key, $secret, $offer, $books] = $this->bundleShop();
        $this->fetchOffer($key, $secret);

        $pick = [$books['A']->id, $books['B']->id, $books['C']->id];

        $this->accept($key, $secret, $offer, $pick)->assertOk()->assertJsonPath('charged', true);
        // A double-click: one charge, not two.
        $this->accept($key, $secret, $offer, $pick)->assertOk();

        $this->assertSame([100.0], $this->charged);
        $this->assertSame(1, Tenant::run($shop, fn (): int => PaymentLedger::query()->where('status', LedgerStatus::SUCCEEDED->value)->count()));

        // Every book is its own line on the shopper's order, in the merchant's order, and the
        // lines add up to exactly what the card was charged — ₪100 over three is not ₪99.99.
        Http::assertSent(function (HttpRequest $req) use ($books): bool {
            $lines = (array) ($req->data()['line_items'] ?? []);

            return $req->method() === 'PUT'
                && str_ends_with($req->url(), '/orders/'.self::ORDER)
                && array_column($lines, 'product_id') === [(int) $books['C']->external_id, (int) $books['A']->external_id, (int) $books['B']->external_id]
                && array_column($lines, 'total') === ['33.33', '33.33', '33.34'];
        });

        // One document for the sale, naming what was bought.
        Bus::assertDispatched(IssueDocumentJob::class, fn (IssueDocumentJob $job): bool => $job->itemTitle === 'Cosmos · Atlas · Borders');
    }

    public function test_a_pick_that_is_not_exactly_the_bundle_is_refused_without_a_charge(): void
    {
        [$shop, $key, $secret, $offer, $books] = $this->bundleShop();
        $foreign = Tenant::run($this->otherShop(), fn (): Product => $this->book('Foreign', '999'));
        $this->fetchOffer($key, $secret);

        $picks = [
            'too few' => [$books['A']->id, $books['B']->id],
            'too many' => [$books['A']->id, $books['B']->id, $books['C']->id, $books['D']->id],
            'the same book twice' => [$books['A']->id, $books['A']->id, $books['B']->id],
            'a book not in the bundle' => [$books['A']->id, $books['B']->id, $books['D']->id],
            'another shop\'s book' => [$books['A']->id, $books['B']->id, $foreign->id],
            'garbage' => ['x', 'y', 'z'],
        ];

        foreach ($picks as $why => $pick) {
            $this->accept($key, $secret, $offer, $pick)
                ->assertStatus(422)
                ->assertJsonPath('result', 'invalid_selection');

            $this->assertSame(0, $this->payplusCalls, "{$why} must never reach the card");
        }
    }

    public function test_a_bundle_short_of_a_product_is_not_shown_and_cannot_be_bought(): void
    {
        [$shop, $key, $secret, $offer, $books] = $this->bundleShop();

        // One of the three listed books left the catalogue: a pick of three is impossible.
        Tenant::run($shop, fn () => $books['B']->delete());

        $this->fetchOffer($key, $secret)->assertOk()->assertJsonPath('offer', null);

        $this->accept($key, $secret, $offer, [$books['A']->id, $books['B']->id, $books['C']->id])
            ->assertStatus(422)
            ->assertJsonPath('result', 'invalid_selection');

        $this->assertSame(0, $this->payplusCalls);
    }

    public function test_an_unfinished_bundle_never_charges_the_single_product_price(): void
    {
        [$shop, $key, $secret, $offer, $books] = $this->bundleShop();
        // The merchant chose "bundle" but never set a price — and the offer still carries an
        // old single-product base price that must not become the charge.
        Tenant::run($shop, fn () => $offer->forceFill(['bundle_price' => null, 'base_price' => 49])->save());

        $this->accept($key, $secret, $offer, [$books['A']->id, $books['B']->id, $books['C']->id])
            ->assertStatus(422)
            ->assertJsonPath('result', 'invalid_selection');

        $this->assertSame(0, $this->payplusCalls);
    }

    public function test_the_bundle_price_splits_to_the_agora(): void
    {
        $offer = new UpsellFlowOffer(['bundle_price' => 100]);

        $this->assertSame([33.33, 33.33, 33.34], $offer->bundleLineTotals(3));
        $this->assertSame([100.0], $offer->bundleLineTotals(1));
        // Whatever the price and the count, the lines add up to exactly the price.
        $this->assertEqualsWithDelta(99.99, array_sum((new UpsellFlowOffer(['bundle_price' => 99.99]))->bundleLineTotals(7)), 0.00001);
    }

    // === The window ===

    public function test_the_window_is_counted_from_the_first_view_and_a_reload_does_not_reset_it(): void
    {
        [, $key, $secret] = $this->bundleShop(timerMinutes: 5);

        $this->assertSame(300, $this->fetchOffer($key, $secret)->json('offer.card.content.timer_seconds'));

        $this->travel(120)->seconds();

        // The thank-you page reloaded two minutes later: three minutes left, not five again.
        $this->assertSame(180, $this->fetchOffer($key, $secret)->json('offer.card.content.timer_seconds'));
    }

    public function test_after_the_window_the_offer_is_gone_and_a_late_accept_is_refused(): void
    {
        [, $key, $secret, $offer, $books] = $this->bundleShop(timerMinutes: 5);
        $this->fetchOffer($key, $secret);

        $this->travel(5 * 60 + UpsellFlowOffer::WINDOW_ACCEPT_GRACE_SECONDS + 1)->seconds();

        $this->fetchOffer($key, $secret)->assertOk()->assertJsonPath('offer', null);

        $this->accept($key, $secret, $offer, [$books['A']->id, $books['B']->id, $books['C']->id])
            ->assertStatus(410)
            ->assertJsonPath('result', 'expired');

        $this->assertSame(0, $this->payplusCalls);
    }

    public function test_a_click_made_as_the_clock_hits_zero_still_goes_through(): void
    {
        [, $key, $secret, $offer, $books] = $this->bundleShop(timerMinutes: 5);
        $this->fetchOffer($key, $secret);

        // Clicked at 0:01, landed a few seconds after zero.
        $this->travel(5 * 60 + 5)->seconds();

        $this->accept($key, $secret, $offer, [$books['A']->id, $books['B']->id, $books['C']->id])
            ->assertOk()
            ->assertJsonPath('charged', true);
    }

    public function test_an_accept_that_already_charged_answers_so_even_after_the_window(): void
    {
        [, $key, $secret, $offer, $books] = $this->bundleShop(timerMinutes: 5);
        $this->fetchOffer($key, $secret);
        $pick = [$books['A']->id, $books['B']->id, $books['C']->id];

        $this->accept($key, $secret, $offer, $pick)->assertOk()->assertJsonPath('charged', true);

        $this->travel(10)->minutes();

        // Not "expired": the shopper DID buy it, and a stale retry must say so.
        $this->accept($key, $secret, $offer, $pick)->assertOk()->assertJsonPath('result', 'already_accepted');
        $this->assertSame(1, $this->payplusCalls);
    }

    // === Fixtures ===

    /**
     * A WooCommerce shop with four books (A–D), a saved card + consent, and an active flow whose
     * offer bundles C, A, B — pick 3 for ₪100, 2 per slide.
     *
     * @return array{0: Shop, 1: string, 2: string, 3: UpsellFlowOffer, 4: array<string, Product>}
     */
    private function bundleShop(?int $timerMinutes = null): array
    {
        $result = (new WooCommerceShopProvisioner)->provision('bundle-'.Str::lower(Str::random(6)).'.example.com');
        $shop = $result['shop'];
        $shop->woocommerce_credentials = array_merge($shop->woocommerce_credentials ?: [], [
            'base_url' => 'https://'.$shop->woocommerce_domain, 'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ]);
        $shop->wc_shop_token = (string) Str::ulid();
        $shop->payplus_credentials = ['api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't'];
        $shop->save();

        $json = (string) base64_decode(strtr($result['connection_token'], '-_', '+/'));
        $data = (array) json_decode($json, true);

        [$offer, $books] = Tenant::run($shop, function () use ($shop, $timerMinutes): array {
            $books = [
                'A' => $this->book('Atlas', '301'),
                'B' => $this->book('Borders', '302'),
                'C' => $this->book('Cosmos', '303'),
                'D' => $this->book('Dunes', '304'),
            ];

            InstallmentPaymentMethod::create([
                'shopify_customer_id' => self::CUSTOMER, 'payplus_card_token_uid' => 'tok-9',
                'payplus_customer_uid' => 'pp-9', 'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);
            CustomerConsent::create([
                'shopify_customer_id' => self::CUSTOMER,
                'consent_context' => CustomerConsent::CONTEXT_UPSELL,
                'accepted_at' => now(),
            ]);

            $flow = new UpsellFlow(['name' => 'Books', 'priority' => 1]);
            $flow->shop_id = $shop->id;
            $flow->forceFill(['status' => UpsellFlowStatus::ACTIVE->value])->save();

            UpsellFlowTrigger::create(['flow_id' => $flow->id, 'match_type' => UpsellFlowTrigger::MATCH_ANY_PRODUCT]);

            $offer = UpsellFlowOffer::create([
                'flow_id' => $flow->id,
                'offer_product_gid' => '',
                'offer_variant_gid' => '',
                'offer_title' => 'Three books',
                'product_selection_mode' => UpsellFlowOffer::PRODUCT_BUNDLE,
                'bundle_product_ids' => [$books['C']->id, $books['A']->id, $books['B']->id],
                'bundle_quantity' => 3,
                'bundle_price' => 100,
                'bundle_columns' => 2,
                'show_timer' => $timerMinutes !== null,
                'timer_minutes' => $timerMinutes,
                'headline' => 'Books for the road',
                'accept_cta' => 'Add to my order',
                'position' => 0,
            ]);

            return [$offer, $books];
        });

        return [$shop->fresh(), (string) $data['k'], (string) $data['s'], $offer, $books];
    }

    private function book(string $title, string $externalId): Product
    {
        $product = Product::create([
            'source' => Product::SOURCE_WOOCOMMERCE,
            'external_id' => $externalId,
            'title' => $title,
            'status' => Product::STATUS_ACTIVE,
            'online_store_status' => Product::ONLINE_PUBLISHED,
        ]);

        // A simple WooCommerce product: the variant id IS the product id.
        ProductVariant::create([
            'product_id' => $product->id,
            'external_variant_id' => $externalId,
            'title' => 'Default',
            'price' => 59,
            'position' => 0,
        ]);

        return $product->fresh();
    }

    private function otherShop(): Shop
    {
        return (new WooCommerceShopProvisioner)->provision('other-'.Str::lower(Str::random(6)).'.example.com')['shop'];
    }

    private function fetchOffer(string $key, string $secret): TestResponse
    {
        $query = http_build_query(['parent_order' => self::ORDER, 'customer' => self::CUSTOMER, 'products' => '1', 'subtotal' => '120']);
        // The signature's freshness is judged on the REAL clock; the offer's window on the
        // application's, which these tests move.
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.'GET'.self::OFFER, $secret, true));

        return $this->call('GET', self::OFFER.'?'.$query, [], [], [], [
            'HTTP_X_LETS_KEY' => $key, 'HTTP_X_LETS_TIMESTAMP' => $ts, 'HTTP_X_LETS_SIGNATURE' => $sig,
        ]);
    }

    /** @param list<mixed> $productIds */
    private function accept(string $key, string $secret, UpsellFlowOffer $offer, array $productIds): TestResponse
    {
        $json = (string) json_encode([
            'flow_id' => $offer->flow_id,
            'offer_id' => $offer->id,
            'parent_order' => self::ORDER,
            'customer' => self::CUSTOMER,
            'email' => 'reader@example.com',
            'product_ids' => $productIds,
        ], JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.'POST'.self::ACCEPT.$json, $secret, true));

        return $this->call('POST', self::ACCEPT, [], [], [], [
            'HTTP_X_LETS_KEY' => $key, 'HTTP_X_LETS_TIMESTAMP' => $ts,
            'HTTP_X_LETS_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $json);
    }
}
