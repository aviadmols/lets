<?php

namespace App\Domain\Privacy;

use App\Domain\Campaigns\Email\Models\CampaignUnsubscribe;
use App\Domain\Campaigns\Email\Models\CustomerLoginToken;
use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Domain\Campaigns\Models\GiftCampaign;
use App\Domain\Campaigns\Models\GiftExportRow;
use App\Domain\Campaigns\Models\GiftExportRun;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Domain\Installments\Models\CardUpdateLink;
use App\Domain\Installments\Models\TokenRecoveryResult;
use App\Domain\Refunds\Models\RefundRequest;
use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Models\CustomerLoginCode;
use App\Models\DataRequestExport;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointEvent;
use App\Models\LoyaltyReferral;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The tables the redaction jobs did not know about (PersonalDataRegistry,
 * "Added with the registry"), erased for ONE customer or for a whole shop.
 *
 * Same doctrine as the jobs: the financial trail stays (amounts, statuses,
 * dates, transaction uids) and the PERSON leaves it. Rows that are nothing but
 * personal data or a credential — campaign recipients, login links and codes,
 * courier-sheet rows, data-request exports — are deleted outright.
 *
 * Runs inside the job's Tenant::run, so every model query is scoped by
 * BelongsToShop; shop_id is ALSO matched explicitly (and is the only wall for
 * webhook_events, which is a platform table).
 *
 * Customer matching FAILS CLOSED: a predicate with nothing to match on
 * matches nothing, never everything.
 */
final class PersonalDataEraser
{
    // === CONSTANTS ===
    private const GID_CUSTOMER_PREFIX = 'gid://shopify/Customer/';

