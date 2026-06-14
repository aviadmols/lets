# Code review log — append-only

## 2026-06-15 — Phase 2-3 (Multi-Tenancy Core + Shared Billing Engine) — VERDICT: BLOCKED
Reviewer: code-review-gatekeeper
Scope: app/Modules/PayPlusShopifyInstallments/** , app/Domain/Billing/** ,
  app/Models/{Shop,InstallmentPlan,InstallmentPayment,InstallmentPaymentMethod,
  Concerns/BelongsToShop}.php , app/Support/{Tenant,TenantContext}.php ,
  config/payplus.php , 3 migrations (payment_methods/plans/payments) ,
  bootstrap/providers.php , phpunit.xml , tests/Feature/{ChargeIdempotency,
  GuardedTransition,TenantIsolation}Test.php

Blocking:
  #1 consent-before-charge — ChargeOrchestrator never verifies a CustomerConsent
     row before a saved-token charge (CLAUDE.md money-safety; ChargeOrchestrator.php:108)
  #2 gateway port-fidelity — chargeWithReference diverges from the live reference
     (missing credit_terms=1; no array_filter; no token-fallback/no_reference guard)
     (PayPlusGateway.php:51-65 vs reference PayPlusInstallmentGateway.php:64-125)
  #3 state-machine divergence — PaymentStatus::allowed() declares transitions
     (pending->retry_scheduled, failed->succeeded) not in the canonical
     PaymentLedgerStatus machine it claims to mirror (PaymentStatus.php:24-31)

Suggestions:
  #4 oversized orchestrator (418 lines) — extract doc-issuance + manual-mode
  #5 $guarded=[] on plan/payment/ledger — prefer $fillable or guard shop_id/status
  #6 duplicate nextSequenceFor() derivation in idempotencyKeyFor + findOrCreatePayment

Passing (verified): fail-closed TenantScope; all tenant models shop_id+BelongsToShop;
  sole withoutGlobalScope is audited acrossAllTenants(); ChargeJob carries shopId +
  TenantContext finally-restore + ShouldBeUnique; ledger-before-charge + lockForUpdate +
  succeeded short-circuit (one-call/one-row proven); IdempotencyKey == ARCHITECTURE.md;
  Idempotency-Key header; masked raw; uid ?: null; DocumentPolicy is sole doc decider;
  LedgerStatus == canonical; encrypted per-shop creds (dedicated key, $hidden, not $fillable);
  no global gateway bind; config holds no secrets.

Gate status: TENANT-SAFE-VAULT = GREEN (independent). SHARED-ENGINE / LEDGER-GREEN = RED.
Re-review: required (laravel-backend) — re-open #1-#3 paths + re-run consent/gateway/state sweeps.

Orchestrator decision on #3: InstallmentPayment (the payment *slot*) has its own
lifecycle machine, distinct from the canonical PaymentLedgerStatus money-truth machine.
The slot machine legitimately includes states the ledger machine does not. The enum's
docblock must stop claiming to "mirror" the ledger machine; its transitions must be
internally coherent; ARCHITECTURE.md ratifies the distinction. (No user question needed.)

### 2026-06-15 — Phase 2-3 RE-REVIEW — VERDICT: PASS (clears the BLOCKED entry above)
Reviewer: code-review-gatekeeper
Scope: re-opened the 3 blocking paths + FIX #5 regression sweep —
  ChargeOrchestrator.php, PayPlus/PayPlusGateway.php (vs reference
  PayPlusInstallmentGateway.php:64-125), Enums/PaymentStatus.php, Domain/Billing/Ledger.php,
  Concerns/HasGuardedStatus.php, Models/{InstallmentPayment,InstallmentPaymentMethod,InstallmentPlan,CustomerConsent}.php,
  migrations 000005/000007, ARCHITECTURE.md state-machines.
Resolved:
  #1 consent-before-charge — hasConsent() gates at :92 BEFORE Ledger::open(:115) + gateway(:131);
     fail-closed (no customer identity → false); miss writes Timeline + skipped('no_consent'),
     no ledger row, no gateway call. Manual mode charges no token. Upsell path not in this phase.
  #2 gateway port-fidelity — credit_terms=1, array_filter(null/''), token fallback chain,
     no_reference guard, Idempotency-Key header, auth/prefix/uid extraction all match the reference;
     all 4 columns + rawToken accessor exist in migration 000005 + the model.
  #3 payment-slot machine — docblock no longer claims to mirror the ledger; allowed() coherent;
     every orchestrator slot transitionTo() permitted; ledger runs pending→failed→retry_scheduled
     (both legal); ARCHITECTURE.md ratification paragraph present.
Regression (FIX #5 $guarded=['shop_id','status']): CLEAN — transitionTo() sets status by direct
  attribute assignment; Ledger uses forceFill; new slot born 'pending' via DB default + forceFill;
  no create/update mass-assigns status; no silent-null-status path.
Still-open (non-blocking, deferred): #4 oversized orchestrator, #6 duplicate nextSequenceFor derivation.
Lint: all 5 re-reviewed files pass php84 -l.
Gate status: TENANT-SAFE-VAULT = GREEN · SHARED-ENGINE = GREEN · LEDGER-GREEN = GREEN.
Re-review: none required. Phase 2-3 gate CLEARED; Phase 3.5 may proceed.
