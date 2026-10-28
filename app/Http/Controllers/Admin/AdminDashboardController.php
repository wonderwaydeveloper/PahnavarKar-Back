<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DiscountCode;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $admin = $request->user();
        abort_unless($admin->canAccessAdminPanel(), 403);
        $stats = [];

        if ($admin->can('manage users')) {
            $stats[] = [
                'label' => 'کاربران',
                'value' => User::query()->count(),
                'description' => 'حساب‌های ثبت‌شده',
            ];
        }

        if ($admin->can('manage plans')) {
            $stats[] = [
                'label' => 'اشتراک‌های فعال',
                'value' => Subscription::query()->active()->count(),
                'description' => 'در حال حاضر',
            ];
        }

        if ($admin->can('view transactions')) {
            $stats[] = [
                'label' => 'پرداخت‌های در انتظار',
                'value' => PaymentOrder::query()->where('status', 'pending')->count(),
                'description' => 'نیازمند پیگیری',
            ];
        }

        if ($admin->can('manage discount codes')) {
            $stats[] = [
                'label' => 'کدهای تخفیف فعال',
                'value' => DiscountCode::query()->where('is_active', true)->count(),
                'description' => 'قابل استفاده',
            ];
        }

        if ($admin->can('manage wallet')) {
            $stats[] = [
                'label' => 'درخواست‌های برداشت',
                'value' => WithdrawalRequest::query()->where('status', 'pending')->count(),
                'description' => 'در صف بررسی',
            ];
        }

        $canViewTransactions = $admin->can('view transactions');
        $recentTransactions = $canViewTransactions
            ? Transaction::query()
                ->with('user:id,name')
                ->latest('id')
                ->limit(6)
                ->get(['id', 'user_id', 'type', 'status', 'amount', 'currency', 'created_at'])
                ->map(fn (Transaction $transaction): array => [
                    'userName' => $transaction->user?->name ?? 'حساب حذف‌شده',
                    'type' => $transaction->type,
                    'status' => $transaction->status,
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'createdAt' => $transaction->created_at?->toIso8601String(),
                ])
            : collect();

        return Inertia::render('Admin/Dashboard', [
            'admin' => [
                'name' => $admin->name,
                'photoUrl' => $admin->profile_photo_path
                    ? Storage::disk('public')->url($admin->profile_photo_path)
                    : null,
            ],
            'stats' => $stats,
            'canManageWallet' => $admin->can('manage wallet'),
            'canManageUsers' => $admin->can('manage users'),
            'canManageDiscountCodes' => $admin->can('manage discount codes'),
            'canManageSettings' => $admin->can('manage plans')
                || $admin->can('manage wallet')
                || $admin->can('manage payment gateways')
                || $admin->can('manage site content'),
            'canViewTransactions' => $canViewTransactions,
            'recentTransactions' => $recentTransactions,
        ]);
    }
}
