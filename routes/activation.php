<?php

use App\Domain\Installments\Http\PlanActivationController;
use App\Domain\Installments\InstallmentsServiceProvider;
use App\Domain\Installments\PlanActivation;
use App\Domain\ShopifySubscriptions\ContractActivation;
use App\Domain\ShopifySubscriptions\Http\ContractActivationController;
use Illuminate\Support\Facades\Route;

/*
 * "Start my subscription" — the link a customer gets for a subscription that waits for
 * them (a product plan with requires_activation).
 *
 * `signed`: the URL carries a signature over the plan's public id and nonce, so it cannot
 * be forged or edited. GET shows a page; only the POST activates. Rate-limited with the
 * same limiter as the card-update landing — the two pages face the same traffic.
 */
Route::prefix('c/start')
    ->middleware(['web', 'signed', 'throttle:'.InstallmentsServiceProvider::LIMITER_LANDING])
    ->group(function (): void {
        Route::get('/{plan}/{nonce}', [PlanActivationController::class, 'show'])
            ->where(['plan' => '[A-Za-z0-9._\-]{1,64}', 'nonce' => '[A-Za-z0-9]{'.PlanActivation::NONCE_LENGTH.'}'])
            ->name(PlanActivation::ROUTE_SHOW);

        Route::post('/{plan}/{nonce}', [PlanActivationController::class, 'activate'])
            ->where(['plan' => '[A-Za-z0-9._\-]{1,64}', 'nonce' => '[A-Za-z0-9]{'.PlanActivation::NONCE_LENGTH.'}'])
            ->name(PlanActivation::ROUTE_ACTIVATE);

        // The same link for a subscription Shopify Payments bills (ContractActivation).
        // One segment more than the plan's, so the two patterns can never match each other.
        Route::get('/s/{contract}/{nonce}', [ContractActivationController::class, 'show'])
            ->where(['contract' => '[0-9]{1,19}', 'nonce' => '[A-Za-z0-9]{'.PlanActivation::NONCE_LENGTH.'}'])
            ->name(ContractActivation::ROUTE_SHOW);

        Route::post('/s/{contract}/{nonce}', [ContractActivationController::class, 'activate'])
            ->where(['contract' => '[0-9]{1,19}', 'nonce' => '[A-Za-z0-9]{'.PlanActivation::NONCE_LENGTH.'}'])
            ->name(ContractActivation::ROUTE_ACTIVATE);
    });
