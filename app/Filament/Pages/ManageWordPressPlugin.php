<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings as SettingsCluster;
use App\Models\Shop;
use App\Services\WooCommerce\WooConnectionTester;
use App\Support\Tenant;
use App\Support\Ui\PanelAccess;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Settings → WordPress plugin (WooCommerce shops). Where a merchant gets the
 * plugin themselves — inside the wp-admin embed too — instead of asking the
 * platform owner: the newest version, the one their store runs, and the
 * download button.
 *
 * The download is the public, secret-free package built from the in-repo source
 * (routes/web.php woocommerce.plugin.download), so this page mints nothing and
 * can never disturb the store's live connection token.
 *
 * The installed version is read from the store's own status route — a request
 * to the merchant's site — so it is asked AFTER the page renders (wire:init),
 * never holding the page hostage to a slow store.
 */
class ManageWordPressPlugin extends Page
{
    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static string $view = 'filament.pages.wordpress-plugin';

    protected static ?string $slug = 'wordpress-plugin';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 85;

    /** The plugin's main file — its `Version:` header is the newest release. */
    public const PLUGIN_MAIN_FILE = 'plugins/lets-payplus-woocommerce/lets-payplus-woocommerce.php';

    public const STATE_CHECKING = 'checking';

    public const STATE_CURRENT = 'current';

    public const STATE_OUTDATED = 'outdated';

    public const STATE_UNKNOWN = 'unknown';

    /** The version the store reported, or null (not asked yet / unreachable). */
    public ?string $installedVersion = null;

    public string $state = self::STATE_CHECKING;

    /** WooCommerce stores only — a Shopify shop has no plugin to install. */
    public static function canAccess(): bool
    {
        return PanelAccess::canSeeShopScoped()
            && PanelAccess::embeddedAllows(static::class)
            && Tenant::current()?->platform === Shop::PLATFORM_WOOCOMMERCE;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationGroup(): ?string
    {
        return null; // the cluster index is flat
    }

    public static function getNavigationLabel(): string
    {
        return __('wordpress_plugin.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('wordpress_plugin.title');
    }

    /** The newest release, read from the plugin source this server ships. */
    public static function latestVersion(): ?string
    {
        $path = base_path(self::PLUGIN_MAIN_FILE);
        if (! is_file($path)) {
            return null;
        }

        $head = (string) file_get_contents($path, false, null, 0, 4096);

        return preg_match('/^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)/m', $head, $m) === 1 ? $m[1] : null;
    }

    public function downloadUrl(): string
    {
        return route('woocommerce.plugin.download');
    }

    /** wire:init — ask the store which version it runs. */
    public function checkInstalled(): void
    {
        $shop = Tenant::current();
        $this->installedVersion = $shop instanceof Shop
            ? app(WooConnectionTester::class)->installedPluginVersion($shop)
            : null;

        $latest = self::latestVersion();
        $this->state = match (true) {
            $this->installedVersion === null || $latest === null => self::STATE_UNKNOWN,
            version_compare($this->installedVersion, $latest, '>=') => self::STATE_CURRENT,
            default => self::STATE_OUTDATED,
        };
    }
}
