/** @jsxImportSource preact */
// === One Shopify-Payments contract card ===
//
// A thin rendering of AccountPresenter's `contracts[]` entry — the sibling of
// PlanCard, deliberately built from the same vocabulary: the server's tone, the
// server's cadence sentence, the server's action list, every string from the
// payload's copy bag. Nothing here decides eligibility; a button exists because
// the server listed it, and the endpoint re-refuses a verb the merchant turned
// off even if a crafted request skips this card entirely.
//
// Verbs post the contract GID to the /subscriptions/api/{verb} endpoints (the
// host page's `act`), and the host re-fetches the whole bootstrap afterwards —
// Shopify owns the contract, so only a re-read is the truth.
//
// FAILED gets the card-update button PROMINENTLY (a banner + primary button):
// billing is stuck, the fix is the shopper's card, and the card lives behind
// Shopify's own emailed page — this button is the whole rescue path.

import { useState } from 'preact/hooks';
import { money, formatDate } from './format.js';

// Payload tone → s-badge tone. The same map PlanCard carries, kept local so
// each card stays a self-contained renderer of its own payload entry.
const TONE_BADGE = {
  active: 'success',
  paused: 'neutral',
  ended: 'neutral',
  attention: 'warning',
};

export function ContractCard({ contract, copy, act, locale }) {
  const [busy, setBusy] = useState(null); // which verb is in flight
  const [confirmCancel, setConfirmCancel] = useState(false);
  const [dateOpen, setDateOpen] = useState(false);
  const [date, setDate] = useState('');

  const actions = contract.actions || [];
  const failed = contract.status === 'FAILED';

  async function run(action, extra) {
    setBusy(action);
    const ok = await act(action, contract.gid, extra);
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
        {/* Header: what + status */}
        <s-stack direction="inline" gap="base" blockAlignment="center" inlineSize="fill">
          <s-heading>{contract.title || copy('contract_title')}</s-heading>
          <s-badge tone={TONE_BADGE[contract.tone] ?? 'neutral'}>
            {copy(`status_${contract.status}`, contract.status)}
          </s-badge>
        </s-stack>

        {/* A failed charge is the one state where the page should LEAD with
            the fix: Shopify emails the shopper its secure card page. */}
        {failed && actions.includes('card_update') && (
          <s-banner tone="warning">
            <s-stack direction="block" gap="small-200">
              <s-text>{copy('card_update_failed_prompt')}</s-text>
              <s-button
                kind="primary"
                loading={busy === 'card_update'}
                disabled={busy !== null}
                onClick={() => run('card_update')}
              >
                {copy('action_update_card')}
              </s-button>
            </s-stack>
          </s-banner>
        )}

        {/* The items in the box. Lines mirrored before the image ride-along
            carry no image_url — the card renders them without a picture. */}
        {Array.isArray(contract.lines) && contract.lines.length > 0 && (
          <s-stack direction="block" gap="small-200">
            {contract.lines.map((line, i) => (
              <s-stack key={i} direction="inline" gap="base" blockAlignment="center">
                {line.image_url && (
                  <s-image
                    src={line.image_url}
                    alt={line.title}
                    aspectRatio="1"
                    inlineSize="small"
                    border="base"
                    borderRadius="base"
                  />
                )}
                <s-stack direction="block" gap="none">
                  <s-text>{line.title}</s-text>
                  <s-text tone="subdued">
                    ×{line.quantity}
                    {line.amount !== null && line.amount !== undefined ? ` · ${money(line.amount, contract)}` : ''}
                  </s-text>
                </s-stack>
              </s-stack>
            ))}
          </s-stack>
        )}

        <s-divider />

        {/* Cadence + next charge — the two facts a subscriber checks */}
        <s-stack direction="block" gap="none">
          <s-stack direction="inline" gap="small-200" blockAlignment="center">
            {contract.amount !== null && contract.amount !== undefined && (
              <s-text emphasis="bold">{money(contract.amount, contract)}</s-text>
            )}
            {contract.cadence && <s-text tone="subdued">{contract.cadence}</s-text>}
          </s-stack>
          {contract.next_billing_date && (
            <s-text>
              {copy('next_charge')} {formatDate(contract.next_billing_date, locale)}
            </s-text>
          )}
        </s-stack>

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
            {/* Quietly here when nothing failed; the banner above owns it when
                a charge already bounced. */}
            {actions.includes('card_update') && !failed && (
              <s-button
                kind="secondary"
                loading={busy === 'card_update'}
                disabled={busy !== null}
                onClick={() => run('card_update')}
              >
                {copy('action_update_card')}
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
              {copy('action_reschedule')}
            </s-button>
          </s-stack>
        )}

        {/* Cancel: two explicit steps, never one tap. */}
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
      </s-stack>
    </s-section>
  );
}
