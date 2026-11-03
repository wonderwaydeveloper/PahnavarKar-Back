<?php

namespace Tests\Feature;

use App\Models\DiscountCode;
use App\Models\DiscountUsage;
use App\Models\PaymentOrder;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AdminDiscountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_discount_api_and_page_require_the_management_permission(): void
    {
        $admin = User::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/discount-codes')
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/discount-codes', [
                'code' => 'SAVE10',
                'type' => 'percentage',
                'value' => 10,
                'min_order_amount' => 0,
            ])
            ->assertForbidden();

        $this->actingAs($admin, 'web')
            ->get('/admin/discount-codes')
            ->assertForbidden();

        $this->assertDatabaseCount('discount_codes', 0);
    }

    public function test_authorized_admin_can_create_normalized_code_and_generate_a_unique_code(): void
    {
        $admin = $this->discountAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/discount-codes', [
                'code' => '  spring_25 ',
                'type' => 'percentage',
                'value' => 25,
                'min_order_amount' => 1000,
                'max_discount' => 500,
                'usage_limit' => 3,
                'starts_at' => now()->subMinute()->toISOString(),
                'expires_at' => now()->addDay()->toISOString(),
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('discount.code', 'SPRING_25')
            ->assertJsonPath('discount.type', 'percentage')
            ->assertJsonPath('discount.value', 25);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/discount-codes', [
                'auto_generate_code' => true,
                'type' => 'fixed',
                'value' => 250,
                'min_order_amount' => 0,
            ])
            ->assertCreated()
            ->assertJsonPath('discount.type', 'fixed')
            ->assertJsonStructure(['discount' => ['code']]);

        $this->assertSame(2, DiscountCode::query()->count());
        $this->assertDatabaseHas('discount_codes', ['code' => 'SPRING_25']);
        $generatedCode = DiscountCode::query()->where('type', 'fixed')->sole()->code;
        $this->assertMatchesRegularExpression('/^PK-[A-F0-9]{16}$/', $generatedCode);
    }

    public function test_invalid_discount_values_and_dates_are_rejected_without_persisting(): void
    {
        $admin = $this->discountAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/discount-codes', [
                'code' => 'TOO_MUCH',
                'type' => 'percentage',
                'value' => 101,
                'min_order_amount' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('value');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/discount-codes', [
                'code' => 'BAD_DATES',
                'type' => 'fixed',
                'value' => 100,
                'min_order_amount' => 0,
                'starts_at' => now()->addDay()->toISOString(),
                'expires_at' => now()->subDay()->toISOString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expires_at');

        $this->assertDatabaseCount('discount_codes', 0);
    }

    public function test_admin_can_assign_discount_code_to_a_user_by_normalized_phone_number(): void
    {
        $admin = $this->discountAdmin();
        $user = User::factory()->create(['phone' => '+989121234567']);

        $this->actingAs($admin, 'web')
            ->post('/admin/discount-codes', [
                'code' => 'PHONE_OFFER',
                'auto_generate_code' => false,
                'type' => 'percentage',
                'value' => 15,
                'min_order_amount' => 0,
                'user_phone' => '۰۰۹۸ ۹۱۲ ۱۲۳ ۴۵۶۷',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.discount-codes.index'));

        $this->assertDatabaseHas('discount_codes', [
            'code' => 'PHONE_OFFER',
            'user_id' => $user->getKey(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/discount-codes', [
                'code' => 'LEGACY_ID',
                'type' => 'percentage',
                'value' => 10,
                'min_order_amount' => 0,
                'user_id' => $user->getKey(),
            ])
            ->assertCreated()
            ->assertJsonPath('discount.user_id', $user->getKey());

        $this->assertDatabaseHas('discount_codes', [
            'code' => 'LEGACY_ID',
            'user_id' => $user->getKey(),
        ]);
        $this->assertDatabaseHas('discount_code_user', [
            'discount_code_id' => DiscountCode::query()->where('code', 'LEGACY_ID')->value('id'),
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_admin_can_assign_one_code_to_multiple_users_by_phone_number(): void
    {
        $admin = $this->discountAdmin();
        $firstUser = User::factory()->create(['phone' => '+989121234567']);
        $secondUser = User::factory()->create(['phone' => '+989351234567']);

        $this->actingAs($admin, 'web')
            ->post('/admin/discount-codes', [
                'code' => 'GROUP_OFFER',
                'auto_generate_code' => false,
                'type' => 'percentage',
                'value' => 15,
                'min_order_amount' => 0,
                'user_phones' => ['۰۰۹۸ ۹۱۲ ۱۲۳ ۴۵۶۷', '09351234567'],
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.discount-codes.index'));

        $code = DiscountCode::query()->where('code', 'GROUP_OFFER')->sole();
        $this->assertTrue($code->is_user_restricted);
        $this->assertNull($code->user_id);
        $this->assertEqualsCanonicalizing(
            [$firstUser->getKey(), $secondUser->getKey()],
            $code->users()->pluck('users.id')->all(),
        );

        $activity = Activity::query()
            ->where('log_name', 'discount_codes')
            ->where('event', 'discount_code_users_changed')
            ->sole();
        $this->assertSame($admin->getKey(), $activity->causer_id);
        $this->assertSame([], $activity->properties->get('before_user_ids'));
        $this->assertSame(
            [$firstUser->getKey(), $secondUser->getKey()],
            $activity->properties->get('after_user_ids'),
        );
    }

    public function test_admin_can_replace_multiple_discount_code_assignments(): void
    {
        $admin = $this->discountAdmin();
        $firstUser = User::factory()->create(['phone' => '+989121234567']);
        $secondUser = User::factory()->create(['phone' => '+989351234567']);
        $thirdUser = User::factory()->create(['phone' => '+989191234567']);
        $code = $this->createCode('REPLACE_GROUP', [
            'is_user_restricted' => true,
        ]);
        $code->users()->sync([$firstUser->getKey(), $secondUser->getKey()]);

        $this->actingAs($admin, 'web')
            ->put('/admin/discount-codes/'.$code->getKey(), [
                'user_phones' => ['09191234567'],
            ])
            ->assertRedirect(route('admin.discount-codes.index'));

        $this->assertEquals([$thirdUser->getKey()], $code->fresh()->users()->pluck('users.id')->all());
        $this->assertSame($thirdUser->getKey(), $code->fresh()->user_id);

        $this->actingAs($admin, 'web')
            ->put('/admin/discount-codes/'.$code->getKey(), [
                'user_phones' => [],
            ])
            ->assertRedirect(route('admin.discount-codes.index'));

        $this->assertSame([], $code->fresh()->users()->pluck('users.id')->all());
        $this->assertFalse($code->fresh()->is_user_restricted);
    }

    public function test_replacing_same_sized_discount_user_group_is_audited_without_logging_noop_changes(): void
    {
        $admin = $this->discountAdmin();
        $firstUser = User::factory()->create(['phone' => '+989121234567']);
        $secondUser = User::factory()->create(['phone' => '+989351234567']);
        $thirdUser = User::factory()->create(['phone' => '+989191234567']);
        $fourthUser = User::factory()->create(['phone' => '+989991234567']);
        $code = $this->createCode('AUDIT_GROUP', [
            'is_user_restricted' => true,
        ]);
        $code->users()->sync([$firstUser->getKey(), $secondUser->getKey()]);

        $this->actingAs($admin, 'web')
            ->put('/admin/discount-codes/'.$code->getKey(), [
                'user_phones' => ['09191234567', '09991234567'],
            ])
            ->assertRedirect(route('admin.discount-codes.index'));

        $this->assertNull($code->fresh()->user_id);
        $this->assertTrue($code->fresh()->is_user_restricted);
        $activity = Activity::query()
            ->where('log_name', 'discount_codes')
            ->where('event', 'discount_code_users_changed')
            ->sole();
        $this->assertSame($code->getKey(), $activity->subject_id);
        $this->assertSame($admin->getKey(), $activity->causer_id);
        $this->assertSame(
            [$firstUser->getKey(), $secondUser->getKey()],
            $activity->properties->get('before_user_ids'),
        );
        $this->assertSame(
            [$thirdUser->getKey(), $fourthUser->getKey()],
            $activity->properties->get('after_user_ids'),
        );

        $this->actingAs($admin, 'web')
            ->put('/admin/discount-codes/'.$code->getKey(), [
                'user_phones' => ['09191234567', '09991234567'],
            ])
            ->assertRedirect(route('admin.discount-codes.index'));

        $this->assertSame(
            1,
            Activity::query()
                ->where('log_name', 'discount_codes')
                ->where('event', 'discount_code_users_changed')
                ->count(),
        );
    }

    public function test_admin_cannot_assign_discount_code_to_an_unknown_phone_number(): void
    {
        $admin = $this->discountAdmin();

        $this->actingAs($admin, 'web')
            ->from('/admin/discount-codes')
            ->post('/admin/discount-codes', [
                'code' => 'UNKNOWN_PHONE',
                'auto_generate_code' => false,
                'type' => 'percentage',
                'value' => 15,
                'min_order_amount' => 0,
                'user_phone' => '09120000000',
                'is_active' => true,
            ])
            ->assertRedirect('/admin/discount-codes')
            ->assertSessionHasErrors('user_phone');

        $this->assertDatabaseMissing('discount_codes', ['code' => 'UNKNOWN_PHONE']);
    }

    public function test_admin_can_change_discount_code_assignment_by_phone_number(): void
    {
        $admin = $this->discountAdmin();
        $user = User::factory()->create(['phone' => '+989121234567']);
        $code = $this->createCode('CHANGE_PHONE');

        $this->actingAs($admin, 'web')
            ->put('/admin/discount-codes/'.$code->getKey(), [
                'user_phone' => '09121234567',
            ])
            ->assertRedirect(route('admin.discount-codes.index'));

        $this->assertDatabaseHas('discount_codes', [
            'id' => $code->getKey(),
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_admin_can_filter_codes_and_cannot_lower_limit_below_recorded_usage(): void
    {
        $admin = $this->discountAdmin();
        $code = $this->createCode('PROMO25', ['usage_limit' => 5, 'usage_count' => 2]);
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/discount-codes?search=promo&type=percentage&is_active=1&per_page=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.code', 'PROMO25');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/admin/discount-codes/'.$code->getKey(), [
                'usage_limit' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('usage_limit');

        $this->assertSame(5, $code->fresh()->usage_limit);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/admin/discount-codes/'.$code->getKey(), [
                'usage_limit' => 4,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('discount.usage_limit', 4)
            ->assertJsonPath('discount.is_active', false);
    }

    public function test_admin_usage_history_is_paginated_and_web_listing_uses_authorized_filters(): void
    {
        $admin = $this->discountAdmin();
        $user = User::factory()->create();
        $code = $this->createCode('HISTORY', ['type' => 'fixed']);
        $order = PaymentOrder::query()->create([
            'user_id' => $user->getKey(),
            'order_reference' => (string) Str::uuid(),
            'kind' => 'subscription',
            'discount_code_id' => $code->getKey(),
            'discount_amount' => 100,
            'gross_amount' => 1000,
            'wallet_amount' => 0,
            'gateway_amount' => 900,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
        DiscountUsage::query()->create([
            'discount_code_id' => $code->getKey(),
            'user_id' => $user->getKey(),
            'payment_order_id' => $order->getKey(),
            'order_amount' => 1000,
            'discount_amount' => 100,
            'status' => 'reserved',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/discount-codes/'.$code->getKey().'/usages?per_page=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.status', 'reserved')
            ->assertJsonPath('data.0.user.id', $user->getKey());

        $this->actingAs($admin, 'web')
            ->get('/admin/discount-codes?search=history&type=fixed&status=active')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/DiscountCodes/Index')
                ->where('discounts.total', 1)
                ->where('discounts.data.0.code', 'HISTORY')
                ->where('discounts.data.0.users', [])
                ->where('discounts.data.0.isUserRestricted', false)
                ->where('filters.search', 'history')
                ->where('filters.type', 'fixed')
                ->where('filters.status', 'active'),
            );

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/admin/discount-codes/'.$code->getKey())
            ->assertMethodNotAllowed();
        $this->assertDatabaseHas('discount_codes', ['id' => $code->getKey()]);
    }

    private function discountAdmin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->givePermissionTo(Permission::findOrCreate('manage discount codes', 'web'));

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createCode(string $code, array $attributes = []): DiscountCode
    {
        return DiscountCode::query()->create(array_merge([
            'code' => $code,
            'type' => 'percentage',
            'value' => 25,
            'min_order_amount' => 0,
            'usage_count' => 0,
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ], $attributes));
    }
}
