<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;

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

        $decrypted = $this->encrypter()->decryptString($value);

        return json_decode($decrypted, true) ?: [];
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $payload = json_encode($value ?: []);

        return [$key => $this->encrypter()->encryptString($payload)];
    }
}
