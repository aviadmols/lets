<?php

namespace App\Console\Commands;

use App\Domain\Installments\ImportedTokenRecovery;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusTokenDiscovery;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;

/**
 * Recover the real PayPlus token for a whole migrated book, in one pass.
 *
 * The subscription screen's button answers this for one member. A store that
 * migrated 1,300 of them needs the same question asked 1,300 times, and — far
 * more importantly — needs to be told WHO IT COULD NOT FIX, because those are
 * the people who must be asked to re-enter a card before their next charge date.
 * The report is the point of this command; the writing is the easy half.
 *
 * DRY RUN BY DEFAULT. It asks PayPlus and prints exactly what it would change,
 * and writes nothing at all unless --apply is passed and confirmed. That is the
 * same shape every other bulk money act in this system takes, for the same
 * reason: a thousand rows changed on a wrong assumption is not a thing anyone
 * can undo by hand.
 *
 * Safe to re-run. Members already carrying a PayPlus customer uid are skipped,
 * so an interrupted pass continues where it stopped rather than re-asking
 * PayPlus about everybody.
 */
final class RecoverImportedTokens extends Command
{
    // === CONSTANTS ===
    protected $signature = 'payplus:recover-tokens
        {--shop= : the shop id (required)}
        {--apply : actually write the recovered tokens (default: dry run)}
        {--routes=email : which routes to try — check,recurring,email (comma separated)}
        {--limit=0 : stop after this many members (0 = all)}
        {--sleep=0 : milliseconds to wait between members, to be gentle on PayPlus}
        {--report= : where to write the CSV (default: storage/app/token-recovery-<shop>-<stamp>.csv)}
        {--include-inactive : also touch cancelled/completed plans (default: skip them)}
        {--redo : re-ask about members already carrying a PayPlus customer uid}';

    protected $description = 'Recover PayPlus tokens for migrated members, and report who could not be fixed.';

    /** Rows read per chunk. Each row costs HTTP calls, so this stays small. */
    private const CHUNK = 100;

    /** Progress is printed every this many members. */
    private const TICK = 25;

    /** Statuses that will never bill again, so are not worth an API call. */
    private const DEAD_STATUSES = [
        PlanStatus::CANCELLED->value,
        PlanStatus::COMPLETED->value,
    ];

    private const CSV_HEADER = [
        'plan_id', 'public_id', 'customer_name', 'customer_email',
        'amount', 'next_charge_at', 'plan_status',
        'card_last_four', 'route', 'detail', 'recovered', 'recurring_live_at_payplus',
    ];

