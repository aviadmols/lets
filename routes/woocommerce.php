<?php

use App\Http\Controllers\WooCommerce\InstallController;
use App\Http\Middleware\VerifyWooCommerceSignature;
use Illuminate\Support\Facades\Route;

/*
 * WooCommerce plugin → SaaS routes. Stateless JSON (no session, no CSRF token — the
 * request comes server-to-server from the WordPress plugin). VerifyWooCommerceSignature
 * is the auth (per-shop API-key HMAC) and binds the tenant from the verified shop. The
 * connect handshake completes the admin-driven onboarding loop.
 */
Route::middleware(VerifyWooCommerceSignature::class)
    ->prefix('api/woocommerce')
    ->group(function () {
        Route::post('/install', [InstallController::class, 'install'])->name('woocommerce.install');
        Route::post('/verify-key', [InstallController::class, 'verify'])->name('woocommerce.verify');
    });