    /**
     * Erase one customer. Call BEFORE the jobs' own redactions: the plans are
     * matched on the email those redactions are about to overwrite.
     *
     * @return array<string, int> table => rows touched
     */
    public function forCustomer(Shop $shop, ?string $customerId, ?string $email): array
    {
        $shopId = (int) $shop->getKey();
        $email = $email !== null && trim($email) !== '' ? mb_strtolower(trim($email)) : null;
        $customerId = $customerId !== null && trim($customerId) !== '' ? trim($customerId) : null;

        if ($customerId === null && $email === null) {
            return [];
        }

        $plans = InstallmentPlan::query()
            ->where('shop_id', $shopId)
            ->where(fn (Builder $q) => $this->byIdOrEmail($q, 'shopify_customer_id', $customerId, 'customer_email', $email))
            ->get(['id', 'shopify_order_id', 'external_order_id', 'customer_phone']);
        $planIds = $plans->pluck('id');

        $ledgerIds = PaymentLedger::query()
            ->where('shop_id', $shopId)
            ->where(function (Builder $q) use ($customerId, $email, $planIds): void {
                $this->byIdOrEmail($q, 'shopify_customer_id', $customerId, 'customer_email', $email);
                if ($planIds->isNotEmpty()) {
                    $q->orWhereIn('plan_id', $planIds);
                }
            })
            ->pluck('id');

        $orderIds = $plans->pluck('shopify_order_id')
            ->merge($plans->pluck('external_order_id'))
            ->merge(PaymentLedger::query()->whereIn('id', $ledgerIds)->pluck('shopify_order_id'))
            ->filter(static fn ($v): bool => $v !== null && $v !== '')
            ->map(static fn ($v): string => (string) $v)
            ->unique()->values();

        $accountIds = LoyaltyAccount::query()
            ->where('shop_id', $shopId)
            ->where(fn (Builder $q) => $this->byIdOrEmail($q, 'customer_ref', $customerId, 'customer_email', $email))
            ->pluck('id');

        return [
            'payment_ledger' => $this->scrubLedger(PaymentLedger::query()->whereIn('id', $ledgerIds)),
            'installment_payments' => $this->scrubJsonColumns(
                InstallmentPayment::query()->where('shop_id', $shopId)->whereIn('plan_id', $planIds), ['raw_response_masked']),
            'subscription_contracts' => $this->scrubContracts(SubscriptionContract::query()->where('shop_id', $shopId)
                ->where(fn (Builder $q) => $this->byIdOrEmail($q, 'shopify_customer_gid',
                    $customerId !== null ? self::GID_CUSTOMER_PREFIX.$customerId : null, 'customer_email', $email))),
            'webhook_events' => $this->scrubWebhooks($shopId, $customerId, $email, $orderIds),
            'email_campaign_recipients' => EmailCampaignRecipient::query()->where('shop_id', $shopId)
                ->where(fn (Builder $q) => $this->byIdOrEmail($q, 'customer_ref', $customerId, 'email', $email))->delete(),
            'customer_login_tokens' => CustomerLoginToken::query()->where('shop_id', $shopId)
                ->where(fn (Builder $q) => $this->byIdOrEmail($q, 'customer_ref', $customerId, 'email', $email))->delete(),
            'customer_login_codes' => $this->deleteLoginCodes($shopId, $email, $plans->pluck('customer_phone')),
            'gift_recipients' => $this->scrubGiftRecipients(GiftRecipient::query()->where('shop_id', $shopId)
                ->where(function (Builder $q) use ($email, $planIds): void {
                    $email !== null ? $q->whereRaw('LOWER(customer_email) = ?', [$email]) : $q->whereRaw('1 = 0');
                    if ($planIds->isNotEmpty()) {
                        $q->orWhere(fn (Builder $w) => $w->where('source_type', GiftRecipient::SOURCE_PLAN)->whereIn('source_id', $planIds));
                    }
                })),
            'gift_export_rows' => $this->deleteGiftExportRows($shopId, $customerId, $email),
            'gift_campaigns' => $this->dropEmailFromLists(GiftCampaign::query()->where('shop_id', $shopId), 'source_emails', $email),
            'gift_export_runs' => $this->dropEmailFromLists(GiftExportRun::query()->where('shop_id', $shopId), 'emails', $email),
            'loyalty_point_events' => $this->scrubJsonColumns(
                LoyaltyPointEvent::query()->where('shop_id', $shopId)->whereIn('loyalty_account_id', $accountIds), ['meta']),
            'loyalty_referrals' => $this->scrubReferrals(LoyaltyReferral::query()->where('shop_id', $shopId)
                ->where(fn (Builder $q) => $this->byIdOrEmail($q, 'buyer_ref', $customerId, 'buyer_email', $email))),
            'refund_requests' => $this->scrubRefundRequests(RefundRequest::query()->where('shop_id', $shopId)
                ->where(fn (Builder $q) => $this->inAny($q, ['plan_id' => $planIds, 'ledger_id' => $ledgerIds]))),
            'card_update_links' => $this->revokeCardLinks(CardUpdateLink::query()->where('shop_id', $shopId)->whereIn('plan_id', $planIds)),
            'token_recovery_results' => TokenRecoveryResult::query()->where('shop_id', $shopId)->whereIn('plan_id', $planIds)
                ->whereNotNull('candidates')->update(['candidates' => null]),
            'upsell_offer_events' => $this->scrubUpsellEvents(UpsellOfferEvent::query()->where('shop_id', $shopId)
                ->where(function (Builder $q) use ($customerId, $planIds, $ledgerIds): void {
                    $customerId !== null ? $q->where('customer_ref', $customerId) : $q->whereRaw('1 = 0');
                    $q->orWhere(fn (Builder $w) => $this->inAny($w, ['plan_id' => $planIds, 'payment_ledger_id' => $ledgerIds]));
                })),
            'data_request_exports' => DataRequestExport::query()->where('shop_id', $shopId)
                ->where(fn (Builder $q) => $this->byIdOrEmail($q, 'shopify_customer_id', $customerId, 'customer_email', $email))->delete(),
        ];
    }

