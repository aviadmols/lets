<?php

namespace Tests\Feature\Legal;

use App\Support\Ui\LegalText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public privacy notice. It is the page Shopify's review reads and the one a
 * data request points at, so the properties that matter are: it is reachable
 * WITHOUT a session, it exists in both languages, and translator-supplied text
 * can never become markup.
 */
final class PrivacyNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_public_and_needs_no_session(): void
    {
        $response = $this->get('/privacy');

        $response->assertOk();
        // Blade escapes the ampersand — assert what the browser actually receives.
        $response->assertSee('Privacy &amp; data protection', false);
        // The claims a reviewer checks for must actually be on the page.
        $response->assertSee('data processor', false);
        $response->assertSee('Card numbers never reach LETS', false);
    }

    public function test_hebrew_renders_rtl(): void
    {
        $response = $this->get('/privacy?lang=he');

        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('פרטיות והגנת מידע', false);
    }

    public function test_an_unknown_language_falls_back_instead_of_erroring(): void
    {
        $this->get('/privacy?lang=zz')->assertOk();
    }

    public function test_translated_copy_can_never_inject_markup(): void
    {
        // Everything is escaped first; ONLY **bold** is restored afterwards.
        $rendered = (string) LegalText::render('<script>alert(1)</script> and **this**');

        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
        $this->assertStringContainsString('<strong>this</strong>', $rendered);
    }
}
