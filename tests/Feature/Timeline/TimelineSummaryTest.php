<?php

namespace Tests\Feature\Timeline;

use App\Models\ActivityEvent;
use App\Support\Ui\EventPresenter;
use Tests\TestCase;

/**
 * EVERY ROW SAYS EXACTLY WHAT WAS DONE — in the admin's language, and without
 * ever showing a URL, a token or a raw catalogue key.
 */
final class TimelineSummaryTest extends TestCase
{
    // === CONSTANTS ===
    private const URL = 'https://www.greeninvoice.co.il/api/v1/documents/download?d=secret-doc-id';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('he');
    }

    public function test_a_payment_row_transition_reads_the_ledger_catalogue_in_hebrew(): void
    {
        $event = $this->event('status_changed', [
            'model' => 'InstallmentPayment', 'from' => 'pending', 'to' => 'succeeded',
            'sequence' => 3, 'amount' => 120, 'currency' => 'ILS',
        ], paymentId: 9);

        $this->assertSame('סטטוס התשלום השתנה', EventPresenter::label($event));

        $summary = (string) EventPresenter::summarize($event);
        $this->assertStringContainsString('ממתין ← הצליח', $summary);
        $this->assertStringContainsString('תשלום 3', $summary);
        $this->assertStringNotContainsString('billing.', $summary);
    }

    public function test_every_ledger_status_has_a_hebrew_word(): void
    {
        foreach (['pending' => 'ממתין', 'succeeded' => 'הצליח', 'failed' => 'נכשל', 'retry_scheduled' => 'תוזמן ניסיון חוזר', 'refunded' => 'זוכה'] as $status => $word) {
            $event = $this->event('status_changed', ['model' => 'InstallmentPayment', 'from' => 'pending', 'to' => $status], paymentId: 1);

            $this->assertStringEndsWith($word, (string) EventPresenter::summarize($event), $status);
        }
    }

    /** A row written before `model` existed is judged by its payment_id. */
    public function test_an_old_payment_row_without_a_model_still_reads_the_ledger_catalogue(): void
    {
        $event = $this->event('status_changed', ['from' => 'failed', 'to' => 'retry_scheduled'], paymentId: 4);

        $this->assertSame('נכשל ← תוזמן ניסיון חוזר', EventPresenter::summarize($event));
    }

    public function test_a_plan_transition_reads_the_plan_catalogue_with_its_reason(): void
    {
        $event = $this->event('status_changed', [
            'model' => 'InstallmentPlan', 'from' => 'awaiting_payment', 'to' => 'active', 'action' => 'dunning_resumed',
        ]);

        $this->assertSame('הסטטוס השתנה', EventPresenter::label($event));
        $this->assertSame('ממתין לתשלום ← פעיל · ניסיונות החיוב חודשו', EventPresenter::summarize($event));
    }

    public function test_an_unknown_status_or_reason_is_humanized_never_a_raw_key(): void
    {
        $event = $this->event('status_changed', [
            'model' => 'InstallmentPlan', 'from' => 'some_new_state', 'to' => 'active', 'action' => 'brand_new_reason',
        ]);

        $summary = (string) EventPresenter::summarize($event);
        $this->assertStringContainsString('Some new state', $summary);
        $this->assertStringContainsString('Brand new reason', $summary);
        $this->assertStringNotContainsString('billing.status', $summary);
        $this->assertStringNotContainsString('timeline.', $summary);
    }

    public function test_a_document_row_names_type_number_and_amount_and_never_a_url(): void
    {
        $event = $this->event('document_issued', [
            'context' => 'recurring', 'document_type' => '320', 'document_number' => '40123',
            'amount' => 89.9, 'currency' => 'ILS', 'order_id' => '94918',
            'document_url' => self::URL, 'invoice_url' => self::URL, 'url' => self::URL,
        ]);

        $summary = (string) EventPresenter::summarize($event);

        $this->assertStringContainsString('סוג מסמך: חשבונית מס / קבלה', $summary);
        $this->assertStringContainsString('מספר מסמך: 40123', $summary);
        $this->assertStringContainsString('עבור: חידוש מנוי', $summary);
        $this->assertStringContainsString('הזמנה: #94918', $summary);
        $this->assertStringContainsString('89.90', $summary);
        $this->assertStringNotContainsString('http', $summary);
        $this->assertStringNotContainsString('greeninvoice', $summary);
    }

    public function test_a_url_hidden_inside_free_text_is_scrubbed(): void
    {
        foreach (['document_failed', 'store_order_failed'] as $kind) {
            $event = $this->event($kind, [
                'error_message' => 'Request to '.self::URL.' failed',
                'reason' => 'cURL error for https://shop.example.com/wp-json/wc/v3/orders?consumer_key=ck_live_123',
            ]);

            $summary = (string) EventPresenter::summarize($event);
            $this->assertStringNotContainsString('http', $summary, $kind);
            $this->assertStringNotContainsString('consumer_key', $summary, $kind);
            $this->assertStringNotContainsString('secret-doc-id', $summary, $kind);
        }
    }

    public function test_a_requested_document_says_what_it_is_for(): void
    {
        $event = $this->event('document_issue_requested', [
            'document_type' => 'invoice_receipt', 'context' => 'final_installment', 'amount' => 250, 'currency' => 'ILS',
        ]);

        $summary = (string) EventPresenter::summarize($event);
        $this->assertStringContainsString('סוג מסמך: חשבונית מס / קבלה', $summary);
        $this->assertStringContainsString('עבור: תשלום אחרון', $summary);
    }

    public function test_a_renewal_charge_says_why_which_card_and_the_approval_number_but_not_the_uid(): void
    {
        $event = $this->event('charge_succeeded', [
            'amount' => 39, 'currency' => 'ILS', 'trigger' => 'auto_renewal', 'charge_number' => 4,
            'card_brand' => 'visa', 'card_last_four' => '4242', 'approval_number' => '0123456',
            'transaction_uid' => 'a3f1c2d4-0000-1111-2222-333344445555', 'key' => 'recurring:1:2:2026-09-01',
        ]);

        $summary = (string) EventPresenter::summarize($event);

        $this->assertStringStartsWith('חידוש אוטומטי', $summary);
        $this->assertStringContainsString('חיוב מס׳ 4', $summary);
        // The card sits in a left-to-right isolate so Hebrew never flips it to "4242 ••••".
        $this->assertStringContainsString("כרטיס: \u{2066}visa •••• 4242\u{2069}", $summary);
        $this->assertStringContainsString('מספר אישור: 0123456', $summary);
        $this->assertStringNotContainsString('a3f1c2d4', $summary);
        $this->assertStringNotContainsString('recurring:1', $summary);
    }

    public function test_a_declined_charge_says_the_reason_attempt_and_next_retry(): void
    {
        config(['app.timezone' => 'Asia/Jerusalem']);

        $event = $this->event('charge_retry_scheduled', [
            'error_code' => '1', 'error_message' => 'כרטיס חסום', 'attempt' => 2,
            'next_retry_at' => '2026-10-02T09:00:00+00:00', 'trigger' => 'retry', 'sequence' => 2,
            'amount' => 150, 'currency' => 'ILS',
        ]);

        $this->assertSame('החיוב נכשל — תוזמן ניסיון חוזר', EventPresenter::label($event));

        $summary = (string) EventPresenter::summarize($event);
        $this->assertStringContainsString('ניסיון חוזר אוטומטי', $summary);
        $this->assertStringContainsString('סיבה: כרטיס חסום', $summary);
        $this->assertStringContainsString('ניסיון: 2', $summary);
        $this->assertStringContainsString('ניסיון הבא: 02/10/2026 12:00', $summary);
    }

    public function test_a_forgiven_period_prints_dates_not_status_keys(): void
    {
        $event = $this->event('cycles_forgiven', ['skipped' => 2, 'from' => '2026-07-01', 'to' => '2026-09-01']);

        $this->assertSame('מחזורים שלא נגבו: 2 · תקופה: 01/07/2026 ← 01/09/2026', EventPresenter::summarize($event));
    }

    public function test_a_recovered_card_never_shows_the_token_tails(): void
    {
        $event = $this->event('payment_method_token_recovered', ['was' => '…abc123', 'now' => '…def456', 'route' => 'email']);

        $summary = (string) EventPresenter::summarize($event);
        $this->assertStringNotContainsString('abc123', $summary);
        $this->assertStringNotContainsString('def456', $summary);
    }

    public function test_an_import_row_says_which_file_and_line(): void
    {
        $event = $this->event('subscription_imported', [
            'line' => 17, 'membership_id' => 'M-883', 'file' => 'members.csv', 'next_charge_at' => '2026-10-05T00:00:00+00:00',
        ]);

        $this->assertSame('יובא מקובץ', EventPresenter::label($event));
        $summary = (string) EventPresenter::summarize($event);
        $this->assertStringContainsString('קובץ: members.csv', $summary);
        $this->assertStringContainsString('שורה: 17', $summary);
        $this->assertStringContainsString('מספר חבר: M-883', $summary);
        $this->assertStringContainsString('חיוב הבא: ', $summary);
    }

    public function test_an_email_row_names_the_email(): void
    {
        $event = $this->event('reminder_email_sent', ['cycle' => '2026-10-01', 'offset_hours' => 48]);

        $summary = (string) EventPresenter::summarize($event);
        $this->assertStringContainsString('אימייל: תזכורת לפני חיוב', $summary);
        $this->assertStringContainsString('נשלח 48 שעות לפני החיוב', $summary);
    }

    public function test_a_contact_edit_names_the_fields_but_not_their_values(): void
    {
        $event = $this->event('customer_details_updated', [
            'was' => ['name' => 'Dana', 'email' => 'a@x.com', 'phone' => null, 'national_id' => '123456782', 'address' => null],
            'now' => ['name' => 'Dana', 'email' => 'b@x.com', 'phone' => '050', 'national_id' => '123456782', 'address' => null],
        ]);

        $summary = (string) EventPresenter::summarize($event);
        $this->assertSame('עודכן: אימייל, טלפון', $summary);
    }

    public function test_the_english_summary_reads_as_english(): void
    {
        app()->setLocale('en');

        $event = $this->event('status_changed', ['model' => 'InstallmentPayment', 'from' => 'pending', 'to' => 'failed'], paymentId: 2);

        $this->assertSame('Payment status changed', EventPresenter::label($event));
        $this->assertSame('Pending → Failed', EventPresenter::summarize($event));
    }

    private function event(string $kind, array $details, ?int $paymentId = null): ActivityEvent
    {
        return (new ActivityEvent)->forceFill([
            'kind' => $kind,
            'details' => $details,
            'payment_id' => $paymentId,
            'actor' => ActivityEvent::ACTOR_SYSTEM,
        ]);
    }
}
