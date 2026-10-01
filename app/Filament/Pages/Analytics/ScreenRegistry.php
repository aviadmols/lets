<?php

namespace App\Filament\Pages\Analytics;

use App\Filament\Pages\Analytics\Screens;
use App\Filament\Pages\Analytics\Screens\AnalyticsScreen;

/**
 * THE one map of the Analytics module: section → sub-tab → screen class.
 *
 * Every screen of the approved sketch is registered here already, pointing at
 * its own class file (a placeholder until somebody builds it). Building a
 * screen means rewriting THAT class file — never this map — so several people
 * can build screens in parallel without touching the same file.
 *
 * Order here is tab order. Labels: analytics.sections.<section> and
 * analytics.subtabs.<section>.<sub>. A section with one sub-tab (Cohorts,
 * Forecast) shows no pill group.
 */
final class ScreenRegistry
{
    // === CONSTANTS ===
    public const SECTIONS = [
        'subscribers' => [
            'overview' => Screens\SubscribersOverview::class,
            'acquisition' => Screens\SubscribersAcquisition::class,
            'order_funnel' => Screens\SubscribersOrderFunnel::class,
            'revenue' => Screens\SubscribersRevenue::class,
            'lifetime_value' => Screens\SubscribersLifetimeValue::class,
        ],
        'cohorts' => [
            'overview' => Screens\Cohorts::class,
        ],
        'payments' => [
            'overview' => Screens\PaymentsOverview::class,
            'recovery' => Screens\PaymentsRecovery::class,
            'failures' => Screens\PaymentsFailures::class,
            'upcoming' => Screens\PaymentsUpcoming::class,
        ],
        'products' => [
            'overview' => Screens\ProductsOverview::class,
            'revenue' => Screens\ProductsRevenue::class,
            'churn' => Screens\ProductsChurn::class,
        ],
        'upsells' => [
            'added' => Screens\UpsellsAdded::class,
            'sold' => Screens\UpsellsSold::class,
        ],
        'cancellations' => [
            'overview' => Screens\CancellationsOverview::class,
            'saves' => Screens\CancellationsSaves::class,
            'order_wise' => Screens\CancellationsOrderWise::class,
            'risk' => Screens\CancellationsRisk::class,
        ],
        'forecast' => [
            'overview' => Screens\Forecast::class,
        ],
        'reports' => [
            'reports' => Screens\ReportsLibrary::class,
            'exports' => Screens\ReportsExports::class,
        ],
    ];

    public const DEFAULT_SECTION = 'subscribers';

    /** @return list<string> */
    public static function sections(): array
    {
        return array_keys(self::SECTIONS);
    }

    /** @return list<string> */
    public static function subtabs(string $section): array
    {
        return array_keys(self::SECTIONS[$section] ?? []);
    }

    public static function hasSubtabs(string $section): bool
    {
        return count(self::SECTIONS[$section] ?? []) > 1;
    }

    /** A known section, or the default one. */
    public static function section(?string $section): string
    {
        return array_key_exists((string) $section, self::SECTIONS) ? (string) $section : self::DEFAULT_SECTION;
    }

    /** A known sub-tab of $section, or its first. */
    public static function subtab(string $section, ?string $sub): string
    {
        $subs = self::SECTIONS[self::section($section)];

        return array_key_exists((string) $sub, $subs) ? (string) $sub : (string) array_key_first($subs);
    }

    public static function screen(string $section, string $sub): AnalyticsScreen
    {
        $section = self::section($section);
        $class = self::SECTIONS[$section][self::subtab($section, $sub)];

        return app($class);
    }
}
