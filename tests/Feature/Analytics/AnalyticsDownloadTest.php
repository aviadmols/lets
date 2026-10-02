<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Support\AnalyticsDownload;
use App\Domain\Tenancy\ShopHosts;
use App\Filament\Pages\Analytics;
use App\Livewire\Analytics\ReportLibrary;
use App\Livewire\Analytics\RiskTable;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Analytics\Concerns\BuildsSubscriptions;
use Tests\Feature\Analytics\Concerns\FollowsAnalyticsDownloads;
use Tests\TestCase;

/**
 * Every Analytics CSV leaves through one signed, short-lived, tenant-bound GET
 * that streams: the shell's Export, the risk table's Export and the report
 * library's Run. The link is refused to another shop's user, after it
 * expires, when tampered with and when signed out; its cells are formula-safe.
 */
final class AnalyticsDownloadTest extends TestCase
{
    use BuildsSubscriptions;
    use FollowsAnalyticsDownloads;
    use RefreshDatabase;

    // === CONSTANTS ===
    private const FORMULA = '=HYPERLINK("http://evil.example","x")';

    private Shop $shop;

    private Shop $other;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-09-29 12:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-09-29 12:00:00'));
        $this->shop = $this->makeShop('a');
        $this->other = $this->makeShop('b');
        $this->user = User::factory()->forShop($this->shop)->create();
        $this->fixture($this->shop, 'Mine Customer');
        $this->fixture($this->other, 'Their Customer');
        Tenant::set($this->shop);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** One live plan at risk (short history) and one cancelled with a formula for a reason. */
    private function fixture(Shop $shop, string $name): void
    {
        $born = CarbonImmutable::now()->subDays(60);
        $this->plan($shop, $shop->id.'1', 50, createdAt: $born, attributes: ['customer_name' => '='.$name]);
        $gone = $this->plan($shop, $shop->id.'2', 30, status: 'cancelled', createdAt: $born, attributes: ['customer_name' => $name]);
        $event = new ActivityEvent();
        $event->forceFill([
            'shop_id' => $shop->getKey(), 'plan_id' => $gone->getKey(), 'payment_id' => null, 'actor' => 'admin:1',
            'kind' => 'status_changed',
            'details' => ['model' => 'InstallmentPlan', 'from' => 'active', 'to' => 'cancelled', 'reason' => self::FORMULA],
            'created_at' => CarbonImmutable::now()->subDays(2),
        ])->save();
    }

    private function shellLink(): string
    {
        return $this->downloadLink(Livewire::test(Analytics::class)->call('go', 'cancellations', 'overview')->call('export'));
    }

    public function test_the_shell_export_streams_the_screen_formula_safe(): void
    {
        $csv = $this->fetchCsv($this->shellLink());

        $this->assertStringContainsString("'".self::FORMULA, str_replace('""', '"', $csv), 'A formula is neutralised.');
        $this->assertStringContainsString('Mine Customer', $csv);
        $this->assertStringNotContainsString('Their Customer', $csv);
    }

    public function test_the_risk_table_and_report_library_stream_through_the_same_door(): void
    {
        // Mint every link first: a GET ends its request, which unbinds the tenant.
        $library = fn (string $report) => Livewire::test(ReportLibrary::class, ['from' => '2026-08-31', 'to' => '2026-09-29'])->call('run', $report);
        $riskUrl = $this->downloadLink(Livewire::test(RiskTable::class)->call('export'));
        $reportUrl = $this->downloadLink($library('cancellation_logs'));
        $subscriptionsUrl = $this->downloadLink($library('subscriptions'));
        $this->assertArrayNotHasKey('redirect', $library('bundle_orders')->effects, 'An untracked report mints no link.');

        $risk = $this->fetchCsv($riskUrl);
        $this->assertStringContainsString("'=Mine Customer", $risk);
        $this->assertStringNotContainsString('Their Customer', $risk);

        $report = $this->fetchCsv($reportUrl);
        $this->assertStringContainsString("'".self::FORMULA, str_replace('""', '"', $report));
        $this->assertStringNotContainsString('Their Customer', $report);

        $this->assertStringNotContainsString('Their Customer', $this->fetchCsv($subscriptionsUrl));
    }

    public function test_the_link_verifies_on_the_store_host_and_the_root_alike(): void
    {
        // One subdomain per shop: the signature is RELATIVE, so a link minted on
        // one host streams on another — and only ever for the user's own shop.
        config(['tenancy.subdomains_enabled' => true, 'tenancy.admin_root_host' => 'app.lets.co.il']);
        $url = $this->shellLink();

        $this->assertStringContainsString('Mine Customer', $this->fetchCsv('https://'.$this->shop->handle.'.app.lets.co.il'.$url));
        $this->assertStringContainsString('Mine Customer', $this->fetchCsv('https://app.lets.co.il'.$url));

        Tenant::clear();
        $this->get('https://'.$this->other->handle.'.app.lets.co.il'.$url)
            ->assertRedirect(ShopHosts::shopAdminUrl($this->shop->handle, ShopHosts::LOGIN_PATH));
        $this->assertGuest();
    }

    public function test_another_shops_user_cannot_use_the_link(): void
    {
        $url = $this->shellLink();

        $this->actingAs(User::factory()->forShop($this->other)->create());
        Tenant::clear();

        $this->get($url)->assertForbidden();
    }

    public function test_an_expired_link_is_refused(): void
    {
        $url = $this->shellLink();

        $this->travel(AnalyticsDownload::TTL_MINUTES + 1)->minutes();
        Tenant::clear();

        $this->get($url)->assertForbidden();
    }

    public function test_a_tampered_link_is_refused(): void
    {
        $url = $this->shellLink();
        Tenant::clear();

        $this->get(preg_replace('/shop=\d+/', 'shop='.$this->other->id, $url))->assertForbidden();
        $this->get(str_replace('kind=screen', 'kind=risk', $url))->assertForbidden();
    }

    public function test_signed_out_is_refused(): void
    {
        $url = $this->shellLink();
        Tenant::clear();
        auth()->logout();

        $response = $this->get($url);
        $this->assertContains($response->getStatusCode(), [302, 401, 403], 'No user, no file.');
        $this->assertStringNotContainsString('Mine Customer', (string) $response->getContent());
    }

    public function test_no_bound_shop_mints_no_link(): void
    {
        Tenant::clear();

        $this->assertNull(AnalyticsDownload::url(AnalyticsDownload::KIND_SCREEN, []));
        $this->assertNull(Tenant::run($this->shop, fn () => AnalyticsDownload::url('bogus', [])));
        $this->assertSame(['a' => ['b']], AnalyticsDownload::decode(AnalyticsDownload::encode(['a' => ['b']])));
        $this->assertSame([], AnalyticsDownload::decode('%%%not-base64'));
        $this->assertSame(0, InstallmentPlan::query()->count(), 'No tenant, no rows.');
    }
}
