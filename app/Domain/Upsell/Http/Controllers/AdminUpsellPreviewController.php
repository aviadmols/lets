<?php

namespace App\Domain\Upsell\Http\Controllers;

use App\Domain\Upsell\Http\Controllers\PostPurchaseController;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Rendering\PostPurchasePresenter;
use App\Domain\Upsell\Rendering\UpsellCardPresenter;
use App\Http\Controllers\Controller;
use App\Models\MerchantUpsellAppearance;
use App\Support\Tenant;
use Illuminate\Http\Response;

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
    public function __construct(
        private readonly UpsellCardPresenter $presenter,
        private readonly PostPurchasePresenter $postPurchase,
    ) {}

    public function __invoke(string $platform, int $offer): Response
    {
        abort_unless(Tenant::check(), 403);

        $appearance = MerchantUpsellAppearance::current();

        $viewModel = $offer > 0
            ? $this->presenter->forOffer($this->offer($offer), $appearance, $platform)
            : $this->presenter->sample($appearance, $platform);

        // Rendered NOW, in the card's language: the page's lang/dir follow it, so a Hebrew card
        // previews right-to-left for an admin working in English, as the shopper will see it.
        return $appearance->inCardLocale(function () use ($viewModel, $appearance, $platform): Response {
            // Shopify's post-purchase page is drawn from SHOPIFY'S components, not our
            // markup, so previewing it with the storefront card would show a surface
            // the shopper never sees. It gets its own view, built from the SAME
            // contract the extension consumes — which is what keeps it faithful.
            if ($platform === PostPurchaseController::PLATFORM) {
                return response(view('upsell.preview-post-purchase', [
                    'viewModel' => $viewModel,
                    'presentation' => $this->postPurchase->present($viewModel, $appearance),
                    'platform' => $platform,
                ])->render());
            }

            return response(view('upsell.preview', [
                'viewModel' => $viewModel,
                'platform' => $platform,
            ])->render());
        });
    }

    /** Tenant-scoped offer, or 404 (global scope resolves a foreign id to not-found). */
    private function offer(int $offer): UpsellFlowOffer
    {
        return UpsellFlowOffer::query()->with('flow')->findOrFail($offer);
    }
}
