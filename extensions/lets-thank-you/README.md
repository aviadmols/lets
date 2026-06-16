# lets-thank-you — PRIMARY post-purchase upsell (PayPlus token widget)

A Shopify **checkout UI extension** (`type = "ui_extension"`, `api_version 2026-04`)
that renders the LETS token-based upsell on two surfaces:

- `purchase.thank-you.block.render` — the Thank-you page (right after checkout).
- `customer-account.order-status.block.render` — the Order-status page.

This is the **primary** post-purchase path for Israeli PayPlus merchants: the
customer's PayPlus token was saved at checkout, so accept charges it with **no card
re-entry** and **no dependency on Shopify Payments**.

## How it talks to the app

1. On render it calls the LETS app through the **App Proxy**:
   `GET /apps/payplus/upsell/offer` → Shopify proxies (signed) to
   `https://app.lets.co.il/proxy/upsell/offer`. The app resolves the eligible
   offer (server-computed price) and returns it plus a **signed** `accept_api_url`.
2. On accept it POSTs that signed URL. The app verifies the signature, binds the
   tenant, recomputes the amount, charges the saved PayPlus token, and creates the
   linked child order. Idempotent (a double-tap → one charge).

The extension sends **no shop id and no amount** — both come from the signed server
response. Runtime: Preact + `@shopify/ui-extensions` 2026.4.x (React was dropped
after 2025-07).

## Develop / deploy

```sh
# from repo root (needs Partner login — interactive, run locally)
shopify app dev      # live-preview against a dev store
shopify app deploy   # bundle + push all extensions to app 382947852289
```

`shopify.app.toml` auto-discovers this folder; no `[[extensions]]` entry needed.
Visual design / copy polish is owned by `admin-design-system`.
