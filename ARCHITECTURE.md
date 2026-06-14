# ARCHITECTURE.md — Locked Decisions

This is the contract. Agents must not silently deviate; if a capability behaves
differently than assumed, adapt the *implementation*, never drop a *pillar*.

## Product

**PayPlus Subscriptions, Installments & Post-Purchase Upsells for Shopify** — a
multi-tenant SaaS for Israeli Shopify merchants using PayPlus. Three pillars:
deposit+installments-until-paid · open-ended recurring · token-based
post-purchase upsells.

## Locked decisions

| Decision | Choice |
|---|---|
| Codebase | Fresh Laravel 11 repo; port the proven `PayPlusShopifyInstallments` module; keep the source project as a read-only reference oracle. |
| Tenancy | Multi-tenant, single DB, `shop_id` on every tenant table + `BelongsToShop` global scope. |
| Admin UI | Filament 3, re-skinned to the Recharge spec (design tokens → CSS vars). |
| Subscription mechanics | BOTH: installments-until-paid AND open-ended recurring (`plan_kind` discriminator). |
| SaaS billing | Flat monthly tiers via Shopify AppSubscription API + free trial + plan-gate middleware. |
| Hosting | Railway: 3 services — web (FrankPHP/Caddy), worker (Horizon), scheduler. Postgres + Redis. |
| Queues | Laravel Horizon on Redis; queues split by type: `charges`, `webhooks`, `sync`, `invoices`, `upsell`. |
| Upsell Shopify order strategy | **Separate linked child order via draft-order-completed-as-paid** (reuse `ShopifyDraftOrderService`). Order-edit is a future option only where supported. |
| Language | English default; full i18n via `lang/en` + `lang/he`; RTL-aware. |

## Per-shop credentials

Each merchant enters their **own** PayPlus account credentials in
**Settings → PayPlus Connection** (api_key, secret_key, terminal_uid,
cashier_uid, payment_page_uid, base_url, webhook_secret) with a "Test
connection" button. Stored **encrypted** on the `shops` row via a dedicated
encryption cast (independent of `APP_KEY`). `PayPlusGatewayFactory::for($shop)`
constructs a gateway bound to that shop's credentials. Shopify token is set on
OAuth install, also encrypted. **No shop can ever touch another shop's account.**

## Charge contexts & plan kinds

- `plan_kind` ∈ { `installments`, `recurring` } — these are *plans*.
- `charge_context` ∈ { `deposit`, `installment`, `recurring`, `upsell`,
  `retry`, `manual` }. **Upsell is a charge context, not necessarily a plan.**

## Canonical state machines (single source of truth)

**InstallmentPlanStatus:** `draft → awaiting_first_payment → active → completed`
· `draft → cancelled` · `awaiting_first_payment → cancelled` · `active → paused`
· `paused → active` · `active → failed` · `failed → active` · `failed → cancelled`

**RecurringPlanStatus:** `draft → active` · `active → paused` · `paused → active`
· `active → cancelled` · `active → failed` · `failed → active` · `failed → cancelled`

**PaymentLedgerStatus:** `pending → succeeded` · `pending → failed` ·
`succeeded → refunded` · `failed → retry_scheduled` · `retry_scheduled → succeeded`
· `retry_scheduled → failed`

Any transition not listed is illegal and rejected by a guarded `transitionTo()`,
which writes a ledger + Timeline event on every move.

## Idempotency keys (deterministic)

- `deposit:{shop_id}:{checkout_id}`
- `installment:{shop_id}:{plan_id}:{sequence}`
- `recurring:{shop_id}:{plan_id}:{billing_cycle_date}`
- `upsell:{shop_id}:{flow_id}:{offer_id}:{parent_order_id}:{customer_id}`
- `retry:{shop_id}:{payment_event_id}:{attempt_number}`

Never send a second PayPlus charge if a `succeeded` ledger event exists for the
same key.

## Env contract (high level — see `.env.example`)

- App: `APP_KEY`, `APP_URL`, `APP_ENV`, `APP_DEBUG`.
- DB/queue: `DATABASE_URL` (Postgres), `REDIS_URL`, `QUEUE_CONNECTION=redis`,
  `CACHE_STORE=redis`, `SESSION_DRIVER=database`.
- Shopify (platform app, public distribution): `SHOPIFY_API_KEY`,
  `SHOPIFY_API_SECRET`, `SHOPIFY_API_VERSION`, `SHOPIFY_APP_URL`,
  `SHOPIFY_OAUTH_SCOPES`, `SHOPIFY_WEBHOOK_SECRET`.
- PayPlus: **per-shop, stored encrypted in DB — NOT in env.** Env holds only
  defaults/sandbox toggles: `PAYPLUS_BASE_URL_DEFAULT`,
  `PAYPLUS_BASE_URL_SANDBOX`, `PAYPLUS_TIMEOUT`.
- Tenancy encryption: `TENANT_CREDENTIALS_KEY` (base64, separate from APP_KEY).

## Reference-engine reuse map

Port from `…\פייפלוס חשבונית\app\Modules\PayPlusShopifyInstallments\`:
gateway (`Services/PayPlus/PayPlusInstallmentGateway.php` → factory),
`Services/ChargeOrchestrator.php`, `Services/PlanActivationService.php`,
`Services/PayPlusCustomerTokenResolver.php`, `Services/FulfillmentLockService.php`,
`Services/ShopifyDraftOrderService.php`, `Services/ShopifyOrderCreator.php`,
`Http/Controllers/Storefront/ReturnController.php`, `Jobs/ChargeInstallmentJob.php`,
`Console/Commands/DispatchDueInstallmentsCommand.php`, the **email engine**
(`Mail/*`, `Support/DefaultEmailTemplates`, `Settings/MailSettings`,
`Filament/Pages/ManageMailSettings`), the **Timeline**
(`Models/InstallmentPlanEvent`, `Support/PlanEventPresenter`,
`Support/EmailPreviewRenderer`, `resources/views/filament/components/plan-events-*`),
the **portal** (`pps_installments.portal.show`, `Services/SignedUrlService`),
and **refunds/store-credit** (`PayPlusInstallmentGateway::refund()`,
`InstallmentStoreCredit`, `ShopifyGiftCardStoreCreditIssuer`).
