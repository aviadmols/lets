<?php

namespace App\Domain\Upsell\Http\Controllers;

use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Rendering\UpsellCardPresenter;
use App\Http\Controllers\Controller;
use App\Models\MerchantUpsellAppearance;
use App\Support\Tenant;
use Illuminate\Contracts\View\View;

/**
 * Filament-authenticated, tenant-scoped PREVIEW of the post-purchase card (Phase 3) — the real
 * "View post-purchase" button, and the live-preview iframe on the Appearance page.
 *
 * Registered INSIDE the admin panel (AdminPanelProvider->routes) so it inherits the panel's
 * persistent middleware (web session + BindTenantFromUser + SetAdminLocale) PLUS an explicit
 * Authenticate on the route. It renders the SAME shared card the storefront renders, from the
 * SAME presenter + CSS + JS, so the preview is faithful by construction.
 *
 * SAFETY:
 *   - Tenant: abort_unless(Tenant::check()) (a platform admin in platform mode can't preview), and
 *     the offer is loaded through the BelongsToShop global scope — a foreign/absent id 404s. NO
 *     withoutGlobalScopes() (unlike the deleted DevPreviewUpsellController), so it is strictly
 *     safer than the dev route it replaces.
 *   - Money: the price is server-computed (discountedPrice); the preview's handlers are INERT
 *     (LetsUpsell.previewHandlers) — no accept path exists, no ledger row, no funnel event.
 *   - offer=0 → a fixed labelled SAMPLE so the Appearance page never renders empty.
 */
final class AdminUpsellPreviewController extends Controller
{
    public function __construct(private readonly UpsellCardPresenter $presenter) {}

    public function __invoke(string $platform, int $offer): View
    {
        abort_unless(Tenant::check(), 403);

        $appearance = MerchantUpsellAppearance::current();

        $viewModel = $offer > 0
            ? $this->presenter->forOffer($this->offer($offer), $appearance, $platform)
            : $this->presenter->sample($appearance, $platform);

        return view('upsell.preview', [
            'viewModel' => $viewModel,
            'platform' => $platform,
        ]);
    }

    /** Tenant-scoped offer, or 404 (global scope resolves a foreign id to not-found). */
    private function offer(int $offer): UpsellFlowOffer
    {
        return UpsellFlowOffer::query()->with('flow')->findOrFail($offer);
    }
}
