<?php

namespace App\Domain\Campaigns\Email;

use App\Domain\Campaigns\Email\Models\CampaignUnsubscribe;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Models\InstallmentPlan;
use App\Models\LoyaltyAccount;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WHO a campaign goes to — the audience bag, evaluated across everything LETS
 * knows about a shop's customers.
 *
 * Three sources, united: SUBSCRIBERS (recurring plans on the PayPlus rail +
 * Shopify-Payments contracts), PURCHASERS (deposit/installment plans — the one
 * kind of store purchase this app itself charged), and LOYALTY MEMBERS (one row
 * per person, whether or not they hold a plan). The status, cadence and product
 * filters narrow the plan-shaped rows; the tier filter narrows the members.
 *
 * OR a NAMED LIST. When the bag carries typed addresses, they REPLACE the rules
 * entirely — the merchant is naming people, not describing a segment. Each one
 * is still resolved against everything we know (so the mail can greet them and
 * sign them in); an address that matches nobody is written to as a `manual` row.
 *
 * A customer is a PERSON, not a row: the union is deduped by lower-cased email,
 * and a row without an email is dropped — an email campaign cannot reach it,
 * and guessing that two anonymous rows are one human is how somebody gets
 * written to twice.
 *
 * Tenant-bound by the models' BelongsToShop scope — this class never names a
 * shop_id; the caller (the admin page, the send job under TenantContext) binds it.
 *
 * Two SQL rules carried over from the account offers, because they bit there:
 *   - `customer_id` (bigint) is only ever null-checked, never compared — Postgres
 *     aborts a bigint-vs-text comparison rather than not matching.
 *   - product OR-branches are guarded with IS NOT NULL, because three-valued
 *     logic makes `NULL IN (...)` disappear from an OR in ways sqlite hides.
 */
final class EmailCampaignAudience
{
    // === CONSTANTS ===
    /** The statuses a contract mirror carries, keyed by the PlanStatus value a merchant picks. */
    public const CONTRACT_STATUS_FOR = [
        PlanStatus::ACTIVE->value => SubscriptionContract::STATUS_ACTIVE,
        PlanStatus::PAUSED->value => SubscriptionContract::STATUS_PAUSED,
        PlanStatus::CANCELLED->value => SubscriptionContract::STATUS_CANCELLED,
        PlanStatus::AWAITING_PAYMENT->value => SubscriptionContract::STATUS_FAILED,
        PlanStatus::FAILED->value => SubscriptionContract::STATUS_FAILED,
        PlanStatus::COMPLETED->value => SubscriptionContract::STATUS_EXPIRED,
    ];

    /** The rail label each row carries, for the merchant's preview list. */
    public const RAIL_PAYPLUS = 'payplus';

    public const RAIL_SHOPIFY = 'shopify';

    public const RAIL_LOYALTY = 'loyalty';

    /** An address the merchant typed that matches nobody this app knows. */
    public const RAIL_MANUAL = 'manual';

    /**
     * How many rows a capped SAMPLE reads per row it means to show, on the one
     * rail whose filters run in PHP rather than SQL. @see fromContracts()
     */
    private const SAMPLE_OVERFETCH = 5;

