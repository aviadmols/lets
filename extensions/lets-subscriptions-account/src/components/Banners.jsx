/** @jsxImportSource preact */
// === The merchant's banners, both placements ===
//
// AccountPresenter ships two slots — `top_banners` (the full-width strip) and
// `banners` (the side rail). This page is a single column, so the rail ones
// simply stack after the top ones; the card markup is identical for both, the
// same rule that keeps the Woo renderer's two placements from drifting into
// two designs. Each banner: heading, subtext, image_url, link_url.

export function Banners({ topBanners, railBanners }) {
  const all = [...(Array.isArray(topBanners) ? topBanners : []), ...(Array.isArray(railBanners) ? railBanners : [])];
  if (!all.length) return null;

  return (
    <s-stack direction="block" gap="base">
      {all.map((banner, i) => (
        <Banner key={i} banner={banner} />
      ))}
    </s-stack>
  );
}

function Banner({ banner }) {
  if (!banner) return null;

  return (
    <s-section>
      <s-stack direction="inline" gap="base" blockAlignment="center">
        {banner.image_url && (
          <s-image src={banner.image_url} alt={banner.heading || ''} aspectRatio="1" inlineSize="small" borderRadius="base" />
        )}
        <s-stack direction="block" gap="none">
          {/* A banner with a link is reachable through its heading; one without
              is plain text rather than a fake button that goes nowhere. */}
          {banner.heading &&
            (banner.link_url ? (
              <s-button kind="plain" href={banner.link_url}>
                {banner.heading}
              </s-button>
            ) : (
              <s-text emphasis="bold">{banner.heading}</s-text>
            ))}
          {banner.subtext && <s-text tone="subdued">{banner.subtext}</s-text>}
        </s-stack>
      </s-stack>
    </s-section>
  );
}
