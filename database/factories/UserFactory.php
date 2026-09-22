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
    protected static ?string $password;

    protected static ?string $pin;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'auth_type' => 'password_only',
            'password' => static::$password ??= Hash::make('password'),
            'pin_hash' => null,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Password + TOTP MFA user (Owner, General Manager, Accountant).
     */
    public function passwordMfa(): static
    {
        return $this->state(fn (array $attributes) => [
            'auth_type' => 'password_mfa',
            'password' => static::$password ??= Hash::make('password'),
            'pin_hash' => null,
        ]);
    }

    /**
     * PIN-only staff (Cashier, Front Desk, Bar Staff, Restaurant Staff).
     */
    public function pinAuth(string $pin = '1234'): static
    {
        return $this->state(fn (array $attributes) => [
            'auth_type' => 'pin',
            'password' => null,
            'pin_hash' => Hash::make($pin),
        ]);
    }
}
