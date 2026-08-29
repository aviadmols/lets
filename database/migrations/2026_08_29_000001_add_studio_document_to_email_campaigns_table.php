<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The newsletter STUDIO's source of truth, on the campaign row it already had.
 *
 * A studio campaign is edited as a structured JSON document of blocks — never
 * as raw HTML — and `body_html` becomes its COMPILED ARTIFACT, rewritten on
 * every document save. That one arrangement is what keeps the entire existing
 * pipeline untouched: preview, test send, the marketing-unsubscribe check,
 * scheduling and the sender all keep reading the column they always read.
 *
 * `document` is NULL for every campaign that predates the studio (and for any
 * merchant who keeps choosing the visual/HTML editors) — null means "this row
 * was never a studio document", and every studio code path steps aside for it.
 *
 * `document_version` is the optimistic-concurrency token: every save bumps it,
 * every mutation from the editor carries the version it was looking at, and a
 * mismatch is refused loudly instead of silently overwriting a colleague (or
 * an approved AI patch is refused when the document moved under it).
 *
 * `body_text` is the plain-text alternative compiled beside the HTML — mail
 * clients and spam filters both want one; null = HTML only, exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table): void {
            $table->json('document')->nullable()->after('body_html');
            $table->unsignedInteger('document_version')->default(0)->after('document');
            $table->longText('body_text')->nullable()->after('document_version');
        });
    }

    public function down(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table): void {
            $table->dropColumn(['document', 'document_version', 'body_text']);
        });
    }
};
