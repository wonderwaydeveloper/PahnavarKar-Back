<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class WalletUserFlowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'wallet.withdrawals.enabled' => true,
        ]);
        Cache::store('array')->flush();
        Cache::forget('admin-runtime-settings:v2:wallet_withdrawals');
    }

    public function test_wallet_summary_and_bank_account_update_only_return_a_masked_iban(): void
    {
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 12500]);
        $iban = 'IR49'.str_repeat('0', 22);

        $this->getJson('/api/v1/auth/wallet')->assertUnauthorized();
        $this->putJson('/api/v1/auth/wallet/bank-account', ['iban' => $iban])->assertUnauthorized();
        $this->postJson('/api/v1/auth/wallet/bank-account/reveal')->assertUnauthorized();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/wallet')
            ->assertOk()
            ->assertJsonPath('wallet.balance', 12500)
            ->assertJsonPath('wallet.withdrawals.enabled', true)
            ->assertJsonPath('wallet.bank_account_iban_masked', null);

        $this->putJson('/api/v1/auth/wallet/bank-account', [
            'iban' => 'ir49 '.str_repeat('۰', 22),
        ])
            ->assertOk()
            ->assertJsonPath('bank_account_iban_masked', 'IR49 •••• •••• •••• '.substr($iban, -4))
            ->assertJsonMissing(['iban' => $iban]);

        $stored = $user->fresh()->bank_account_iban;
        $this->assertSame($iban, $stored);
        $this->assertNotSame($iban, $user->fresh()->getRawOriginal('bank_account_iban'));

        $this->actingAs($user->fresh(), 'sanctum');
        $this->getJson('/api/v1/auth/wallet')
            ->assertJsonPath('wallet.bank_account_iban_masked', 'IR49 •••• •••• •••• '.substr($iban, -4))
            ->assertJsonMissing(['bank_account_iban' => $iban]);

        $this->postJson('/api/v1/auth/wallet/bank-account/reveal')
            ->assertOk()
            ->assertJsonPath('iban', $iban)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->putJson('/api/v1/auth/wallet/bank-account', ['iban' => 'IR00'.str_repeat('0', 22)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('iban');
    }

    public function test_revealing_an_unregistered_iban_returns_not_found(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/wallet/bank-account/reveal')
            ->assertNotFound();
    }

    public function test_withdrawal_requires_a_saved_iban_and_snapshots_the_destination(): void
    {
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 250000]);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/v1/auth/wallet/withdrawal-requests', [
            'amount' => 100000,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $firstIban = $this->iban(str_repeat('0', 22));
        $secondIban = $this->iban(str_repeat('1', 22));
        $this->putJson('/api/v1/auth/wallet/bank-account', ['iban' => $firstIban])->assertOk();
        $this->postJson('/api/v1/auth/wallet/withdrawal-requests', [
            'amount' => 100000,
            'idempotency_key' => (string) Str::uuid(),
        ])
            ->assertCreated()
            ->assertJsonPath('withdrawal_request.bank_account_iban_masked', 'IR49 •••• •••• •••• '.substr($firstIban, -4))
            ->assertJsonMissing(['bank_account_iban' => $firstIban]);

        $this->putJson('/api/v1/auth/wallet/bank-account', ['iban' => $secondIban])->assertOk();
        $withdrawal = $user->fresh()->withdrawalRequests()->sole();
        $this->assertSame($firstIban, $withdrawal->bank_account_iban);
        $this->assertNotSame($firstIban, $withdrawal->getRawOriginal('bank_account_iban'));
        $this->assertSame(150000, $user->fresh()->wallet_balance);

        $this->getJson('/api/v1/auth/wallet/withdrawal-requests')
            ->assertOk()
            ->assertJsonPath('data.0.bank_account_iban_masked', 'IR49 •••• •••• •••• '.substr($firstIban, -4))
            ->assertJsonMissing(['bank_account_iban' => $firstIban]);
    }

    public function test_withdrawal_can_use_a_valid_card_number_and_only_returns_a_masked_snapshot(): void
    {
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 250000]);
        $idempotencyKey = (string) Str::uuid();
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/v1/auth/wallet/withdrawal-requests', [
            'amount' => 100000,
            'idempotency_key' => $idempotencyKey,
            'payout_method' => 'card',
            'card_number' => '۴۱۱۱-۱۱۱۱-۱۱۱۱-۱۱۱۱',
        ])
            ->assertCreated()
            ->assertJsonPath('withdrawal_request.payout_method', 'card')
            ->assertJsonPath('withdrawal_request.bank_account_card_masked', '•••• •••• •••• 1111')
            ->assertJsonPath('withdrawal_request.bank_account_iban_masked', null)
            ->assertJsonMissing(['bank_account_card_number' => '4111111111111111']);

        $withdrawal = $user->fresh()->withdrawalRequests()->sole();
        $this->assertSame('4111111111111111', $withdrawal->bank_account_card_number);
        $this->assertNotSame('4111111111111111', $withdrawal->getRawOriginal('bank_account_card_number'));
        $this->assertSame(150000, $user->fresh()->wallet_balance);

        $this->getJson('/api/v1/auth/wallet/withdrawal-requests')
            ->assertOk()
            ->assertJsonPath('data.0.bank_account_card_masked', '•••• •••• •••• 1111')
            ->assertJsonMissing(['bank_account_card_number' => '4111111111111111']);

        $this->postJson('/api/v1/auth/wallet/withdrawal-requests', [
            'amount' => 100000,
            'idempotency_key' => $idempotencyKey,
            'payout_method' => 'card',
            'card_number' => '5555555555554444',
        ])->assertConflict()
            ->assertJsonPath('code', 'withdrawal_request_conflict');
        $this->assertSame(150000, $user->fresh()->wallet_balance);
    }

    public function test_invalid_card_number_does_not_create_a_withdrawal_or_reserve_balance(): void
    {
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 250000]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/wallet/withdrawal-requests', [
                'amount' => 100000,
                'idempotency_key' => (string) Str::uuid(),
                'payout_method' => 'card',
                'card_number' => '4111111111111112',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('card_number');

        $this->assertSame(250000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('withdrawal_requests', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    private function iban(string $bban): string
    {
        $rearranged = $bban.'IR00';
        $numeric = str_replace(
            ['I', 'R'],
            ['18', '27'],
            $rearranged,
        );
        $remainder = 0;
        foreach (str_split($numeric) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return 'IR'.str_pad((string) (98 - $remainder), 2, '0', STR_PAD_LEFT).$bban;
    }
}