    /**
     * Everyone the bag reaches, one row per PERSON.
     *
     * @param  array<string, mixed>  $audience  raw or cleaned bag (cleaned again here)
     * @return Collection<int, array{
     *     source_type: string, source_id: int, email: string, name: ?string,
     *     customer_ref: ?string, rail: string, already_enrolled: bool, unsubscribed: bool
     * }>
     */
    public function recipients(array $audience, ?EmailCampaign $campaign = null, ?int $perSource = null): Collection
    {
        $bag = EmailCampaign::cleanAudience($audience);

        // A NAMED LIST REPLACES THE RULES. "Send this to these five people" is
        // not a narrowing of "active subscribers of product X" — read as one it
        // would silently drop the cancelled member the merchant meant to write
        // to. The rules answer "who is this for"; the list answers "who", full
        // stop, and the form says so above the field.
        if ($bag['emails'] !== []) {
            $rows = $this->fromNamedEmails($bag['emails']);
            $rows = $this->flagUnsubscribed($rows);

            return ($campaign !== null ? $this->flagEnrolled($rows, $campaign) : $rows)->values();
        }

        $sources = $bag['sources'] === [] ? EmailCampaign::SOURCES : $bag['sources'];

        $rows = collect();

        if (in_array(EmailCampaign::SOURCE_SUBSCRIBERS, $sources, true)) {
            $rows = $rows
                ->concat($this->fromPlans($bag, PlanKind::RECURRING, $perSource))
                ->concat($this->fromContracts($bag, $perSource));
        }

        if (in_array(EmailCampaign::SOURCE_PURCHASERS, $sources, true)) {
            $rows = $rows->concat($this->fromPlans($bag, PlanKind::INSTALLMENTS, $perSource));
        }

        if (in_array(EmailCampaign::SOURCE_LOYALTY_MEMBERS, $sources, true)) {
            $rows = $rows->concat($this->fromLoyalty($bag, $perSource));
        }

        $rows = $this->dedupeByEmail($rows);
        $rows = $this->flagUnsubscribed($rows);

        if ($campaign !== null) {
            $rows = $this->flagEnrolled($rows, $campaign);
        }

        return $rows->values();
    }

    /** How many people the bag reaches today (suppressed addresses excluded). */
    public function count(array $audience): int
    {
        return $this->recipients($audience)
            ->reject(static fn (array $row): bool => $row['unsubscribed'])
            ->count();
    }

    /**
     * The first N rows, for the merchant's "who would get this" preview.
     *
     * The limit is pushed INTO each source query rather than applied to the
     * finished list. Building the whole audience — every subscriber, contract
     * and club member a shop has — to then show ten of them made opening a modal
     * cost the same as sending the campaign, and on the shops this app is built
     * for that is thousands of hydrated rows for a glance.
     *
     * A sample is honestly a sample: taking N per source and deduping can return
     * fewer than N, and the two people it shows are the two it would write to
     * first. The exact number lives in count(), which the screen shows beside it.
     */
    public function sample(array $audience, ?EmailCampaign $campaign = null, int $limit = 100): Collection
    {
        $limit = max(1, $limit);

        return $this->recipients($audience, $campaign, $limit)->take($limit)->values();
    }

    // === Sources ===

    /**
     * The addresses the merchant TYPED, in the order they typed them.
     *
     * Each one is looked up across everything this app knows — and deliberately
     * WITHOUT the rule filters: the merchant named a person, not a segment, so a
     * cancelled subscriber or a member of the wrong tier is still that person.
     * A match hands over the name and the customer_ref, which is what makes
     * `{customer_name}` and `{account_login_url}` work for them; an address that
     * matches nobody is still written to, as a `manual` row with no reference.
     *
     * @param  list<string>  $emails  already lower-cased and validated by the bag's guard
     * @return Collection<int, array<string, mixed>>
     */
    private function fromNamedEmails(array $emails): Collection
    {
        $known = [];

        // Plans first, then contracts, then memberships — the same precedence
        // the rule path dedupes by, so one person resolves to one row either way.
        foreach ($this->lookupPlans($emails) as $row) {
            $known[$row['email']] ??= $row;
        }
        foreach ($this->lookupContracts($emails) as $row) {
            $known[$row['email']] ??= $row;
        }
        foreach ($this->lookupLoyalty($emails) as $row) {
            $known[$row['email']] ??= $row;
        }

        $rows = [];
        foreach ($emails as $email) {
            $rows[] = $known[$email] ?? [
                'source_type' => EmailCampaignRecipient::SOURCE_MANUAL,
                // No row of ours stands behind this address. 0 rather than a
                // made-up id: the recipient's source must never point at some
                // other customer's plan.
                'source_id' => 0,
                'email' => $email,
                'name' => null,
                'customer_ref' => null,
                'rail' => self::RAIL_MANUAL,
                'already_enrolled' => false,
                'unsubscribed' => false,
            ];
        }

        return collect($rows);
    }

