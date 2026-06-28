<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * A RESILIENT drop-in for Laravel's built-in `'encrypted'` string cast. Uses Crypt
 * (APP_KEY) and the same encryptString/decryptString format, so it is binary-compatible
 * with values already written by `'encrypted'`. Two deliberate differences:
 *
 *   1. READ — a value that can't be decrypted (e.g. written under a ROTATED APP_KEY →
 *      DecryptException "The MAC is invalid") degrades to null + logs, instead of
 *      throwing and 500-ing every page that touches the model.
 *   2. SAVE — because this is a custom cast (NOT Laravel's special built-in encrypted
 *      handling), originalIsEquivalent() compares the RAW ciphertext and never decrypts
 *      the OLD value during the dirty-check. So overwriting the field (e.g. re-minting a
 *      token) can never 500 on an undecryptable original — the exact bug this fixes.
 *
 * Trade-off: encryption is non-deterministic, so a raw-ciphertext comparison always
 * reports the field "dirty" → the column is re-written on every save even when unchanged.
 * That is harmless (a stable plaintext, a fresh ciphertext) and worth the crash-safety.
 */
final class EncryptedString implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (\Throwable $e) {
            Log::channel('stderr')->warning('encrypted_string.decrypt_failed', [
                'model' => $model::class,
                'model_id' => $model->getKey(),
                'attribute' => $key,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [$key => null];
        }

        return [$key => Crypt::encryptString((string) $value)];
    }
}
