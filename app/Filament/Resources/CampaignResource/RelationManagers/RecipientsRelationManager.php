<?php

namespace App\Filament\Resources\CampaignResource\RelationManagers;

use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Filament\Resources\CampaignResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Who this campaign was written to, and what happened to each one.
 *
 * READ ONLY — no create, no edit, no delete. These rows are the record of who
 * received an email; a merchant who could edit them could make a send that
 * happened look like one that did not. Retrying a failure is a campaign-level
 * action (it re-queues the job), not a row edit.
 *
 * Shown only once the campaign has recipients: an empty tab on a draft is a tab
 * that answers nothing.
 */
class RecipientsRelationManager extends RelationManager
{
    // === CONSTANTS ===
    protected static string $relationship = 'recipients';

    /** The lang file this screen reads — the resource's own. */
    public const LANG = CampaignResource::LANG;

    /** The three answers to "did they open their account from this email?". */
    public const LINK_CLICKED = 'clicked';

    public const LINK_NOT_CLICKED = 'not_clicked';

    /** No link was written to this person — the question does not apply. */
    public const LINK_NONE = 'none';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __(self::LANG.'.stat.recipients');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer_name')
                    ->label(__(self::LANG.'.table.person'))
                    ->formatStateUsing(fn (EmailCampaignRecipient $record): string => $record->label())
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('email')
                    ->label(__(self::LANG.'.table.email'))
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__(self::LANG.'.table.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __(self::LANG.'.recipient_status.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        EmailCampaignRecipient::STATUS_SENT => 'success',
                        EmailCampaignRecipient::STATUS_FAILED => 'danger',
                        EmailCampaignRecipient::STATUS_SKIPPED => 'gray',
                        default => 'info',
                    }),

                Tables\Columns\TextColumn::make('source_type')
                    ->label(__(self::LANG.'.table.rail'))
                    ->formatStateUsing(fn (string $state): string => __(self::LANG.'.rail.'.match ($state) {
                        EmailCampaignRecipient::SOURCE_CONTRACT => 'shopify',
                        EmailCampaignRecipient::SOURCE_LOYALTY => 'loyalty',
                        default => 'payplus',
                    })),

                Tables\Columns\TextColumn::make('reason')
                    ->label(__(self::LANG.'.table.reason'))
                    ->formatStateUsing(fn (?string $state): string => $state === null || $state === ''
                        ? '—'
                        : __(self::LANG.'.reason.'.$state))
                    ->placeholder('—'),

                // Did they come in? THREE answers, not two: a person the campaign
                // never wrote a link to has not "failed to click" it, and showing
                // them as a miss would quietly understate every campaign whose body
                // has no {account_login_url} at all.
                Tables\Columns\TextColumn::make('loginToken.consumed_at')
                    ->label(__(self::LANG.'.table.account_link'))
                    ->badge()
                    ->state(fn (EmailCampaignRecipient $record): string => match ($record->clickedAccountLink()) {
                        true => self::LINK_CLICKED,
                        false => self::LINK_NOT_CLICKED,
                        default => self::LINK_NONE,
                    })
                    ->formatStateUsing(fn (string $state): string => __(self::LANG.'.account_link.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        self::LINK_CLICKED => 'success',
                        self::LINK_NOT_CLICKED => 'warning',
                        default => 'gray',
                    })
                    // When they arrived, and whether they came back — the reuse
                    // window means one link can serve a phone now and a laptop later.
                    ->description(function (EmailCampaignRecipient $record): ?string {
                        $token = $record->loginToken;

                        if ($token?->consumed_at === null) {
                            return null;
                        }

                        $when = $token->consumed_at->format('d M Y H:i');
                        $uses = (int) ($token->use_count ?? 0);

                        return $uses > 1
                            ? __(self::LANG.'.account_link.first_of', ['when' => $when, 'count' => $uses])
                            : $when;
                    }),

                Tables\Columns\TextColumn::make('sent_at')
                    ->label(__(self::LANG.'.table.sent_at'))
                    ->dateTime('d M Y H:i')
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__(self::LANG.'.table.status'))
                    ->options(fn (): array => collect(EmailCampaignRecipient::STATUSES)
                        ->mapWithKeys(fn (string $s): array => [$s => __(self::LANG.'.recipient_status.'.$s)])
                        ->all()),

                Tables\Filters\TernaryFilter::make('clicked_account_link')
                    ->label(__(self::LANG.'.table.account_link'))
                    ->placeholder(__(self::LANG.'.account_link.any'))
                    ->trueLabel(__(self::LANG.'.account_link.clicked'))
                    ->falseLabel(__(self::LANG.'.account_link.not_clicked'))
                    ->queries(
                        true: fn ($query) => $query->whereHas('loginToken', fn ($q) => $q->whereNotNull('consumed_at')),
                        false: fn ($query) => $query->whereHas('loginToken', fn ($q) => $q->whereNull('consumed_at')),
                        blank: fn ($query) => $query,
                    ),
            ])
            // The column reads the token per row; eager-load it so a page of 100
            // recipients is one extra query rather than a hundred.
            ->modifyQueryUsing(fn ($query) => $query->with('loginToken'))
            ->defaultSort('id')
            ->paginated([25, 50, 100]);
    }

    /** Nothing on this tab writes. */
    public function isReadOnly(): bool
    {
        return true;
    }
}
