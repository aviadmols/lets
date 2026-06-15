<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * The admin login. Each MERCHANT user belongs to EXACTLY ONE shop (shop_id); the
 * production tenant binding (BindTenantFromUser) binds that shop so the
 * BelongsToShop global scope isolates every admin query.
 *
 * A platform-admin user (is_platform_admin = true) is the app OWNER — it has no
 * single shop and may reach an explicit, audited cross-tenant path. It must NOT
 * silently see all tenants through normal panel queries: with no shop bound, the
 * global scope fails closed (zero rows), and the panel denies access unless a
 * tenant is bound. Platform admins operate through the audited
 * BelongsToShop::acrossAllTenants() seam, never through ambient panel state.
 *
 * Panel access (canAccessPanel) is fail-closed: a user with NEITHER a shop NOR
 * the platform-admin flag is denied. is_platform_admin is GUARDED from mass
 * assignment — it can only be set deliberately via forceFill/direct attribute.
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Mass-assignable. is_platform_admin is DELIBERATELY excluded (guarded): a
     * privilege flag must never be set from request input. shop_id is fillable so
     * OAuth onboarding can create-or-attach a merchant user to its shop, but it is
     * never accepted from a user-facing form.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'shop_id',
    ];

    /**
     * Never mass-assignable. is_platform_admin is the one privilege escalation we
     * refuse to expose to fill()/create() — set it explicitly in a seeder/console.
     *
     * @var list<string>
     */
    protected $guarded = [
        'is_platform_admin',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }

    /** The shop this merchant user administers (null for a platform-admin owner). */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    /** A merchant user that is bound to exactly one shop. */
    public function belongsToShop(): bool
    {
        return $this->shop_id !== null;
    }

    /**
     * Filament gate (fail closed): only a user linked to a shop OR an explicit
     * platform admin may reach the panel. A shopless, non-platform user is denied
     * here AND the binding middleware refuses to bind a tenant — defence in depth.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->belongsToShop() || $this->isPlatformAdmin();
    }
}
