<?php

namespace App\Mail\Concerns;

use App\Models\Shop;
use App\Support\BusinessName;

/**
 * Resolves the merchant-facing "business name" used in email copy + the From
 * name. Ported from the reference engine's Mail/ResolvesBusinessName: a single
 * source of truth so every mailable signs off as the same store, never as the
 * platform.
 *
 * Multi-tenant: the name is read from the SENDING shop, never from a global
 * config. Falls back through shop name → shopify domain → platform app name so a
 * partially-onboarded shop still produces a sensible signature.
 */
trait ResolvesBusinessName
{
    // === CONSTANTS ===
    /**
     * Delegates to BusinessName so the admin's email preview can resolve the SAME
     * name without instantiating a mailable — a preview that signed off as a
     * different store than the sent mail would be a preview of nothing.
     */
    protected function resolveBusinessName(?Shop $shop): string
    {
        return BusinessName::for($shop);
    }
}
