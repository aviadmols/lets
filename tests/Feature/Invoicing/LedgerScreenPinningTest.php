<?php

namespace Tests\Feature\Invoicing;

use App\Filament\Resources\IssuedDocumentResource\Pages\ListIssuedDocuments;
use App\Filament\Resources\PaymentLedgerResource;
use App\Models\PaymentLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * Pins the Payments/Invoices screens to the data they display.
 *
 * The charge-context filter was a hand-typed list, so a context that existed in
 * rows could silently be missing from the filter — which is exactly how the
 * `gateway` context would have stayed invisible. These tests hold the model's
 * constants, the filter list, and both lang catalogues to each other.
 */
final class LedgerScreenPinningTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_charge_context_constant_is_filterable_and_translated(): void
    {
        $constants = collect((new ReflectionClass(PaymentLedger::class))->getConstants())
            ->filter(fn ($v, string $k): bool => str_starts_with($k, 'CONTEXT_'))
            ->values()
            ->all();

        $filterable = PaymentLedgerResource::chargeContexts();

        foreach ($constants as $context) {
            $this->assertContains($context, $filterable, "CONTEXT '{$context}' exists on rows but not in the filter.");

            foreach (['en', 'he'] as $locale) {
                $key = 'billing.charge_context.'.$context;
                $this->assertNotSame(
                    $key,
                    trans($key, [], $locale),
                    "'{$key}' is untranslated in {$locale}.",
                );
            }
        }

        // And the reverse: the filter may not invent a context rows can't hold.
        foreach ($filterable as $context) {
            $this->assertContains($context, $constants);
        }
    }

    public function test_the_invoices_screen_lands_on_all_not_on_the_attention_tab(): void
    {
        // Landing on "Needs attention" meant a merchant whose documents all issued
        // correctly opened an empty screen and read it as "no invoices were
        // created" — the opposite of what happened.
        $this->assertSame('all', (new ListIssuedDocuments())->getDefaultActiveTab());
    }
}
