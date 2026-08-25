<?php

use App\Domain\Campaigns\Email\CampaignLoginLinks;
use App\Domain\Campaigns\Email\CampaignsEmailServiceProvider;
use App\Domain\Campaigns\Email\CampaignUnsubscribeLinks;
use App\Domain\Campaigns\Email\Http\CampaignLoginController;
use App\Domain\Campaigns\Email\Http\CampaignUnsubscribeController;
use App\Domain\Campaigns\Email\Http\HostedAccountController;
use App\Domain\Campaigns\Email\Http\Middleware\CampaignPublicHeaders;
use App\Domain\Campaigns\Email\Http\Middleware\RequireHostedAccountSession;
use Illuminate\Support\Facades\Route;

/*
 * The PUBLIC side of email campaigns — what a shopper reaches from the email.
 *
 *   /c/login/{token}        the passwordless "enter my account" landing
 *   /c/unsubscribe/{id}     the signed unsubscribe page (+ RFC 8058 one-click)
 *   /c/account              the SaaS-hosted personal area (Shopify landing)
 *
 * Mounted in the `web` group (session + CSRF for the POST buttons). The token
 * and the URL signature are the auth; no admin session is ever involved. Every
 * response carries CampaignPublicHeaders (no referrer, no store, no index).
 */

Route::prefix('c')->middleware([CampaignPublicHeaders::class])->group(function (): void {

    Route::middleware(['throttle:'.CampaignsEmailServiceProvider::LIMITER_LOGIN])->group(function (): void {
        Route::get('/login/{token}', [CampaignLoginController::class, 'show'])
            ->where('token', '[A-Za-z0-9]{32,128}')
            ->name(CampaignLoginLinks::ROUTE_SHOW);

        Route::post('/login/{token}', [CampaignLoginController::class, 'consume'])
            ->where('token', '[A-Za-z0-9]{32,128}')
            ->name(CampaignLoginLinks::ROUTE_CONSUME);
    });

    Route::middleware(['signed', 'throttle:'.CampaignsEmailServiceProvider::LIMITER_UNSUBSCRIBE])->group(function (): void {
        Route::get('/unsubscribe/{recipient}', [CampaignUnsubscribeController::class, 'show'])
            ->whereNumber('recipient')
            ->name(CampaignUnsubscribeLinks::ROUTE_SHOW);

        Route::post('/unsubscribe/{recipient}', [CampaignUnsubscribeController::class, 'confirm'])
            ->whereNumber('recipient')
            ->name(CampaignUnsubscribeLinks::ROUTE_CONFIRM);
    });

    Route::middleware([RequireHostedAccountSession::class, 'throttle:'.CampaignsEmailServiceProvider::LIMITER_ACCOUNT])->group(function (): void {
        Route::get('/account', [HostedAccountController::class, 'show'])
            ->name(HostedAccountController::ROUTE_SHOW);

        Route::post('/account/act/{action}', [HostedAccountController::class, 'act'])
            ->where('action', '[a-z_]+')
            ->name(HostedAccountController::ROUTE_ACT);

        Route::post('/account/logout', [HostedAccountController::class, 'logout'])
            ->name(HostedAccountController::ROUTE_LOGOUT);
    });
});
