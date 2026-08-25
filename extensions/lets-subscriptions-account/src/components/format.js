// === Shared formatting for the PayPlus-rail account components ===
//
// Deliberately thin: the model arrives with every sentence written and every
// amount resolved server-side, so the only jobs left are JOINING an amount to
// the currency symbol the server chose, and writing a date in the PAYLOAD's
// locale (a Hebrew page must date in Hebrew whatever the browser speaks) —
// the exact rules public/account/lets-account.js already lives by.

/**
 * "89 ₪" — the server's number beside the server's symbol. Prefers the symbol
 * (₪) over the three-letter code (ILS); never recomputes or localises the
 * amount itself.
 */
export function money(amount, sub) {
  if (amount === null || amount === undefined) return '';
  const unit = (sub && (sub.currency_symbol || sub.currency)) || '';

  return unit ? `${amount} ${unit}` : String(amount);
}

/** A date the shopper reads, in the payload's locale. Falls back to the ISO string. */
export function formatDate(iso, locale) {
  if (!iso) return '';
  const d = new Date(`${iso}T00:00:00`);
  if (isNaN(d.getTime())) return String(iso);

  try {
    return d.toLocaleDateString(locale || undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  } catch {
    return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  }
}

/**
 * translate(key) with an EN-safe fallback, mirroring the upsell widget. Lives
 * here (not in SubscriptionsPage.jsx, its original home) so the account page
 * carries no import from the retired shopify.query read path.
 */
export function makeTranslator(i18n) {
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

/**
 * A dated sentence, whichever shape the copy catalog wrote it in: a {date}
 * placeholder, a :date one, or a lead-in the date follows — the same tolerance
 * the Woo renderer has, so one copy bag serves both.
 */
export function withDate(text, iso, locale) {
  const when = formatDate(iso, locale);
  if (!text) return when;
  if (!when) return String(text);
  if (String(text).indexOf('{date}') !== -1) return String(text).replace('{date}', when);
  if (String(text).indexOf(':date') !== -1) return String(text).replace(':date', when);

  return `${text} ${when}`;
}
