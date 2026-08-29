<?php

namespace Tests\Feature\Studio;

use App\Domain\Campaigns\Studio\BlockTextSanitizer;
use Tests\TestCase;

/**
 * The one wall limited rich text passes through — a merchant's paste and a
 * model's patch payload alike. Rebuilt from an allowlist, so the unanticipated
 * simply does not survive.
 */
final class BlockTextSanitizerTest extends TestCase
{
    public function test_scripts_and_event_handlers_do_not_survive(): void
    {
        $out = BlockTextSanitizer::clean(
            '<p onclick="steal()">שלום <script>alert(1)</script><b>עולם</b></p>',
        );

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringContainsString('<b>עולם</b>', $out);
        // The script's TEXT is also gone... actually unwrapped: assert no alert marker leaks as executable
        $this->assertStringNotContainsString('</script>', $out);
    }

    public function test_a_javascript_href_dies_here_not_in_a_mail_client(): void
    {
        $out = BlockTextSanitizer::clean('<a href="javascript:alert(1)">לחץ</a>');

        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringContainsString('<a>לחץ</a>', $out);
    }

    public function test_allowed_links_and_token_hrefs_are_kept(): void
    {
        $out = BlockTextSanitizer::clean(
            '<a href="https://example.co.il/sale">מבצע</a> <a href="{account_login_url}">כניסה</a>',
        );

        $this->assertStringContainsString('href="https://example.co.il/sale"', $out);
        $this->assertStringContainsString('href="{account_login_url}"', $out);
    }

    public function test_unknown_elements_are_unwrapped_not_deleted(): void
    {
        // A <div> loses its box but keeps its words — stripping a merchant's
        // paragraph because a paste carried markup would read as data loss.
        $out = BlockTextSanitizer::clean('<div class="x"><table><tr><td>הטקסט החשוב</td></tr></table></div>');

        $this->assertStringContainsString('הטקסט החשוב', $out);
        $this->assertStringNotContainsString('<div', $out);
        $this->assertStringNotContainsString('<table', $out);
    }

    public function test_template_syntax_of_other_flavours_stays_inert_literal_text(): void
    {
        // The strtr pin: substitution is one flat pass over known tokens.
        // Anything shaped like another engine's syntax must come out as the
        // harmless text it will remain in the sent email.
        $out = BlockTextSanitizer::clean('<p>{{ 7*7 }} @php echo 1; @endphp {customer_name}</p>');

        $this->assertStringContainsString('{{ 7*7 }}', $out);
        $this->assertStringContainsString('@php', $out);
        $this->assertStringContainsString('{customer_name}', $out);
    }

    public function test_text_is_escaped_and_length_capped(): void
    {
        $out = BlockTextSanitizer::clean('1 < 2 & "quotes"');
        $this->assertStringContainsString('1 &lt; 2 &amp; &quot;quotes&quot;', $out);

        $long = BlockTextSanitizer::clean(str_repeat('א', BlockTextSanitizer::MAX_LENGTH + 100));
        $this->assertLessThanOrEqual(BlockTextSanitizer::MAX_LENGTH, mb_strlen($long));
    }
}
