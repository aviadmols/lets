<?php

/*
 * Email campaigns + the passwordless "enter my account" link they carry.
 *
 * Platform-wide knobs only. Per-shop choices (the audience, the copy, a
 * campaign's own link lifetime) live on the campaign row.
 */
return [

    // How long a {account_login_url} stays usable, from the moment the email is
    // SENT (tokens are minted per recipient in the send job, not at scheduling).
    // A merchant may pick a shorter or longer window per campaign, up to the cap.
    'login_link_ttl_hours' => (int) env('CAMPAIGN_LOGIN_TTL_HOURS', 168),
    'max_login_ttl_hours' => (int) env('CAMPAIGN_LOGIN_MAX_TTL_HOURS', 336),

    // Pacing of the per-recipient send jobs. A campaign of thousands is spread
    // over minutes rather than fired at queue speed into one SMTP relay.
    'emails_per_second' => (int) env('CAMPAIGN_EMAILS_PER_SECOND', 2),

    // Spent/expired tokens are kept this long after expiry so an abuse report
    // can still be answered, then pruned nightly.
    'token_prune_days' => (int) env('CAMPAIGN_TOKEN_PRUNE_DAYS', 30),

    // The SaaS-hosted personal area (the Shopify landing): idle lifetime of the
    // session the magic link opens. Short on purpose — the link is the login.
    'hosted_session_minutes' => (int) env('CAMPAIGN_HOSTED_SESSION_MINUTES', 60),

];
