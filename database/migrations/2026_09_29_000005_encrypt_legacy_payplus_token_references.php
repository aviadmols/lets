<?php

use App\Casts\EncryptedOrPlainString;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypt the `payplus_token_reference` values still stored in PLAINTEXT.
 *
 * 2026_09_29_000002 widened the column so the model could encrypt it, but rows
 * written before (imported `recurring_token`, activation writes) stayed in the
 * clear — a fallback CHARGE token at rest in plaintext. This walks the table by id
 * in chunks and rewrites each plaintext value as Crypt ciphertext (the same format
 * the model's EncryptedOrPlainString cast reads).
 *
 * IDEMPOTENT: a value that already decrypts is skipped, and so is one that looks
 * like an encryption envelope but won't decrypt (never double-encrypt ciphertext
 * under a rotated key). Re-running changes nothing. Query builder only — no model,
 * no tenant scope — and one UPDATE per row by primary key: portable across
 * Postgres and SQLite.
 *
 * KEY CANARY — before ANY write. Ciphertext is only as good as the key that wrote
 * it, and a developer's local .env can reach the live database with a DIFFERENT
 * APP_KEY: encrypting there would replace every plaintext token with ciphertext
 * production can never read — permanent token loss. So first it samples values
 * the app already encrypted with APP_KEY (CANARY_COLUMNS) and demands that at
 * least one decrypts with THIS process's key:
 *   - ciphertext found, none decrypts → throw (wrong APP_KEY), nothing written;
 *   - no ciphertext anywhere to prove the key → SKIP (logged); the tolerant
 *     EncryptedOrPlainString cast keeps the plaintext rows chargeable, and a
 *     later run on the right host can still encrypt them.
 *
 * down() is a no-op: writing a charge token back to plaintext is never wanted.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    private const TABLE = 'installment_payment_methods';

    private const COLUMN = 'payplus_token_reference';

    private const CHUNK = 200;

    /** Columns written with Laravel's `encrypted` cast under APP_KEY: [table, column]. */
    private const CANARY_COLUMNS = [
        ['installment_payment_methods', 'payplus_card_token_uid'],
        ['installment_payment_methods', 'payplus_token_reference'],
        ['shops', 'shopify_access_token'],
        ['users', 'two_factor_secret'],
        ['mail_settings', 'smtp_password'],
    ];

    /** Values sampled per canary column. */
    private const CANARY_SAMPLE = 20;

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        if (! $this->keyIsProven()) {
            return;
        }

        $encrypted = 0;

        DB::table(self::TABLE)
            ->select(['id', self::COLUMN])
            ->whereNotNull(self::COLUMN)
            ->where(self::COLUMN, '!=', '')
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows) use (&$encrypted): void {
                foreach ($rows as $row) {
                    $value = (string) $row->{self::COLUMN};

                    if ($this->alreadyEncrypted($value)) {
                        continue;
                    }

                    DB::table(self::TABLE)
                        ->where('id', $row->id)
                        ->update([self::COLUMN => Crypt::encryptString($value)]);
                    $encrypted++;
                }
            });

        Log::info('migration.payplus_token_reference_encrypted', ['rows' => $encrypted]);
    }

    public function down(): void
    {
        // Deliberately nothing — see the class docblock.
    }

    /**
     * True when existing ciphertext decrypts with this key; false (skip) when there
     * is no ciphertext to test; throws when there is ciphertext and none decrypts.
     */
    private function keyIsProven(): bool
    {
        $seen = 0;

        foreach (self::CANARY_COLUMNS as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $values = DB::table($table)
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->orderBy('id')
                ->limit(self::CANARY_SAMPLE)
                ->pluck($column);

            foreach ($values as $value) {
                $value = (string) $value;
                if (! EncryptedOrPlainString::looksEncrypted($value)) {
                    continue; // legacy plaintext proves nothing either way
                }
                $seen++;

                try {
                    Crypt::decryptString($value);

                    return true;
                } catch (DecryptException) {
                    // keep looking — one success is proof enough
                }
            }
        }

        if ($seen > 0) {
            throw new \RuntimeException(sprintf(
                'Refusing to encrypt payplus_token_reference: %d existing APP_KEY ciphertext value(s) were sampled and none decrypts with this process\'s APP_KEY. '
                .'This is the WRONG key for this database (a local .env pointed at another environment?). Nothing was written. Run the migration where APP_KEY matches the data.',
                $seen,
            ));
        }

        Log::warning('migration.payplus_token_reference_encryption_skipped', [
            'reason' => 'no_app_key_ciphertext_to_verify_key',
        ]);

        return false;
    }

    private function alreadyEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return EncryptedOrPlainString::looksEncrypted($value);
        }
    }
};
