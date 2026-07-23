/** @jsxImportSource preact */
// === Target: customer-account.page.render — the LETS Subscriptions personal area ===
//
// A full page inside the shopper's native Shopify account: every subscription OUR
// app created, with the verbs a subscriber actually needs — pause, resume, skip
// the next delivery, move the next charge date, cancel.
//
// Reads come from the Customer Account API (scoped by Shopify to the logged-in
// customer + our app's contracts); verbs go to the LETS backend with a fresh
// session token, which re-checks ownership server-side. After every verb the
// list is re-fetched from Shopify — the page never trusts its own optimism about
// a contract Shopify owns.
//
// Rendered with Shopify's s-* web components only, so the page inherits the
// store's account theme (fonts, colors, dark mode) instead of fighting it.

import '@shopify/ui-extensions/preact';
import { render } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import { fetchContracts, contractAction } from './subscriptionsClient.js';

export default function () {
  render(<SubscriptionsPage />, document.body);
}

// Status → badge tone. PAUSED is neutral (a choice, not a problem); FAILED is
// critical (billing is stuck and the shopper should update their card).
const STATUS_TONE = {
  ACTIVE: 'success',
  PAUSED: 'neutral',
  CANCELLED: 'neutral',
  EXPIRED: 'neutral',
  FAILED: 'critical',
};

function SubscriptionsPage() {
  const [state, setState] = useState({ phase: 'loading', contracts: [] });
  const [notice, setNotice] = useState(null); // {tone, text} after a verb
  const t = makeTranslator(shopify?.i18n);

  async function load() {
    try {
      const contracts = await fetchContracts();
      setState({ phase: contracts.length ? 'list' : 'empty', contracts });
    } catch {
      setState({ phase: 'error', contracts: [] });
    }
  }

  useEffect(() => {
    load();
  }, []);

  // One verb → server → re-read from Shopify → toast. `extra` carries the
  // reschedule date; everything else identifying stays server-side.
  async function act(action, gid, extra) {
    setNotice(null);
    const result = await contractAction(action, gid, extra);

    if (result.ok) {
      await load();
      setNotice({ tone: 'success', text: t(`notice.${action}_ok`) });
    } else {
      setNotice({ tone: 'critical', text: t(`notice.failed_${result.reason}`, t('notice.failed')) });
    }

    return result.ok;
  }

  return (
    <s-page heading={t('page.title')}>
      <s-stack direction="block" gap="base">
        {notice && (
          <s-banner tone={notice.tone} dismissible onDismiss={() => setNotice(null)}>
            <s-text>{notice.text}</s-text>
          </s-banner>
        )}

        {state.phase === 'loading' && (
          <s-section>
            <s-stack direction="inline" gap="base" inlineAlignment="center">
              <s-spinner accessibilityLabel={t('page.loading')} />
            </s-stack>
          </s-section>
        )}

        {state.phase === 'error' && (
          <s-banner tone="critical">
            <s-text>{t('page.error')}</s-text>
          </s-banner>
        )}

        {state.phase === 'empty' && (
          <s-section>
            <s-stack direction="block" gap="small-100" inlineAlignment="center">
              <s-heading>{t('empty.title')}</s-heading>
              <s-text tone="subdued">{t('empty.body')}</s-text>
            </s-stack>
          </s-section>
        )}

        {state.phase === 'list' &&
          state.contracts.map((contract) => (
            <ContractCard key={contract.gid} contract={contract} act={act} t={t} i18n={shopify?.i18n} />
          ))}
      </s-stack>
    </s-page>
  );
}

/**
 * One subscription. The verb set follows the STATUS — a paused contract offers
 * resume, an active one offers pause/skip/reschedule, and a terminal one offers
 * nothing (Shopify cannot reactivate a cancelled contract; a new checkout can).
 */
