<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AccountAccessRestrictionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_apply_selected_capabilities_without_revoking_sessions(): void
    {
        $manager = $this->manager();
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;
        $restrictedUntil = now()->addDays(3)->startOfMinute();

        $this->actingAs($manager, 'web')
            ->patch(route('admin.users.status', $user), [
                'mode' => 'restricted',
                'restriction_capabilities' => ['financial', 'profile'],
                'reason' => 'Review required',
                'restricted_until' => $restrictedUntil->toDateTimeString(),
                'revoke_sessions' => false,
            ])
            ->assertRedirect();

        $user->refresh();
        $this->assertSame(['financial', 'profile'], $user->restricted_capabilities);
        $this->assertSame('Review required', $user->restriction_reason);
        $this->assertTrue($user->restricted_until->equalTo($restrictedUntil));
        $this->assertTrue($user->is_active);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->getKey()]);

        Auth::forgetGuards();
        $this->withToken($token)
            ->postJson('/api/v1/auth/wallet/withdrawal-requests')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_capability_restricted')
            ->assertJsonPath('capability', 'financial')
            ->assertJsonPath('restriction_reason', 'Review required')
            ->assertJsonPath('restricted_until', $restrictedUntil->toIso8601String());

        Auth::forgetGuards();
        $this->withToken($token)
            ->patchJson('/api/v1/auth/profile', ['name' => 'Blocked update'])
            ->assertForbidden()
            ->assertJsonPath('capability', 'profile');

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_expired_restrictions_are_cleared_and_audited(): void
    {
        $user = User::factory()->create([
            'restricted_capabilities' => ['financial'],
            'restriction_reason' => 'Temporary review',
            'restricted_until' => now()->subSecond(),
        ]);

        Artisan::call('accounts:reactivate-expired-suspensions');

        $user->refresh();
        $this->assertNull($user->restricted_capabilities);
        $this->assertNull($user->restriction_reason);
        $this->assertNull($user->restricted_until);
        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $user->getKey(),
            'event' => 'account_restriction_expired',
        ]);
    }

    public function test_suspension_can_preserve_a_session_until_the_account_is_reactivated(): void
    {
        $manager = $this->manager();
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->actingAs($manager, 'web')
            ->patch(route('admin.users.status', $user), [
                'mode' => 'suspended',
                'reason' => 'Temporary review',
                'revoke_sessions' => false,
            ])
            ->assertRedirect();

        $user->refresh();
        $this->assertFalse($user->is_active);
        $this->assertFalse($user->suspension_revoke_sessions);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->getKey()]);

        Auth::forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_disabled')
            ->assertJsonPath('restriction_reason', 'Temporary review');
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->getKey()]);

        $logoutToken = $user->createToken('logout-while-suspended')->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($logoutToken)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->getKey(),
            'name' => 'logout-while-suspended',
        ]);

        Auth::forgetGuards();
        $this->actingAs($manager, 'web')
            ->patch(route('admin.users.status', $user), ['mode' => 'active'])
            ->assertRedirect();

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    private function manager(): User
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(Permission::findOrCreate('manage users', 'web'));

        return $manager;
    }
}
