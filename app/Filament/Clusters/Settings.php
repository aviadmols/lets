<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;
use Filament\Pages\SubNavigationPosition;

/**
 * Settings — ONE sidebar item that opens every merchant setting behind an
 * in-page left index (the approved Recharge sketch, 2026-09-27). Each settings
 * page joins with `protected static ?string $cluster = Settings::class;` and
 * keeps its URL: the cluster slug is `settings`, and the pages' own slugs
 * dropped their old `settings/` prefix, so /admin/settings/payplus is still
 * /admin/settings/payplus.
 *
 * Opening /admin/settings redirects to the first page the viewer can access
 * (Filament's Cluster::mount), so a Shopify-Payments shop with no PayPlus
 * screen lands on the next one. The item hides itself when no member page is
 * accessible — a platform admin who has not entered a shop sees none.
 */
class Settings extends Cluster
{
    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $slug = 'settings';

    /** Last in its group, which is the last group — the bottom of the sidebar. */
    protected static ?int $navigationSort = 100;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    /**
     * Not in the scrolling nav list: the sketch pins Settings to the FOOT of the
     * sidebar, above the store card, where it stays put however long the list
     * above it grows. filament/partials/sidebar-shop renders it there (through
     * showInSidebarFooter + getNavigationItems, so the active state is Filament's).
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /** The footer entry shows exactly when the nav item used to: any member page is accessible. */
    public static function showInSidebarFooter(): bool
    {
        return static::canAccessClusteredComponents();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('nav.settings');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __('nav.settings');
    }
}
