<?php

namespace App\Filament\Resources\CampaignResource\Pages;

use App\Domain\Campaigns\Email\CampaignBodyNormalizer;
use App\Domain\Campaigns\Email\CampaignPreview;
use App\Domain\Campaigns\Email\EmailCampaignAudience;
use App\Domain\Campaigns\Email\EmailCampaignSender;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Filament\Resources\CampaignResource;
use App\Mail\CampaignTestMail;
use App\Mail\Support\CampaignMailer;
use App\Models\Shop;
use App\Support\Tenant;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Throwable;

/**
 * Editing a campaign — and everything that DOES something with it.
 *
 * ANOTHER SHOP'S CAMPAIGN IS A 404, not a permission message: the record is
 * resolved through the resource's query, which the BelongsToShop global scope
 * has already pinned to the bound tenant.
 *
 * The header actions are the verbs. Each one states its consequence in the
 * confirmation — "send to N people" with the suppressed count beside it,
 * "revoke every link in this email" — because these are the two irreversible
 * things this screen can do: an email that has left cannot be recalled, and a
 * revoked link cannot be un-revoked.
 *
 * PREVIEW AND TEST NEVER MINT A CREDENTIAL. Both render through CampaignPreview,
 * whose sign-in and unsubscribe placeholders resolve to sample URLs — the
 * EmailPreviewRenderer discipline, for the same reason.
 */
class EditCampaign extends EditRecord
{
    // === CONSTANTS ===
    protected static string $resource = CampaignResource::class;

    /** The shared mail-preview partial (subject + sandboxed iframe). */
    public const PREVIEW_VIEW = 'filament.pages.partials.mail-preview';

    /** The audience preview modal's own partial. */
    public const AUDIENCE_VIEW = 'filament.resources.campaign.audience-preview';

    public function getTitle(): string|Htmlable
    {
        return __(CampaignResource::LANG.'.model.edit');
    }

    // === Form data ===

