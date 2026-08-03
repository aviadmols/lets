<?php

namespace App\Domain\Loyalty\Http\Controllers;

use App\Domain\Loyalty\PointsEngine;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointEvent;
use App\Models\MerchantLoyaltySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The three things a member can do on the page: join, set their birthday once,
 * and claim a social action once.
 *
 * All three fail CLOSED for an unidentified visitor — the platform's proof of
 * who they are is the only credential, and without it there is nobody to credit.
 * Each grant carries a deterministic idempotency key, so a double-tapped button
 * or a replayed request pays exactly once (PointsEngine enforces it).
 */
final class LoyaltyActionController extends LoyaltyController
{
    /** Join the club — creates the membership and pays the welcome bonus. */
    public function join(Request $request, PointsEngine $engine): JsonResponse
    {
        $visitor = $this->visitor($request);

        if ($visitor === null || ! $visitor->isIdentified() || ! $this->programEnabled()) {
            return $this->denied();
        }

        $account = $engine->join($visitor->customerRef, $visitor->email, $visitor->name);

        return response()->json([
            'ok' => true,
            'message' => __('loyalty.page.joined'),
            'balance' => (int) $account->points_balance,
        ]);
    }

    /**
     * Record the member's birthday — ONCE. A movable birthday is a repeatable
     * annual bonus, so the second attempt is refused rather than silently
     * ignored (the page shows the date it already holds).
     */
    public function birthday(Request $request): JsonResponse
    {
        $visitor = $this->visitor($request);
        $account = $visitor?->account();

        if ($visitor === null || $account === null || ! $this->programEnabled()) {
            return $this->denied();
        }

        if ($account->birthdayLocked()) {
            return response()->json([
                'ok' => false,
                'message' => __('loyalty.page.birthday_locked'),
            ], SymfonyResponse::HTTP_CONFLICT);
        }

        $date = $this->parseBirthday((string) $request->input('birthday', ''));
        if ($date === null) {
            return response()->json(['ok' => false, 'message' => __('loyalty.page.birthday_invalid')], SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $account->forceFill([
            'birthday' => $date->toDateString(),
            'birthday_set_at' => now(),
        ])->save();

        // The points arrive on the day (GrantLoyaltyBirthdayPoints), not now —
        // otherwise the birthday field would be a same-day payout button.
        return response()->json(['ok' => true, 'message' => __('loyalty.page.birthday_saved')]);
    }

    /**
     * Claim a one-click social action. We cannot verify a like or a follow, and
     * the admin copy says so — the wall here is only that each action pays once
     * per member, ever.
     */
    public function social(Request $request, PointsEngine $engine): JsonResponse
    {
        $visitor = $this->visitor($request);
        $account = $visitor?->account();

        if ($visitor === null || $account === null || ! $this->programEnabled()) {
            return $this->denied();
        }

        $key = (string) $request->input('key', '');
        $action = MerchantLoyaltySettings::current()->socialAction($key);

        if ($action === null) {
            return response()->json(['ok' => false, 'message' => __('loyalty.page.claim_unavailable')], SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $event = $engine->grant(
            $account,
            LoyaltyPointEvent::KIND_SOCIAL,
            $action['points'],
            LoyaltyPointEvent::keyForSocial((int) $account->getKey(), $action['key']),
            ['key' => $action['key']],
        );

        return response()->json([
            'ok' => $event !== null,
            'message' => $event !== null ? __('loyalty.page.claimed') : __('loyalty.page.already_claimed'),
            'balance' => (int) $account->refresh()->points_balance,
        ]);
    }

    // === Internals ===

    /**
     * A birthday must be a real past date — a future one would schedule a bonus
     * nobody has earned, and a nonsense string would silently store null.
     */
    private function parseBirthday(string $value): ?Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        return $date->isFuture() ? null : $date;
    }

    private function denied(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => __('loyalty.page.login_required'),
        ], SymfonyResponse::HTTP_UNAUTHORIZED);
    }
}
