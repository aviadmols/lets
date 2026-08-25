/** @jsxImportSource preact */
// === What is coming: the benefit / charge timeline ===
//
// AccountPresenter's `upcoming` rows: {kind, tone, at, amount, points,
// remaining, label}. The row's SENTENCE is the copy bag's `benefit_{kind}` —
// pre-translated server-side — and the meta joins whatever facts the row
// carries, exactly the way the Woo renderer does.

import { money, formatDate } from './format.js';

export function UpcomingList({ rows, copy, locale }) {
  const list = Array.isArray(rows) ? rows : [];

  return (
    <s-section>
      <s-stack direction="block" gap="base">
        <s-heading>{copy('upcoming_heading')}</s-heading>

        {!list.length && <s-text tone="subdued">{copy('empty_upcoming')}</s-text>}

        {list.map((row, i) => {
          const meta = [];
          if (row.label) meta.push(row.label);
          if (row.amount !== null && row.amount !== undefined) meta.push(money(row.amount, null));
          if (row.points) meta.push(`+${row.points}`);
          if (row.remaining !== null && row.remaining !== undefined) meta.push(money(row.remaining, null));

          return (
            <s-stack key={i} direction="block" gap="none">
              <s-text emphasis="bold">
                {row.at ? `${formatDate(row.at, locale)} · ` : ''}
                {copy(`benefit_${row.kind}`, row.kind)}
              </s-text>
              {meta.length > 0 && <s-text tone="subdued">{meta.join(' · ')}</s-text>}
            </s-stack>
          );
        })}
      </s-stack>
    </s-section>
  );
}
