<?php

return [
    /*
     * How long a signed customer-portal magic link stays valid. Links are emailed
     * and may be opened days later (vs. a 60-minute storefront action link), so the
     * default is generous. The signature still binds shop + plan + customer, so a
     * stale link only ever exposes its own customer's plans on its own shop.
     */
    'link_ttl_days' => (int) env('PORTAL_LINK_TTL_DAYS', 7),

    /*
     * Platform-wide DEFAULTS for customer self-service. These are now the FALLBACK
     * only: the per-shop App\Models\MerchantBillingSettings row owns the live flags,
     * and PortalController reads them from there for the bound shop. These config
     * values seed a shop's row when it is first materialised (MerchantBillingSettings::
     * current()), so the operator's configured default still applies to a merchant who
     * never opened the Billing settings screen. SAFE defaults: allow both. The
     * controller also gates each action on the plan's state machine regardless.
     */
    'allow_customer_pause' => (bool) env('PORTAL_ALLOW_CUSTOMER_PAUSE', true),
    'allow_customer_cancel' => (bool) env('PORTAL_ALLOW_CUSTOMER_CANCEL', true),
];
