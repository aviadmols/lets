/** @jsxImportSource preact */
// === Target: customer-account.page.render — the LETS personal area ===
//
// ONE page, TWO rails, ONE payload:
//
//   GET /subscriptions/api/account ships the whole AccountPresenter model — the
//   same view-model the WooCommerce area renders — and since Phase 3 that model
//   carries the Shopify-Payments CONTRACTS too (`account.contracts`), shaped in
//   the plan cards' vocabulary. This page is a thin renderer of it: sections in
//   the merchant's order, every string from the payload's copy bag, every verb
//   the server listed. After any PayPlus verb the WHOLE model is replaced from
//   the response; after any contract verb the bootstrap is RE-FETCHED (Shopify
//   owns the contract, so only a re-read is the truth). The old client-side
//   shopify.query read (ContractsSection/subscriptionsClient.js) is retired.
//
// Section keys with no renderer here (orders, documents, profile, addresses —
// the platform's own screens — and anything the server ships before this
// extension learns it) are skipped silently, the same tolerance the Woo
// renderer has.

import '@shopify/ui-extensions/preact';
import { render } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import { fetchAccount, accountAction, contractAction } from './accountClient.js';
import { makeTranslator } from './components/format.js';
import { PlanCard } from './components/PlanCard.jsx';
import { ContractCard } from './components/ContractCard.jsx';
import { OfferCard } from './components/OfferCard.jsx';
import { LoyaltyCard } from './components/LoyaltyCard.jsx';
import { Banners } from './components/Banners.jsx';
import { UpcomingList } from './components/UpcomingList.jsx';
import { SupportCard } from './components/SupportCard.jsx';

export default function () {
  render(<AccountPage />, document.body);
}

// The section keys this page knows how to draw. `welcome` is handled as the
// hero (first, with the banner strip after it); the platform-owned keys are
// deliberately absent — Shopify's own account screens already render them.
const KNOWN_SECTIONS = ['welcome', 'subscriptions', 'upcoming', 'benefits', 'loyalty', 'support'];

