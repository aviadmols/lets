<?php

namespace Tests\Feature\Admin;

use App\Filament\Clusters\Settings;
use App\Filament\Pages\HomeDashboard;
use App\Livewire\TopbarAlerts;
use App\Models\IssuedDocument;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The approved sketch's shell: sidebar groups in the same order in both
 * languages, Settings pinned at the foot, and the bell + Home strip reading the
 * same counts as the sidebar badges.
 */
final class AdminShellTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const GROUP_KEYS = ['nav.group.customers', 'nav.group.products', 'nav.group.payments', 'nav.group.upsell'];

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'shell.example.com',
            'name' => 'Shell',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    /** One locale per test: Filament builds the navigation once per app instance. */
    public static function locales(): array
    {
        return ['english' => ['en'], 'hebrew' => ['he']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function test_the_sidebar_groups_keep_their_order_in_every_language(string $locale): void
    {
        $html = $this->get('/admin?locale='.$locale)->assertOk()->getContent();

        preg_match_all('/fi-sidebar-group-label[^>]*>\s*(.*?)\s*</su', $html, $m);
        $rendered = array_map(fn (string $label): string => html_entity_decode($label), $m[1]);

        $this->assertSame(
            array_map(fn (string $key): string => __($key, [], $locale), self::GROUP_KEYS),
            $rendered,
            "the sidebar groups are out of order in {$locale}",
        );
    }

    public function test_settings_is_pinned_at_the_foot_not_in_the_list(): void
    {
        $this->assertFalse(Settings::shouldRegisterNavigation());

        $html = $this->get('/admin')->assertOk()->getContent();

        $foot = mb_strpos($html, 'rc-sidebar-foot');
        $this->assertNotFalse($foot);
        $this->assertStringContainsString(__('nav.settings'), mb_substr($html, $foot, 4000));
    }

    public function test_the_bell_and_the_home_strip_count_documents_needing_attention(): void
    {
        $this->document(IssuedDocument::STATUS_FAILED);

        $alerts = Livewire::test(TopbarAlerts::class)->instance()->alerts();
        $this->assertCount(1, $alerts);
        $this->assertSame('1', $alerts[0]['count']);

        $attention = Livewire::test(HomeDashboard::class)->instance()->attention();
        $this->assertSame('1', $attention['invoices']);
        $this->assertNull($attention['charges']);
    }

    public function test_nothing_waiting_means_no_strip_and_a_quiet_bell(): void
    {
        $this->assertSame([], Livewire::test(TopbarAlerts::class)->instance()->alerts());
        $this->assertNull(Livewire::test(HomeDashboard::class)->instance()->attention());
    }

    public function test_the_bell_is_silent_without_a_bound_shop(): void
    {
        $this->document(IssuedDocument::STATUS_FAILED);
        Tenant::clear();

        $this->assertSame([], (new TopbarAlerts)->alerts());
    }

    private function document(string $status): void
    {
        (new IssuedDocument)->forceFill([
            'shop_id' => $this->shop->getKey(),
            'provider' => 'greeninvoice',
            'context' => 'deposit',
            'idempotency_key' => 'shell-test:'.$status,
            'status' => $status,
        ])->save();
    }
}