function ContractCard({ contract, act, t, i18n }) {
  const [busy, setBusy] = useState(null); // which verb is in flight
  const [confirmCancel, setConfirmCancel] = useState(false);
  const [dateOpen, setDateOpen] = useState(false);
  const [date, setDate] = useState('');

  const isActive = contract.status === 'ACTIVE';
  const isPaused = contract.status === 'PAUSED';
  const terminal = !isActive && !isPaused;

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
          <s-heading>{lineTitle(contract, t)}</s-heading>
          <s-badge tone={STATUS_TONE[contract.status] ?? 'neutral'}>
            {t(`status.${contract.status}`, contract.status)}
          </s-badge>
        </s-stack>

        {/* The items in the box */}
        <s-stack direction="block" gap="small-200">
          {contract.lines.map((line, i) => (
            <s-stack key={i} direction="inline" gap="base" blockAlignment="center">
              {line.image && (
                <s-image src={line.image} alt={line.imageAlt} aspectRatio="1" inlineSize="small" border="base" borderRadius="base" />
              )}
              <s-stack direction="block" gap="none">
                <s-text>{line.name}</s-text>
                <s-text tone="subdued">
                  {t('card.qty')} {line.quantity}
                  {line.price ? ` · ${money(line.price, i18n)}` : ''}
                </s-text>
              </s-stack>
            </s-stack>
          ))}
        </s-stack>

        <s-divider />

        {/* Cadence + next charge — the two facts a subscriber checks */}
        <s-stack direction="block" gap="none">
          <s-text tone="subdued">{cadence(contract, t)}</s-text>
          {contract.nextBillingDate && !terminal && (
            <s-text emphasis="bold">
              {t('card.next_charge')} {formatDate(contract.nextBillingDate, i18n)}
            </s-text>
          )}
          {contract.price && (
            <s-text tone="subdued">
              {t('card.per_delivery')} {money(contract.price, i18n)}
            </s-text>
          )}
        </s-stack>

        {/* Verbs, by status */}
        {!terminal && (
          <s-stack direction="inline" gap="small-200">
            {isActive && (
              <s-button
                kind="secondary"
                loading={busy === 'skip'}
                disabled={busy !== null}
                onClick={() => run('skip')}
              >
                {t('action.skip')}
              </s-button>
            )}
            {isActive && (
              <s-button
                kind="secondary"
                loading={busy === 'pause'}
                disabled={busy !== null}
                onClick={() => run('pause')}
              >
                {t('action.pause')}
              </s-button>
            )}
            {isPaused && (
              <s-button
                kind="primary"
                loading={busy === 'resume'}
                disabled={busy !== null}
                onClick={() => run('resume')}
              >
                {t('action.resume')}
              </s-button>
            )}
            {isActive && (
              <s-button kind="secondary" disabled={busy !== null} onClick={() => setDateOpen(!dateOpen)}>
                {t('action.reschedule')}
              </s-button>
            )}
          </s-stack>
        )}

        {/* Reschedule: a plain date field + confirm. The server refuses past dates. */}
        {dateOpen && !terminal && (
          <s-stack direction="inline" gap="small-200" blockAlignment="end">
            <s-text-field
              label={t('action.reschedule_label')}
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
              {t('action.reschedule_confirm')}
            </s-button>
          </s-stack>
        )}

        {/* Cancel: two explicit steps, never one tap. */}
        {!terminal && (
          <s-stack direction="inline" gap="small-200">
            {!confirmCancel ? (
              <s-button kind="plain" tone="critical" disabled={busy !== null} onClick={() => setConfirmCancel(true)}>
                {t('action.cancel')}
              </s-button>
            ) : (
              <>
                <s-text tone="subdued">{t('action.cancel_sure')}</s-text>
                <s-button
                  kind="secondary"
                  tone="critical"
                  loading={busy === 'cancel'}
                  disabled={busy !== null}
                  onClick={() => run('cancel')}
                >
                  {t('action.cancel_confirm')}
                </s-button>
                <s-button kind="plain" disabled={busy !== null} onClick={() => setConfirmCancel(false)}>
                  {t('action.cancel_keep')}
                </s-button>
              </>
            )}
          </s-stack>
        )}
      </s-stack>
    </s-section>
  );
}

// === Small helpers ===

/** The card title: the first item's name (+ how many more ride along). */
function lineTitle(contract, t) {
  const first = contract.lines[0]?.name ?? t('card.subscription');
  const more = contract.lines.length - 1;

  return more > 0 ? `${first} ${t('card.and_more')} ${more}` : first;
}

/** "Every month" / "Every 3 months" from the billing policy. */
function cadence(contract, t) {
  const n = contract.intervalCount;
  const unit = t(`interval.${contract.interval}${n === 1 ? '' : 's'}`, contract.interval.toLowerCase());

  return n === 1 ? `${t('card.every')} ${unit}` : `${t('card.every')} ${n} ${unit}`;
}

function money(price, i18n) {
  const amount = Number(price.amount ?? 0);
  try {
    return i18n?.formatCurrency?.(amount, { currency: price.currencyCode }) ?? `${amount} ${price.currencyCode}`;
  } catch {
    return `${amount} ${price.currencyCode}`;
  }
}

function formatDate(iso, i18n) {
  try {
    return new Date(iso).toLocaleDateString(i18n?.locale ?? undefined, {
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    });
  } catch {
    return String(iso).slice(0, 10);
  }
}

/** translate(key) with an EN-safe fallback, mirroring the upsell widget. */
function makeTranslator(i18n) {
  return (key, fallback) => {
    try {
      const value = i18n?.translate?.(key);
      if (value && value !== key) return value;
    } catch {
      /* fall through */
    }

    return fallback ?? key;
  };
}