function AccountPage() {
  const [account, setAccount] = useState(null);
  const [phase, setPhase] = useState('loading'); // loading | ready | error
  const [notice, setNotice] = useState(null); // {tone, text} after a verb
  const t = makeTranslator(shopify?.i18n);

  useEffect(() => {
    (async () => {
      try {
        setAccount(await fetchAccount(shopify?.i18n?.locale));
        setPhase('ready');
      } catch {
        setPhase('error');
      }
    })();
  }, []);

  /**
   * copy(key, fallback): the payload's copy bag first (THE source for all
   * user-facing strings), the locale files for chrome-only keys, then the
   * fallback (or the key itself) so a missing sentence degrades to readable.
   */
  function copy(key, fallback) {
    const bag = account && account.copy;
    if (bag && bag[key]) return bag[key];

    return t(key, fallback);
  }

  /**
   * One PayPlus-rail verb → server → REPLACE the whole model from the
   * response → toast. The refreshed bag names the outcome: `result_{action}`
   * on success, `result_{action}_{result}` for a refusal with its own
   * sentence, the generic `failed` otherwise.
   */
  async function act(action, body) {
    setNotice(null);

    try {
      const res = await accountAction(action, body);
      if (res.account) setAccount(res.account);

      const bag = (res.account && res.account.copy) || (account && account.copy) || {};

      if (res.ok) {
        setNotice({ tone: 'success', text: bag[`result_${action}`] || bag.saved || t('notice.saved', '') });
      } else {
        setNotice({
          tone: 'critical',
          text: bag[`result_${action}_${res.result}`] || bag.failed || t('notice.failed'),
        });
      }

      return res.ok;
    } catch {
      setNotice({ tone: 'critical', text: (account && account.copy && account.copy.failed) || t('notice.failed') });

      return false;
    }
  }

  /**
   * One Shopify-Payments CONTRACT verb → server → RE-FETCH the bootstrap →
   * toast. The verb endpoints answer with the contract alone, and Shopify owns
   * it — so the page redraws from a fresh read of the whole model, never from
   * its own optimism. The refreshed bag's `result_{action}` names the outcome
   * (the contract verbs share the plan verbs' sentences; card_update has its
   * own — "we emailed you a link" is not "saved").
   */
  async function actContract(action, gid, extra) {
    setNotice(null);

    try {
      const res = await contractAction(action, gid, extra);
      let bag = (account && account.copy) || {};

      if (res.ok) {
        const fresh = await fetchAccount(shopify?.i18n?.locale);
        setAccount(fresh);
        bag = (fresh && fresh.copy) || bag;
        setNotice({ tone: 'success', text: bag[`result_${action}`] || bag.saved || t('notice.saved', '') });
      } else {
        setNotice({ tone: 'critical', text: bag.failed || t('notice.failed') });
      }

      return res.ok;
    } catch {
      setNotice({ tone: 'critical', text: (account && account.copy && account.copy.failed) || t('notice.failed') });

      return false;
    }
  }

  const locale = (account && account.appearance && account.appearance.locale) || shopify?.i18n?.locale;
  // Shopify guarantees a logged-in customer on this surface, so an
  // unidentified payload is abnormal — it renders as an empty page, and the
  // error banner covers the fetch failures.
  const identified = phase === 'ready' && account && account.identified !== false;
  const sections = identified ? (account.sections || []).filter((key) => KNOWN_SECTIONS.includes(key)) : [];
  const plans = identified ? account.subscriptions || [] : [];
  const contracts = identified ? account.contracts || [] : [];

  const children = [];

  if (notice) {
    children.push(
      <s-banner key="notice" tone={notice.tone} dismissible onDismiss={() => setNotice(null)}>
        <s-text>{notice.text}</s-text>
      </s-banner>,
    );
  }

  if (phase === 'loading') {
    children.push(
      <s-section key="account-loading">
        <s-stack direction="inline" gap="base" inlineAlignment="center">
          <s-spinner accessibilityLabel={t('page.loading')} />
        </s-stack>
      </s-section>,
    );
  }

  if (phase === 'error') {
    children.push(
      <s-banner key="account-error" tone="critical">
        <s-text>{t('page.error')}</s-text>
      </s-banner>,
    );
  }

  if (identified) {
    // Hero first (when the merchant shows it), then the banner strip and the
    // top offers — announcements before decisions, decisions before the cards.
    if (sections.includes('welcome')) {
      children.push(
        <s-section key="welcome">
          <s-stack direction="block" gap="small-100">
            <s-heading>
              {account.greeting ? `${copy('welcome_heading')}, ${account.greeting}` : copy('welcome_heading')}
            </s-heading>
            <s-text tone="subdued">{copy('welcome_subtext')}</s-text>
          </s-stack>
        </s-section>,
      );
    }

    children.push(<Banners key="banners" topBanners={account.top_banners} railBanners={account.banners} />);

    (account.offers || []).forEach((offer) => {
      children.push(<OfferCard key={`offer-${offer.id}`} offer={offer} copy={copy} act={act} locale={locale} />);
    });

    sections.forEach((key) => {
      if (key === 'welcome') return;

      if (key === 'subscriptions') {
        children.push(
          <s-heading key="subs-heading">{copy('subscriptions_heading')}</s-heading>,
          ...plans.map((sub) => <PlanCard key={sub.id} sub={sub} copy={copy} act={act} locale={locale} />),
          // The Shopify-Payments contracts belong to the same section: to the
          // shopper they are simply more subscriptions — now rendered from the
          // same payload (account.contracts), no client-side Shopify read.
          ...contracts.map((contract) => (
            <ContractCard key={contract.gid} contract={contract} copy={copy} act={actContract} locale={locale} />
          )),
        );

        if (plans.length === 0 && contracts.length === 0) {
          children.push(
            <s-section key="subs-empty">
              <s-stack direction="block" gap="small-100" inlineAlignment="center">
                <s-text tone="subdued">{copy('empty_subscriptions')}</s-text>
              </s-stack>
            </s-section>,
          );
        }
        return;
      }

      if (key === 'upcoming') {
        children.push(<UpcomingList key="upcoming" rows={account.upcoming} copy={copy} locale={locale} />);
        return;
      }

      if (key === 'benefits') {
        if (Array.isArray(account.benefits) && account.benefits.length) {
          children.push(
            <s-section key="benefits">
              <s-stack direction="block" gap="small-200">
                <s-heading>{copy('benefits_heading')}</s-heading>
                {account.benefits.map((row, i) => (
                  <s-stack key={i} direction="block" gap="none">
                    <s-text>{row.label}</s-text>
                    {row.note && <s-text tone="subdued">{row.note}</s-text>}
                  </s-stack>
                ))}
              </s-stack>
            </s-section>,
          );
        }
        return;
      }

      if (key === 'loyalty') {
        children.push(<LoyaltyCard key="loyalty" loyalty={account.loyalty} copy={copy} />);
        return;
      }

      if (key === 'support') {
        children.push(<SupportCard key="support" support={account.support} copy={copy} />);
      }
    });

    // The rail offers close the column — secondary asks, after the plans.
    (account.rail_offers || []).forEach((offer) => {
      children.push(<OfferCard key={`rail-offer-${offer.id}`} offer={offer} copy={copy} act={act} locale={locale} />);
    });
  }

  return (
    <s-page heading={t('page.title')}>
      <s-stack direction="block" gap="base">
        {children}
      </s-stack>
    </s-page>
  );
}
