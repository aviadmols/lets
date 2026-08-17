<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Resources\TeamMemberResource\Pages;
use App\Models\User;
use App\Support\Tenant;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 90;

    /** Short enough to type once, long enough not to be guessed. */
    public const MIN_PASSWORD = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.settings');
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

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('team.col.added'))
                    ->dateTime('d M Y'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

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

    /** Never yourself, and never the last way in. */
    public static function mayDelete(User $record): bool
    {
        if ((int) $record->getKey() === (int) Auth::id()) {
            return false;
        }

        return self::getEloquentQuery()->count() > 1;
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
