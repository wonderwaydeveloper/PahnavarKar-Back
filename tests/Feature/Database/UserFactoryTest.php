<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_factory_creates_an_active_user_with_a_zero_wallet_balance(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->is_active);
        $this->assertSame(0, $user->wallet_balance);
        $this->assertNull($user->phone);
        $this->assertNull($user->phone_verified_at);
    }

    public function test_states_create_inactive_verified_and_funded_users(): void
    {
        $user = User::factory()
            ->inactive()
            ->withVerifiedPhone()
            ->withWalletBalance(250_000)
            ->create();

        $this->assertFalse($user->is_active);
        $this->assertMatchesRegularExpression('/^\+989[0-9]{9}$/', $user->phone);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertSame(250_000, $user->wallet_balance);
    }
}