    /**
     * A row written before a filter existed carries no key for it; the form must
     * still mount with every group present and empty.
     *
     * @param  array<string, mixed>  $data
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return CampaignResource::normalizeAudience($data);
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = CampaignResource::normalizeAudience($data);
        $data['body_html'] = CampaignBodyNormalizer::clean($data['body_html'] ?? '');

        return $data;
    }

    /**
     * The one rule the form enforces itself: a marketing email must carry an
     * unsubscribe link. Checked on SAVE rather than as a field validator because
     * the token may live in either editor's content, and the rule is about the
     * body as a whole.
     */
    protected function beforeSave(): void
    {
        $data = $this->form->getState();

        if (! ($data['is_marketing'] ?? false)) {
            return;
        }

        $body = CampaignBodyNormalizer::clean($data['body_html'] ?? '');

        if (! str_contains($body, EmailCampaign::TOKEN_UNSUBSCRIBE)) {
            Notification::make()
                ->danger()
                ->title(__(CampaignResource::LANG.'.form.unsubscribe_required', [
                    'token' => EmailCampaign::TOKEN_UNSUBSCRIBE,
                ]))
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(__(CampaignResource::LANG.'.form.saved'));
    }

    // === Header actions ===

    protected function getHeaderActions(): array
    {
        return [
            $this->previewAction(),
            $this->previewAudienceAction(),
            $this->sendTestAction(),
            $this->sendNowAction(),
            $this->scheduleAction(),
            $this->cancelAction(),
            $this->retryFailedAction(),
            $this->revokeLinksAction(),
            DeleteAction::make()->visible(fn (): bool => $this->record->isEditable()),
        ];
    }

    private function previewAction(): Action
    {
        return Action::make('preview')
            ->label(__(CampaignResource::LANG.'.action.preview'))
            ->icon('heroicon-o-eye')
            ->modalWidth('3xl')
            ->modalSubmitAction(false)
            ->modalContent(fn (): View => $this->previewModal());
    }

    private function previewAudienceAction(): Action
    {
        return Action::make('previewAudience')
            ->label(__(CampaignResource::LANG.'.action.preview_audience'))
            ->icon('heroicon-o-users')
            ->modalWidth('2xl')
            ->modalSubmitAction(false)
            ->modalContent(fn (): View => $this->audienceModal());
    }

    private function sendTestAction(): Action
    {
        return Action::make('sendTest')
            ->label(__(CampaignResource::LANG.'.action.send_test'))
            ->icon('heroicon-o-paper-airplane')
            ->form([
                TextInput::make('recipient')
                    ->label(__('mail.test.recipient'))
                    ->email()
                    ->default(fn (): ?string => auth()->user()?->email)
                    ->required(),
            ])
            ->action(fn (array $data) => $this->sendTest((string) $data['recipient']));
    }

    private function sendNowAction(): Action
    {
        return Action::make('sendNow')
            ->label(__(CampaignResource::LANG.'.action.send_now'))
            ->icon('heroicon-o-rocket-launch')
            ->color('primary')
            ->visible(fn (): bool => $this->record->isEditable())
            ->requiresConfirmation()
            ->modalHeading(fn (): string => __(CampaignResource::LANG.'.action.send_now_confirm', [
                'count' => $this->audienceCount(),
            ]))
            ->modalDescription(fn (): string => __(CampaignResource::LANG.'.action.send_now_body', [
                'suppressed' => $this->suppressedCount(),
            ]))
            ->action(fn () => $this->sendNow());
    }

    private function scheduleAction(): Action
    {
        return Action::make('schedule')
            ->label(__(CampaignResource::LANG.'.action.schedule'))
            ->icon('heroicon-o-clock')
            ->visible(fn (): bool => $this->record->status() === EmailCampaign::STATUS_DRAFT
                && $this->record->scheduled_at !== null)
            ->requiresConfirmation()
            ->modalHeading(__(CampaignResource::LANG.'.action.schedule_confirm'))
            ->action(fn () => $this->schedule());
    }

    private function cancelAction(): Action
    {
        return Action::make('cancelCampaign')
            ->label(__(CampaignResource::LANG.'.action.cancel'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (): bool => in_array(
                $this->record->status(),
                [EmailCampaign::STATUS_SCHEDULED, EmailCampaign::STATUS_SENDING],
                true,
            ))
            ->requiresConfirmation()
            ->modalHeading(__(CampaignResource::LANG.'.action.cancel_confirm'))
            ->modalDescription(__(CampaignResource::LANG.'.action.cancel_body'))
            ->action(fn () => $this->cancelCampaign());
    }

    private function retryFailedAction(): Action
    {
        return Action::make('retryFailed')
            ->label(__(CampaignResource::LANG.'.action.retry_failed'))
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (): bool => (int) $this->record->failed_count > 0)
            ->requiresConfirmation()
            ->action(fn () => $this->retryFailed());
    }

    private function revokeLinksAction(): Action
    {
        return Action::make('revokeLinks')
            ->label(__(CampaignResource::LANG.'.action.revoke_links'))
            ->icon('heroicon-o-shield-exclamation')
            ->color('danger')
            ->visible(fn (): bool => in_array(
                $this->record->status(),
                [EmailCampaign::STATUS_SENDING, EmailCampaign::STATUS_SENT],
                true,
            ) && $this->record->login_links_revoked_at === null)
            ->requiresConfirmation()
            ->modalHeading(__(CampaignResource::LANG.'.action.revoke_confirm'))
            ->modalDescription(__(CampaignResource::LANG.'.action.revoke_body'))
            ->action(fn () => $this->revokeLinks());
    }

    // === Actions ===

    public function sendNow(): void
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            return;
        }

        $result = app(EmailCampaignSender::class)->send($shop, $this->record);

        if ($result['dispatched'] === 0 && $result['enrolled'] === 0) {
            Notification::make()
                ->warning()
                ->title(__(CampaignResource::LANG.'.form.nothing_to_send'))
                ->send();
        } else {
            Notification::make()
                ->success()
                ->title(__(CampaignResource::LANG.'.form.sent_summary', [
                    'count' => $result['dispatched'],
                    'suppressed' => $result['suppressed'],
                    'already' => $result['already'],
                ]))
                ->send();
        }

