<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A custom-HTML offer can now carry its own stylesheet.
 *
 * The custom HTML is class-based by design — SafeHtml drops the `style`
 * attribute — which left a merchant's classes styled only when the storefront
 * theme happened to define them. `custom_css` is the other half of the block:
 * scrubbed by App\Support\SafeCss on save and on read (the customHtml
 * discipline, applied to the second column), delivered to the renderer as the
 * card's `css` and injected there via a <style> element's textContent — never
 * parsed as HTML.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_offers', function (Blueprint $table): void {
            $table->text('custom_css')->nullable()->after('custom_html');
        });
    }

    public function down(): void
    {
        Schema::table('account_offers', function (Blueprint $table): void {
            $table->dropColumn('custom_css');
        });
    }
};
