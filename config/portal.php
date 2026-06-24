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
     * Per-shop allow flags for customer self-service. Until the merchant settings
     * screen lands (a separate wave), these are platform-wide DEFAULTS read by the
     * PortalController. SAFE defaults: allow both. The controller already gates each
     * action on the plan's current state machine, so a disallowed transition can
     * never run regardless.
     *
     * TODO(settings wave): move these to a per-shop MerchantBillingSettings row and
     * read them through that model instead of config.
     */
    'allow_customer_pause' => (bool) env('PORTAL_ALLOW_CUSTOMER_PAUSE', true),
    'allow_customer_cancel' => (bool) env('PORTAL_ALLOW_CUSTOMER_CANCEL', true),
];
