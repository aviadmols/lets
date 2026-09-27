<?php

namespace Tests\Feature\Settings;

use App\Filament\Pages\ManagePayPlusConnection;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The PayPlus base URL is a CHOICE between PayPlus's own hosts, never a free
 * URL: it decides where the merchant's API secret is sent. Raw Livewire state is
 * client-writable, so the wall is on the server — at save, at connect, and where
 * the stored value is read.
 */
final class PayPlusBaseUrlWallTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const PRODUCTION = 'https://restapi.payplus.co.il';

    private const SANDBOX = 'https://restapidev.payplus.co.il';

    private const FOREIGN = 'http://10.0.0.5:8080';

    protected function setUp(): void
    {
        parent::setUp();
        config(['payplus.base_url' => self::PRODUCTION, 'payplus.base_url_sandbox' => self::SANDBOX]);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_tampered_base_url_is_refused_at_save_and_never_stored(): void
    {
        $shop = $this->boundShop();

        Livewire::test(ManagePayPlusConnection::class)
            ->set('data.api_key', 'k')
            ->set('data.secret_key', 's')
            ->set('data.base_url', self::FOREIGN)
            ->set('data.terminal_uid', 't')
            ->set('data.payment_page_uid', 'p')
            ->call('save')
            ->assertNotified(__('settings.payplus.base_url_refused'));

        $this->assertArrayNotHasKey('base_url', $shop->fresh()->payplus_credentials ?: []);
    }

    public function test_the_sandbox_host_is_still_a_legal_choice(): void
    {
        $shop = $this->boundShop();

        Livewire::test(ManagePayPlusConnection::class)
            ->set('data.api_key', 'k')
            ->set('data.secret_key', 's')
            ->set('data.base_url', self::SANDBOX)
            ->set('data.terminal_uid', 't')
            ->set('data.payment_page_uid', 'p')
            ->call('save');

        $this->assertSame(self::SANDBOX, $shop->fresh()->payplusConfig()['base_url']);
    }

    public function test_connect_never_calls_a_tampered_host(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->boundShop();

        Livewire::test(ManagePayPlusConnection::class)
            ->set('data.api_key', 'k')
            ->set('data.secret_key', 's')
            ->set('data.base_url', self::FOREIGN)
            ->call('connect');

        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), '10.0.0.5'));
        Http::assertSent(static fn ($request): bool => str_starts_with($request->url(), self::PRODUCTION));
    }

    public function test_a_stored_foreign_base_url_is_never_used(): void
    {
        $shop = $this->boundShop();
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'base_url' => self::FOREIGN];
        $shop->save();

        $this->assertSame(self::PRODUCTION, $shop->fresh()->payplusConfig()['base_url']);
    }

    // === Helpers ===

    private function boundShop(): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => 'wall-'.uniqid().'.myshopify.com',
            'name' => 'Wall',
            'status' => Shop::STATUS_ACTIVE,
        ]);

        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());

        return $shop;
    }
}
