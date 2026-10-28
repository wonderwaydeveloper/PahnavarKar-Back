<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;

class AdminRoleManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get();
        $systemPermissions = config('admin-permissions');

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->with(['permissions' => fn ($query) => $query->where('guard_name', 'web')->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'key' => $role->name,
                'nameFa' => $role->name_fa,
                'nameEn' => $role->name_en,
                'permissionIds' => $role->permissions->pluck('id')->values(),
                'userCount' => DB::table('model_has_roles')
                    ->where('role_id', $role->id)
                    ->where('model_type', (new User)->getMorphClass())
                    ->count(),
                'protected' => in_array($role->name, ['Owner', 'Admin', 'User'], true),
            ]);

        return Inertia::render('Admin/Roles/Index', [
            'admin' => ['name' => $request->user()->name],
            'roles' => $roles,
            'permissions' => $permissions->map(fn (Permission $permission): array => [
                'id' => $permission->id,
                'key' => $permission->name,
                'nameFa' => $permission->name_fa,
                'nameEn' => $permission->name_en,
                'system' => array_key_exists($permission->name, $systemPermissions),
                'protected' => $permission->name === 'manage roles',
            ])->values(),
        ]);
    }

    public function storeRole(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z][A-Za-z0-9 _.-]*$/', Rule::unique('roles', 'name')->where('guard_name', 'web'), Rule::notIn(['Owner', 'Admin', 'User'])],
            'name_fa' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ]);
        $permissions = $this->permissionsForRole($data['permission_ids']);
        $this->assertManageRolesNotGranted($permissions);

        $role = Role::query()->create([
            'name' => trim($data['key']),
            'name_fa' => trim($data['name_fa']),
            'name_en' => trim($data['name_en']),
            'guard_name' => 'web',
        ]);
        $role->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->logRoleChange($request, 'role_created', $role, ['permissions' => $permissions->pluck('name')->all()]);

        return back()->with('status', 'نقش جدید با موفقیت ایجاد شد.');
    }

    public function updateRole(Request $request, Role $role): RedirectResponse
    {
        abort_unless($role->guard_name === 'web', 404);
        $this->assertEditableRole($role);

        $data = $request->validate([
            'name_fa' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ]);
        $permissions = $this->permissionsForRole($data['permission_ids']);
        $this->assertManageRolesNotGranted($permissions);

        DB::transaction(function () use ($role, $data, $permissions, $request): void {
            $lockedRole = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
            $this->assertEditableRole($lockedRole);
            $lockedRole->forceFill([
                'name_fa' => trim($data['name_fa']),
                'name_en' => trim($data['name_en']),
            ])->saveOrFail();
            $lockedRole->syncPermissions($permissions);
            $this->logRoleChange($request, 'role_updated', $lockedRole, [
                'permissions' => $permissions->pluck('name')->all(),
            ]);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return back()->with('status', 'نقش با موفقیت به‌روزرسانی شد.');
    }

    public function destroyRole(Request $request, Role $role): RedirectResponse
    {
        abort_unless($role->guard_name === 'web', 404);
        $this->assertEditableRole($role);

        if (in_array($role->name, ['Owner', 'Admin', 'User'], true)) {
            throw ValidationException::withMessages([
                'role' => 'نقش‌های پایهٔ سامانه قابل حذف نیستند.',
            ]);
        }
        if ($role->users()->exists()) {
            throw ValidationException::withMessages([
                'role' => 'ابتدا این نقش را از کاربران بردارید؛ سپس می‌توانید آن را حذف کنید.',
            ]);
        }

        $this->logRoleChange($request, 'role_deleted', $role, []);
        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return back()->with('status', 'نقش حذف شد.');
    }

    public function storePermission(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z][A-Za-z0-9 _.-]*$/', Rule::unique('permissions', 'name')->where('guard_name', 'web')],
            'name_fa' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
        ]);

        $permission = Permission::query()->create([
            'name' => trim($data['key']),
            'name_fa' => trim($data['name_fa']),
            'name_en' => trim($data['name_en']),
            'guard_name' => 'web',
        ]);
        Role::findByName('Owner', 'web')->givePermissionTo($permission);
        $this->logRoleChange($request, 'permission_created', $permission, []);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return back()->with('status', 'دسترسی جدید با موفقیت ایجاد شد.');
    }

    public function updatePermission(Request $request, Permission $permission): RedirectResponse
    {
        abort_unless($permission->guard_name === 'web', 404);
        $data = $request->validate([
            'name_fa' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
        ]);

        $permission->forceFill([
            'name_fa' => trim($data['name_fa']),
            'name_en' => trim($data['name_en']),
        ])->saveOrFail();
        $this->logRoleChange($request, 'permission_labels_updated', $permission, []);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return back()->with('status', 'نام‌های نمایشی دسترسی به‌روزرسانی شد.');
    }

    public function destroyPermission(Request $request, Permission $permission): RedirectResponse
    {
        abort_unless($permission->guard_name === 'web', 404);
        if (
            $permission->name === 'manage roles'
            || array_key_exists($permission->name, config('admin-permissions'))
        ) {
            throw ValidationException::withMessages([
                'permission' => 'دسترسی‌های پایهٔ سامانه قابل حذف نیستند.',
            ]);
        }

        $this->logRoleChange($request, 'permission_deleted', $permission, []);
        $permission->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return back()->with('status', 'دسترسی سفارشی حذف شد.');
    }

    /**
     * @param  array<int, int|string>  $permissionIds
     * @return Collection<int, Permission>
     */
    private function permissionsForRole(array $permissionIds): Collection
    {
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereKey($permissionIds)
            ->get();

        if ($permissions->count() !== count(array_unique($permissionIds))) {
            throw ValidationException::withMessages([
                'permission_ids' => 'یک یا چند دسترسی انتخاب‌شده معتبر نیست.',
            ]);
        }

        return $permissions;
    }

    private function assertManageRolesNotGranted(Collection $permissions): void
    {
        if ($permissions->contains('name', 'manage roles')) {
            throw ValidationException::withMessages([
                'permission_ids' => 'دسترسی مدیریت نقش‌ها فقط برای نقش Owner محفوظ است.',
            ]);
        }
    }

    private function assertEditableRole(Role $role): void
    {
        if ($role->name === 'Owner') {
            abort(403, 'نقش Owner قابل ویرایش یا حذف نیست.');
        }
    }

    private function logRoleChange(Request $request, string $event, Role|Permission $subject, array $properties): void
    {
        activity('security')
            ->performedOn($subject)
            ->causedBy($request->user())
            ->event($event)
            ->withProperties(['key' => $subject->name, ...$properties])
            ->log($event);
    }
}
