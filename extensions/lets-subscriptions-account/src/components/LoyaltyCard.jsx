/** @jsxImportSource preact */
// === The loyalty club, as much of it as a personal-area card needs ===
//
// AccountPresenter's `loyalty` slot: program_name, balance {points, formatted,
// credit}, status {tier}. Everything arrives pre-formatted server-side — the
// balance string, the credit-worth string — so this card only lays them out.

export function LoyaltyCard({ loyalty, copy }) {
  if (!loyalty) return null;

  const balance = loyalty.balance || {};
  const status = loyalty.status || {};

  return (
    <s-section>
      <s-stack direction="block" gap="small-100">
        <s-heading>{loyalty.program_name || copy('loyalty_heading')}</s-heading>
        <s-text emphasis="bold">{balance.formatted || String(balance.points || 0)}</s-text>
        <s-text tone="subdued">{copy('points_balance')}</s-text>
        {status.tier && (
          <s-text tone="subdued">
            {copy('tier')}: {status.tier}
          </s-text>
        )}
        {balance.credit && (
          <s-text tone="subdued">
            {copy('points_worth')} {balance.credit}
          </s-text>
        )}
      </s-stack>
    </s-section>
  );
}