    /**
     * @param  list<string>  $emails
     * @return Collection<int, array<string, mixed>>
     */
    private function lookupPlans(array $emails): Collection
    {
        return InstallmentPlan::query()
            ->whereIn(DB::raw('lower(customer_email)'), $emails)
            ->orderBy('id')
            ->get()
            ->map(fn (InstallmentPlan $plan): ?array => $this->row(
                EmailCampaignRecipient::SOURCE_PLAN,
                (int) $plan->getKey(),
                $plan->customer_email,
                $plan->customer_name,
                $this->planRef($plan),
                self::RAIL_PAYPLUS,
            ))
            ->filter();
    }

    /**
     * @param  list<string>  $emails
     * @return Collection<int, array<string, mixed>>
     */
    private function lookupContracts(array $emails): Collection
    {
        return SubscriptionContract::query()
            ->whereIn(DB::raw('lower(customer_email)'), $emails)
            ->orderBy('id')
            ->get()
            ->map(fn (SubscriptionContract $c): ?array => $this->row(
                EmailCampaignRecipient::SOURCE_CONTRACT,
                (int) $c->getKey(),
                $c->customer_email,
                $c->customer_name,
                $this->gidTail($c->shopify_customer_gid),
                self::RAIL_SHOPIFY,
            ))
            ->filter();
    }

    /**
     * @param  list<string>  $emails
     * @return Collection<int, array<string, mixed>>
     */
    private function lookupLoyalty(array $emails): Collection
    {
        return LoyaltyAccount::query()
            ->whereIn(DB::raw('lower(customer_email)'), $emails)
            ->orderBy('id')
            ->get()
            ->map(fn (LoyaltyAccount $a): ?array => $this->row(
                EmailCampaignRecipient::SOURCE_LOYALTY,
                (int) $a->getKey(),
                $a->customer_email,
                $a->customer_name,
                $a->customer_ref,
                self::RAIL_LOYALTY,
            ))
            ->filter();
    }

    /**
     * The PayPlus rail, one kind at a time: recurring = subscribers, installments
     * = purchasers. Status, cadence and product filters all apply in SQL.
     *
     * @param  array{statuses: list<string>, frequencies: list<string>, product_ids: list<string>}  $bag
     * @return Collection<int, array<string, mixed>>
     */
    private function fromPlans(array $bag, PlanKind $kind, ?int $limit = null): Collection
    {
        $query = InstallmentPlan::query()
            ->where('plan_kind', $kind->value)
            ->whereIn('status', $bag['statuses'])
            ->whereNotNull('customer_email')
            ->where('customer_email', '<>', '');

        if ($bag['frequencies'] !== [] && $kind === PlanKind::RECURRING) {
            $query->whereIn('billing_frequency', $bag['frequencies']);
        }

        if ($bag['product_ids'] !== []) {
            $query->where(fn (Builder $q): Builder => $this->productIn($q, $bag['product_ids']));
        }

        return $query
            ->select(['id', 'customer_email', 'customer_name', 'shopify_customer_id', 'customer_id'])
            ->orderBy('id')
            ->when($limit !== null, fn (Builder $q) => $q->limit($limit))
            ->get()
            ->map(fn (InstallmentPlan $plan): ?array => $this->row(
                EmailCampaignRecipient::SOURCE_PLAN,
                (int) $plan->getKey(),
                $plan->customer_email,
                $plan->customer_name,
                $this->planRef($plan),
                self::RAIL_PAYPLUS,
            ))
            ->filter();
    }

