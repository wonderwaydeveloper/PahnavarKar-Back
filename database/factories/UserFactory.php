<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
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
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
            'wallet_balance' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function withVerifiedPhone(): static
    {
        return $this->state(fn (): array => [
            'phone' => fake()->unique()->numerify('+989#########'),
            'phone_verified_at' => now(),
        ]);
    }

    public function withWalletBalance(int $balance): static
    {
        return $this->state(fn (): array => ['wallet_balance' => $balance]);
    }
}
