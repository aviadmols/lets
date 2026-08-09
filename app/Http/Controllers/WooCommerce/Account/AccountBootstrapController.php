<?php

namespace App\Http\Controllers\WooCommerce\Account;

use App\Domain\Account\AccountPresenter;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/woocommerce/account/bootstrap
 *
 * The whole personal area as one JSON view-model. The plugin fetches this
 * server-side and hands it to `lets-account.js`, which renders it into the page —
 * not an iframe, so the area inherits the theme's typography, its focus order and
 * its responsive behaviour.
 *
 * POST rather than GET on purpose: the plugin's GET signer excludes the query
 * string, so identity travelling in a GET would be unsigned. The same reason
 * /orders/documents is a POST.
 */
final class AccountBootstrapController extends WooAccountController
{
    public function __invoke(Request $request, AccountPresenter $presenter): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return Tenant::run($shop, function () use ($request, $shop, $presenter): JsonResponse {
            $visitor = $this->visitor($request, $shop);

            $model = $this->inShopperLocale(
                static fn (): array => $presenter->present($visitor),
                $request,
            );

            return response()->json(['ok' => true, 'account' => $model]);
        });
    }
}
