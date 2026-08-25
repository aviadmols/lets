/** @jsxImportSource preact */
// === One PayPlus-rail subscription card ===
//
// A thin rendering of AccountPresenter's `subscriptions[]` entry: the status,
// the price sentence, the facts, the verbs the SERVER listed, the payment
// history with the shopper's own receipts, and the offers drawn under this
// plan. Nothing here decides eligibility, formats money or writes a sentence —
// every string is the payload's, so this card and the WooCommerce renderer can
// never disagree about the same subscription.

import { useState } from 'preact/hooks';
import { money, formatDate } from './format.js';
import { OfferCard } from './OfferCard.jsx';

// Payload tone → s-badge tone. `attention` (failed / awaiting first payment /
// draft) is a warning: billing needs the shopper, but nothing is on fire yet.
const TONE_BADGE = {
  active: 'success',
  paused: 'neutral',
  ended: 'neutral',
  attention: 'warning',
};

export function PlanCard({ sub, copy, act, locale }) {
  // A subscription that is OVER arrives folded (sub.collapsed — decided
  // server-side, so the merchant's preview and this page agree). A plain
  // toggle opens the record when the shopper wants the history.
  const [open, setOpen] = useState(!sub.collapsed);
  const [busy, setBusy] = useState(null); // which verb is in flight
  const [confirmCancel, setConfirmCancel] = useState(false);
  const [dateOpen, setDateOpen] = useState(false);
  const [date, setDate] = useState('');
  const [paymentsOpen, setPaymentsOpen] = useState(false);

  const actions = sub.actions || [];

  async function run(action, extra) {
    setBusy(action);
    const ok = await act(action, { subscription: sub.id, ...extra });
    setBusy(null);
    if (ok) {
      setConfirmCancel(false);
      setDateOpen(false);
      setDate('');
    }
  }

  return (
    <s-section>
      <s-stack direction="block" gap="base">
        {/* Header: what + status. Always visible, even folded. */}
        <s-stack direction="inline" gap="base" blockAlignment="center" inlineSize="fill">
          <s-heading>{sub.title || copy('subscriptions_heading')}</s-heading>
          <s-badge tone={TONE_BADGE[sub.tone] ?? 'neutral'}>
            {copy(`status_${sub.status}`, sub.status)}
          </s-badge>
          {sub.collapsed && (
            <s-button kind="plain" onClick={() => setOpen(!open)}>
              {open ? '−' : '+'}
            </s-button>
          )}
        </s-stack>

        {open && (
          <s-stack direction="block" gap="base">
            {/* Price: the server's amount, struck regular price only when the
                shopper is actually below it, and the server's own cadence
                sentence — never a frequency map of our own. */}
            <s-stack direction="inline" gap="small-200" blockAlignment="center">
              <s-text emphasis="bold">{money(sub.amount, sub)}</s-text>
              {sub.regular_amount && sub.regular_amount > sub.amount && (
                <s-text tone="subdued" accessibilityRole="deletion">
                  {money(sub.regular_amount, sub)}
                </s-text>
              )}
              {sub.cadence && <s-text tone="subdued">{sub.cadence}</s-text>}
            </s-stack>

            {/* Facts */}
            <s-stack direction="block" gap="none">
              {sub.next_charge_at && (
                <s-text>
                  {copy('next_charge')} {formatDate(sub.next_charge_at, locale)}
                </s-text>
              )}
              {sub.kind === 'installments' && sub.total > 0 && (
                <s-text tone="subdued">
                  {money(sub.paid, sub)} {copy('paid_of')} {money(sub.total, sub)}
                </s-text>
              )}
              {sub.kind === 'installments' && sub.remaining !== null && sub.remaining !== undefined && (
                <s-text tone="subdued">
                  {copy('remaining')} {money(sub.remaining, sub)}
                </s-text>
              )}
              {sub.intro && sub.intro.total > 0 && (
                <s-text tone="subdued">
                  {copy('benefit_intro_ending')} {sub.intro.used}/{sub.intro.total}
                </s-text>
              )}
              <s-text tone="subdued">
                {copy('payment_method')}{' '}
                {sub.card
                  ? `${sub.card.brand} •••• ${sub.card.last_four}${sub.card.expires ? ` · ${sub.card.expires}` : ''}`
                  : copy('no_card')}
              </s-text>
            </s-stack>

            {/* What is already queued for the next cycle */}
            {sub.next_order && sub.next_order.lines && sub.next_order.lines.length > 0 && (
              <s-stack direction="block" gap="none">
                <s-text emphasis="bold">{copy('benefit_next_order_extra')}</s-text>
                <s-text tone="subdued">
                  {sub.next_order.lines
                    .map((l) => (l.quantity > 1 ? `${l.name} ×${l.quantity}` : l.name))
                    .join(', ')}
                </s-text>
              </s-stack>
            )}

            {/* Verbs — exactly the ones the server listed, in the Woo order:
                reversible everyday verbs first, cancel last and confirmed. */}
            {actions.length > 0 && (
              <s-stack direction="inline" gap="small-200">
                {actions.includes('skip') && (
                  <s-button
                    kind="secondary"
                    loading={busy === 'skip'}
                    disabled={busy !== null}
                    onClick={() => run('skip')}
                  >
                    {copy('action_skip')}
                  </s-button>
                )}
                {actions.includes('reschedule') && (
                  <s-button kind="secondary" disabled={busy !== null} onClick={() => setDateOpen(!dateOpen)}>
                    {copy('action_reschedule')}
                  </s-button>
                )}
                {actions.includes('pause') && (
                  <s-button
                    kind="secondary"
                    loading={busy === 'pause'}
                    disabled={busy !== null}
                    onClick={() => run('pause')}
                  >
                    {copy('action_pause')}
                  </s-button>
                )}
                {actions.includes('resume') && (
                  <s-button
                    kind="primary"
                    loading={busy === 'resume'}
                    disabled={busy !== null}
                    onClick={() => run('resume')}
                  >
                    {copy('action_resume')}
                  </s-button>
                )}
              </s-stack>
            )}

            {/* Reschedule: a plain date field + confirm. The server refuses past dates. */}
            {dateOpen && (
              <s-stack direction="inline" gap="small-200" blockAlignment="end">
                <s-text-field
                  label={copy('action_reschedule')}
                  value={date}
                  placeholder="2026-08-15"
                  onChange={(e) => setDate(e?.target?.value ?? e?.detail?.value ?? '')}
                />
                <s-button
                  kind="primary"
                  loading={busy === 'reschedule'}
                  disabled={busy !== null || !date}
                  onClick={() => run('reschedule', { date })}
                >
                  {copy('action.reschedule_confirm')}
                </s-button>
              </s-stack>
            )}

            {/* Cancel: two explicit steps, never one tap. The confirm sentence
                is the server's (copy.confirm_cancel), same as the Woo renderer. */}
            {actions.includes('cancel') && (
              <s-stack direction="inline" gap="small-200" blockAlignment="center">
                {!confirmCancel ? (
                  <s-button kind="plain" tone="critical" disabled={busy !== null} onClick={() => setConfirmCancel(true)}>
                    {copy('action_cancel')}
                  </s-button>
                ) : (
                  <>
                    <s-text tone="subdued">{copy('confirm_cancel')}</s-text>
                    <s-button
                      kind="secondary"
                      tone="critical"
                      loading={busy === 'cancel'}
                      disabled={busy !== null}
                      onClick={() => run('cancel')}
                    >
                      {copy('action_cancel')}
                    </s-button>
                    <s-button kind="plain" disabled={busy !== null} onClick={() => setConfirmCancel(false)}>
                      {copy('action.cancel_keep')}
                    </s-button>
                  </>
                )}
              </s-stack>
            )}

            {/* WHY the exit buttons are missing, when a commitment holds them. */}
            {sub.commitment_note && <s-text tone="subdued">{sub.commitment_note}</s-text>}

            {/* Payments, folded behind a plain toggle */}
            {sub.payments && sub.payments.length > 0 && (
              <s-stack direction="block" gap="small-200">
                <s-divider />
                <s-button kind="plain" onClick={() => setPaymentsOpen(!paymentsOpen)}>
                  {copy('payments_heading')}
                </s-button>
                {paymentsOpen &&
                  sub.payments.map((p) => (
                    <s-stack key={p.sequence} direction="inline" gap="base" blockAlignment="center" inlineSize="fill">
                      <s-text tone="subdued">#{p.sequence}</s-text>
                      <s-text>
                        {money(p.amount, sub)}
                        {p.at ? ` · ${formatDate(p.at, locale)}` : ''}
                      </s-text>
                      <s-text tone="subdued">{copy(`status_${p.status}`, p.status)}</s-text>
                      {p.receipt_url && (
                        <s-button kind="plain" href={p.receipt_url} target="_blank">
                          {copy('receipt_label')}
                        </s-button>
                      )}
                    </s-stack>
                  ))}
              </s-stack>
            )}

            {/* Offers aimed at THIS plan, last — after the shopper has read
                what they would be switching from. */}
            {Array.isArray(sub.offers) &&
              sub.offers.map((offer) => (
                <OfferCard key={offer.id} offer={offer} copy={copy} act={act} locale={locale} />
              ))}
          </s-stack>
        )}
      </s-stack>
    </s-section>
  );
}
