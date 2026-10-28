<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\UserAccountStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;

class AdminUserController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('manage users'), 403);

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'in:active,inactive'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $ownerCanManageRoles = $request->user()->hasRole('Owner') && $request->user()->can('manage roles');
        $users = User::query()
            ->with('roles:id,name,name_fa,name_en')
            ->when(isset($filters['search']), function ($query) use ($filters): void {
                $search = trim($filters['search']);
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when(isset($filters['status']), fn ($query) => $query->where(
                'is_active',
                $filters['status'] === 'active',
            ))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'admin' => [
                'name' => $request->user()->name,
            ],
            'users' => [
                'data' => $users->getCollection()->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'isActive' => $user->is_active,
                    'isPrivileged' => $user->hasAnyRole(['Owner', 'Admin']),
                    'statusReason' => $user->account_status_reason,
                    'roles' => $user->roles->map(fn (Role $role): array => [
                        'id' => $role->id,
                        'key' => $role->name,
                        'nameFa' => $role->name_fa,
                        'nameEn' => $role->name_en,
                    ])->values(),
                    'createdAt' => $user->created_at?->toIso8601String(),
                ]),
                'links' => $users->linkCollection()->toArray(),
                'currentPage' => $users->currentPage(),
                'lastPage' => $users->lastPage(),
                'total' => $users->total(),
            ],
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'canManageRoles' => $ownerCanManageRoles,
            'assignableRoles' => $ownerCanManageRoles
                ? Role::query()->where('guard_name', 'web')->where('name', '!=', 'Owner')->orderBy('name')->get(['id', 'name', 'name_fa', 'name_en'])
                    ->map(fn (Role $role): array => [
                        'id' => $role->id,
                        'key' => $role->name,
                        'nameFa' => $role->name_fa,
                        'nameEn' => $role->name_en,
                    ])->values()
                : [],
        ]);
    }

    public function updateStatus(Request $request, User $user, UserAccountStatusService $statusService): RedirectResponse
    {
        abort_unless($request->user()->can('manage users'), 403);

        if (is_string($request->input('reason'))) {
            $reason = trim($request->input('reason'));
            $request->merge(['reason' => $reason === '' ? null : $reason]);
        }

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'reason' => ['required_if:is_active,false', 'nullable', 'string', 'min:5', 'max:500'],
        ]);

        $statusService->setActive(
            $user,
            $request->user(),
            (bool) $data['is_active'],
            isset($data['reason']) ? trim($data['reason']) : null,
        );

        return back()->with('status', $data['is_active']
            ? 'دسترسی حساب کاربر دوباره فعال شد.'
            : 'حساب کاربر غیرفعال و نشست‌های فعال آن لغو شد.');
    }

    public function updateAccess(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->hasRole('Owner') && $actor->can('manage roles'), 403);

        $data = $request->validate([
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
            'permission_ids' => ['prohibited'],
        ]);

        if ($user->hasRole('Owner')) {
            throw ValidationException::withMessages([
                'access' => 'برای محافظت از مالک سامانه، دسترسی حساب‌های Owner از این بخش قابل تغییر نیست.',
            ]);
        }

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->where('name', '!=', 'Owner')
            ->whereKey($data['role_ids'])
            ->get();
        if ($roles->count() !== count($data['role_ids'])) {
            throw ValidationException::withMessages([
                'access' => 'یکی از نقش‌های انتخاب‌شده مجاز نیست.',
            ]);
        }

        DB::transaction(function () use ($user, $roles, $actor): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            if ($lockedUser->hasRole('Owner')) {
                throw ValidationException::withMessages([
                    'access' => 'برای محافظت از مالک سامانه، دسترسی حساب‌های Owner قابل تغییر نیست.',
                ]);
            }

            $before = [
                'roles' => $lockedUser->roles()->pluck('name')->all(),
                'permissions' => $lockedUser->getDirectPermissions()->pluck('name')->all(),
            ];
            $lockedUser->syncRoles($roles);
            $lockedUser->syncPermissions([]);

            activity('security')
                ->performedOn($lockedUser)
                ->causedBy($actor)
                ->event('user_access_updated')
                ->withProperties([
                    'before' => $before,
                    'roles' => $roles->pluck('name')->all(),
                    'direct_permissions' => [],
                ])
                ->log('User roles updated and direct permissions removed');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return back()->with('status', 'نقش‌های کاربر به‌روزرسانی شد؛ دسترسی‌ها فقط از نقش‌های انتخاب‌شده دریافت می‌شوند.');
    }
}
