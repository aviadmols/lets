<?php

namespace App\Domain\Auth\TwoFactor;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

/**
 * The ONE place that knows how a second factor works: the TOTP secret, the QR
 * the authenticator app scans, checking a code, and the one-time recovery codes.
 * The login challenge and the Security page both call it, so they cannot
 * disagree about what a valid code is.
 *
 * - A code is accepted within ±WINDOW 30-second steps (clock drift), and never
 *   twice: the accepted step is stored and an older-or-equal one is refused, so
 *   a code read over someone's shoulder is dead once used.
 * - Recovery codes are stored as sha256 hashes and burn on use.
 * - The secret is encrypted at rest by the model cast.
 */
final class TwoFactorAuthenticator
{
    // === CONSTANTS ===
    /** The account name the authenticator app shows above the code. */
    public const ISSUER = 'LETS';

    /** Steps of 30s either side of now that still count (drift tolerance). */
    public const WINDOW = 1;

    /** 160-bit secret (32 base32 chars), the RFC 4226 recommendation. */
    public const SECRET_LENGTH = 32;

    public const RECOVERY_CODE_COUNT = 8;

    /** Each recovery code is two blocks of this many characters: "abcde-fghij". */
    public const RECOVERY_BLOCK_LENGTH = 5;

    public const QR_SIZE_PX = 192;

    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(self::SECRET_LENGTH);
    }

    /** The QR the app scans, as an inline SVG string (generated here, never user input). */
    public function qrCodeSvg(User $user, string $secret): string
    {
        $uri = $this->google2fa->getQRCodeUrl(self::ISSUER, (string) $user->email, $secret);

        $writer = new Writer(new ImageRenderer(
            new RendererStyle(self::QR_SIZE_PX, 1),
            new SvgImageBackEnd,
        ));

        // Drop the XML prolog so the SVG can sit inline in the page.
        return trim((string) preg_replace('/^<\?xml[^>]*\?>/', '', $writer->writeString($uri)));
    }

    /** Checks a code against a secret that is NOT saved yet (the enrolment step). */
    public function codeMatchesSecret(string $secret, string $code): bool
    {
        $code = self::normaliseCode($code);
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        try {
            return $this->google2fa->verifyKey($secret, $code, self::WINDOW) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Saves a secret the owner proved with a live code, and hands back fresh recovery codes. */
    public function enable(User $user, string $secret, string $code): ?array
    {
        if (! $this->codeMatchesSecret($secret, $code)) {
            return null;
        }

        $codes = $this->newRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => array_map(self::hash(...), $codes),
            'two_factor_confirmed_at' => now(),
            'two_factor_last_timestep' => null,
        ])->save();

        Log::info('auth.two_factor_enabled', ['user_id' => $user->getKey()]);

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_timestep' => null,
        ])->save();

        Log::info('auth.two_factor_disabled', ['user_id' => $user->getKey()]);
    }

    /** @return list<string> the new plain codes, shown once */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map(self::hash(...), $codes)])->save();

        Log::info('auth.two_factor_recovery_codes_regenerated', ['user_id' => $user->getKey()]);

        return $codes;
    }

    /**
     * A 6-digit app code for an ENABLED user, one use only. Stores the accepted
     * step so the same code (or an older one) is refused afterwards.
     */
    public function verifyCode(User $user, string $code): bool
    {
        $code = self::normaliseCode($code);
        if (! $user->hasTwoFactorEnabled() || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        try {
            $step = $this->google2fa->verifyKeyNewer(
                (string) $user->two_factor_secret,
                $code,
                // 0, never null: with a previous step the library returns the step
                // that matched (not just true), which is what we must store.
                (int) ($user->two_factor_last_timestep ?? 0),
                self::WINDOW,
            );
        } catch (Throwable) {
            return false;
        }

        if (! is_int($step)) {
            return false;
        }

        // Compare-and-set: two requests racing with the same code both pass the
        // check above, but only one can move the step forward.
        $claimed = User::query()
            ->whereKey($user->getKey())
            ->where(fn ($q) => $q->whereNull('two_factor_last_timestep')->orWhere('two_factor_last_timestep', '<', $step))
            ->update(['two_factor_last_timestep' => $step]);

        if ($claimed !== 1) {
            return false;
        }

        $user->forceFill(['two_factor_last_timestep' => $step])->syncOriginalAttribute('two_factor_last_timestep');

        return true;
    }

    /** A recovery code, burned on success. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $hash = self::hash(Str::lower(trim($code)));
        $stored = (array) ($user->two_factor_recovery_codes ?? []);

        foreach ($stored as $i => $candidate) {
            if (is_string($candidate) && hash_equals($candidate, $hash)) {
                unset($stored[$i]);
                $remaining = array_values($stored);

                // Compare-and-set on the stored list: of two requests racing with
                // the same code, only the one that still sees it may burn it.
                $claimed = User::query()
                    ->whereKey($user->getKey())
                    ->where('two_factor_recovery_codes', $user->getRawOriginal('two_factor_recovery_codes'))
                    ->update(['two_factor_recovery_codes' => json_encode($remaining)]);

                if ($claimed !== 1) {
                    return false;
                }

                $user->forceFill(['two_factor_recovery_codes' => $remaining])->syncOriginalAttribute('two_factor_recovery_codes');

                Log::warning('auth.two_factor_recovery_code_used', [
                    'user_id' => $user->getKey(),
                    'remaining' => count($stored),
                ]);

                return true;
            }
        }

        return false;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count((array) ($user->two_factor_recovery_codes ?? []));
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        return array_map(
            fn (): string => Str::lower(Str::random(self::RECOVERY_BLOCK_LENGTH).'-'.Str::random(self::RECOVERY_BLOCK_LENGTH)),
            range(1, self::RECOVERY_CODE_COUNT),
        );
    }

    private static function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    /** Apps show "123 456"; people paste with spaces. */
    private static function normaliseCode(string $code): string
    {
        return preg_replace('/\s+/', '', $code) ?? '';
    }
}