    /**
     * The Shopify-Payments rail. Status is mapped from the merchant's PlanStatus
     * vocabulary; cadence via the contract's interval; products in PHP against
     * the mirrored lines (a JSON column every database queries differently).
     *
     * @param  array{statuses: list<string>, frequencies: list<string>, product_ids: list<string>}  $bag
     * @return Collection<int, array<string, mixed>>
     */
    private function fromContracts(array $bag, ?int $limit = null): Collection
    {
        $statuses = [];
        foreach ($bag['statuses'] as $status) {
            if (isset(self::CONTRACT_STATUS_FOR[$status])) {
                $statuses[] = self::CONTRACT_STATUS_FOR[$status];
            }
        }

        // A status list that maps to nothing on this rail (draft, awaiting first
        // payment) reaches no contracts — an honest zero, not everyone.
        if ($statuses === []) {
            return collect();
        }

        return SubscriptionContract::query()
            ->whereIn('status', $statuses)
            ->whereNotNull('customer_email')
            ->where('customer_email', '<>', '')
            ->select(['id', 'customer_email', 'customer_name', 'shopify_customer_gid', 'lines', 'interval', 'interval_count'])
            ->orderBy('id')
            // Cadence and product are filtered in PHP on this rail (a mirrored
            // JSON column every database queries differently), so a preview's cap
            // has to be taken BEFORE the filters and therefore over-fetched — cut
            // to exactly $limit here and a sample could come back empty while
            // plenty of contracts matched. This bounds the read without pretending
            // the filter happened in SQL.
            ->when($limit !== null, fn (Builder $q) => $q->limit($limit * self::SAMPLE_OVERFETCH))
            ->get()
            ->filter(fn (SubscriptionContract $c): bool => $this->contractCadenceMatches($c, $bag['frequencies']))
            ->filter(fn (SubscriptionContract $c): bool => $this->contractSells($c, $bag['product_ids']))
            ->map(fn (SubscriptionContract $c): ?array => $this->row(
                EmailCampaignRecipient::SOURCE_CONTRACT,
                (int) $c->getKey(),
                $c->customer_email,
                $c->customer_name,
                $this->gidTail($c->shopify_customer_gid),
                self::RAIL_SHOPIFY,
            ))
            ->filter();
    }

    /**
     * Club members: one row per person already. Only the tier filter applies —
     * a membership has no status, cadence or product.
     *
     * @param  array{loyalty_tier_ids: list<int>}  $bag
     * @return Collection<int, array<string, mixed>>
     */
    private function fromLoyalty(array $bag, ?int $limit = null): Collection
    {
        return LoyaltyAccount::query()
            ->whereNotNull('customer_email')
            ->where('customer_email', '<>', '')
            ->when($bag['loyalty_tier_ids'] !== [], fn (Builder $q) => $q->whereIn('tier_id', $bag['loyalty_tier_ids']))
            ->select(['id', 'customer_email', 'customer_name', 'customer_ref'])
            ->orderBy('id')
            ->when($limit !== null, fn (Builder $q) => $q->limit($limit))
            ->get()
            ->map(fn (LoyaltyAccount $a): ?array => $this->row(
                EmailCampaignRecipient::SOURCE_LOYALTY,
                (int) $a->getKey(),
                $a->customer_email,
                $a->customer_name,
                $a->customer_ref,
                self::RAIL_LOYALTY,
            ))
            ->filter();
    }

    // === Filters ===

