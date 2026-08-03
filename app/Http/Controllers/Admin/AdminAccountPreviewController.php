<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\AccountPresenter;
use App\Models\MerchantPortalAppearance;
use App\Support\Tenant;
use App\Support\Ui\PanelAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The personal area, rendered for the merchant's own preview iframe.
 *
 * It loads the SAME public/account/lets-account.{css,js} the storefront runs, with
 * AccountPresenter::sample() behind it — so what the merchant tunes is what their
 * shoppers get, and a divergence between the two is impossible rather than merely
 * unlikely.
 *
 * Nothing here reads a shopper's data. sample() invents a subscriber; only the
 * SETTINGS are real, because those are what is being previewed.
 *
 * Registered on the Filament panel's own ->routes() so it inherits the session,
 * BindTenantFromUser and SetAdminLocale — the same wiring the loyalty preview uses.
 */
final class AdminAccountPreviewController
{
    public function __invoke(Request $request, AccountPresenter $presenter): View
    {
        if (! PanelAccess::canSeeShopScoped() || ! Tenant::check()) {
            throw new NotFoundHttpException;
        }

        return view('account.preview', [
            'model' => $presenter->sample(MerchantPortalAppearance::current()),
        ]);
    }
}
