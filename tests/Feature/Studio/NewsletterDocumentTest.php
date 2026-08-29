<?php

namespace Tests\Feature\Studio;

use App\Domain\Campaigns\Studio\Blocks\BlockRegistry;
use App\Domain\Campaigns\Studio\NewsletterDocument;
use Tests\TestCase;

/**
 * The document guard — the law every other studio component leans on:
 * whatever JSON came in (an editor, an old release, a model), what comes out
 * of fromArray() renders. Degraded input NARROWS; it never corrupts and it
 * never throws.
 */
final class NewsletterDocumentTest extends TestCase
{
    public function test_garbage_in_valid_document_out(): void
    {
        $document = NewsletterDocument::fromArray([
            'preheader' => str_repeat('א', 500),
            'globals' => [
                'direction' => 'sideways',
                'width' => 5000,
                'background_color' => 'red',            // not #rrggbb
                'font_family' => 'comic-sans',
                'border_radius' => -3,
            ],
            'blocks' => 'not-a-list',
        ]);

        $globals = $document->globals();
        $this->assertSame('rtl', $globals['direction']);
        $this->assertSame(NewsletterDocument::MAX_WIDTH, $globals['width']);
        $this->assertSame(NewsletterDocument::DEFAULT_GLOBALS['background_color'], $globals['background_color']);
        $this->assertSame('assistant', $globals['font_family']);
        $this->assertSame(NewsletterDocument::MIN_RADIUS, $globals['border_radius']);
        $this->assertSame(NewsletterDocument::MAX_PREHEADER, mb_strlen($document->preheader()));
        $this->assertSame([], $document->blocks());
    }

    public function test_an_unknown_block_type_is_dropped_not_kept(): void
    {
        $document = NewsletterDocument::fromArray([
            'blocks' => [
                ['type' => 'heading', 'content' => ['text' => 'שלום']],
                ['type' => 'countdown_timer', 'content' => ['until' => 'tomorrow']],
                ['type' => 'text', 'content' => ['html' => '<p>hi</p>']],
            ],
        ]);

        // Forward-compat in both directions: what this build cannot draw, it
        // does not carry — never a crash, never a mystery blob.
        $this->assertCount(2, $document->blocks());
        $this->assertSame(['heading', 'text'], array_column($document->blocks(), 'type'));
    }

    public function test_a_foreign_block_id_is_replaced_with_a_server_minted_one(): void
    {
        $document = NewsletterDocument::fromArray([
            'blocks' => [
                ['id' => 'blk_evil-or-wrong', 'type' => 'heading', 'content' => ['text' => 'x']],
            ],
        ]);

        $id = $document->blocks()[0]['id'];
        $this->assertNotSame('blk_evil-or-wrong', $id);
        $this->assertMatchesRegularExpression('/^blk_[0-9A-Za-z]{26}$/', $id);
    }

    public function test_the_block_cap_holds(): void
    {
        $blocks = array_fill(0, NewsletterDocument::MAX_BLOCKS + 20, ['type' => 'spacer']);

        $document = NewsletterDocument::fromArray(['blocks' => $blocks]);

        $this->assertCount(NewsletterDocument::MAX_BLOCKS, $document->blocks());
    }

    public function test_round_trip_is_stable(): void
    {
        $first = NewsletterDocument::fromArray([
            'preheader' => 'מבצע החודש',
            'globals' => ['direction' => 'ltr', 'width' => 620],
            'blocks' => [
                ['type' => 'heading', 'content' => ['text' => 'Hello', 'level' => 2]],
                ['type' => 'button', 'content' => ['label' => 'Go', 'url' => 'https://example.com']],
            ],
        ]);

        // A guarded document re-guarded must not drift — otherwise every save
        // would slowly mutate what the merchant wrote.
        $second = NewsletterDocument::fromArray($first->toArray());

        $this->assertSame($first->toArray(), $second->toArray());
    }

    public function test_every_registered_type_survives_its_own_defaults(): void
    {
        foreach (BlockRegistry::all() as $type => $definition) {
            $document = NewsletterDocument::fromArray([
                'blocks' => [['type' => $type, 'content' => $definition->defaultContent()]],
            ]);

            $this->assertCount(1, $document->blocks(), $type.' must accept its own defaults');
        }
    }

    public function test_mutations_return_new_instances(): void
    {
        $original = NewsletterDocument::empty();
        $changed = $original->withPreheader('חדש');

        $this->assertSame('', $original->preheader());
        $this->assertSame('חדש', $changed->preheader());
    }
}
