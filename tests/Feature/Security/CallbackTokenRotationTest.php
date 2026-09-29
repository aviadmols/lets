<?php

namespace Tests\Feature\Security;

use App\Console\Commands\RotateCallbackTokens;
use App\Domain\Security\CallbackTokenRotator;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `security:rotate-callback-tokens` — a shop's PayPlus callback token can be
 * rotated without breaking a hosted page PayPlus already holds a link for. The
 * deposit callback route (`/woocommerce/deposit/callback/{wc_shop_token}`) is
 * used as the concrete resolution surface under test; WooGatewayCallbackController
 * and WooCardUpdateCallbackController share the exact same
 * Shop::resolveByCallbackToken() seam.
 */
final class CallbackTokenRotationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_old_token_keeps_resolving_inside_the_grace_window(): void
    {
        $shop = $this->shop();
        $oldToken = (string) $shop->callbackToken();

        app(CallbackTokenRotator::class)->rotate($shop, graceDays: 14);
        $shop->refresh();

        $this->assertNotSame($oldToken, $shop->callback_token);

        // The OLD link PayPlus (or an inbox) is still holding must not 404.
        $response = $this->postJson('/woocommerce/deposit/callback/'.$oldToken, []);
        $response->assertOk();
    }

    public function test_the_old_token_stops_resolving_once_the_grace_window_elapses(): void
    {
        $shop = $this->shop();
        $oldToken = (string) $shop->callbackToken();

        app(CallbackTokenRotator::class)->rotate($shop, graceDays: 14);

        Carbon::setTestNow(now()->addDays(15));
        try {
            $response = $this->postJson('/woocommerce/deposit/callback/'.$oldToken, []);
            $response->assertNotFound();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_the_new_token_resolves_immediately(): void
    {
        $shop = $this->shop();

        $new = app(CallbackTokenRotator::class)->rotate($shop, graceDays: 14);

        $this->postJson('/woocommerce/deposit/callback/'.$new, [])->assertOk();
    }

    public function test_a_stranger_token_never_resolves(): void
    {
        $this->shop();

        $this->postJson('/woocommerce/deposit/callback/'.Str::random(40), [])->assertNotFound();
    }

    public function test_dry_run_writes_nothing(): void
    {
        $shop = $this->shop();
        $before = $shop->only(['callback_token', 'wc_shop_token', 'previous_callback_token', 'previous_callback_token_expires_at']);

        $this->artisan(RotateCallbackTokens::class, ['--shop' => $shop->getKey(), '--dry-run' => true])
            ->assertSuccessful();

        $shop->refresh();
        $this->assertSame($before, $shop->only(['callback_token', 'wc_shop_token', 'previous_callback_token', 'previous_callback_token_expires_at']));
    }

    public function test_rotating_twice_is_safe_and_each_rotation_supersedes_the_last_grace_token(): void
    {
        $shop = $this->shop();
        $original = (string) $shop->callbackToken();

        app(CallbackTokenRotator::class)->rotate($shop, graceDays: 14);
        $shop->refresh();
        $afterFirst = $shop->callback_token;

        app(CallbackTokenRotator::class)->rotate($shop, graceDays: 14);
        $shop->refresh();

        // The token from BEFORE the first rotation is two rotations back — only
        // one previous token is kept, so it no longer resolves.
        $this->postJson('/woocommerce/deposit/callback/'.$original, [])->assertNotFound();

        // The token from between the two rotations is still inside its grace window.
        $this->postJson('/woocommerce/deposit/callback/'.$afterFirst, [])->assertOk();

        // The current token resolves too.
        $this->postJson('/woocommerce/deposit/callback/'.$shop->callback_token, [])->assertOk();
    }

    public function test_the_command_requires_exactly_one_of_shop_or_all(): void
    {
        $this->artisan(RotateCallbackTokens::class, [])->assertFailed();

        $shop = $this->shop();
        $this->artisan(RotateCallbackTokens::class, ['--shop' => $shop->getKey(), '--all' => true])->assertFailed();
    }

    public function test_all_rotates_every_shop(): void
    {
        $a = $this->shop('shop-a.example.com');
        $b = $this->shop('shop-b.example.com');
        $tokenA = (string) $a->callbackToken();
        $tokenB = (string) $b->callbackToken();

        $this->artisan(RotateCallbackTokens::class, ['--all' => true])->assertSuccessful();

        $a->refresh();
        $b->refresh();
        $this->assertNotSame($tokenA, $a->callback_token);
        $this->assertNotSame($tokenB, $b->callback_token);
    }

    // === Fixtures ===

    private function shop(string $domain = 'rotate.example.com'): Shop
    {
        $token = (string) Str::ulid();
        $shop = Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->wc_shop_token = $token;
        $shop->woocommerce_credentials = ['base_url' => 'https://'.$domain];
        $shop->payplus_credentials = ['api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't', 'payment_page_uid' => 'pp'];
        $shop->save();

        return $shop->fresh();
    }
}
