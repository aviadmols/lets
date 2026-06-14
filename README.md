# PayPlus Subscriptions, Installments & Post-Purchase Upsells for Shopify

A multi-tenant **SaaS Shopify app** for Israeli merchants using the **PayPlus**
gateway. Recharge-style, broader. Three monetization pillars:

1. **Deposit + installments until fully paid** — release fulfillment only after
   full payment, then issue the final document.
2. **Open-ended recurring subscriptions** — bills until cancelled/paused.
3. **PayPlus-token-based post-purchase upsells** — one-click on the thank-you
   page, charged on the already-saved token, no re-entry.

Built on **Laravel 11 + Filament 3 (re-skinned to Recharge) + Horizon + Postgres
+ Redis**, deployed on **Railway**. Ports the proven single-tenant engine at
`…\פייפלוס חשבונית\app\Modules\PayPlusShopifyInstallments` and makes it
multi-tenant.

## How this repo is built

Work is driven by a team of **7 expert Claude Code agents** in `.claude/agents/`.
Start every session by invoking **`recharge-orchestrator`** — it owns the phased
roadmap and enforces the handoff order and phase gates.

- The full plan: `C:\Users\user\.claude\plans\iridescent-tickling-octopus.md`
- Conventions + module map: [CLAUDE.md](CLAUDE.md)
- Locked architecture decisions: [ARCHITECTURE.md](ARCHITECTURE.md)

## Scaffold status & next step (Phase 1)

This is the **scaffold pass**: agents + orienting docs + the tenant-safety core
stubs (`app/Models/Shop.php`, `app/Models/Concerns/BelongsToShop.php`,
`app/Support/Tenant.php`, `app/Casts/EncryptedCredentials.php`), the domain
migration stubs (`shops`, `payment_ledger`, `customer_consents`,
`activity_events`), the Railway deploy files, `.env.example`, and the `lang/`
skeleton.

**The Laravel framework itself is not yet installed** (no `vendor/`, no
`bootstrap/`, `public/`, `config/app.php`, `artisan`). That is the
`railway-infra` + `laravel-backend` agents' **Phase 1** job. Recommended install
that preserves these hand-authored files:

```sh
# from an empty TEMP dir, create a clean Laravel 11 app, then copy its framework
# files (artisan, bootstrap/, public/, config/, etc.) into this repo WITHOUT
# overwriting composer.json or the app/, database/migrations/, lang/, config/tenancy.php
# files already here. Then:
composer install
php artisan key:generate
php artisan tenant:generate-key      # writes TENANT_CREDENTIALS_KEY
composer require filament/filament:^3.2 laravel/horizon \
        bezhansalleh/filament-language-switch spatie/laravel-settings
php artisan migrate
```

Then proceed through the roadmap (port engine → Shopify app → admin → upsell →
billing → launch).
