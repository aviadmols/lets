<?php

namespace App\Domain\Loyalty\Http\Controllers;

use App\Domain\Loyalty\Rendering\LoyaltyPagePresenter;
use App\Http\Controllers\Controller;
use App\Models\MerchantLoyaltySettings;
use App\Models\Shop;
use App\Support\Tenant;
use App\Support\Ui\PanelAccess;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The merchant's own view of the members page, inside the admin.
 *
 * It renders the REAL customer page — the same Blade, the same stylesheet — so
 * what the merchant tunes is what their shoppers will see, rather than an
 * approximation that drifts. What it does NOT do is act: the model comes from
 * LoyaltyPagePresenter::sample(), which invents the member and never mints a
 * referral code, so opening this tab cannot publish a discount to the store or
 * create a membership.
 *
 * Auth is the admin session (Filament's Authenticate middleware on the route)
 * plus the bound tenant — a merchant can only ever preview their own club.
 */
final class AdminLoyaltyPreviewController extends Controller
{
    public function __invoke(LoyaltyPagePresenter $presenter): View
    {
        abort_unless(PanelAccess::canSeeShopScoped(), Response::HTTP_FORBIDDEN);

        $shop = Tenant::current();
        abort_unless($shop instanceof Shop, Response::HTTP_FORBIDDEN);

        $settings = MerchantLoyaltySettings::current();
        app()->setLocale($settings->pageLocale());

        return view('storefront.loyalty.page', [
            'model' => $presenter->sample($settings),
            // No live actions in a preview: every button would have no member to
            // credit, so they are rendered inert rather than wired to a 401.
            'actions' => [],
            'preview' => true,
        ]);
    }
}
