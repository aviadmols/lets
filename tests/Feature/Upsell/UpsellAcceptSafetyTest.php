<?php

namespace Tests\Feature\Upsell;

use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\Ledger;
use App\Domain\Upsell\AcceptUpsellRequest;
use App\Domain\Upsell\Enums\OfferEventType;
use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowBranch;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Domain\Upsell\UpsellChargeResult;
use App\Domain\Upsell\UpsellChargeService;
use App\Domain\Upsell\UpsellSignedUrlService;
use App\Models\InstallmentPaymentMethod;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The walls around the one-click upsell charge on a saved PayPlus token:
 *
 *   - IN-FLIGHT: a charge still unsettled for the key (a pending row, a
 *     retry_scheduled row, or another request holding the key's lock) is never
 *     sent a second time — the second tap is refused, not "reused";
 *   - ELIGIBILITY: only an offer that is live AND was put in front of this order
 *     (an impression, or a forward branch the shopper answered) can be charged
 *     or declined;
 *   - IDENTITY: the saved card is matched on the platform customer id only —
 *     never on the sequential local customers PK;
 *   - VERBS: GET on an accept URL moves no money; the charge is a POST.
 */
final class UpsellAcceptSafetyTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const ORDER = '4001';

    private const CUSTOMER = '501';

    public int $payplusCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payplusCalls = 0;
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private UpsellAcceptSafetyTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
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
    }

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    // === In-flight wall ===

    public function test_a_pending_row_for_the_key_is_never_charged_a_second_time(): void
    {
        [$shop, $flow, $offer] = $this->shopWithShownOffer();

        foreach ([LedgerStatus::PENDING, LedgerStatus::RETRY_SCHEDULED] as $unsettled) {
            Tenant::run($shop, function () use ($shop, $flow, $offer, $unsettled): void {
                PaymentLedger::query()->delete();
                $row = $this->openRow($shop, $flow, $offer);
                if ($unsettled === LedgerStatus::RETRY_SCHEDULED) {
                    Ledger::transition($row, LedgerStatus::FAILED);
                    Ledger::transition($row, LedgerStatus::RETRY_SCHEDULED);
                }

                // The first tap's charge is at PayPlus right now. The second tap:
                $result = $this->service()->accept($shop, $this->request($flow, $offer));

                $this->assertSame(UpsellChargeResult::RESULT_IN_FLIGHT, $result->result);
                $this->assertSame($unsettled->value, (string) $row->fresh()->status);
            });
        }

        $this->assertSame(0, $this->payplusCalls, 'An unsettled key must never reach PayPlus again.');
    }

    public function test_a_tap_that_arrives_while_another_holds_the_key_is_refused(): void
    {
        [$shop, $flow, $offer] = $this->shopWithShownOffer();
        $key = IdempotencyKey::upsell((int) $shop->id, (int) $flow->id, (int) $offer->id, self::ORDER, self::CUSTOMER);

        $lock = Cache::lock('upsell:accept:'.$key, 30);
        $this->assertTrue($lock->get());

        try {
            $result = Tenant::run($shop, fn () => $this->service()->accept($shop, $this->request($flow, $offer)));
        } finally {
            $lock->release();
        }

        $this->assertSame(UpsellChargeResult::RESULT_IN_FLIGHT, $result->result);
        $this->assertSame(0, $this->payplusCalls);
        $this->assertSame(0, Tenant::run($shop, fn (): int => PaymentLedger::query()->count()));

        // Released: the next tap charges, once.
        $this->assertTrue(Tenant::run($shop, fn () => $this->service()->accept($shop, $this->request($flow, $offer)))->isCharged());
        $this->assertSame(1, $this->payplusCalls);
    }

    public function test_a_declined_attempt_may_be_tried_again_and_charges_once(): void
    {
        [$shop, $flow, $offer] = $this->shopWithShownOffer();

        Tenant::run($shop, function () use ($shop, $flow, $offer): void {
            Ledger::transition($this->openRow($shop, $flow, $offer), LedgerStatus::FAILED);

            $again = $this->service()->accept($shop, $this->request($flow, $offer));
            $this->assertSame(UpsellChargeResult::RESULT_CHARGED, $again->result);

            $replay = $this->service()->accept($shop, $this->request($flow, $offer));
            $this->assertSame(UpsellChargeResult::RESULT_ALREADY, $replay->result);
        });

        $this->assertSame(1, $this->payplusCalls);
    }

    public function test_a_refunded_upsell_is_not_charged_again(): void
    {
        [$shop, $flow, $offer] = $this->shopWithShownOffer();

        Tenant::run($shop, function () use ($shop, $flow, $offer): void {
            $row = $this->openRow($shop, $flow, $offer);
            Ledger::transition($row, LedgerStatus::SUCCEEDED);
            Ledger::transition($row, LedgerStatus::REFUNDED);

            $result = $this->service()->accept($shop, $this->request($flow, $offer));
            $this->assertSame(UpsellChargeResult::RESULT_ALREADY, $result->result);
        });

        $this->assertSame(0, $this->payplusCalls);
    }

    // === Eligibility ===

    public function test_an_offer_never_shown_on_the_order_is_neither_charged_nor_declined(): void
    {
        [$shop, $flow, $offer] = $this->shopWithShownOffer(shown: false);

        Tenant::run($shop, function () use ($shop, $flow, $offer): void {
            $this->assertSame(
                UpsellChargeResult::RESULT_NOT_ELIGIBLE,
                $this->service()->accept($shop, $this->request($flow, $offer))->result,
            );
            $this->service()->decline((int) $shop->id, $this->request($flow, $offer));

            // Shown on ANOTHER order does not count for this one.
            $this->impression($offer, 'some-other-order');
            $this->assertSame(
                UpsellChargeResult::RESULT_NOT_ELIGIBLE,
                $this->service()->accept($shop, $this->request($flow, $offer))->result,
            );

            // Nothing was recorded as answered, and nothing was charged.
            $this->assertSame(0, UpsellOfferEvent::query()
                ->whereIn('event_type', [OfferEventType::ACCEPTED->value, OfferEventType::DECLINED->value])->count());
        });

        $this->assertSame(0, $this->payplusCalls);
    }

    public function test_an_offer_whose_flow_is_no_longer_active_is_not_charged(): void
    {
        [$shop, $flow, $offer] = $this->shopWithShownOffer();

        Tenant::run($shop, function () use ($shop, $flow, $offer): void {
            $flow->forceFill(['status' => UpsellFlowStatus::INACTIVE->value])->save();

            $this->assertSame(
                UpsellChargeResult::RESULT_NOT_ELIGIBLE,
                $this->service()->accept($shop, $this->request($flow->fresh(), $offer))->result,
            );
        });

        $this->assertSame(0, $this->payplusCalls);
    }

    public function test_a_branch_offer_is_eligible_only_after_the_shopper_answered_the_offer_before_it(): void
    {
        [$shop, $flow, $first] = $this->shopWithShownOffer();

        Tenant::run($shop, function () use ($shop, $flow, $first): void {
            $next = $this->offer($flow, position: 1, base: 30.0);
            $decline = $this->offer($flow, position: 2, base: 20.0);
            UpsellFlowBranch::create([
                'flow_id' => $flow->id,
                'from_offer_id' => $first->id,
                'on_accept_next_offer_id' => $next->id,
                'on_decline_next_offer_id' => $decline->id,
            ]);

            // Before the first offer is answered, neither branch target is open.
            $this->assertSame(UpsellChargeResult::RESULT_NOT_ELIGIBLE, $this->service()->accept($shop, $this->request($flow, $next))->result);

            // Accepting the first opens the ACCEPT branch — and only that one.
            $this->assertTrue($this->service()->accept($shop, $this->request($flow, $first))->isCharged());
            $this->assertSame(UpsellChargeResult::RESULT_NOT_ELIGIBLE, $this->service()->accept($shop, $this->request($flow, $decline))->result);
            $this->assertSame(UpsellChargeResult::RESULT_CHARGED, $this->service()->accept($shop, $this->request($flow, $next))->result);
        });

        $this->assertSame(2, $this->payplusCalls);
    }

    // === Identity ===

    public function test_a_numeric_ref_never_matches_a_card_by_the_local_customer_pk(): void
    {
        [$shop, $flow, $offer] = $this->shopWithShownOffer(withCard: false);

        Tenant::run($shop, function () use ($shop, $flow, $offer): void {
            // Someone else's card, whose LOCAL customer id happens to be 501.
            InstallmentPaymentMethod::create([
                'customer_id' => (int) self::CUSTOMER,
                'shopify_customer_id' => 'gid://shopify/Customer/999',
                'payplus_card_token_uid' => 'tok-other',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            $this->assertSame(
                UpsellChargeResult::RESULT_NO_METHOD,
                $this->service()->accept($shop, $this->request($flow, $offer))->result,
            );
        });

        $this->assertSame(0, $this->payplusCalls);
    }

    // === Verbs ===

    public function test_get_on_an_accept_link_shows_a_confirm_page_and_charges_nothing(): void
    {
        [$shop, $flow, $offer] = $this->shopWithShownOffer();
        $url = Tenant::run($shop, fn (): string => app(UpsellSignedUrlService::class)->acceptUrl($flow, $offer, self::ORDER, self::CUSTOMER));

        // A link scanner / prefetcher / <img> following the link.
        $this->get($url)->assertOk()->assertSee('method="POST"', false);
        $this->assertSame(0, $this->payplusCalls);

        // The shopper pressing the button.
        $this->post($url)->assertOk();
        $this->assertSame(1, $this->payplusCalls);
    }

    public function test_the_json_accept_is_post_only(): void
    {
        [$shop, $flow, $offer] = $this->shopWithShownOffer();
        $url = Tenant::run($shop, fn (): string => app(UpsellSignedUrlService::class)->acceptApiUrl($flow, $offer, self::ORDER, self::CUSTOMER));

        $this->getJson($url)->assertStatus(405);
        $this->assertSame(0, $this->payplusCalls);

        $this->postJson($url)->assertOk()->assertJsonPath('result', 'charged');
        $this->postJson($url)->assertOk()->assertJsonPath('result', 'already_accepted');
        $this->assertSame(1, $this->payplusCalls);
    }

    // === Fixtures ===

    /** @return array{0: Shop, 1: UpsellFlow, 2: UpsellFlowOffer} */
    private function shopWithShownOffer(bool $shown = true, bool $withCard = true): array
    {
        $shop = Shop::create([
            'shopify_domain' => 'safety.myshopify.com',
            'name' => 'Safety',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        [$flow, $offer] = Tenant::run($shop, function () use ($shop, $shown, $withCard): array {
            $flow = new UpsellFlow(['name' => 'Flow', 'priority' => 1]);
            $flow->shop_id = $shop->id;
            $flow->forceFill(['status' => UpsellFlowStatus::ACTIVE->value])->save();

            $offer = $this->offer($flow, position: 0, base: 50.0);

            if ($withCard) {
                // Vaulted under the Shopify customer id, as the deposit flow writes it.
                InstallmentPaymentMethod::create([
                    'shopify_customer_id' => self::CUSTOMER,
                    'payplus_card_token_uid' => 'tok-1',
                    'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
                ]);
            }

            if ($shown) {
                $this->impression($offer, self::ORDER);
            }

            return [$flow->fresh(), $offer];
        });

        return [$shop->fresh(), $flow, $offer];
    }

    private function offer(UpsellFlow $flow, int $position, float $base): UpsellFlowOffer
    {
        return UpsellFlowOffer::create([
            'flow_id' => $flow->id,
            'offer_product_gid' => 'gid://shopify/Product/77',
            'offer_variant_gid' => 'gid://shopify/ProductVariant/770',
            'offer_title' => 'Add-on '.$position,
            'base_price' => $base,
            'discount_type' => UpsellFlowOffer::DISCOUNT_NONE,
            'position' => $position,
        ]);
    }

    private function impression(UpsellFlowOffer $offer, string $orderId): void
    {
        UpsellOfferEvent::record([
            'flow_id' => $offer->flow_id,
            'offer_id' => $offer->id,
            'event_type' => OfferEventType::IMPRESSION,
            'parent_order_id' => $orderId,
            'currency' => 'ILS',
        ]);
    }

    private function request(UpsellFlow $flow, UpsellFlowOffer $offer): AcceptUpsellRequest
    {
        return new AcceptUpsellRequest($flow, $offer, self::ORDER, self::CUSTOMER);
    }

    private function openRow(Shop $shop, UpsellFlow $flow, UpsellFlowOffer $offer): PaymentLedger
    {
        return Ledger::open(
            shopId: (int) $shop->id,
            chargeContext: PaymentLedger::CONTEXT_UPSELL,
            idempotencyKey: IdempotencyKey::upsell((int) $shop->id, (int) $flow->id, (int) $offer->id, self::ORDER, self::CUSTOMER),
            amount: 50.0,
        );
    }

    private function service(): UpsellChargeService
    {
        return app(UpsellChargeService::class);
    }
}
