<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quick action is a chat turn that KNOWS its stage. The routing stays
 * deterministic — "הצע שורת נושא" is a button, not a guess about the
 * merchant's phrasing — so the button stamps the stage on the assistant row
 * and the router honors the stamp before its own rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chat_messages', function (Blueprint $table): void {
            // null = route by the document (the default deterministic rules)
            $table->string('stage_hint', 32)->nullable()->after('selected_block_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_chat_messages', function (Blueprint $table): void {
            $table->dropColumn('stage_hint');
        });
    }
};
