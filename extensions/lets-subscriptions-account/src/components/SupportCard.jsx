/** @jsxImportSource preact */
// === Support: how to reach the shop ===
//
// AccountPresenter's `support` slot: {email, url}. Link buttons only — a card
// with neither draws nothing at all.

export function SupportCard({ support, copy }) {
  const email = support?.email;
  const url = support?.url;
  if (!email && !url) return null;

  return (
    <s-section>
      <s-stack direction="block" gap="small-100">
        <s-heading>{copy('support_heading')}</s-heading>
        <s-stack direction="inline" gap="small-200">
          {email && (
            <s-button kind="secondary" href={`mailto:${email}`}>
              {email}
            </s-button>
          )}
          {url && (
            <s-button kind="secondary" href={url}>
              {copy('support_heading')}
            </s-button>
          )}
        </s-stack>
      </s-stack>
    </s-section>
  );
}
