<?php

namespace Tests\Feature;

use App\Support\Ui\Money;
use App\Support\Ui\StatusBadge;
use Tests\TestCase;

/**
 * Smoke tests for the admin design system layer (Phase 5). Proves the panel +
 * resources register, the status→tone map covers every canonical state-machine
 * value, the lang catalogs mirror EN↔HE, and the theme asset is published.
 */
class AdminDesignSystemTest extends TestCase
{
    public function test_admin_panel_routes_are_registered(): void
    {
        $names = collect(app('router')->getRoutes())->map->getName()->filter()->all();

        $this->assertContains('filament.admin.resources.subscriptions.index', $names);
        $this->assertContains('filament.admin.resources.subscriptions.view', $names);
        $this->assertContains('filament.admin.resources.payments.index', $names);
        $this->assertContains('filament.admin.pages.customers', $names);
        $this->assertContains('filament.admin.pages.settings.payplus', $names);
    }

    public function test_status_badge_map_covers_every_canonical_status(): void
    {
        // Canonical PlanStatus + PaymentLedgerStatus values (ARCHITECTURE.md §3.3).
        $canonical = [
            'draft', 'awaiting_first_payment', 'active', 'paused', 'completed', 'failed', 'cancelled',
            'pending', 'succeeded', 'refunded', 'retry_scheduled',
        ];

        foreach ($canonical as $status) {
            $this->assertTrue(StatusBadge::isKnown($status), "Status [$status] missing from StatusBadge::TONES");
            $this->assertContains(StatusBadge::tone($status), ['green', 'gray', 'teal', 'red', 'amber']);
        }
    }

    public function test_unknown_status_falls_back_without_throwing(): void
    {
        $this->assertSame(StatusBadge::FALLBACK_TONE, StatusBadge::tone('not_a_real_status'));
        $this->assertFalse(StatusBadge::isKnown('not_a_real_status'));
    }

    public function test_lang_catalogs_mirror_between_en_and_he(): void
    {
        $files = ['billing', 'common', 'nav', 'dashboard', 'states', 'timeline', 'subscriptions', 'customers', 'settings'];

        foreach ($files as $file) {
            $en = $this->flatten(require base_path("lang/en/$file.php"));
            $he = $this->flatten(require base_path("lang/he/$file.php"));

            $this->assertSame([], array_values(array_diff($en, $he)), "lang/he/$file.php is missing keys present in EN");
            $this->assertSame([], array_values(array_diff($he, $en)), "lang/he/$file.php has keys absent from EN");
        }
    }

    public function test_money_formatter_returns_a_currency_string(): void
    {
        $this->assertIsString(Money::format(1200));
        $this->assertStringContainsString('1', Money::format(1200));
    }

    public function test_theme_asset_is_published_and_token_pure(): void
    {
        $path = public_path('css/rc-admin.css');
        $this->assertFileExists($path, 'Run `npm run build` to publish the theme asset.');

        $css = file_get_contents($path);
        $this->assertStringContainsString('--rc-blue: #3B5BDB', $css);
    }

    /** @return list<string> dot-flattened key paths */
    private function flatten(array $arr, string $prefix = ''): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $key = $prefix === '' ? (string) $k : "$prefix.$k";
            $out = is_array($v) ? array_merge($out, $this->flatten($v, $key)) : array_merge($out, [$key]);
        }

        return $out;
    }
}
