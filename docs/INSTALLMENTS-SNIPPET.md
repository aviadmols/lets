# Deposit & installments button — merchant install guide

Add a **"Pay a deposit & reserve it"** button to your product pages. Shoppers pay a
deposit now; LETS reserves the item and bills the rest in installments. The order
ships once it is fully paid.

There are **two ways** to add it — pick one:

- **A. App block (recommended)** — no code, added in the theme editor.
- **B. Pasteable snippet** — for merchants who prefer to edit theme code.

Both call LETS **only through the Shopify App Proxy** (`/apps/payplus/...`), so every
request is signed by Shopify and your shop is identified securely. The button never
sends a price — LETS computes the deposit and the schedule on the server from your
catalog, so the numbers can't be tampered with.

---

## A. App block (recommended — no code)

1. In Shopify admin, go to **Online Store → Themes → Customize**.
2. Open a **Product** template (top bar dropdown → *Products → Default product*).
3. In the product section, click **Add block**.
4. Under **Apps**, choose **LETS deposit & installments**.
5. Drag it to where you want the button (usually just under *Add to cart*).
6. (Optional) Click the block to edit the **button label**, **sub-label**, and
   **colors**.
7. Click **Save**.

That's it. The button appears on every product using that template and always prices
the variant the shopper has selected.

> **App Proxy subpath.** The block has an *App Proxy subpath* setting that defaults to
> `payplus`. Leave it as-is unless LETS support tells you otherwise — it must match the
> app's configured proxy subpath or the calculator won't load.

---

## B. Pasteable snippet (edit theme code)

Use this if you'd rather place the button in code (e.g. a heavily customized theme).

1. In Shopify admin, go to **Online Store → Themes → ⋯ → Edit code**.
2. Under **Snippets**, click **Add a new snippet**, name it
   `lets-installments-button`, and paste the contents of
   `extensions/lets-installments/snippet/lets-installments-button.liquid`
   (from this app's repo) into it. **Save**.
3. Open your product template — usually **Sections → `main-product.liquid`**
   (older themes: **Templates → `product.liquid`**).
4. Find the add-to-cart button (search for `name="add"` or `product-form`).
   Immediately **after** it, paste:

   ```liquid
   {% render 'lets-installments-button',
      product: product,
      variant: product.selected_or_first_available_variant,
      proxy_subpath: 'payplus',
      label: 'Pay a deposit & reserve it',
      sublabel: 'Split the rest into installments' %}
   ```

5. **Save.** Open a product page on your storefront to confirm the button appears.

> Pass `proxy_subpath: 'payplus'` exactly as your app's App Proxy subpath is
> configured (default `payplus`).

---

## What the shopper sees

1. Taps **Pay a deposit & reserve it** → a dialog opens with the **deposit
   calculator**.
2. Picks the **down-payment %**, **number of installments**, **billing frequency**,
   and (for monthly) the **charge day**. The schedule preview updates live — all
   amounts computed by LETS on the server.
3. Taps **Continue to pay the deposit** → redirected to the secure **PayPlus** page
   to pay the deposit.
4. After paying, LETS reserves the item and schedules the remaining installments
   automatically. The order is released for fulfillment **only after it is fully
   paid**.

---

## How it works (for the technically curious)

- The button opens an `<iframe>` pointing at
  `/apps/payplus/installments/modal/{productGid}/{variantGid}`. Shopify proxies and
  **signs** that request; LETS verifies the signature and derives your shop from it.
- The calculator previews schedules via `POST /apps/payplus/installments/quote` and
  starts a plan via `POST /apps/payplus/installments/start` — both signed the same way.
- `start` creates the installments plan (awaiting first payment) plus an **unpaid
  deposit invoice** in your store, and returns that invoice's URL. The iframe asks
  the page to redirect there.
- When the deposit is paid, Shopify sends an **`orders/paid`** webhook; LETS matches
  it to the plan, records the paid deposit, captures the reusable payment token, and
  **activates** the plan so the remaining installments bill on schedule.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| Dialog shows *"Installments are not available for this item"* | The product hasn't synced to LETS yet, or the variant has no price. Re-sync products in the LETS admin and try again. |
| Calculator won't load (blank dialog) | The **App Proxy subpath** in the block/snippet must match the app's configured subpath (default `payplus`). |
| Button doesn't appear (snippet route) | Make sure the `{% render %}` tag is inside the product template and that `product` is in scope. |
| Deposit paid but plan not active | Confirm the `orders/paid` webhook is registered (it is, on install). Check the plan's Timeline in the LETS admin for `deposit_paid_plan_activated`. |
