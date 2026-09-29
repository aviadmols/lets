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

    /** @return list<Encrypter> current key first, then each previous key, in order. */
    private function encrypters(): array
    {
        $keys = [
            (string) config('tenancy.credentials_key'),
            ...(array) config('tenancy.previous_credentials_keys', []),
        ];

        return array_map(
            fn (string $key): Encrypter => new Encrypter(self::normalize($key), self::CIPHER),
            array_values(array_filter($keys, fn (string $key): bool => $key !== '')),
        );
    }

    /** Accept base64:... form (matches Laravel's APP_KEY convention). */
    private static function normalize(string $key): string
    {
        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key;
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (empty($value)) {
            return [];
        }

        $encrypters = $this->encrypters();
        $lastError = null;

        // Current key first (the common case, one attempt); on a MAC/key mismatch
        // fall back through TENANT_CREDENTIALS_PREVIOUS_KEYS — see config/tenancy.php.
        // This is what lets a key rotation NOT silently disconnect every shop the
        // moment it deploys: reads keep working under the old key until
        // `tenant:rotate-credentials-key` re-encrypts everything under the new one.
        foreach ($encrypters as $encrypter) {
            try {
                $decrypted = $encrypter->decryptString($value);

                return json_decode($decrypted, true) ?: [];
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        // Ciphertext that no known key (current or previous) can decrypt must NOT
        // crash every read of this attribute (it would 500 the shop's admin pages
        // and block re-minting). Degrade to "unset": the shop reads as not-connected
        // and the credentials can simply be re-entered/re-minted (which overwrites
        // the bag with a fresh, decryptable ciphertext).
        //
        // CRITICAL, not warning: every attempt (current + all previous keys) failed,
        // which — outside an in-progress key rotation — means a shop's PayPlus,
        // WooCommerce or invoicing connection just went dark with no charges firing
        // and no error a merchant would ever see. This line is the alert; wire a log
        // drain/alert rule on `critical` in this channel to page on it.
        Log::channel('stderr')->critical('encrypted_credentials.decrypt_failed', [
            'model' => $model::class,
            'model_id' => $model->getKey(),
            'attribute' => $key,
            'error' => $lastError?->getMessage(),
            'previous_keys_tried' => count($encrypters) - 1,
        ]);

        return [];
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $payload = json_encode($value ?: []);
        $encrypters = $this->encrypters();
        $current = $encrypters[0] ?? new Encrypter(self::normalize((string) config('tenancy.credentials_key')), self::CIPHER);

        // Writes ALWAYS use the CURRENT key, never a previous one — every save
        // organically re-encrypts under the new key, on top of the explicit
        // `tenant:rotate-credentials-key` command for rows nobody happens to save.
        return [$key => $current->encryptString($payload)];
    }
}
