<?php

namespace Tests\Feature\Mail;

use App\Domain\Brand\SafeSiteFetcher;
use App\Filament\Pages\ManageMailSettings;
use App\Mail\Support\MailTransport;
use App\Mail\Support\TemplateRenderer;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\PublicDnsSafeSiteFetcher;
use Tests\TestCase;

/**
 * The merchant's own SMTP relay is a merchant-typed host our workers connect
 * to, so it passes the same address walls as every other merchant-steered
 * outbound host; and merchant-edited HTML bodies escape the customer data they
 * are filled with.
 */
final class MerchantRelayWallTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(SafeSiteFetcher::class, static fn () => new PublicDnsSafeSiteFetcher([
            'redis.railway.internal' => ['10.0.0.7'],
        ]));

        $this->shop = Shop::create([
            'woocommerce_domain' => 'relay-wall.example.com',
            'name' => 'Relay Wall',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === The ladder never connects inward ===

    public function test_a_relay_resolving_inside_is_never_the_transport(): void
    {
        $this->relay('redis.railway.internal', 6379);

        $chosen = MailTransport::for($this->shop);

        $this->assertNotSame('redis.railway.internal', $chosen['config']['host'] ?? null);
    }

    public function test_the_metadata_address_and_odd_ports_are_refused(): void
    {
        $this->assertNotNull(MailTransport::merchantRelayRefusal($this->relay('169.254.169.254', 587)));
        $this->assertNotNull(MailTransport::merchantRelayRefusal($this->relay('smtp.example.com', 6379)));
    }

    public function test_a_public_relay_on_a_mail_port_is_still_used(): void
    {
        $this->relay('smtp.example.com', 465);

        $this->assertSame('smtp.example.com', MailTransport::for($this->shop)['config']['host']);
    }

    // === The screen refuses it at save ===

    public function test_the_screen_refuses_a_private_relay_at_save(): void
    {
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(ManageMailSettings::class)
            ->set('data.override_env_smtp', true)
            ->set('data.smtp_host', 'redis.railway.internal')
            ->set('data.smtp_port', 587)
            ->call('save')
            ->assertHasErrors(['data.smtp_host']);

        $this->assertNull(MerchantMailSettings::current()->fresh()->smtp_host);
    }

    public function test_the_screen_refuses_a_non_mail_port(): void
    {
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(ManageMailSettings::class)
            ->set('data.override_env_smtp', true)
            ->set('data.smtp_host', 'smtp.example.com')
            ->set('data.smtp_port', 6379)
            ->call('save')
            ->assertHasErrors(['data.smtp_port']);
    }

    // === HTML bodies escape the data they are filled with ===

    public function test_html_bodies_escape_customer_values_but_keep_our_own_markup(): void
    {
        $html = TemplateRenderer::renderHtml('<p>Hi {customer_name}</p>{items_table}', [
            'customer_name' => '<a href="https://evil.example">Pay here</a>',
            'items_table' => '<table><tr><td>ok</td></tr></table>',
        ]);

        $this->assertStringNotContainsString('<a href', $html);
        $this->assertStringContainsString('&lt;a href=&quot;https://evil.example&quot;&gt;', $html);
        $this->assertStringContainsString('<table><tr><td>ok</td></tr></table>', $html);

        // The subject / text path is not HTML and stays byte-for-byte.
        $this->assertSame('Hi <b>', TemplateRenderer::render('Hi {x}', ['x' => '<b>']));
    }

    // === Helpers ===

    private function relay(string $host, int $port): MerchantMailSettings
    {
        $settings = MerchantMailSettings::current();
        $settings->override_env_smtp = true;
        $settings->smtp_host = $host;
        $settings->smtp_port = $port;
        $settings->save();

        return $settings;
    }
}