    /**
     * Erase every customer of a shop (shop/redact).
     *
     * @return array<string, int>
     */
    public function forShop(Shop $shop): array
    {
        $shopId = (int) $shop->getKey();

        $counts = [
            'payment_ledger' => $this->scrubLedger(PaymentLedger::query()->where('shop_id', $shopId)),
            'installment_payments' => $this->scrubJsonColumns(InstallmentPayment::query()->where('shop_id', $shopId), ['raw_response_masked']),
            'subscription_contracts' => $this->scrubContracts(SubscriptionContract::query()->where('shop_id', $shopId), true),
            'webhook_events' => WebhookEvent::query()->where('shop_id', $shopId)->whereNotNull('raw_payload')->update(['raw_payload' => null]),
            'email_campaign_recipients' => EmailCampaignRecipient::query()->where('shop_id', $shopId)->delete(),
            'customer_login_tokens' => CustomerLoginToken::query()->where('shop_id', $shopId)->delete(),
            'customer_login_codes' => CustomerLoginCode::query()->where('shop_id', $shopId)->delete(),
            'campaign_unsubscribes' => CampaignUnsubscribe::query()->where('shop_id', $shopId)->delete(),
            'gift_recipients' => $this->scrubGiftRecipients(GiftRecipient::query()->where('shop_id', $shopId)),
            'gift_export_rows' => GiftExportRow::query()->where('shop_id', $shopId)->delete(),
            'gift_campaigns' => GiftCampaign::query()->where('shop_id', $shopId)->whereNotNull('source_emails')->update(['source_emails' => null]),
            'gift_export_runs' => GiftExportRun::query()->where('shop_id', $shopId)->whereNotNull('emails')->update(['emails' => null]),
            'loyalty_accounts' => $this->scrubLoyaltyAccounts(LoyaltyAccount::query()->where('shop_id', $shopId)),
            'loyalty_point_events' => $this->scrubJsonColumns(LoyaltyPointEvent::query()->where('shop_id', $shopId), ['meta']),
            'loyalty_referrals' => $this->scrubReferrals(LoyaltyReferral::query()->where('shop_id', $shopId)),
            'refund_requests' => $this->scrubRefundRequests(RefundRequest::query()->where('shop_id', $shopId)),
            'card_update_links' => $this->revokeCardLinks(CardUpdateLink::query()->where('shop_id', $shopId)),
            'token_recovery_results' => TokenRecoveryResult::query()->where('shop_id', $shopId)->whereNotNull('candidates')->update(['candidates' => null]),
            'upsell_offer_events' => $this->scrubUpsellEvents(UpsellOfferEvent::query()->where('shop_id', $shopId)),
            'data_request_exports' => DataRequestExport::query()->where('shop_id', $shopId)->delete(),
        ];

        $counts['shop_credentials'] = $this->dropCredentialsIfUninstalled($shop);

        return $counts;
    }

    // === Per-table actions ===

    private function scrubLedger(Builder $query): int
    {
        $count = 0;

        $query->each(function (PaymentLedger $row) use (&$count): void {
            $row->forceFill([
                'customer_name' => $row->customer_name !== null ? RedactionPolicy::SENTINEL : null,
                'customer_email' => $row->customer_email !== null ? RedactionPolicy::SENTINEL : null,
                'raw_response_masked' => is_array($row->raw_response_masked) ? RedactionPolicy::scrubJson($row->raw_response_masked) : $row->raw_response_masked,
            ])->save();
            $count++;
        });

        return $count;
    }

    /** @param list<string> $columns */
    private function scrubJsonColumns(Builder $query, array $columns): int
    {
        $count = 0;

        $query->each(function ($row) use ($columns, &$count): void {
            $patch = [];
            foreach ($columns as $column) {
                if (is_array($row->{$column})) {
                    $patch[$column] = RedactionPolicy::scrubJson($row->{$column});
                }
            }
            if ($patch !== []) {
                $row->forceFill($patch)->save();
                $count++;
            }
        });

        return $count;
    }

    private function scrubContracts(Builder $query, bool $dropCustomerKey = false): int
    {
        $count = 0;

        $query->each(function (SubscriptionContract $contract) use ($dropCustomerKey, &$count): void {
            $patch = [
                'customer_email' => $contract->customer_email !== null ? RedactionPolicy::SENTINEL : null,
                'customer_name' => $contract->customer_name !== null ? RedactionPolicy::SENTINEL : null,
                'card_brand' => null,
                'card_last_four' => null,
                'card_exp' => null,
                'lines' => is_array($contract->lines) ? RedactionPolicy::scrubJson($contract->lines) : $contract->lines,
            ];
            if ($dropCustomerKey) {
                $patch['shopify_customer_gid'] = null;
            }
            $contract->forceFill($patch)->save();
            $count++;
        });

        return $count;
    }

