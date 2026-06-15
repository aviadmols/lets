<?php

use App\Http\Controllers\Shopify\OAuthController;
use App\Http\Controllers\Shopify\WebhookController;
use App\Http\Middleware\VerifyShopifyWebhook;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shopify boundary routes (public app)
|--------------------------------------------------------------------------
| OAuth install/callback + the ONE platform webhook endpoint for all shops.
| These are stateless/API-style and live OUTSIDE the embedded-admin middleware
| group (the embedded admin uses SessionTokenAuth, registered on the Filament
| panel by admin-design-system).
*/

// === OAuth (public-app authorization-code grant) ===
Route::get('/shopify/install', [OAuthController::class, 'install'])->name('shopify.install');
Route::get('/shopify/callback', [OAuthController::class, 'callback'])->name('shopify.callback');

// === Webhooks (one endpoint, routed by X-Shopify-Shop-Domain, HMAC-verified) ===
// VerifyShopifyWebhook runs BEFORE the controller: raw-body HMAC, fail closed
// (401 bad/absent, 503 if the platform secret is empty in production).
Route::post('/shopify/webhooks/{topic?}', WebhookController::class)
    ->where('topic', '.*')
    ->middleware(VerifyShopifyWebhook::class)
    ->name('shopify.webhooks');
