<?php

namespace App\Console\Commands;

use App\Domain\Installments\ImportedTokenRecovery;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusTokenDiscovery;
use App\Support\Tenant;
use Illuminate\Console\Command;

/**
 * Point a subscription at a saved card A HUMAN picked, from that member's own
 * PayPlus vault.
 *
 * The automatic rules refuse whenever the choice is not forced, and they are
 * right to: picking between two of somebody's cards is not a guess a machine
 * should make with their money. But the merchant looking at the same rows in
 * PayPlus can often see immediately which card is current — the vault screen
 * shows when each was added — and until now had no way to say so. The
 * subscription would sit unbilled because the code would not decide and the
 * person who could decide had no lever.
 *
 * This is that lever, and it keeps every wall that matters:
 *
 *   - The token MUST already be in this member's own vault. Candidates come from
 *     /Token/List for the customer records found by THEIR email, so a typo or a
 *     pasted token from another customer is refused rather than attached. Billing
 *     the wrong person's card every month is the one failure here that does not
 *     announce itself.
 *   - DRY RUN by default. It prints what it would change and writes nothing
 *     without --apply.
 *   - The write goes through ImportedTokenRecovery::apply(), so the customer_uid
 *     travels with the token and the Timeline records the swap — as ROUTE_MANUAL,
 *     which is the honest label: a person decided this, not an algorithm.
 *
 * Run with no --token to just LIST the member's cards. That is also how to read
 * PayPlus's own "added at" column, whose field name their docs do not publish.
 */
final class AttachPayPlusCard extends Command
{
    // === CONSTANTS ===
    protected $signature = 'payplus:attach-card
        {--shop= : the shop id (required)}
        {--plan= : the subscription id (required)}
        {--token= : the PayPlus token uid to attach — omit to just list the cards}
        {--apply : actually write it (default: dry run)}';

    protected $description = "Attach a saved PayPlus card you choose to a subscription, from that member's own vault.";

    public function handle(ImportedTokenRecovery $recovery): int
    {
        $shop = Shop::find((int) $this->option('shop'));

        if (! $shop instanceof Shop) {
            $this->error('Pass --shop=<id> of an existing shop.');

            return self::FAILURE;
        }

        return Tenant::run($shop, function () use ($shop, $recovery): int {
            $plan = InstallmentPlan::query()->find((int) $this->option('plan'));

            if ($plan === null) {
                $this->error('Pass --plan=<id> of a subscription in this shop.');

                return self::FAILURE;
            }

            $method = $plan->paymentMethod;

            if ($method === null) {
                $this->error('This subscription has no card record to re-point.');

                return self::FAILURE;
            }

            $probe = PayPlusTokenDiscovery::forShop($shop);

            if (! $probe->isConfigured()) {
                $this->error('This shop has no PayPlus connection configured.');

                return self::FAILURE;
            }

            $email = trim((string) $plan->customer_email);

            if ($email === '') {
                $this->error('This member has no email, so their PayPlus records cannot be found.');

                return self::FAILURE;
            }

            $held = trim((string) ($method->payplus_card_token_uid ?? ''));

            // Every card on every record carrying this exact email — the old
            // checkout minted a fresh customer per spelling of a name, so one
            // person's cards are spread across records.
            $cards = [];
            $ownerOf = [];

            foreach ($probe->customersByEmail($email) as $customer) {
                $uid = (string) ($customer['customer_uid'] ?? $customer['uid'] ?? '');

                foreach ($probe->tokens($uid) as $card) {
                    $cards[] = $card;
                    $ownerOf[(string) ($card['token'] ?? '')] = $uid;
                }
            }

            if ($cards === []) {
                $this->warn("PayPlus holds no saved card under {$email}.");

                return self::SUCCESS;
            }

            $this->line("Plan {$plan->getKey()} · ".($plan->customer_name ?: $email));
            $this->newLine();

            foreach ($cards as $card) {
                $token = (string) ($card['token'] ?? '');
                $mine = $held !== '' && $token === $held;

                $this->line(($mine ? '  * ' : '    ').json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            $this->newLine();
            $this->line('  * = the card this subscription currently points at');

            $wanted = trim((string) $this->option('token'));

            if ($wanted === '') {
                $this->newLine();
                $this->info('Re-run with --token=<uid> --apply to attach one of these.');

                return self::SUCCESS;
            }

            // THE WALL. Only a card already in this member's vault may be attached.
            if (! array_key_exists($wanted, $ownerOf)) {
                $this->error('That token is not one of this member\'s saved cards. Refusing.');

                return self::FAILURE;
            }

            if ($wanted === $held) {
                $this->info('That is already the card this subscription uses. Nothing to do.');

                return self::SUCCESS;
            }

            $this->newLine();
            $this->line('  was : …'.mb_substr($held === '' ? '(none)' : $held, -6));
            $this->line('  now : …'.mb_substr($wanted, -6));

            if (! $this->option('apply')) {
                $this->newLine();
                $this->warn('DRY RUN — nothing was written. Re-run with --apply.');

                return self::SUCCESS;
            }

            $ok = $recovery->apply($plan, [
                'route' => ImportedTokenRecovery::ROUTE_MANUAL,
                'token' => $wanted,
                'customer_uid' => $ownerOf[$wanted] ?: null,
                'recurring_live' => false,
            ]);

            if (! $ok) {
                $this->error('The write was refused.');

                return self::FAILURE;
            }

            $this->info('Attached. Charge the subscription from its screen to test it.');

            return self::SUCCESS;
        });
    }
}
