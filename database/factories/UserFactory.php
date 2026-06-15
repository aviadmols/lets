<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // shop_id is fillable (OAuth onboarding sets it); default unbound.
            // is_platform_admin is GUARDED — never set via fill; DB default false.
            'shop_id' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** A merchant user bound to a given shop (the normal case). */
    public function forShop(int|\App\Models\Shop $shop): static
    {
        return $this->state(fn (array $attributes) => [
            'shop_id' => $shop instanceof \App\Models\Shop ? $shop->getKey() : $shop,
            'is_platform_admin' => false,
        ]);
    }

    /**
     * The platform owner: no single shop, may reach the audited cross-tenant path.
     * is_platform_admin is GUARDED on the model, so the constructor's fill() drops
     * it — set it via forceFill after making (mirrors how production seeds it).
     */
    public function platformAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'shop_id' => null,
        ])->afterMaking(function (\App\Models\User $user): void {
            $user->forceFill(['is_platform_admin' => true]);
        })->afterCreating(function (\App\Models\User $user): void {
            $user->forceFill(['is_platform_admin' => true])->save();
        });
    }
}
