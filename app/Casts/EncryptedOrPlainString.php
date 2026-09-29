<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * An `encrypted` string cast for a column that is being MOVED to encryption while
 * rows written before the move still hold plaintext.
 *
 * `payplus_token_reference` was stored in the clear (imported `recurring_token`,
 * activation writes) until the model started casting it `encrypted`. Laravel's
 * built-in cast throws DecryptException on such a row — and the charge path reads
 * the token AFTER the pending ledger row is committed, so a throw there strands the
 * cycle. This cast can never throw on read:
 *
 *   - ciphertext that decrypts      → the plaintext;
 *   - a value that is NOT a Laravel
 *     encryption envelope           → the raw value (legacy plaintext), logged once
 *                                     per process per attribute, never the value;
 *   - an envelope that won't decrypt
 *     (rotated APP_KEY, tampering)  → null + logged: ciphertext is never passed on
 *                                     as if it were a token.
 *
 * WRITES always encrypt (Crypt::encryptString, binary-compatible with `encrypted`).
 * The data migration 2026_09_29_000005 encrypts the legacy rows in place, after
 * which the plaintext branch is only a safety net.
 */
final class EncryptedOrPlainString implements CastsAttributes
{
    // === CONSTANTS ===
    private const LOG_PLAINTEXT = 'encrypted_or_plain.legacy_plaintext_read';

    private const LOG_UNDECRYPTABLE = 'encrypted_or_plain.decrypt_failed';

    /** @var array<string, true> class:attribute pairs already logged this process */
    private static array $logged = [];

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = (string) $value;

        try {
            return Crypt::decryptString($raw);
        } catch (DecryptException) {
            if (self::looksEncrypted($raw)) {
                self::logOnce(self::LOG_UNDECRYPTABLE, $model, $key);

                return null;
            }

            self::logOnce(self::LOG_PLAINTEXT, $model, $key);

            return $raw;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [$key => null];
        }

        return [$key => Crypt::encryptString((string) $value)];
    }

    /** A Laravel encryption envelope: base64 of a JSON object carrying iv + value + mac. */
    public static function looksEncrypted(string $value): bool
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload) && isset($payload['iv'], $payload['value']);
    }

    private static function logOnce(string $message, Model $model, string $key): void
    {
        $slot = $message.':'.$model::class.':'.$key;
        if (isset(self::$logged[$slot])) {
            return;
        }
        self::$logged[$slot] = true;

        Log::warning($message, [
            'model' => $model::class,
            'model_id' => $model->getKey(),
            'attribute' => $key,
        ]);
    }
}
