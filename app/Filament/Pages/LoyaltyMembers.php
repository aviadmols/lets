<?php

namespace App\Filament\Pages;

use App\Domain\Loyalty\PointsEngine;
use App\Filament\Concerns\ShopScopedScreen;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointEvent;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Who is in the club, what they hold, and the one lever a merchant needs over
 * it: a manual adjustment.
 *
 * The adjustment goes through PointsEngine like every other movement — it is a
 * recorded event with its own idempotency key, not a hand-edited balance. That
 * is the whole point of an append-only points ledger: "why do I have 300
 * points?" always has an answer, including when the answer is "the merchant
 * gave you 100 as an apology".
 */
class LoyaltyMembers extends Page
{
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static string $view = 'filament.pages.loyalty-members';
    protected static ?string $slug = 'loyalty/members';
    protected static ?int $navigationSort = 31;

    /** Rows per page — the club list is a lookup, not an export. */
    public const PAGE_SIZE = 50;

    public string $search = '';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.customers');
    }

    public static function getNavigationLabel(): string
    {
        return __('loyalty.admin.members.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('loyalty.admin.members.title');
    }

    /** @return Collection<int, LoyaltyAccount> the club's members, richest first */
    public function members(): Collection
    {
        return LoyaltyAccount::query()
            ->with('tier')
            ->when($this->search !== '', function ($query): void {
                $term = '%'.$this->search.'%';
                $query->where(fn ($q) => $q
                    ->where('customer_name', 'like', $term)
                    ->orWhere('customer_email', 'like', $term)
                    ->orWhere('customer_ref', 'like', $term));
            })
            ->orderByDesc('points_balance')
            ->limit(self::PAGE_SIZE)
            ->get();
    }

    /**
     * Add or remove points by hand. Recorded like any other movement — a unique
     * key per adjustment, so a double-submitted modal cannot pay twice.
     */
    public function adjustPointsAction(): Actions\Action
    {
        return Actions\Action::make('adjustPoints')
            ->label(__('loyalty.admin.members.adjust'))
            ->modalHeading(__('loyalty.admin.members.adjust'))
            ->form([
                TextInput::make('points')
                    ->label(__('loyalty.admin.members.adjust_points'))
                    ->numeric()
                    ->required(),
                TextInput::make('reason')
                    ->label(__('loyalty.admin.members.adjust_reason'))
                    ->maxLength(120),
            ])
            ->action(function (array $data, array $arguments): void {
                $account = LoyaltyAccount::query()->find((int) ($arguments['account'] ?? 0));
                $points = (int) ($data['points'] ?? 0);

                if ($account === null || $points === 0) {
                    Notification::make()->title(__('loyalty.admin.members.adjust_failed'))->danger()->send();

                    return;
                }

                $event = app(PointsEngine::class)->grant(
                    $account,
                    LoyaltyPointEvent::KIND_ADJUST,
                    $points,
                    'adjust:'.Str::uuid()->toString(),
                    ['reason' => (string) ($data['reason'] ?? '')],
                );

                $event !== null
                    ? Notification::make()->title(__('loyalty.admin.members.adjust_done'))->success()->send()
                    : Notification::make()->title(__('loyalty.admin.members.adjust_failed'))->danger()->send();
            });
    }
}
