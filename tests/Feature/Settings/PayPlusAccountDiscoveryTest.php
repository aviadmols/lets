<?php

namespace Tests\Feature\Settings;

use App\Filament\Pages\ManagePayPlusConnection;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusAccountDiscovery;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PayPlus auto-discovery (PayPlusAccountDiscovery) + the redesigned Connection
 * screen. Proves:
 *   - GET /MyTerminals parses a BARE array AND a {data:[...]} wrapped variant.
 *   - GET /PaymentPages/list parses pages with uid + name + cashier_uid.
 *   - any non-2xx / auth failure FAILS CLOSED → [] with a typed reason.
 *   - saving the discovered bag writes ONLY to the bound shop (tenant isolation).
 *
 * Every PayPlus call is Http::fake()d — no real network.
 */
final class PayPlusAccountDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const API_KEY = 'api-key-123';
    private const SECRET_KEY = 'secret-key-456';
    private const BASE_URL = 'https://restapi.payplus.co.il';
    private const TERMINAL_UID = 'term-uuid-aaa';
    private const PAGE_UID = 'page-uid-bbb';
    private const CASHIER_UID = 'cashier-uid-ccc';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === A. terminals() parsing ===

    public function test_terminals_parses_a_bare_array(): void
    {
        Http::fake([
            '*/MyTerminals*' => Http::response([
                ['uuid' => self::TERMINAL_UID, 'name_terminal' => 'Main terminal', 'status' => true],
                ['uuid' => 'term-2', 'name_terminal' => 'Backup', 'status' => false],
            ], 200),
        ]);

        $terminals = $this->discovery()->terminals();

        $this->assertCount(2, $terminals);
        $this->assertSame(self::TERMINAL_UID, $terminals[0]['uid']);
        $this->assertSame('Main terminal', $terminals[0]['name']);
        $this->assertTrue($terminals[0]['active']);
        $this->assertFalse($terminals[1]['active']);
    }

    public function test_terminals_parses_a_data_wrapped_array(): void
    {
        Http::fake([
            '*/MyTerminals*' => Http::response([
                'results' => ['status' => 'success'],
                'data' => [
                    ['uuid' => self::TERMINAL_UID, 'name_terminal' => 'Wrapped terminal', 'status' => 1],
                ],
            ], 200),
        ]);

        $terminals = $this->discovery()->terminals();

        $this->assertCount(1, $terminals);
        $this->assertSame(self::TERMINAL_UID, $terminals[0]['uid']);
        $this->assertSame('Wrapped terminal', $terminals[0]['name']);
        $this->assertTrue($terminals[0]['active']);
    }

    public function test_terminals_sends_payplus_auth_headers(): void
    {
        Http::fake(['*/MyTerminals*' => Http::response([], 200)]);

        $this->discovery()->terminals();

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('api-key', self::API_KEY)
                && $request->hasHeader('secret-key', self::SECRET_KEY)
                && str_contains($request->url(), '/api/v1.0/MyTerminals');
        });
    }

    // === B. paymentPages() parsing ===

    public function test_payment_pages_parses_uid_name_and_cashier(): void
    {
        Http::fake([
            '*/PaymentPages/list*' => Http::response([
                ['uid' => self::PAGE_UID, 'name' => 'Checkout page', 'cashier_uid' => self::CASHIER_UID, 'terminal_uid' => self::TERMINAL_UID],
            ], 200),
        ]);

        $pages = $this->discovery()->paymentPages(self::TERMINAL_UID);

        $this->assertCount(1, $pages);
        $this->assertSame(self::PAGE_UID, $pages[0]['uid']);
        $this->assertSame('Checkout page', $pages[0]['name']);
        $this->assertSame(self::CASHIER_UID, $pages[0]['cashier_uid']);
    }

    public function test_payment_pages_passes_terminal_uid_and_paging_query(): void
    {
        Http::fake(['*/PaymentPages/list*' => Http::response([], 200)]);

        $this->discovery()->paymentPages(self::TERMINAL_UID);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'terminal_uid='.self::TERMINAL_UID)
                && str_contains($request->url(), 'take=500');
        });
    }

    // === C. fail-closed contract ===

    public function test_auth_failure_fails_closed_with_typed_reason(): void
    {
        Http::fake(['*/MyTerminals*' => Http::response(['error' => 'unauthorized'], 401)]);

        $discovery = $this->discovery();
        $terminals = $discovery->terminals();

        $this->assertSame([], $terminals);
        $this->assertSame(PayPlusAccountDiscovery::REASON_AUTH, $discovery->lastReason);
    }

    public function test_server_error_fails_closed(): void
    {
        Http::fake(['*/MyTerminals*' => Http::response('boom', 500)]);

        $discovery = $this->discovery();

        $this->assertSame([], $discovery->terminals());
        $this->assertSame(PayPlusAccountDiscovery::REASON_TRANSPORT, $discovery->lastReason);
    }

    public function test_malformed_body_fails_closed(): void
    {
        // A 200 with an object that has no recognisable list wrapper.
        Http::fake(['*/MyTerminals*' => Http::response(['unexpected' => 'shape'], 200)]);

        $discovery = $this->discovery();

        $this->assertSame([], $discovery->terminals());
        $this->assertSame(PayPlusAccountDiscovery::REASON_MALFORMED, $discovery->lastReason);
    }

    // === D. Screen: discovered bag saves to the bound shop only (isolation) ===

    public function test_save_persists_discovered_bag_on_the_current_shop_only(): void
    {
        $shop = $this->makeShop('disco.myshopify.com');
        $other = $this->makeShop('other.myshopify.com');

        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());

        Livewire::test(ManagePayPlusConnection::class)
            ->set('data.api_key', self::API_KEY)
            ->set('data.secret_key', self::SECRET_KEY)
            ->set('data.base_url', self::BASE_URL)
            ->set('data.terminal_uid', self::TERMINAL_UID)
            ->set('data.payment_page_uid', self::PAGE_UID)
            ->set('data.cashier_uid', self::CASHIER_UID)
            ->call('save')
            ->assertHasNoErrors();

        // The bound shop received the full discovered bag (decrypted on read).
        $bag = $shop->fresh()->payplus_credentials;
        $this->assertSame(self::TERMINAL_UID, $bag['terminal_uid']);
        $this->assertSame(self::PAGE_UID, $bag['payment_page_uid']);
        $this->assertSame(self::CASHIER_UID, $bag['cashier_uid']);
        $this->assertSame(self::API_KEY, $bag['api_key']);
        $this->assertTrue($shop->fresh()->hasPayplusConnection());

        // The OTHER shop must remain untouched — no cross-tenant write.
        $this->assertSame([], $other->fresh()->payplus_credentials);
        $this->assertFalse($other->fresh()->hasPayplusConnection());
    }

    public function test_connect_action_populates_terminal_options_from_discovery(): void
    {
        Http::fake([
            '*/MyTerminals*' => Http::response([
                ['uuid' => self::TERMINAL_UID, 'name_terminal' => 'Sole terminal', 'status' => true],
            ], 200),
            '*/PaymentPages/list*' => Http::response([
                ['uid' => self::PAGE_UID, 'name' => 'Sole page', 'cashier_uid' => self::CASHIER_UID],
            ], 200),
        ]);

        $shop = $this->makeShop('connect.myshopify.com');
        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());

        $component = Livewire::test(ManagePayPlusConnection::class)
            ->set('data.api_key', self::API_KEY)
            ->set('data.secret_key', self::SECRET_KEY)
            ->set('data.base_url', self::BASE_URL)
            ->call('connect')
            ->assertHasNoErrors();

        // A sole terminal auto-selected → its sole page auto-selected → cashier set.
        $component
            ->assertSet('data.terminal_uid', self::TERMINAL_UID)
            ->assertSet('data.payment_page_uid', self::PAGE_UID)
            ->assertSet('data.cashier_uid', self::CASHIER_UID);
    }

    // === Helpers ===

    private function discovery(): PayPlusAccountDiscovery
    {
        return PayPlusAccountDiscovery::for(self::API_KEY, self::SECRET_KEY, self::BASE_URL);
    }

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
        ]);
    }
}
