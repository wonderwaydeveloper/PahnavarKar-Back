<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\TestCase;

class ProfilePhotoLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_upload_replaces_and_removes_public_profile_photo(): void
    {
        Storage::fake('public');
        $oldPath = 'profile-photos/1/old.jpg';
        Storage::disk('public')->put($oldPath, 'old photo');
        $user = User::factory()->create(['profile_photo_path' => $oldPath]);
        $this->actingAs($user, 'sanctum');

        $upload = $this->postJson('/api/v1/auth/profile/photo', [
            'photo' => UploadedFile::fake()->image('new-photo.jpg'),
        ]);

        $upload->assertOk()
            ->assertJsonPath('user.profile_photo_path', fn (string $path): bool => str_starts_with(
                $path,
                'profile-photos/'.$user->id.'/',
            ));

        $newPath = $upload->json('user.profile_photo_path');
        $this->assertIsString($newPath);
        Storage::disk('public')->assertExists($newPath);
        Storage::disk('public')->assertMissing($oldPath);
        $this->assertSame($newPath, $user->fresh()->profile_photo_path);

        $this->deleteJson('/api/v1/auth/profile/photo')
            ->assertOk()
            ->assertJsonPath('user.profile_photo_path', null);

        Storage::disk('public')->assertMissing($newPath);
        $this->assertNull($user->fresh()->profile_photo_path);
    }

    public function test_api_rejects_unsupported_and_oversized_profile_photos_without_mutation(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/v1/auth/profile/photo', [
            'photo' => UploadedFile::fake()->create('avatar.svg', 2, 'image/svg+xml'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('photo');

        $this->postJson('/api/v1/auth/profile/photo', [
            'photo' => UploadedFile::fake()->image('oversized.jpg')->size(5121),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('photo');

        $this->assertNull($user->fresh()->profile_photo_path);
        $this->assertSame([], Storage::disk('public')->allFiles('profile-photos/'.$user->id));
    }

    public function test_api_cleans_up_new_photo_when_database_persistence_fails(): void
    {
        Storage::fake('public');
        $oldPath = 'profile-photos/1/old.jpg';
        Storage::disk('public')->put($oldPath, 'old photo');
        $user = User::factory()->create(['profile_photo_path' => $oldPath]);
        $this->actingAs($user, 'sanctum');

        Event::listen('eloquent.saving: '.User::class, static function (User $savingUser) use ($user): void {
            if ($savingUser->is($user) && $savingUser->isDirty('profile_photo_path')) {
                throw new RuntimeException('Simulated profile persistence failure.');
            }
        });

        $response = $this->postJson('/api/v1/auth/profile/photo', [
            'photo' => UploadedFile::fake()->image('new-photo.jpg'),
        ]);
        $response->assertInternalServerError();

        $this->assertSame($oldPath, $user->fresh()->profile_photo_path);
        Storage::disk('public')->assertExists($oldPath);
        $this->assertSame([$oldPath], Storage::disk('public')->allFiles('profile-photos/'.$user->id));
    }

    public function test_admin_profile_and_dashboard_use_the_public_storage_photo_url(): void
    {
        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);
        $path = 'profile-photos/1/admin.jpg';
        Storage::disk('public')->put($path, 'admin photo');
        $admin = User::factory()->create(['profile_photo_path' => $path]);
        $admin->assignRole('Admin');
        $expectedUrl = Storage::disk('public')->url($path);

        $this->actingAs($admin, 'web')
            ->get('/admin/profile')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Profile/Edit')
                ->where('admin.photoUrl', $expectedUrl),
            );

        $this->actingAs($admin, 'web')
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Dashboard')
                ->where('admin.photoUrl', $expectedUrl),
            );
    }

    public function test_admin_can_replace_and_remove_profile_photo(): void
    {
        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);
        $oldPath = 'profile-photos/1/old.jpg';
        Storage::disk('public')->put($oldPath, 'old photo');
        $admin = User::factory()->create(['profile_photo_path' => $oldPath]);
        $admin->assignRole('Admin');

        $this->actingAs($admin, 'web')
            ->post('/admin/profile', [
                'name' => $admin->name,
                'photo' => UploadedFile::fake()->image('replacement.webp'),
                'remove_photo' => false,
            ])
            ->assertRedirect('/admin/profile')
            ->assertSessionHas('status');

        $newPath = $admin->fresh()->profile_photo_path;
        $this->assertIsString($newPath);
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertExists($newPath);
        Storage::disk('public')->assertMissing($oldPath);

        $this->actingAs($admin, 'web')
            ->post('/admin/profile', [
                'name' => $admin->name,
                'remove_photo' => true,
            ])
            ->assertRedirect('/admin/profile')
            ->assertSessionHas('status');

        $this->assertNull($admin->fresh()->profile_photo_path);
        Storage::disk('public')->assertMissing($newPath);
    }
}
