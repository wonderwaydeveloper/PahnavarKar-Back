<?php

namespace Tests\Feature\Auth;

use App\Models\Otp;
use App\Models\PaymentAttempt;
use App\Models\PaymentOrder;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountDeletionFinancialOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_account_deletion_is_blocked_until_payment_order_is_finalized(): void
    {
        $user = $this->userWithOtp();
        $token = $user->createToken('test-device')->plainTextToken;
        $order = PaymentOrder::query()->create([
            'user_id' => $user->getKey(),
            'order_reference' => (string) Str::uuid(),
            'kind' => 'wallet_top_up',
            'gross_amount' => 1000,
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);

        $this->deleteJson('/api/v1/auth/account', ['code' => '123456'], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account');

        $this->assertDatabaseHas('users', ['id' => $user->getKey()]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->getKey()]);
        $this->assertFalse(Otp::query()->firstOrFail()->used);

        $order->forceFill(['status' => 'failed'])->saveOrFail();

        $this->deleteJson('/api/v1/auth/account', ['code' => '123456'], [
            'Authorization' => 'Bearer '.$token,
        ])->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $user->getKey()]);
    }

    public function test_manual_account_deletion_is_blocked_while_withdrawal_is_pending_or_awaiting_payout(): void
    {
        $user = $this->userWithOtp();
        $token = $user->createToken('test-device')->plainTextToken;
        $attributes = [
            'user_id' => $user->getKey(),
            'amount' => 1000,
            'currency' => 'TOMAN',
            'idempotency_expires_at' => now()->addDay(),
        ];
        $pendingWithdrawal = WithdrawalRequest::query()->create([
            ...$attributes,
            'request_reference' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => 'pending',
        ]);
        $approvedWithdrawal = WithdrawalRequest::query()->create([
            ...$attributes,
            'request_reference' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => 'approved_pending_payout',
        ]);

        $this->deleteJson('/api/v1/auth/account', ['code' => '123456'], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account');

        $this->assertDatabaseHas('users', ['id' => $user->getKey()]);

        $pendingWithdrawal->forceFill(['status' => 'rejected'])->saveOrFail();

        $this->deleteJson('/api/v1/auth/account', ['code' => '123456'], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account');

        $approvedWithdrawal->forceFill(['status' => 'rejected'])->saveOrFail();

        $this->deleteJson('/api/v1/auth/account', ['code' => '123456'], [
            'Authorization' => 'Bearer '.$token,
        ])->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $user->getKey()]);
    }

    public function test_manual_account_deletion_is_blocked_while_payment_attempt_status_is_unknown(): void
    {
        $user = $this->userWithOtp();
        $token = $user->createToken('test-device')->plainTextToken;
        $attempt = PaymentAttempt::query()->create([
            'user_id' => $user->getKey(),
            'merchant_reference' => (string) Str::uuid(),
            'gateway' => 'zarinpal',
            'gateway_environment' => 'sandbox',
            'amount' => 1000,
            'currency' => 'TOMAN',
            'status' => 'initiation_unknown',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);

        $this->deleteJson('/api/v1/auth/account', ['code' => '123456'], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account');

        $this->assertDatabaseHas('users', ['id' => $user->getKey()]);

        $attempt->forceFill(['status' => 'failed'])->saveOrFail();

        $this->deleteJson('/api/v1/auth/account', ['code' => '123456'], [
            'Authorization' => 'Bearer '.$token,
        ])->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $user->getKey()]);
    }

    private function userWithOtp(): User
    {
        $user = User::factory()->create([
            'phone' => '+989121234567',
            'phone_verified_at' => now(),
        ]);

        Otp::query()->create([
            'user_id' => $user->getKey(),
            'phone' => $user->phone,
            'code_hash' => Hash::make('123456'),
            'type' => 'login',
            'expires_at' => now()->addMinutes(5),
        ]);

        return $user;
    }
}
