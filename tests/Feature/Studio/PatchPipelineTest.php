<?php

namespace Tests\Feature\Studio;

use App\Domain\Campaigns\Studio\NewsletterDocument;
use App\Domain\Campaigns\Studio\Ops\OpValidator;
use App\Domain\Campaigns\Studio\Ops\PatchApplier;
use App\Domain\Campaigns\Studio\Ops\PatchOp;
use Tests\TestCase;

/**
 * The patch pipeline's laws, pinned:
 *   - the op vocabulary contains NO send/schedule/cancel/delete verb — the AI
 *     cannot do what the list cannot say;
 *   - validation and application reject PER-OP, never all-or-nothing;
 *   - every payload passes the block definitions' own cleaners — an AI payload
 *     and a human keystroke hit the same wall;
 *   - a marketing document keeps its last footer, whoever asks.
 */
final class PatchPipelineTest extends TestCase
{
    private function document(): NewsletterDocument
    {
        return NewsletterDocument::fromArray(['blocks' => [
            ['type' => 'heading', 'content' => ['text' => 'שלום']],
            ['type' => 'text', 'content' => ['html' => '<p>גוף</p>']],
            ['type' => 'footer', 'content' => []],
        ]]);
    }

    public function test_the_vocabulary_has_no_dangerous_verb(): void
    {
        // The structural half of "AI never sends": pinned as the EXACT set, so
        // a future verb is a conscious change to this test, not a drift.
        $this->assertSame([
            'add_block', 'update_block_content', 'update_block_style', 'move_block',
            'duplicate_block', 'remove_block', 'set_global_style', 'set_preheader', 'set_subject',
        ], PatchOp::OPS);

        foreach (['send', 'schedule', 'cancel', 'delete'] as $verb) {
            foreach (PatchOp::OPS as $op) {
                $this->assertStringNotContainsString($verb, $op);
            }
        }
    }

    public function test_validation_rejects_per_op_and_keeps_the_rest(): void
    {
        $result = (new OpValidator)->validate([
            ['op' => 'set_subject', 'payload' => ['text' => 'נושא חדש']],
            ['op' => 'send_campaign', 'payload' => []],                    // no such verb
            ['op' => 'update_block_content', 'payload' => ['text' => 'x']], // no target
            ['op' => 'add_block', 'block_type' => 'countdown', 'payload' => []], // no such type
            'not-even-an-object',
        ]);

        $this->assertCount(1, $result['ops']);
        $this->assertSame('set_subject', $result['ops'][0]->op);

        $reasons = array_column($result['rejected'], 'reason');
        $this->assertContains(OpValidator::REJECT_UNKNOWN_OP, $reasons);
        $this->assertContains(OpValidator::REJECT_MISSING_TARGET, $reasons);
        $this->assertContains(OpValidator::REJECT_UNKNOWN_TYPE, $reasons);
        $this->assertContains(OpValidator::REJECT_BAD_PAYLOAD, $reasons);
    }

    public function test_an_ai_payload_hits_the_same_cleaners_as_a_keystroke(): void
    {
        $document = $this->document();
        $textId = $document->blocks()[1]['id'];

        $outcome = (new PatchApplier)->apply($document, [
            new PatchOp(
                op: PatchOp::OP_UPDATE_BLOCK_CONTENT,
                targetId: $textId,
                payload: ['html' => '<p onclick="x()">חדש<script>evil()</script></p>'],
                reason: '',
                confidence: 1.0,
            ),
        ], true);

        $stored = $outcome['document']->findBlock($textId);
        $this->assertSame('<p>חדש</p>', $stored['content']['html']);
    }

    public function test_add_block_gets_a_server_minted_id_never_the_models(): void
    {
        $outcome = (new PatchApplier)->apply($this->document(), [
            new PatchOp(
                op: PatchOp::OP_ADD_BLOCK,
                targetId: null,
                payload: ['label' => 'קנו', 'url' => 'https://x.example', 'id' => 'blk_spoofed'],
                reason: '',
                confidence: 1.0,
                blockType: 'button',
                position: 1,
            ),
        ], true);

        $button = collect($outcome['document']->blocks())->firstWhere('type', 'button');
        $this->assertNotNull($button);
        $this->assertNotSame('blk_spoofed', $button['id']);
        $this->assertSame(1, $outcome['document']->blockIndex($button['id']));
    }

    public function test_the_last_footer_of_a_marketing_document_survives_everyone(): void
    {
        $document = $this->document();
        $footerId = collect($document->blocks())->firstWhere('type', 'footer')['id'];

        $remove = new PatchOp(PatchOp::OP_REMOVE_BLOCK, $footerId, [], '', 1.0);

        $marketing = (new PatchApplier)->apply($document, [$remove], true);
        $this->assertSame(1, $marketing['document']->countBlocksOf('footer'));
        $this->assertSame(PatchApplier::REJECT_LAST_FOOTER, $marketing['rejected'][0]['reason']);

        // An operational (non-marketing) email may drop it.
        $operational = (new PatchApplier)->apply($document, [$remove], false);
        $this->assertSame(0, $operational['document']->countBlocksOf('footer'));
    }

    public function test_a_vanished_target_rejects_while_siblings_land(): void
    {
        $document = $this->document();
        $headingId = $document->blocks()[0]['id'];

        $outcome = (new PatchApplier)->apply($document, [
            new PatchOp(PatchOp::OP_UPDATE_BLOCK_CONTENT, 'blk_gone', ['text' => 'x'], '', 1.0),
            new PatchOp(PatchOp::OP_UPDATE_BLOCK_CONTENT, $headingId, ['text' => 'עודכן'], '', 1.0),
            new PatchOp(PatchOp::OP_SET_SUBJECT, null, ['text' => 'נושא מהמודל'], '', 1.0),
        ], true);

        $this->assertCount(2, $outcome['applied']);
        $this->assertSame(PatchApplier::REJECT_TARGET_GONE, $outcome['rejected'][0]['reason']);
        $this->assertSame('עודכן', $outcome['document']->findBlock($headingId)['content']['text']);
        $this->assertSame('נושא מהמודל', $outcome['subject']);
    }

    public function test_the_applier_is_pure_the_input_document_is_untouched(): void
    {
        $document = $this->document();
        $before = $document->toArray();

        (new PatchApplier)->apply($document, [
            new PatchOp(PatchOp::OP_SET_PREHEADER, null, ['text' => 'חדש'], '', 1.0),
        ], true);

        $this->assertSame($before, $document->toArray());
    }
}
