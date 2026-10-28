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
