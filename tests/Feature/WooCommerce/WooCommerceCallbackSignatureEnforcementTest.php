<?php

namespace Tests\Feature\WooCommerce;

use App\Domain\Installments\DepositPlanService;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FOLLOW-UP 1 — mandatory-when-configured callback HMAC.
 *
 * config('woocommerce.require_callback_signature') defaults to FALSE (today's
 * optional-HMAC behaviour preserved). When the owner flips it to TRUE (after
 * confirming PayPlus signs WC callbacks against a real terminal), a callback that
 * LACKS a valid signature is rejected 401 and never processed; a correctly-signed
 * one still works; an empty per-shop secret → 503 (fail-closed). Covers BOTH the
 * deposit callback and the gateway ("mode B") callback.
 */
final class WooCommerceCallbackSignatureEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /** The per-shop PayPlus secret the callbacks verify the `hash` header against. */
    private const SECRET = 'sk-secret';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DEPOSIT callback
    // ─────────────────────────────────────────────────────────────────────────

    public function test_default_false_still_processes_an_unsigned_deposit_callback(): void
    {
        // No flag set → default FALSE → current behaviour: unsigned callback processed.
        [$shop, $token] = $this->shopWithToken('sig-default.example.com');
        $this->awaitingPlan($shop, 'PUB-SIG-DEF');

        $this->postJson('/woocommerce/deposit/callback/'.$token, [
            'transaction' => ['more_info' => 'PUB-SIG-DEF', 'status_code' => '000'],
        ])->assertOk()->assertJsonPath('activated', true);
    }

    public function test_required_signature_rejects_an_unsigned_deposit_callback_401(): void
    {
        config()->set('woocommerce.require_callback_signature', true);
        [$shop, $token] = $this->shopWithToken('sig-req.example.com');
        $this->awaitingPlan($shop, 'PUB-SIG-REQ');

        // NO `hash` header → 401, and the plan is NOT activated.
        $this->postJson('/woocommerce/deposit/callback/'.$token, [
            'transaction' => ['more_info' => 'PUB-SIG-REQ', 'status_code' => '000'],
        ])->assertStatus(401);

        $plan = Tenant::run($shop, fn (): ?InstallmentPlan => InstallmentPlan::query()->where('public_id', 'PUB-SIG-REQ')->first());
        $this->assertSame(PlanStatus::AWAITING_FIRST_PAYMENT, $plan->status);
    }

    public function test_required_signature_processes_a_correctly_signed_deposit_callback(): void
    {
        config()->set('woocommerce.require_callback_signature', true);
        [$shop, $token] = $this->shopWithToken('sig-ok.example.com');
        $this->awaitingPlan($shop, 'PUB-SIG-OK');

        $body = ['transaction' => ['more_info' => 'PUB-SIG-OK', 'status_code' => '000']];
        $raw = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $hash = base64_encode(hash_hmac('sha256', $raw, self::SECRET, true));

        $this->call('POST', '/woocommerce/deposit/callback/'.$token, [], [], [], [
            'HTTP_HASH' => $hash, 'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertOk()->assertJsonPath('activated', true);
    }

    public function test_required_signature_with_empty_secret_is_503(): void
    {
        config()->set('woocommerce.require_callback_signature', true);
        // Shop with NO PayPlus secret → cannot verify → 503 (fail-closed).
        $token = (string) Str::ulid();
        $shop = Shop::create([
            'woocommerce_domain' => 'sig-nosecret.example.com',
            'name' => 'NoSecret',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->wc_shop_token = $token;
        $shop->woocommerce_credentials = ['base_url' => 'https://sig-nosecret.example.com'];
        $shop->save();
        $this->awaitingPlan($shop->fresh(), 'PUB-SIG-NS');

        $this->postJson('/woocommerce/deposit/callback/'.$token, [
            'transaction' => ['more_info' => 'PUB-SIG-NS', 'status_code' => '000'],
        ])->assertStatus(503);
    }

    public function test_a_present_but_wrong_signature_is_401_even_when_not_required(): void
    {
        // Default FALSE: a present-but-WRONG signature still fails closed (unchanged).
        [$shop, $token] = $this->shopWithToken('sig-wrong.example.com');
        $this->awaitingPlan($shop, 'PUB-SIG-WRONG');

        $this->call('POST', '/woocommerce/deposit/callback/'.$token, [], [], [], [
            'HTTP_HASH' => 'not-the-real-hash', 'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['transaction' => ['more_info' => 'PUB-SIG-WRONG', 'status_code' => '000']]))
            ->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GATEWAY ("mode B") callback
    // ─────────────────────────────────────────────────────────────────────────

    public function test_required_signature_rejects_an_unsigned_gateway_callback_401(): void
    {
        config()->set('woocommerce.require_callback_signature', true);
        Http::fake();
        [$shop, $token] = $this->shopWithToken('gw-sig-req.example.com', connected: true);

        $this->postJson('/woocommerce/gateway/callback/'.$token, [
            'transaction' => ['more_info' => 'gw:4242', 'status_code' => '000'],
        ])->assertStatus(401);

        Http::assertNothingSent();
    }

    public function test_required_signature_processes_a_correctly_signed_gateway_callback(): void
    {
        config()->set('woocommerce.require_callback_signature', true);
        Http::fake(['*/wp-json/wc/v3/orders/4242' => Http::response(['id' => 4242, 'status' => 'processing'], 200)]);
        [$shop, $token] = $this->shopWithToken('gw-sig-ok.example.com', connected: true);

        $body = ['transaction' => ['more_info' => 'gw:4242', 'status_code' => '000']];
        $raw = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $hash = base64_encode(hash_hmac('sha256', $raw, self::SECRET, true));

        $this->call('POST', '/woocommerce/gateway/callback/'.$token, [], [], [], [
            'HTTP_HASH' => $hash, 'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertOk()->assertJsonPath('paid', true);
    }

    public function test_default_false_still_processes_an_unsigned_gateway_callback(): void
    {
        Http::fake(['*/wp-json/wc/v3/orders/4242' => Http::response(['id' => 4242, 'status' => 'processing'], 200)]);
        [$shop, $token] = $this->shopWithToken('gw-sig-def.example.com', connected: true);

        $this->postJson('/woocommerce/gateway/callback/'.$token, [
            'transaction' => ['more_info' => 'gw:4242', 'status_code' => '000'],
        ])->assertOk()->assertJsonPath('paid', true);
    }

    // === Helpers ===

    /** @return array{0:Shop,1:string} [shop, wc_shop_token] */
    private function shopWithToken(string $domain, bool $connected = false): array
    {
        $token = (string) Str::ulid();
        $shop = Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->wc_shop_token = $token;
        $creds = ['base_url' => 'https://'.$domain];
        if ($connected) {
            $creds = array_merge($creds, ['consumer_key' => 'ck', 'consumer_secret' => 'cs']);
        }
        $shop->woocommerce_credentials = $creds;
        // PayPlus secret present so the `hash` header can be verified.
        $shop->payplus_credentials = ['api_key' => 'pk', 'secret_key' => self::SECRET, 'terminal_uid' => 't', 'payment_page_uid' => 'pp'];
        $shop->save();

        return [$shop->fresh(), $token];
    }

    private function awaitingPlan(Shop $shop, string $publicId): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($shop, $publicId): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->fill([
                'plan_kind' => PlanKind::INSTALLMENTS->value,
                'charge_context' => 'deposit',
                'total_amount' => 400,
                'total_charged' => 0,
                'installment_amount' => 100,
                'currency' => 'ILS',
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'interval_count' => 1,
                'public_id' => $publicId,
                'meta' => [
                    DepositPlanService::META_DEPOSIT_AMOUNT => 100.0,
                    DepositPlanService::META_QUOTE => [
                        'schedule' => [['sequence' => 1, 'amount' => 100, 'due_at' => now()->addMonth()->toDateString()]],
                    ],
                ],
            ]);
            $plan->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'status' => PlanStatus::AWAITING_FIRST_PAYMENT->value,
            ])->save();

            return $plan->fresh();
        });
    }
}
