<?php

namespace App\Http\Controllers\WooCommerce\Account;

use App\Models\InstallmentPlan;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/woocommerce/account/identity
 *
 * "Does LETS already know this address?" — asked by the plugin's SERVER after a
 * sign-in code was VERIFIED and no WordPress user answered to the destination.
 * A member the store imported has subscriptions here but no WP account; without
 * this lookup they are greeted like a stranger and asked to introduce themselves
 * on a store that has been charging them for a year. With it, the plugin opens
 * their account from what LETS already knows and signs them straight in.
 *
 * Scope is deliberately minimal: name and email, never an address or a plan.
 * The caller holds the store's HMAC secret (it IS the store), and it only asks
 * after the shopper proved the destination by code — but this endpoint cannot
 * verify that order of events, so it hands out nothing a receipt would not.
 */
final class AccountIdentityController extends WooAccountController
{
    // === CONSTANTS ===
    /** Phone matching compares the LAST digits (country codes fold away). */
    private const PHONE_SUFFIX_LENGTH = 9;

    /** Cap on plans scanned for a phone match (PHP-side normalization). */
    private const PHONE_SCAN_CHUNK = 500;

    public function __invoke(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $channel = $request->input('channel') === 'sms' ? 'sms' : 'email';
        $destination = trim((string) ($this->cleanString($request->input('destination')) ?? ''));

        if ($destination === '') {
            return response()->json(['ok' => false], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return Tenant::run($shop, function () use ($channel, $destination): JsonResponse {
            $plan = $channel === 'email'
                ? $this->newestPlanByEmail($destination)
                : $this->newestPlanByPhone($destination);

            if ($plan === null) {
                return response()->json(['ok' => true, 'known' => false]);
            }

            [$first, $last] = $this->splitName($plan);

            return response()->json([
                'ok' => true,
                'known' => true,
                'first_name' => $first,
                'last_name' => $last,
                'email' => (string) ($plan->customer_email ?? ''),
            ]);
        });
    }

    /** The member's newest plan carrying this email (any status — cancelled members still own their identity). */
    private function newestPlanByEmail(string $email): ?InstallmentPlan
    {
        return InstallmentPlan::query()
            ->whereRaw('LOWER(customer_email) = ?', [mb_strtolower($email)])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The member's newest plan whose phone ends with the destination's digits.
     *
     * Normalized in PHP, not SQL: stored phones arrive as `050-123 4567`,
     * `+972501234567` and everything between, and the regexp functions that
     * would fold them differ between Postgres (production) and sqlite (tests) —
     * the exact divergence that has bitten this codebase before.
     */
    private function newestPlanByPhone(string $destination): ?InstallmentPlan
    {
        $needle = $this->phoneSuffix($destination);
        if ($needle === null) {
            return null;
        }

        $match = null;
        InstallmentPlan::query()
            ->whereNotNull('customer_phone')
            ->orderByDesc('id')
            // chunk(), not chunkById(): chunkById re-orders by id ASC internally,
            // which would quietly make the OLDEST match win.
            ->chunk(self::PHONE_SCAN_CHUNK, function ($plans) use (&$match, $needle): bool {
                foreach ($plans as $plan) {
                    if ($this->phoneSuffix((string) $plan->customer_phone) === $needle) {
                        $match = $plan;

                        return false; // stop chunking — newest wins and we order desc
                    }
                }

                return true;
            });

        return $match;
    }

    /** The last PHONE_SUFFIX_LENGTH digits, or null when there are too few to compare. */
    private function phoneSuffix(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return strlen($digits) >= self::PHONE_SUFFIX_LENGTH
            ? substr($digits, -self::PHONE_SUFFIX_LENGTH)
            : null;
    }

    /**
     * First/last from what the plan knows: the merchant-edited contact block
     * wins (it is the corrected truth), then the plan's own customer_name split
     * on its first space — the convention the rest of the admin already uses.
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(InstallmentPlan $plan): array
    {
        $contact = method_exists($plan, 'contactAddress') ? (array) $plan->contactAddress() : [];
        $first = trim((string) ($contact['firstName'] ?? ''));
        $last = trim((string) ($contact['lastName'] ?? ''));

        if ($first !== '' || $last !== '') {
            return [$first, $last];
        }

        $parts = preg_split('/\s+/', trim((string) ($plan->customer_name ?? ''))) ?: [];
        $first = (string) array_shift($parts);

        return [$first, trim(implode(' ', $parts))];
    }
}