    /**
     * Webhook payloads naming this customer: their own customer webhooks, their
     * orders, or any payload carrying their email. Scrubbed, not deleted — the
     * row is the delivery audit.
     *
     * @param  Collection<int, string>  $orderIds
     */
    private function scrubWebhooks(int $shopId, ?string $customerId, ?string $email, Collection $orderIds): int
    {
        $count = 0;

        WebhookEvent::query()
            ->where('shop_id', $shopId)
            ->whereNotNull('raw_payload')
            ->where(function (Builder $q) use ($customerId, $email, $orderIds): void {
                $q->whereRaw('1 = 0');
                if ($customerId !== null) {
                    $q->orWhere('shopify_id', $customerId);
                }
                if ($orderIds->isNotEmpty()) {
                    $q->orWhereIn('shopify_id', $orderIds->all());
                }
                if ($email !== null) {
                    $q->orWhereRaw('LOWER(CAST(raw_payload AS TEXT)) LIKE ?', ['%'.addcslashes($email, '%_\\').'%']);
                }
            })
            ->each(function (WebhookEvent $event) use (&$count): void {
                $event->forceFill(['raw_payload' => is_array($event->raw_payload) ? RedactionPolicy::scrubJson($event->raw_payload) : null])->save();
                $count++;
            });

        return $count;
    }

    /** @param Collection<int, mixed> $phones */
    private function deleteLoginCodes(int $shopId, ?string $email, Collection $phones): int
    {
        $hashes = [];
        if ($email !== null) {
            $hashes[] = CustomerLoginCode::hashDestination($shopId, CustomerLoginCode::CHANNEL_EMAIL, $email);
        }
        foreach ($phones as $phone) {
            if (is_string($phone) && trim($phone) !== '' && $phone !== RedactionPolicy::SENTINEL) {
                $hashes[] = CustomerLoginCode::hashDestination($shopId, CustomerLoginCode::CHANNEL_SMS, $phone);
            }
        }

        return $hashes === []
            ? 0
            : CustomerLoginCode::query()->where('shop_id', $shopId)->whereIn('destination_hash', $hashes)->delete();
    }

    private function scrubGiftRecipients(Builder $query): int
    {
        $count = 0;

        $query->each(function (GiftRecipient $recipient) use (&$count): void {
            $recipient->forceFill([
                'customer_name' => $recipient->customer_name !== null ? RedactionPolicy::SENTINEL : null,
                'customer_email' => $recipient->customer_email !== null ? RedactionPolicy::SENTINEL : null,
            ])->save();
            $count++;
        });

        return $count;
    }

    /** Courier-sheet rows are short-lived and encrypted; any row naming the customer goes. */
    private function deleteGiftExportRows(int $shopId, ?string $customerId, ?string $email): int
    {
        $count = 0;

        GiftExportRow::query()->where('shop_id', $shopId)->each(function (GiftExportRow $row) use ($customerId, $email, &$count): void {
            $haystack = mb_strtolower((string) json_encode([$row->recipient, $row->fields]));

            if (($email !== null && str_contains($haystack, $email))
                || ($customerId !== null && str_contains($haystack, '"'.$customerId.'"'))) {
                $row->delete();
                $count++;
            }
        });

        return $count;
    }

    private function dropEmailFromLists(Builder $query, string $column, ?string $email): int
    {
        if ($email === null) {
            return 0;
        }

        $count = 0;

        $query->whereNotNull($column)->each(function ($row) use ($column, $email, &$count): void {
            $list = is_array($row->{$column}) ? $row->{$column} : [];
            $kept = array_values(array_filter($list, static fn ($v): bool => mb_strtolower(trim((string) $v)) !== $email));

            if (count($kept) !== count($list)) {
                $row->forceFill([$column => $kept])->save();
                $count++;
            }
        });

        return $count;
    }

