# Analytics sketch (approved 2026-11-23)

The merchant analytics screens as approved, in the LETS Agent panel language. This folder is the
reference for building them in the admin.

| File | What it is |
|---|---|
| `analytics-lets-agent.html` | **The approved design.** Six screens: joins, active subscriptions, churn, abandoned carts, revenue forecast, payment problems. Sidebar + black hero card + lime accent. |
| `analytics-first-draft.html` | The first, plainer draft (kept for history). |
| `extract*.php` | The READ-ONLY queries that filled the sketch with one shop's real numbers. |

The HTML files carry **no data**: the line `const DATA = /*DATA*/null;` is filled by the extract
scripts. The filled file holds real customers' names, emails and phones, so it is never committed.

## Rules the merchant set while reviewing

- Joins count only people who joined through the site and paid; imported members are left out.
- Retention ("passed month N" = also paid month N+1) and the net-subscribers chart start on
  2026-09-15, the day after the import's status corrections.
- Payment problems list only customers whose payment did not go through and is still unpaid,
  including plans the system paused for non-payment (`payment_failed_at` set).
- Abandoned carts = a non-imported plan still `awaiting_first_payment`; show who came back and bought.
- Free (`no_charge`) subscriptions count as active, and say so.
- Every person's name opens their subscription page.
