<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;

/**
 * A screen that is registered but not built yet. Renders a neat "Coming in
 * this build" card naming the sketch board it will implement, so the shell,
 * the tabs and the URL work end to end before the screen exists.
 *
 * TO BUILD A SCREEN: replace the body of its stub class (e.g.
 * PaymentsOverview) so it extends AnalyticsScreen instead of this class —
 * the registry already points at it; no shared file needs editing.
 */
abstract class PlaceholderScreen extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const VIEW = 'filament.pages.analytics.placeholder';

    /** The spec.md heading this screen implements (e.g. "3.1 Payments — Overview"). */
    public const SPEC = '';

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        return [
            'sketch' => static::SKETCH,
            'spec' => static::SPEC,
        ];
    }

    public function isPlaceholder(): bool
    {
        return true;
    }
}