        $this->refreshFormData(['status']);
    }

    public function schedule(): void
    {
        $at = $this->record->scheduled_at;
        if ($at === null) {
            return;
        }

        if (app(EmailCampaignSender::class)->schedule($this->record, $at)) {
            Notification::make()
                ->success()
                ->title(__(CampaignResource::LANG.'.form.scheduled', ['time' => $at->format('d M Y H:i')]))
                ->send();
        } else {
            Notification::make()->warning()->title(__(CampaignResource::LANG.'.form.cannot_send'))->send();
        }

        $this->refreshFormData(['status']);
    }

    public function cancelCampaign(): void
    {
        if (app(EmailCampaignSender::class)->cancel($this->record)) {
            Notification::make()->success()->title(__(CampaignResource::LANG.'.form.cancelled'))->send();
        }

        $this->refreshFormData(['status']);
    }

    public function retryFailed(): void
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            return;
        }

        $result = app(EmailCampaignSender::class)->retryFailed($shop, $this->record);

        Notification::make()
            ->success()
            ->title(__(CampaignResource::LANG.'.form.retried', ['count' => $result['requeued']]))
            ->send();

        $this->refreshFormData(['status']);
    }

    public function revokeLinks(): void
    {
        $count = app(EmailCampaignSender::class)->revokeLoginLinks($this->record);

        Notification::make()
            ->success()
            ->title(__(CampaignResource::LANG.'.form.links_revoked', ['count' => $count]))
            ->send();
    }

    /**
     * A test to one address, rendered from the merchant's CURRENT (unsaved)
     * copy. It carries no real sign-in link and no real unsubscribe link, and
     * writes nothing: a test is a look at the design, not a send.
     */
    public function sendTest(string $recipient): void
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            return;
        }

        $state = $this->form->getState();
        $preview = app(CampaignPreview::class)->render(
            subject: (string) ($state['subject'] ?? ''),
            body: CampaignBodyNormalizer::clean($state['body_html'] ?? ''),
            shop: $shop,
        );

        try {
            CampaignMailer::for($shop)->to($recipient)->send(new CampaignTestMail(
                shop: $shop,
                renderedSubject: __(CampaignResource::LANG.'.mail.test_prefix').$preview['subject'],
                renderedHtml: $preview['html'],
            ));

            Notification::make()
                ->success()
                ->title(__(CampaignResource::LANG.'.form.test_sent', ['email' => $recipient]))
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title(__(CampaignResource::LANG.'.form.test_failed', ['reason' => class_basename($e)]))
                ->send();
        }
    }

    // === Preview ===

    public function previewModal(): View
    {
        $state = $this->form->getState();

        $preview = app(CampaignPreview::class)->render(
            subject: (string) ($state['subject'] ?? ''),
            body: CampaignBodyNormalizer::clean($state['body_html'] ?? ''),
            shop: Tenant::current(),
        );

        return view(self::PREVIEW_VIEW, [
            'subject' => $preview['subject'],
            'html' => $preview['html'],
            // A campaign has no platform default to fall back on — the merchant
            // wrote every word, so "using your own copy" is always true.
            'isCustom' => true,
            'note' => __(CampaignResource::LANG.'.preview.note'),
        ]);
    }

    public function audienceModal(): View
    {
        $audience = $this->audienceFromForm();
        $engine = app(EmailCampaignAudience::class);

        return view(self::AUDIENCE_VIEW, [
            'rows' => $engine->sample($audience, $this->record, CampaignResource::MAX_PREVIEW_ROWS),
            'total' => $engine->recipients($audience, $this->record)->count(),
        ]);
    }

    // === Internals ===

    /** The bag as the form currently holds it — unsaved edits included. */
    private function audienceFromForm(): array
    {
        $state = $this->form->getState();

        return is_array($state['audience'] ?? null) ? $state['audience'] : $this->record->audience();
    }

    private function audienceCount(): int
    {
        return app(EmailCampaignAudience::class)->count($this->audienceFromForm());
    }

    private function suppressedCount(): int
    {
        return app(EmailCampaignAudience::class)
            ->recipients($this->audienceFromForm())
            ->where('unsubscribed', true)
            ->count();
    }
}