    /**
     * One column per platform: WooCommerce fills external_product_id, the
     * Shopify-via-PayPlus rail fills shopify_product_id. Each branch is guarded
     * with IS NOT NULL so a null product can never slip through the OR.
     *
     * @param  list<string>  $ids
     */
    private function productIn(Builder $query, array $ids): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNotNull('external_product_id')->whereIn('external_product_id', $ids))
            ->orWhere(fn (Builder $q) => $q->whereNotNull('shopify_product_id')->whereIn('shopify_product_id', $ids));
    }

    /** @param list<string> $frequencies empty = any */
    private function contractCadenceMatches(SubscriptionContract $contract, array $frequencies): bool
    {
        if ($frequencies === []) {
            return true;
        }

        $frequency = SubscriptionContract::INTERVAL_FREQUENCY[strtoupper((string) $contract->interval)] ?? null;

        return $frequency !== null && in_array($frequency, $frequencies, true);
    }

    /**
     * Does this contract renew one of the chosen products? Mirrored lines carry
     * a gid; the filter holds bare ids — both spellings are accepted. A contract
     * mirrored without lines is EXCLUDED from a product-narrowed audience rather
     * than assumed in (the same call GiftEligibility makes).
     *
     * @param  list<string>  $ids  empty = every product
     */
    private function contractSells(SubscriptionContract $contract, array $ids): bool
    {
        if ($ids === []) {
            return true;
        }

        foreach ((array) ($contract->lines ?? []) as $line) {
            $gid = (string) (is_array($line) ? ($line['product_id'] ?? '') : '');
            if ($gid === '') {
                continue;
            }

            $numeric = str_contains($gid, '/') ? (string) Str::afterLast($gid, '/') : $gid;

            if (in_array($numeric, $ids, true) || in_array($gid, $ids, true)) {
                return true;
            }
        }

        return false;
    }

    // === Shaping ===

    /**
     * One display/enrolment row, or null when the address is unusable.
     *
     * @return array<string, mixed>|null
     */
    private function row(string $sourceType, int $sourceId, mixed $email, mixed $name, ?string $ref, string $rail): ?array
    {
        $email = is_string($email) ? mb_strtolower(trim($email)) : '';
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $name = is_string($name) ? trim($name) : '';

        return [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'email' => $email,
            'name' => $name !== '' ? mb_substr($name, 0, 255) : null,
            'customer_ref' => $ref !== null && $ref !== '' ? mb_substr($ref, 0, 64) : null,
            'rail' => $rail,
            'already_enrolled' => false,
            'unsubscribed' => false,
        ];
    }

    /**
     * The reference the personal area will recognise this person by — the same
     * precedence PortalSignedUrlService and CustomerPlans use, as a bare value.
     */
    private function planRef(InstallmentPlan $plan): ?string
    {
        foreach ([$plan->external_customer_id, $plan->shopify_customer_id] as $candidate) {
            $candidate = trim((string) ($candidate ?? ''));
            if ($candidate !== '' && $candidate !== '0') {
                return $candidate;
            }
        }

        return $plan->customer_id !== null ? (string) $plan->customer_id : null;
    }

    private function gidTail(mixed $gid): ?string
    {
        $gid = trim((string) ($gid ?? ''));
        if ($gid === '') {
            return null;
        }

        return str_contains($gid, '/') ? (string) Str::afterLast($gid, '/') : $gid;
    }

    /**
     * One email per PERSON. The first row for an address wins (plans before
     * contracts before memberships, oldest first within each), but a later row's
     * name or reference fills a gap the winner left — a member row often knows
     * the name a guest plan does not.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function dedupeByEmail(Collection $rows): Collection
    {
        $byEmail = [];

        foreach ($rows as $row) {
            $email = $row['email'];

            if (! isset($byEmail[$email])) {
                $byEmail[$email] = $row;

                continue;
            }

            if ($byEmail[$email]['name'] === null && $row['name'] !== null) {
                $byEmail[$email]['name'] = $row['name'];
            }
            if ($byEmail[$email]['customer_ref'] === null && $row['customer_ref'] !== null) {
                $byEmail[$email]['customer_ref'] = $row['customer_ref'];
            }
        }

        return collect(array_values($byEmail));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function flagUnsubscribed(Collection $rows): Collection
    {
        $suppressed = CampaignUnsubscribe::suppressedSet();
        if ($suppressed === []) {
            return $rows;
        }

        return $rows->map(static function (array $row) use ($suppressed): array {
            $row['unsubscribed'] = isset($suppressed[$row['email']]);

            return $row;
        });
    }

    /**
     * Mark the people this campaign already enrolled, so a re-run's preview says
     * "already in" instead of promising a second copy.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function flagEnrolled(Collection $rows, EmailCampaign $campaign): Collection
    {
        $enrolled = EmailCampaignRecipient::query()
            ->where('email_campaign_id', $campaign->getKey())
            ->pluck('email')
            ->flip();

        if ($enrolled->isEmpty()) {
            return $rows;
        }

        return $rows->map(static function (array $row) use ($enrolled): array {
            $row['already_enrolled'] = $enrolled->has($row['email']);

            return $row;
        });
    }
}
