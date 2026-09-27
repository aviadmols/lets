<?php

namespace Tests\Feature\Privacy;

use App\Console\Commands\PruneRawPayloads;
use App\Models\WebhookEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Raw webhook payloads (names, emails, addresses) are blanked once their
 * retention window passes; the delivery audit row stays. Failed jobs are
 * pruned on a schedule. Both are pinned so a refactor cannot quietly drop them.
 */
final class RetentionPruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_payloads_past_their_window_are_blanked_and_the_rows_stay(): void
    {
        $oldProcessed = $this->event(PruneRawPayloads::RETENTION_DAYS + 1, processed: true);
        $freshProcessed = $this->event(PruneRawPayloads::RETENTION_DAYS - 1, processed: true);
        $unprocessed = $this->event(PruneRawPayloads::RETENTION_DAYS + 1, processed: false);
        $abandoned = $this->event(PruneRawPayloads::UNPROCESSED_RETENTION_DAYS + 1, processed: false);

        $this->artisan('privacy:prune-raw-payloads')->assertSuccessful();

        $this->assertNull($oldProcessed->fresh()->raw_payload);
        $this->assertNotNull($freshProcessed->fresh()->raw_payload, 'Inside the window: kept.');
        $this->assertNotNull($unprocessed->fresh()->raw_payload, 'Unprocessed may still be replayed: kept longer.');
        $this->assertNull($abandoned->fresh()->raw_payload);
        $this->assertSame(4, WebhookEvent::query()->count(), 'The audit rows stay.');
    }

    public function test_both_prunes_are_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($e): string => (string) $e->command)->implode("\n");

        $this->assertStringContainsString('privacy:prune-raw-payloads', $commands);
        $this->assertStringContainsString('queue:prune-failed', $commands);
    }

    private function event(int $ageDays, bool $processed): WebhookEvent
    {
        $event = WebhookEvent::create([
            'topic' => 'orders/paid',
            'raw_payload' => ['email' => 'someone@example.com'],
            'processed_at' => $processed ? now()->subDays($ageDays) : null,
        ]);
        $event->forceFill(['created_at' => now()->subDays($ageDays)])->save();

        return $event;
    }
}
