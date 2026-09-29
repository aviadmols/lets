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
 * down() is a no-op: writing a charge token back to plaintext is never wanted.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    private const TABLE = 'installment_payment_methods';

    private const COLUMN = 'payplus_token_reference';

    private const CHUNK = 200;

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, self::COLUMN)) {
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
