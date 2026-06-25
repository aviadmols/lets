<?php

namespace Tests\Feature\Observability;

use App\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\EmailPreviewRenderer;
use App\Support\Tenant;
use App\Support\Ui\EventPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Timeline "Preview email" action (W9 Part A / §6.6) on the Subscription detail.
 * Proves: a previewable email event renders through EmailPreviewRenderer (the same
 * isolated-iframe partial as Mail Settings); a non-email event is NOT previewable;
 * and the event is resolved SCOPED to the current plan — an event of ANOTHER plan
 * (even in the same shop) is refused (never previews another plan's data).
 *
 * The action's security seam is ViewSubscription::scopedEmailEvent() — a pure static
 * method we exercise directly (the full Filament page's typed $record resists the raw
 * Livewire harness; testing the seam + the preview partial covers the real behaviour).
 */
final class TimelineEmailPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'tl-preview.myshopify.com',
            'name' => 'TL Preview',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        Tenant::set($this->shop);

        $this->actingAs(User::factory()->forShop($this->shop)->create());
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_previewable_email_event_renders_the_isolated_preview(): void
    {
        $plan = $this->makePlan();
        $event = $this->event($plan->id, 'charge_succeeded_email_sent');

        // The action resolves the scoped event → its template → the SAME isolated-iframe
        // partial as ManageMailSettings. Exercise that exact machinery.
        $scoped = ViewSubscription::scopedEmailEvent($plan->id, $event->id);
        $this->assertNotNull($scoped);

        $template = EventPresenter::emailTemplate($scoped);
        $preview = EmailPreviewRenderer::preview($template, MerchantMailSettings::current());

        $rendered = view('filament.pages.partials.mail-preview', [
            'subject' => $preview['subject'],
            'html' => $preview['html'],
            'isCustom' => $preview['is_custom'],
        ])->render();

        // Subject label + the sandboxed, isolated iframe (the one allowed inline-CSS spot).
        $this->assertStringContainsString(__('mail.field.subject'), $rendered);
        $this->assertStringContainsString('rc-preview__frame', $rendered);
        $this->assertStringContainsString('sandbox', $rendered);
    }

    public function test_event_kind_maps_to_the_correct_template(): void
    {
        $plan = $this->makePlan();
        $event = $this->event($plan->id, 'charge_failed_email_sent');

        // The presenter resolves the previewable kind → its mail template.
        $this->assertSame('charge_failed', EventPresenter::emailTemplate($event));
    }

    public function test_non_email_event_is_not_previewable(): void
    {
        $event = $this->event($this->makePlan()->id, 'charge_succeeded'); // a money event, not an email

        $this->assertFalse($event->isEmailPreviewable());
        $this->assertNull(EventPresenter::emailTemplate($event));
    }

    public function test_action_refuses_an_event_belonging_to_another_plan(): void
    {
        $planA = $this->makePlan();
        $planB = $this->makePlan();

        // A previewable email event that belongs to plan B (same shop).
        $foreignEvent = $this->event($planB->id, 'charge_succeeded_email_sent');

        // The seam pins plan_id: scoped to plan A it is REFUSED (null), even though the
        // event is a real previewable email of the same shop — never another plan's data.
        $this->assertNull(ViewSubscription::scopedEmailEvent($planA->id, $foreignEvent->id));
        // Scoped to its OWN plan B it resolves (proving the refusal is the scope, not a bug).
        $this->assertNotNull(ViewSubscription::scopedEmailEvent($planB->id, $foreignEvent->id));
    }

    public function test_action_refuses_a_non_previewable_event_id(): void
    {
        $plan = $this->makePlan();
        $nonEmail = $this->event($plan->id, 'plan_created');

        // Right plan, but not an email-previewable kind → refused.
        $this->assertNull(ViewSubscription::scopedEmailEvent($plan->id, $nonEmail->id));
        // And a zero / missing id is refused too (fail closed).
        $this->assertNull(ViewSubscription::scopedEmailEvent($plan->id, 0));
    }

    // === Helpers ===

    private function makePlan(): InstallmentPlan
    {
        $plan = InstallmentPlan::create([
            'plan_kind' => PlanKind::INSTALLMENTS->value,
            'total_amount' => 600,
            'installment_amount' => 100,
            'currency' => 'ILS',
            'customer_name' => 'Dana Cohen',
            'customer_email' => 'dana@example.com',
            'meta' => ['installment_count' => 6],
        ]);
        $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

        return $plan;
    }

    private function event(int $planId, string $kind): ActivityEvent
    {
        return ActivityEvent::create([
            'shop_id' => $this->shop->id,
            'plan_id' => $planId,
            'kind' => $kind,
            'actor' => ActivityEvent::ACTOR_SYSTEM,
            'created_at' => now(),
        ]);
    }
}