    public function handle(ImportedTokenRecovery $recovery): int
    {
        $shop = Shop::find((int) $this->option('shop'));

        if (! $shop instanceof Shop) {
            $this->error('Pass --shop=<id> of an existing shop.');

            return self::FAILURE;
        }

        $routes = $this->routes();

        if ($routes === []) {
            $this->error('--routes must name at least one of: check, recurring, email.');

            return self::FAILURE;
        }

        // Whole-shop condition: without keys every member would "fail" for the
        // same reason, and a report naming 1,170 people we never actually asked
        // about is worse than no report.
        if (! PayPlusTokenDiscovery::forShop($shop)->isConfigured()) {
            $this->error("Shop {$shop->getKey()}'s PayPlus credentials could not be read here. Run this on production.");

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $total = $this->countTargets($shop);

        if ($total === 0) {
            $this->info('Nothing to do: no migrated members are waiting for a token.');

            return self::SUCCESS;
        }

        $this->line("Shop {$shop->getKey()} · {$total} migrated member(s) · routes: ".implode(', ', $routes));

        if (! $apply) {
            $this->warn('DRY RUN — PayPlus will be asked, but nothing will be written. Re-run with --apply to save.');
        } elseif (! $this->confirm("Write recovered tokens onto up to {$total} payment methods?", false)) {
            $this->info('Nothing written.');

            return self::SUCCESS;
        }

        return $this->sweep($recovery, $shop, $routes, $apply, $total);
    }

    private function sweep(ImportedTokenRecovery $recovery, Shop $shop, array $routes, bool $apply, int $total): int
    {
        $path = $this->reportPath($shop);
        File::ensureDirectoryExists(dirname($path));
        $csv = fopen($path, 'w');
        fputcsv($csv, self::CSV_HEADER);

        $limit = (int) $this->option('limit');
        $sleepUs = max(0, (int) $this->option('sleep')) * 1000;

        $seen = 0;
        $recovered = 0;
        $live = 0;
        $byReason = [];
        $failures = [];

        Tenant::run($shop, function () use (
            $recovery, $routes, $apply, $csv, $limit, $sleepUs, $total,
            &$seen, &$recovered, &$live, &$byReason, &$failures
        ): void {
            $this->targets()->chunkById(self::CHUNK, function ($plans) use (
                $recovery, $routes, $apply, $csv, $limit, $sleepUs, $total,
                &$seen, &$recovered, &$live, &$byReason, &$failures
            ): bool {
                foreach ($plans as $plan) {
                    if ($limit > 0 && $seen >= $limit) {
                        return false; // stop chunking
                    }

                    $outcome = $recovery->probe($plan, null, $routes);
                    $ok = $apply
                        ? $recovery->apply($plan, $outcome)
                        : in_array($outcome['route'], [ImportedTokenRecovery::ROUTE_RECURRING, ImportedTokenRecovery::ROUTE_EMAIL], true);

                    $seen++;
                    $ok ? $recovered++ : null;

                    if ($outcome['recurring_live']) {
                        $live++;
                    }

                    if (! $ok) {
                        $reason = $outcome['detail'] ?: $outcome['route'];
                        $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
                        $failures[] = $plan;
                    }

                    fputcsv($csv, [
                        $plan->getKey(),
                        (string) $plan->public_id,
                        (string) $plan->customer_name,
                        (string) $plan->customer_email,
                        (string) $plan->installment_amount,
                        (string) $plan->next_charge_at,
                        (string) ($plan->status instanceof PlanStatus ? $plan->status->value : $plan->status),
                        (string) $plan->paymentMethod?->card_last_four,
                        $outcome['route'],
                        $outcome['detail'],
                        $ok ? 'yes' : 'no',
                        $outcome['recurring_live'] ? 'yes' : 'no',
                    ]);

                    if ($seen % self::TICK === 0) {
                        $this->line("  … {$seen} / {$total} · recovered {$recovered}");
                    }

                    if ($sleepUs > 0) {
                        usleep($sleepUs);
                    }
                }

                return true;
            });
        });

        fclose($csv);

        $this->report($seen, $recovered, $live, $byReason, $failures, $path, $apply);

        return self::SUCCESS;
    }

    /** The migrated members still waiting for a real token. */
    private function targets(): Builder
    {
        $query = InstallmentPlan::query()
            ->with('paymentMethod')
            ->whereHas('paymentMethod', function ($q): void {
                // An imported method: it carries the old system's reference.
                $q->whereNotNull('payplus_token_reference');

                if (! $this->option('redo')) {
                    // A recovered (or natively vaulted) card always has this.
                    $q->whereNull('payplus_customer_uid');
                }
            })
            ->orderBy('id');

        if (! $this->option('include-inactive')) {
            $query->whereNotIn('status', self::DEAD_STATUSES);
        }

        return $query;
    }

    private function countTargets(Shop $shop): int
    {
        return (int) Tenant::run($shop, fn (): int => $this->targets()->count());
    }

    /** @return list<string> */
    private function routes(): array
    {
        $map = [
            'check' => ImportedTokenRecovery::ROUTE_ALREADY_VALID,
            'recurring' => ImportedTokenRecovery::ROUTE_RECURRING,
            'email' => ImportedTokenRecovery::ROUTE_EMAIL,
        ];

        $asked = array_filter(array_map('trim', explode(',', (string) $this->option('routes'))));

        return array_values(array_filter(array_map(
            static fn (string $r): ?string => $map[strtolower($r)] ?? null,
            $asked,
        )));
    }

    private function reportPath(Shop $shop): string
    {
        return (string) ($this->option('report')
            ?: storage_path('app/token-recovery-'.$shop->getKey().'-'.now()->format('Ymd-His').'.csv'));
    }

    /**
     * @param  array<string, int>  $byReason
     * @param  list<InstallmentPlan>  $failures
     */
    private function report(int $seen, int $recovered, int $live, array $byReason, array $failures, string $path, bool $apply): void
    {
        $failed = $seen - $recovered;

        $this->newLine();
        $this->line('<options=bold>'.($apply ? 'Done' : 'Dry run — nothing was written').'</>');
        $this->table(['', 'members'], [
            ['asked about', $seen],
            [$apply ? 'token recovered' : 'would be recovered', $recovered],
            ['COULD NOT BE FIXED', $failed],
        ]);

        if ($byReason !== []) {
            arsort($byReason);
            $this->line('<options=bold>Why they could not be fixed</>');
            $rows = [];
            foreach ($byReason as $reason => $count) {
                $rows[] = [$reason, $count, self::MEANING[$reason] ?? ''];
            }
            $this->table(['reason', 'members', 'what it means'], $rows);
        }

        if ($failures !== []) {
            $this->line('<options=bold>Members to ask for a new card</>');
            $rows = [];
            foreach ($failures as $plan) {
                $rows[] = [
                    $plan->getKey(),
                    mb_substr((string) $plan->customer_name, 0, 24),
                    (string) $plan->customer_email,
                    (string) $plan->installment_amount,
                    substr((string) $plan->next_charge_at, 0, 10),
                ];
            }
            $this->table(['plan', 'customer', 'email', 'amount', 'next charge'], $rows);
        }

        if ($live > 0) {
            $this->warn("{$live} member(s) are STILL being billed by PayPlus on its own schedule. Cancel those there before charging, or they pay twice.");
        }

        $this->info("Full report (every member, recovered or not): {$path}");

        if (! $apply && $recovered > 0) {
            $this->info('Re-run with --apply to save these tokens.');
        }
    }

    /** Plain-language meaning for each reason the CSV can carry. */
    private const MEANING = [
        'no_last_four_to_match_on' => 'we hold no last-4, so a card at PayPlus cannot be matched safely',
        'no_card_matched' => 'PayPlus knows them but no saved card matches the one we hold',
        'not_found_at_payplus' => 'PayPlus has no customer with that email',
        'no_payment_method' => 'this plan has no saved card at all',
        'payplus_not_connected' => 'the shop has no usable PayPlus credentials',
        'token_exists_at_payplus' => 'their token is already valid — the failure is the terminal, not the token',
    ];
}
