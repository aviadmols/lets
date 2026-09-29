<?php

namespace App\Livewire;

use App\Filament\Pages\PaymentRecovery;
use App\Filament\Resources\IssuedDocumentResource;
use App\Support\Tenant;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The bell in the top bar. It rings for the two things the engine deliberately
 * leaves to a human, and nothing else: subscriptions whose charges could not be
 * collected (PaymentRecovery) and accounting documents that need attention
 * (IssuedDocumentResource). The counts are the SAME ones the sidebar badges
 * show — read from those screens, never recomputed here — so the bell and the
 * sidebar cannot disagree.
 *
 * The top bar's end is persisted across SPA navigation, so the component
 * re-reads its counts whenever the page changes (see the view's
 * livewire:navigated listener) instead of showing the count from the first load.
 */
class TopbarAlerts extends Component
{
    // === CONSTANTS ===
    public const VIEW = 'filament.partials.topbar-alerts';

    /**
     * @return list<array{label:string, count:string, url:string, tone:string}>
     */
    public function alerts(): array
    {
        if (! Tenant::check()) {
            return [];
        }

        $alerts = [];

        if (PaymentRecovery::canAccess() && ($count = PaymentRecovery::getNavigationBadge()) !== null) {
            $alerts[] = [
                'label' => __('nav.alerts.failed_charges'),
                'count' => $count,
                'url' => PaymentRecovery::getUrl(),
                'tone' => 'danger',
            ];
        }

        if (IssuedDocumentResource::canAccess() && ($count = IssuedDocumentResource::getNavigationBadge()) !== null) {
            $alerts[] = [
                'label' => __('nav.alerts.invoices'),
                'count' => $count,
                'url' => IssuedDocumentResource::getUrl(),
                'tone' => 'warning',
            ];
        }

        return $alerts;
    }

    public function render(): View
    {
        return view(self::VIEW, ['alerts' => $this->alerts()]);
    }
}
