<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;

/**
 * Encrypts a JSON credentials bag (PayPlus keys, etc.) using a DEDICATED key
 * (TENANT_CREDENTIALS_KEY) — independent of APP_KEY so it can be rotated without
 * touching session/cookie encryption. Stored as an opaque ciphertext string.
 *
 * Returns an array on read; accepts an array on write.
 */
final class EncryptedCredentials implements CastsAttributes
{
    // === CONSTANTS ===
    private const CIPHER = 'AES-256-CBC';

    private function encrypter(): Encrypter
    {
        $key = (string) config('tenancy.credentials_key');

        // Accept base64:... form (matches Laravel's APP_KEY convention).
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return new Encrypter($key, self::CIPHER);
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (empty($value)) {
            return [];
        }

        try {
            $decrypted = $this->encrypter()->decryptString($value);
        } catch (\Throwable $e) {
            // Ciphertext that can't be decrypted — e.g. a bag encrypted under a
            // ROTATED/old TENANT_CREDENTIALS_KEY → "The MAC is invalid" — must NOT crash
            // every read of this attribute (it would 500 the shop's admin pages and block
            // re-minting). Degrade to "unset": the shop reads as not-connected and the
            // credentials can simply be re-entered/re-minted (which overwrites the bag
            // with a fresh, decryptable ciphertext). Logged for visibility.
            Log::channel('stderr')->warning('encrypted_credentials.decrypt_failed', [
                'model' => $model::class,
                'model_id' => $model->getKey(),
                'attribute' => $key,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return json_decode($decrypted, true) ?: [];
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $payload = json_encode($value ?: []);

        return [$key => $this->encrypter()->encryptString($payload)];
    }
}
