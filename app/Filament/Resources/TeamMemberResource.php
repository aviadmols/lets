<?php

namespace App\Filament\Resources;

use App\Filament\Clusters\Settings as SettingsCluster;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Resources\TeamMemberResource\Pages;
use App\Models\User;
use App\Support\ShopTeam;
use App\Support\Tenant;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * The shop's own team — the people who may sign in and run it.
 *
 * A merchant had exactly one login, the one created when the store connected, so
 * a second person meant sharing a password. This is the fix: the owner adds
 * colleagues, each with their own credentials, each bound to this shop alone.
 *
 * TENANCY IS EXPLICIT HERE, and that is the whole risk of the screen. `User` is
 * the one admin-facing model that does NOT carry BelongsToShop — it cannot, since
 * a platform admin has no shop — so nothing scopes these queries for us. Every
 * query in this class therefore pins shop_id itself, and a create stamps the
 * bound shop rather than accepting one. A missing `where` on any other screen
 * shows a merchant an empty table; a missing `where` HERE would handed them
 * somebody else's staff list.
 *
 * OWNER VS MEMBER (ShopTeam). The shop's oldest login is its owner; only the
 * owner (or the operator inside the shop) may add, edit or remove OTHER logins.
 * A member may edit their own name, email and password and nothing else here —
 * a support login set up for a colleague cannot re-key the owner and lock them
 * out. Enforced by canCreate/canEdit/canDelete, which Filament checks on the
 * page itself as well as on the buttons.
 *
 * PLATFORM ADMINS ARE INVISIBLE AND UNTOUCHABLE. They are filtered out of every
 * query, so a merchant can neither see the operator's account nor rename, lock
 * or delete it — and `is_platform_admin` is guarded on the model besides, so the
 * flag cannot be reached from a form even if a field were added by accident.
 */
class TeamMemberResource extends Resource
{
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound

    // === CONSTANTS ===
    protected static ?string $model = User::class;

    protected static ?string $slug = 'team';

    /** Behind the one Settings item: in-page left index, URL /admin/settings/{slug}. */
    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 90;

    /** Short enough to type once, long enough not to be guessed. */
    public const MIN_PASSWORD = 10;

    public static function getNavigationGroup(): ?string
    {
        return null; // the cluster index is flat
    }

    public static function getNavigationLabel(): string
    {
        return __('team.nav');
    }

    public static function getModelLabel(): string
    {
        return __('team.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('team.title');
    }

    /**
     * THE ISOLATION WALL. Both halves matter: the shop pin keeps one merchant out
     * of another's team, and the platform-admin exclusion keeps the operator's own
     * account off a customer's screen.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('shop_id', Tenant::id())
            ->where(fn (Builder $q): Builder => $q
                ->where('is_platform_admin', false)
                ->orWhereNull('is_platform_admin'));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make(__('team.form.heading'))
                ->description(__('team.form.intro'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('team.form.name'))
                        ->required()
                        ->maxLength(120),

                    TextInput::make('email')
                        ->label(__('team.form.email'))
                        ->email()
                        ->required()
                        ->maxLength(190)
                        // Across the WHOLE table, not just this shop: the email is
                        // the login, and two accounts sharing one would make
                        // "who just signed in" unanswerable.
                        ->unique(table: User::class, ignoreRecord: true)
                        ->extraInputAttributes(['dir' => 'ltr']),

                    TextInput::make('password')
                        ->label(__('team.form.password'))
                        ->helperText(__('team.form.password_help'))
                        ->password()
                        ->revealable()
                        ->minLength(self::MIN_PASSWORD)
                        // Required when creating; on edit an empty box means
                        // "leave the password alone" — see dehydrateStateUsing.
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->extraInputAttributes(['dir' => 'ltr']),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('team.col.name'))
                    ->weight('semibold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->label(__('team.col.email'))
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('role')
                    ->label(__('team.col.role'))
                    ->state(fn (User $record): string => ShopTeam::isOwner($record)
                        ? __('team.role.owner')
                        : __('team.role.member')),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('team.col.added'))
                    ->dateTime('d M Y'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn (User $record): bool => self::canEdit($record)),

                // A team of one that deletes itself is a shop nobody can sign in
                // to. The last account, and your own, both stay.
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (User $record): bool => self::mayDelete($record))
                    ->modalDescription(fn (User $record): string => __('team.delete.body', ['email' => $record->email])),
            ])
            ->defaultSort('id')
            ->emptyStateHeading(__('team.empty'))
            ->emptyStateIcon('heroicon-o-users');
    }

    /** Adding a login is the owner's call. */
    public static function canCreate(): bool
    {
        return ShopTeam::mayManageOthers(self::actor());
    }

    /** Your own row always; anybody else's only as the owner. */
    public static function canEdit(Model $record): bool
    {
        return (int) $record->getKey() === (int) Auth::id()
            || ShopTeam::mayManageOthers(self::actor());
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof User && self::mayDelete($record);
    }

    public static function canDeleteAny(): bool
    {
        return ShopTeam::mayManageOthers(self::actor());
    }

    /**
     * Only the owner removes people — never yourself, and never the last way in.
     */
    public static function mayDelete(User $record): bool
    {
        if (! ShopTeam::mayManageOthers(self::actor())) {
            return false;
        }

        if ((int) $record->getKey() === (int) Auth::id()) {
            return false;
        }

        return self::getEloquentQuery()->count() > 1;
    }

    private static function actor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeamMembers::route('/'),
            'create' => Pages\CreateTeamMember::route('/create'),
            'edit' => Pages\EditTeamMember::route('/{record}/edit'),
        ];
    }
}