    private function scrubLoyaltyAccounts(Builder $query): int
    {
        $count = 0;

        $query->each(function (LoyaltyAccount $account) use (&$count): void {
            $account->forceFill([
                'customer_name' => RedactionPolicy::SENTINEL,
                'customer_email' => RedactionPolicy::SENTINEL,
                'birthday' => null,
                // Unique per shop, so a per-row placeholder rather than one sentinel.
                'customer_ref' => 'redacted:'.$account->getKey(),
            ])->save();
            $count++;
        });

        return $count;
    }

    private function scrubReferrals(Builder $query): int
    {
        $count = 0;

        $query->each(function (LoyaltyReferral $referral) use (&$count): void {
            $referral->forceFill([
                'buyer_ref' => null,
                'buyer_email' => $referral->buyer_email !== null ? RedactionPolicy::SENTINEL : null,
            ])->save();
            $count++;
        });

        return $count;
    }

    private function scrubRefundRequests(Builder $query): int
    {
        $count = 0;

        $query->each(function (RefundRequest $request) use (&$count): void {
            $patch = ['reason' => $request->reason !== null && $request->reason !== '' ? RedactionPolicy::SENTINEL : $request->reason];
            foreach (['lines', 'money_result', 'store_result', 'doc_result'] as $column) {
                if (is_array($request->{$column})) {
                    $patch[$column] = RedactionPolicy::scrubJson($request->{$column});
                }
            }
            $request->forceFill($patch)->save();
            $count++;
        });

        return $count;
    }

    /** The address a link went to goes, and a live link is revoked with it. */
    private function revokeCardLinks(Builder $query): int
    {
        $count = 0;

        $query->each(function (CardUpdateLink $link) use (&$count): void {
            $link->forceFill([
                'sent_to' => null,
                'revoked_at' => $link->revoked_at ?? now(),
            ])->save();
            $count++;
        });

        return $count;
    }

    private function scrubUpsellEvents(Builder $query): int
    {
        $count = 0;

        $query->each(function (UpsellOfferEvent $event) use (&$count): void {
            $event->forceFill([
                'customer_ref' => null,
                'context' => is_array($event->context) ? RedactionPolicy::scrubJson($event->context) : $event->context,
            ])->save();
            $count++;
        });

        return $count;
    }

    /**
     * The merchant's own keys — only once the shop is really gone. Shopify's
     * webhook tester can send shop/redact to a store that is still installed;
     * wiping a live shop's PayPlus keys would stop every charge it makes.
     */
    private function dropCredentialsIfUninstalled(Shop $shop): int
    {
        if ((string) $shop->status !== Shop::STATUS_UNINSTALLED) {
            return 0;
        }

        $shopId = (int) $shop->getKey();

        // Raw updates: the columns are cast (encrypted), and NULL is the one
        // value that must bypass the cast rather than be encrypted as "".
        Shop::query()->whereKey($shopId)->update([
            'payplus_credentials' => null,
            'invoicing_credentials' => null,
            'shopify_access_token' => null,
            'shopify_refresh_token' => null,
        ]);
        DB::table('mail_settings')->where('shop_id', $shopId)->update(['smtp_username' => null, 'smtp_password' => null]);
        DB::table('merchant_sms_settings')->where('shop_id', $shopId)->update(['api_token' => null]);

        return 1;
    }

    // === Matching ===

    /** id OR case-insensitive email; with neither, nothing (fail closed). */
    private function byIdOrEmail(Builder $q, string $idColumn, ?string $id, string $emailColumn, ?string $email): Builder
    {
        $q->whereRaw('1 = 0');

        if ($id !== null) {
            $q->orWhere($idColumn, $id);
        }
        if ($email !== null) {
            $q->orWhereRaw('LOWER('.$emailColumn.') = ?', [$email]);
        }

        return $q;
    }

    /** @param array<string, Collection<int, mixed>> $sets */
    private function inAny(Builder $q, array $sets): Builder
    {
        $q->whereRaw('1 = 0');

        foreach ($sets as $column => $ids) {
            if ($ids->isNotEmpty()) {
                $q->orWhereIn($column, $ids->all());
            }
        }

        return $q;
    }
}
