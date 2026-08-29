<?php

namespace Tests\Feature\Studio;

use App\Domain\Campaigns\Studio\NewsletterDocument;
use App\Domain\Campaigns\Studio\Render\EmailRenderer;
use App\Domain\Campaigns\Studio\Render\PlainTextRenderer;
use App\Domain\Campaigns\Studio\Render\RenderedEmail;
use Tests\TestCase;

/**
 * Structure → the email. The laws: everything merchant-written is escaped,
 * `{token}`s survive for the send-time strtr, the footer's unsubscribe link is
 * unconditional, and RTL is the default posture — this is an Israeli product.
 */
final class EmailRendererTest extends TestCase
{
    private function render(array $raw, string $highlight = ''): RenderedEmail
    {
        return (new EmailRenderer)->render(NewsletterDocument::fromArray($raw), $highlight);
    }

    public function test_the_shell_is_rtl_hebrew_by_default(): void
    {
        $rendered = $this->render(['blocks' => [['type' => 'heading', 'content' => ['text' => 'שלום']]]]);

        $this->assertStringContainsString('dir="rtl"', $rendered->html);
        $this->assertStringContainsString('lang="he"', $rendered->html);
        $this->assertStringContainsString('שלום', $rendered->html);
    }

    public function test_merchant_content_is_escaped_where_it_lands(): void
    {
        $rendered = $this->render(['blocks' => [
            ['type' => 'heading', 'content' => ['text' => '<b>לא מודגש</b>']],
        ]]);

        // Markup typed into a heading renders as TEXT, not as markup.
        $this->assertStringNotContainsString('<b>לא מודגש</b>', $rendered->html);
        $this->assertStringContainsString('&lt;b&gt;', $rendered->html);
    }

    public function test_tokens_survive_the_compile_for_the_send_time_strtr(): void
    {
        $rendered = $this->render(['blocks' => [
            ['type' => 'heading', 'content' => ['text' => 'שלום {customer_name}']],
            ['type' => 'button', 'content' => ['label' => 'כניסה', 'url' => '{account_login_url}']],
            ['type' => 'footer', 'content' => []],
        ]]);

        $this->assertStringContainsString('{customer_name}', $rendered->html);
        $this->assertStringContainsString('href="{account_login_url}"', $rendered->html);
        $this->assertStringContainsString('href="{unsubscribe_url}"', $rendered->html);
    }

    public function test_the_footer_unsubscribe_link_cannot_be_configured_away(): void
    {
        // No content key removes it — the marketing law holds by construction.
        $rendered = $this->render(['blocks' => [
            ['type' => 'footer', 'content' => ['business_line' => '', 'address_line' => '', 'note' => '']],
        ]]);

        $this->assertStringContainsString('{unsubscribe_url}', $rendered->html);
        $this->assertStringContainsString('{unsubscribe_url}', $rendered->text);
    }

    public function test_no_scripts_ever(): void
    {
        $rendered = $this->render(['blocks' => [
            ['type' => 'text', 'content' => ['html' => '<p>רגיל</p>']],
        ]]);

        $this->assertStringNotContainsString('<script', $rendered->html);
    }

    public function test_warnings_name_the_block_and_the_problem(): void
    {
        $rendered = $this->render(['blocks' => [
            ['type' => 'image', 'content' => ['url' => 'https://cdn.example.com/x.png', 'alt' => '']],
            ['type' => 'button', 'content' => ['label' => 'קנו', 'url' => '']],
            ['type' => 'text', 'content' => ['html' => '<p>{custmer_name}</p>']],
        ]]);

        $codes = array_column($rendered->warnings, 'code');
        $this->assertContains(RenderedEmail::WARN_IMAGE_WITHOUT_ALT, $codes);
        $this->assertContains(RenderedEmail::WARN_BUTTON_WITHOUT_URL, $codes);
        $this->assertContains(RenderedEmail::WARN_UNKNOWN_TOKEN, $codes);
        $this->assertContains(RenderedEmail::WARN_NO_FOOTER, $codes);
    }

    public function test_the_selection_highlight_is_preview_only(): void
    {
        $raw = ['blocks' => [['type' => 'heading', 'content' => ['text' => 'x']]]];
        $id = NewsletterDocument::fromArray($raw)->blocks()[0]['id'] ?? '';

        $document = NewsletterDocument::fromArray($raw);
        $blockId = $document->blocks()[0]['id'];

        $highlighted = (new EmailRenderer)->render($document, $blockId);
        $compiled = (new EmailRenderer)->render($document);

        $this->assertStringContainsString('outline:2px solid', $highlighted->html);
        $this->assertStringNotContainsString('outline:2px solid', $compiled->html);
    }

    public function test_ltr_documents_flip_the_shell(): void
    {
        $rendered = $this->render([
            'globals' => ['direction' => 'ltr'],
            'blocks' => [['type' => 'heading', 'content' => ['text' => 'Hello']]],
        ]);

        $this->assertStringContainsString('dir="ltr"', $rendered->html);
        $this->assertStringContainsString('lang="en"', $rendered->html);
    }

    public function test_plain_text_reads_like_the_email(): void
    {
        $text = (new PlainTextRenderer)->render(NewsletterDocument::fromArray(['blocks' => [
            ['type' => 'heading', 'content' => ['text' => 'מבצע']],
            ['type' => 'text', 'content' => ['html' => '<p>שורה אחת</p><p>שורה שתיים</p>']],
            ['type' => 'button', 'content' => ['label' => 'קנו עכשיו', 'url' => 'https://shop.example']],
            ['type' => 'footer', 'content' => ['business_line' => 'העסק שלי']],
        ]]));

        $this->assertStringContainsString('מבצע', $text);
        $this->assertStringContainsString("שורה אחת\nשורה שתיים", $text);
        $this->assertStringContainsString('קנו עכשיו: https://shop.example', $text);
        $this->assertStringContainsString('{unsubscribe_url}', $text);
        $this->assertStringNotContainsString('<p>', $text);
    }
}
