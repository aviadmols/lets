<?php

namespace App\Console\Commands;

use App\Domain\Installments\ImportedTokenRecovery;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "What does PayPlus actually hold for the members whose charge just failed?"
 *
 * A CSV-migrated subscriber charges against the `card_token` the merchant's old
 * system exported. When PayPlus answers `this-token-not-exist`, the merchant has
 * three very different problems and no way to tell them apart:
 *
 *   - the exported value is not a PayPlus token at all (old system, other PSP);
 *   - it IS a PayPlus token, but minted on a DIFFERENT terminal;
 *   - PayPlus holds a perfectly good card for that customer under another uid.
 *
 * Only the first is unfixable without asking every customer to re-enter a card.
 * This asks PayPlus which one it is, across a whole day's failures at once —
 * the same question the subscription screen's button asks about one member, run
 * through the SAME service so the two can never answer differently.
 *
 * READ-ONLY by construction: it calls the recovery service's probe(), never its
 * apply(), and there is no --apply flag to add. Writing a recovered token is a
 * decision somebody makes per member, on the screen, with the answer in front
 * of them.
 */
final class ProbeImportedTokens extends Command
{
    // === CONSTANTS ===
    protected $signature = 'payplus:probe-tokens
        {--shop= : the shop id to probe (required)}
        {--date= : which day\'s failed charges to look at (default: today)}
        {--plan=* : probe these plan ids instead of a day\'s failures}
        {--terminal= : probe THIS terminal instead of the shop\'s (the old system\'s)}
        {--limit=25 : stop after this many plans}';

    protected $description = 'Ask PayPlus what it holds for imported members whose charge failed. Read-only.';

    /** Ledger statuses that mean "we tried and did not get the money". */
    private const FAILED_STATUSES = [
        LedgerStatus::FAILED->value,
        LedgerStatus::RETRY_SCHEDULED->value,
    ];

    /** Column width for the per-plan label. */
    private const LABEL_WIDTH = 26;

    /** What each route means for the merchant, in one line. */
    private const VERDICT = [
        ImportedTokenRecovery::ROUTE_RECURRING => 'RECOVERABLE — the old recurring still holds a live card token',
        ImportedTokenRecovery::ROUTE_EMAIL => 'RECOVERABLE — PayPlus holds a matching saved card for this email',
        ImportedTokenRecovery::ROUTE_ALREADY_VALID => 'token is VALID at PayPlus — the terminal is the problem, not the token',
        ImportedTokenRecovery::ROUTE_NONE => 'nothing PayPlus can safely give us',
    ];

    public function handle(ImportedTokenRecovery $recovery): int
    {
        $shop = Shop::find((int) $this->option('shop'));

        if (! $shop instanceof Shop) {
            $this->error('Pass --shop=<id> of an existing shop.');

            return self::FAILURE;
        }

        $plans = $this->plansToProbe($shop);

        if ($plans->isEmpty()) {
            $this->info('Nothing to probe: no failed charges matched.');

            return self::SUCCESS;
        }

        $this->line("Shop {$shop->getKey()} · {$plans->count()} plan(s)");
        $this->line('READ-ONLY: nothing is charged and nothing is written.');
        $this->newLine();

        $terminal = $this->option('terminal') ?: null;
        $tally = [];
        $live = 0;
        $unreachable = 0;

        foreach ($plans as $plan) {
            $outcome = Tenant::run($shop, fn (): array => $recovery->probe($plan, $terminal));

            $route = $outcome['route'];
            $tally[$route] = ($tally[$route] ?? 0) + 1;

            $label = mb_substr((string) ($plan->customer_name ?: $plan->customer_email ?: $plan->getKey()), 0, self::LABEL_WIDTH);
            $ok = in_array($route, [ImportedTokenRecovery::ROUTE_RECURRING, ImportedTokenRecovery::ROUTE_EMAIL], true);

            $this->line(sprintf(
                '  %s %s (plan %d) — %s%s',
                $ok ? '<fg=green>✓</>' : '<fg=red>✗</>',
                str_pad($label, self::LABEL_WIDTH),
                $plan->getKey(),
                self::VERDICT[$route] ?? $route,
                $outcome['detail'] !== '' ? " [{$outcome['detail']}]" : '',
            ));

            if ($outcome['detail'] === 'payplus_not_connected') {
                $unreachable++;
            }

            if ($outcome['recurring_live']) {
                $live++;
                $this->warn('       ⚠ still LIVE at PayPlus — it may be charging on its own');
            }
        }

        if ($unreachable === $plans->count()) {
            // Never let "we could not ask" read as "we asked and PayPlus said no".
            $this->newLine();
            $this->error('PayPlus was never reached: this shop\'s credentials could not be read here. Run this on production.');

            return self::FAILURE;
        }

        $this->summarise($plans->count(), $tally, $live);

        return self::SUCCESS;
    }

    /**
     * The plans this run asks about: explicit ids, else every plan whose ledger
     * shows a charge that failed on the chosen day.
     *
     * @return Collection<int, InstallmentPlan>
     */
    private function plansToProbe(Shop $shop): Collection
    {
        $ids = array_filter(array_map('intval', (array) $this->option('plan')));
        $limit = max(1, (int) $this->option('limit'));

        return Tenant::run($shop, function () use ($ids, $limit): Collection {
            $query = InstallmentPlan::query()->with('paymentMethod');

            if ($ids !== []) {
                return $query->whereKey($ids)->limit($limit)->get();
            }

            $day = Carbon::parse($this->option('date') ?: 'today');

            $failedPlanIds = PaymentLedger::query()
                ->whereIn('status', self::FAILED_STATUSES)
                ->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
                ->whereNotNull('plan_id')
                ->distinct()
                ->pluck('plan_id');

            return $query->whereKey($failedPlanIds)->limit($limit)->get();
        });
    }

    /** @param array<string, int> $tally */
    private function summarise(int $total, array $tally, int $live): void
    {
        $this->newLine();
        $this->line('<options=bold>Summary</>');

        $rows = [];
        foreach (self::VERDICT as $route => $meaning) {
            $rows[] = [$route, ($tally[$route] ?? 0).' / '.$total, $meaning];
        }
        $this->table(['route', 'members', 'meaning'], $rows);

        if ($live > 0) {
            $this->warn("{$live} recurring(s) are still LIVE at PayPlus — cancel them there before resuming charging, or these members get billed twice.");
        }

        $recoverable = ($tally[ImportedTokenRecovery::ROUTE_RECURRING] ?? 0)
            + ($tally[ImportedTokenRecovery::ROUTE_EMAIL] ?? 0);

        if ($recoverable > 0) {
            $this->info("{$recoverable} member(s) can be fixed from the subscription screen: open one and press \"Find saved card\", then charge it.");

            return;
        }

        if (($tally[ImportedTokenRecovery::ROUTE_ALREADY_VALID] ?? 0) === $total && $total > 0) {
            $this->info('Every token is valid at PayPlus: the failure is the TERMINAL. Re-run with --terminal=<the old one> to confirm.');

            return;
        }

        $this->warn('No token could be recovered. The remaining route is asking each customer to re-enter their card.');
    }
}
