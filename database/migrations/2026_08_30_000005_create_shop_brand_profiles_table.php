<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One shop's Design DNA, learned from its own website.
 *
 * The capture is a conversation with the shop's site (fetch → extract →
 * analyze), and this row is where it lands: `dna` holds the structured answer
 * (colors mapped to the studio's global keys, a font key, a tone note, a
 * confidence per field), `status` is where the conversation stands, and
 * NOTHING is used until the merchant approves — a palette guessed wrong is a
 * branding mistake on every email, so the human looks first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_brand_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            /** The site the DNA was learned from. */
            $table->string('source_url', 500)->nullable();

            // pending | ready | approved | failed
            $table->string('status', 16)->default('pending');

            /** The structured Design DNA (colors, font, tone, confidence). */
            $table->json('dna')->nullable();

            /** Which pages were actually read — the merchant's provenance line. */
            $table->json('pages')->nullable();

            $table->string('failure_reason', 64)->nullable();

            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            // One brand per shop — two half-approved palettes would make
            // "which one are we designing with" unanswerable.
            $table->unique('shop_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_brand_profiles');
    }
};
