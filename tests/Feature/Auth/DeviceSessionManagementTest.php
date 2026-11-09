<?php

namespace Tests\Feature\Auth;

use App\Models\Otp;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeviceSessionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_limit_rejects_a_fourth_device_and_expired_sessions_are_pruned_before_retry(): void
    {
        Queue::fake();
        $user = $this->userFor('+989121234567');
        $deviceIds = [
            '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1174',
            '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1175',
            '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1176',
        ];
        $sessions = [];
        foreach ($deviceIds as $deviceId) {
            $sessions[] = $this->addDevice($user, $deviceId);
        }

        $newDeviceId = '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1177';
        $otp = $this->createOtp($user);
        $payload = $this->loginPayload($user->phone, $newDeviceId);

        $this->postJson('/api/v1/auth/otp/verify', $payload)
            ->assertStatus(409);
        $this->assertFalse($otp->fresh()->used);
        $this->assertDatabaseCount('personal_access_tokens', 3);
        $this->assertDatabaseCount('user_devices', 3);

        $expiredToken = $user->tokens()->where('name', 'device:'.$deviceIds[0])->firstOrFail();
        $expiredToken->forceFill(['expires_at' => now()->subSecond()])->saveOrFail();

        $this->postJson('/api/v1/auth/otp/verify', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'authenticated');

        $this->assertTrue($otp->fresh()->used);
        $this->assertDatabaseCount('personal_access_tokens', 3);
        $this->assertDatabaseCount('user_devices', 4);
        $this->assertFalse($sessions[0]['device']->fresh()->is_active);
        $this->assertSame(3, $user->devices()->where('is_active', true)->count());
    }

    public function test_authenticated_activity_renews_expiring_token_and_device_list_marks_other_stale_devices(): void
    {
        $user = $this->userFor('+989121234568');
        $current = $this->addDevice(
            $user,
            '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1178',
            now()->subDays(10),
            now()->addDays(20),
        );
        $stale = $this->addDevice(
            $user,
            '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1179',
            now()->subDays(10),
        );

        $this->withToken($current['token'])
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $currentToken = $user->tokens()->where('name', 'device:'.$current['device']->device_id)->firstOrFail();
        $this->assertTrue($currentToken->expires_at->greaterThanOrEqualTo(now()->addDays(29)));

        $response = $this->withToken($current['token'])->getJson('/api/v1/auth/devices')->assertOk();
        $this->assertFalse(collect($response->json('data'))->firstWhere('id', $current['device']->id)['is_stale']);
        $staleDevice = collect($response->json('data'))->firstWhere('id', $stale['device']->id);
        $this->assertTrue($staleDevice['is_stale']);
    }

    public function test_revoking_another_device_and_logging_out_current_revoke_tokens_and_push_subscriptions(): void
    {
        $user = $this->userFor('+989121234569');
        $current = $this->addDevice(
            $user,
            '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1180',
            pushToken: 'ExponentPushToken[current-device]',
        );
        $other = $this->addDevice(
            $user,
            '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1181',
            pushToken: 'ExponentPushToken[other-device]',
        );

        $this->withToken($current['token'])
            ->deleteJson('/api/v1/auth/devices/'.$other['device']->id)
            ->assertNoContent();

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'device:'.$current['device']->device_id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'device:'.$other['device']->device_id]);
        $this->assertTrue($other['device']->fresh()->is_active === false);
        $this->assertDatabaseMissing('push_subscriptions', ['push_token' => 'ExponentPushToken[other-device]']);
        $this->assertDatabaseHas('push_subscriptions', ['push_token' => 'ExponentPushToken[current-device]']);

        Auth::forgetGuards();
        $this->withToken($current['token'])->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'device:'.$current['device']->device_id]);
        $this->assertFalse($current['device']->fresh()->is_active);
        $this->assertDatabaseMissing('push_subscriptions', ['push_token' => 'ExponentPushToken[current-device]']);
    }

    public function test_logout_all_revokes_every_token_and_deactivates_devices_and_push_subscriptions(): void
    {
        $user = $this->userFor('+989121234570');
        $first = $this->addDevice(
            $user,
            '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1182',
            pushToken: 'ExponentPushToken[first-device]',
        );
        $second = $this->addDevice(
            $user,
            '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1183',
            pushToken: 'ExponentPushToken[second-device]',
        );

        $this->withToken($first['token'])->postJson('/api/v1/auth/logout-all')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('push_subscriptions', 0);
        $this->assertFalse($first['device']->fresh()->is_active);
        $this->assertFalse($second['device']->fresh()->is_active);
    }

    public function test_logout_others_preserves_current_session_and_revokes_other_sessions_atomically(): void
    {
        $user = $this->userFor('+989121234573');
        $current = $this->addDevice(
            $user,
            '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1186',
            pushToken: 'ExponentPushToken[kept-device]',
        );
        $other = $this->addDevice(
            $user,
            '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1187',
            pushToken: 'ExponentPushToken[revoked-device]',
        );
        $legacyToken = $user->createToken('legacy-client', ['*'], now()->addDays(30));

        $this->withToken($current['token'])
            ->postJson('/api/v1/auth/logout-others')
            ->assertNoContent();

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'device:'.$current['device']->device_id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'device:'.$other['device']->device_id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $legacyToken->accessToken->id]);
        $this->assertTrue($current['device']->fresh()->is_active);
        $this->assertFalse($other['device']->fresh()->is_active);
        $this->assertDatabaseHas('push_subscriptions', ['push_token' => 'ExponentPushToken[kept-device]']);
        $this->assertDatabaseMissing('push_subscriptions', ['push_token' => 'ExponentPushToken[revoked-device]']);

        Auth::forgetGuards();
        $this->withToken($current['token'])->getJson('/api/v1/auth/me')->assertOk();
        Auth::forgetGuards();
        $this->withToken($other['token'])->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logout_others_respects_security_capability_restrictions(): void
    {
        $user = $this->userFor('+989121234574');
        $user->forceFill(['restricted_capabilities' => ['security']])->saveOrFail();
        $current = $this->addDevice(
            $user,
            '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1188',
        );
        $other = $this->addDevice(
            $user,
            '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1189',
        );

        $this->withToken($current['token'])
            ->postJson('/api/v1/auth/logout-others')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_capability_restricted');

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'device:'.$current['device']->device_id]);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'device:'.$other['device']->device_id]);
        $this->assertTrue($current['device']->fresh()->is_active);
        $this->assertTrue($other['device']->fresh()->is_active);
    }

    public function test_phone_change_preserves_current_session_and_revokes_other_device_sessions(): void
    {
        $user = $this->userFor('+989121234571');
        $current = $this->addDevice(
            $user,
            '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1184',
            pushToken: 'ExponentPushToken[phone-current]',
        );
        $other = $this->addDevice(
            $user,
            '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1185',
            pushToken: 'ExponentPushToken[phone-other]',
        );
        $newPhone = '+989121234572';
        $otp = $this->createOtp(null, $newPhone);

        $this->withToken($current['token'])
            ->postJson('/api/v1/auth/profile/phone', [
                'phone' => $newPhone,
                'code' => '123456',
            ])
            ->assertOk()
            ->assertJsonPath('user.phone', $newPhone);

        $this->assertTrue($otp->fresh()->used);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'device:'.$current['device']->device_id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'device:'.$other['device']->device_id]);
        $this->assertTrue($current['device']->fresh()->is_active);
        $this->assertFalse($other['device']->fresh()->is_active);
        $this->assertDatabaseHas('push_subscriptions', ['push_token' => 'ExponentPushToken[phone-current]']);
        $this->assertDatabaseMissing('push_subscriptions', ['push_token' => 'ExponentPushToken[phone-other]']);

        Auth::forgetGuards();
        $this->withToken($current['token'])
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.phone', $newPhone);
    }

    private function userFor(string $phone): User
    {
        return User::factory()->create([
            'phone' => $phone,
            'phone_verified_at' => now(),
        ]);
    }

    /**
     * @return array{device: UserDevice, token: string}
     */
    private function addDevice(
        User $user,
        string $deviceId,
        $lastActivity = null,
        $expiresAt = null,
        ?string $pushToken = null,
    ): array {
        $device = $user->devices()->create([
            'device_id' => $deviceId,
            'device_name' => 'Test device',
            'device_model' => 'Test model',
            'platform' => 'android',
            'last_activity' => $lastActivity ?? now(),
            'is_active' => true,
        ]);
        if ($pushToken !== null) {
            PushSubscription::query()->create([
                'user_id' => $user->getKey(),
                'user_device_id' => $device->getKey(),
                'push_token' => $pushToken,
                'platform' => 'android',
            ]);
        }

        $accessToken = $user->createToken(
            'device:'.$deviceId,
            ['*'],
            $expiresAt ?? now()->addDays(30),
        );

        return ['device' => $device, 'token' => $accessToken->plainTextToken];
    }

    private function createOtp(?User $user, ?string $phone = null): Otp
    {
        return Otp::query()->create([
            'user_id' => $user?->getKey(),
            'phone' => $phone ?? $user?->phone,
            'code_hash' => Hash::make('123456'),
            'type' => 'login',
            'expires_at' => now()->addMinutes(5),
        ]);
    }

    private function loginPayload(string $phone, string $deviceId): array
    {
        return [
            'phone' => $phone,
            'type' => 'login',
            'code' => '123456',
            'device_id' => $deviceId,
        ];
    }
}
