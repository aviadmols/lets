# Green Invoice (Morning) invoicing module — review log

Append-only. See CLAUDE.md §code-review-gatekeeper.

## 2026-07-22 — VERDICT: BLOCKED
Reviewer: code-review-gatekeeper
Scope: app/Domain/Invoicing/**, app/Models/{IssuedDocument,MerchantInvoicingSettings}.php,
       app/Http/Controllers/WooCommerce/InvoicingController.php, app/Filament/Pages/ManageInvoicing.php,
       3 migrations, config/invoicing.php, routes/woocommerce.php, plugins/lets-payplus-woocommerce/**,
       lang/{en,he}/*, tests/Feature/Invoicing/** + 22 modified money-path files
Blocking: B1 DocumentPolicy bypassed at 3/5 dispatch sites · B2 credit note links to the wrong
          document (and unconditional linking on sale documents) · B3 pending row is re-issued after
          worker death -> second real tax document · B4 issued_documents absent from GDPR redaction
Suggestions: S1 inert server-side plan wall · S2 worker-locale document text · S3 upsell refund can
          never credit · S4 unreachable refund-key amount · S5 forShop() race · S6 static test hook
          in product code · S7 CONST-at-top x6 · S8 masker scope · S9 unguarded afterCommit dispatch
Nits: N1 status key collision · N2 missing FKs · N3 N+1 on hidden ledger column
Verified green: tenant isolation · afterCommit ordering · secrets · strtr · no inline CSS · EN/HE parity
Re-review: required

## 2026-07-22 (re-review #1) — VERDICT: BLOCKED
Closed: B1 central DocumentPolicy on all 5 paths (binding verified live) · B2 credit note keyed to the
       refunded ledger row, sale linking policy-gated · B3 attempted_at wall, all 4 states traced
Still blocking: B4-a customers/redact misses upsell documents (plan_id null; attributable via
       ledger_id -> payment_ledger.shopify_customer_id) · B4-b RedactionPolicy::PII_JSON_KEYS omits taxId
Introduced/exposed: NEW-1 no merchant surface or retry path for failed/unresolved documents · NEW-2
       retry/manual charges never invoiced · NEW-3 token-step transport wrongly classed non-retryable
Verified green: 528 tests / 1751 assertions
Re-review: required

## 2026-07-22 (re-review #2) — VERDICT: PASS-WITH-SUGGESTIONS
Clears: both BLOCKED entries above.
Closed: B4-a customers/redact now unions plan-linked AND ledger-linked documents (closure-wrapped
       orWhereIn — verified it cannot escape the tenant scope) · B4-b taxid/tax_id/vat_id/vat_number/
       national_id added to PII_JSON_KEYS · NEW-3 REASON_TOKEN_TRANSPORT retryable, document-POST
       transport still not · 3 nits (PLATFORM_ORDER constant, dead STATUS_SKIPPED, test name)
WITHDRAWN BY REVIEWER: NEW-2 (retry/manual never invoiced) — incorrect. PaymentType has three cases
       and toChargeContext() is total over them; no producer can yield a retry/manual ChargeContext.
       Finding was based on a defensive match arm, not a reachable path. Gatekeeper error.
Open, non-gating: NEW-1 no merchant surface / re-issue action for failed+unresolved documents —
       ruled OUT of this unit, recorded as a DoD GATE ITEM for the invoicing module. The B3 safety
       design delegates recovery to it, so it must be green before the module is declared done.
       S6 static test hook + S7 CONST-at-top on DTOs — accepted as-is.
Post-review nits closed by the implementer: stale DocumentContext docblock · email-only redact
       payload now reaches upsell documents (with an explicit fail-closed guard).
Verified green: 529 tests / 1756 assertions · 10/10 acrossAllTenants sites shop_id-paired
Re-review: not required. Module may advance.

## 2026-07-22 (re-review #3) — Document reconciliation surface (NEW-1) — VERDICT: BLOCKED
Discharges: the NEW-1 DoD gate item — retry / issueAfterVerifying / recordExisting give the B3
       refusal the human counterpart it depended on; nav badge + attention-first tab make it
       discoverable without opening the screen.
Blocking: C1 orderStub() sent payment_gateway = null, which GreenInvoiceProvider reads as CREDIT_CARD
       — a re-issued bacs/cod store order would be declared as a card payment on a tax document.
Ratification required: CLAUDE.md edited by a builder (content sound and stricter; authority is the issue).
Suggestions: S-A the attempted_at stamp was not an atomic claim.
Verified green: 549 tests / 1807 assertions

## 2026-07-22 (re-review #4, FINAL) — Invoicing module + reconciliation surface — VERDICT: PASS-WITH-SUGGESTIONS
Clears: every prior BLOCKED entry in this file.
Closed: C1 — the storefront's report is now PERSISTED on the row (migration 2026_07_22_000005,
       `source_payload`), so a cod order re-issues as cod and the customer identity survives; a row
       that cannot be rebuilt faithfully is REFUSED (`NOT_REBUILDABLE`) rather than approximated.
       S-A — the attempt stamp is a conditional `UPDATE … WHERE attempted_at IS NULL`, decided by the
       database; the retry button is narrowed to `failed` so it cannot race an in-flight row.
       3 nits (two status writers documented, DocumentContext docblock, distinct force-issued kind).
Ratified: the CLAUDE.md additions (Document-safety law, destructive-DB rule, Invoicing module map)
       were put to the owner and ratified. Now enforced as contract, not as reviewer opinion.
Post-review, fixed by the implementer rather than deferred:
       S-B — redaction now NULLS `source_payload` instead of scrubbing it. A scrubbed skeleton still
       looked rebuildable, so a retry after a GDPR erasure would have printed "[redacted]" as the
       client name and tax id on a real Israeli tax document. Covered by
       `DocumentSafetyWallsTest::test_a_redacted_store_order_report_cannot_be_reissued`.
       NITs — `syncOriginalAttribute('attempted_at')` + a single `now()`; `document_force_issued`
       re-toned to `warning` (new `.rc-timeline__dot--warning`, reusing the existing `--rc-amber`
       token) so the one act that can duplicate a tax document does not render as routine traffic.
Verified green: 553 tests / 1817 assertions · full EN↔HE parity · 8/8 acrossAllTenants sites
       shop_id-paired · no inline CSS · document_url absent from the Timeline · no Blade::render
Re-review: not required. THE MODULE IS DONE.
