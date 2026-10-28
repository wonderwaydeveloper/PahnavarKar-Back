<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DiscountCode;
use App\Services\Admin\DiscountCodeManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminDiscountCodePageController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('manage discount codes'), 403);

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:64'],
            'type' => ['sometimes', 'nullable', Rule::in(['percentage', 'fixed'])],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'inactive'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $discounts = DiscountCode::query()
            ->with('user:id,name,phone')
            ->withCount(['usages' => fn ($query) => $query->whereIn('status', ['reserved', 'consumed'])])
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->where(
                'code',
                'like',
                '%'.strtoupper(trim($filters['search'])).'%',
            ))
            ->when(filled($filters['type'] ?? null), fn ($query) => $query->where('type', $filters['type']))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where(
                'is_active',
                $filters['status'] === 'active',
            ))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/DiscountCodes/Index', [
            'admin' => ['name' => $request->user()->name],
            'discounts' => [
                'data' => $discounts->getCollection()->map(fn (DiscountCode $discount): array => [
                    'id' => $discount->id,
                    'code' => $discount->code,
                    'type' => $discount->type,
                    'value' => $discount->value,
                    'minOrderAmount' => $discount->min_order_amount,
                    'maxDiscount' => $discount->max_discount,
                    'usageLimit' => $discount->usage_limit,
                    'usageCount' => $discount->usages_count,
                    'user' => $discount->user ? [
                        'id' => $discount->user->id,
                        'name' => $discount->user->name,
                        'phone' => $discount->user->phone,
                    ] : null,
                    'startsAt' => $discount->starts_at?->format('Y-m-d\TH:i'),
                    'expiresAt' => $discount->expires_at?->format('Y-m-d\TH:i'),
                    'isActive' => $discount->is_active,
                ]),
                'links' => $discounts->linkCollection()->toArray(),
                'currentPage' => $discounts->currentPage(),
                'lastPage' => $discounts->lastPage(),
                'total' => $discounts->total(),
            ],
            'filters' => [
                'search' => $filters['search'] ?? '',
                'type' => $filters['type'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
        ]);
    }

    public function store(Request $request, DiscountCodeManagementService $discountCodes): RedirectResponse
    {
        abort_unless($request->user()->can('manage discount codes'), 403);
        $discountCode = $discountCodes->create($request);

        return to_route('admin.discount-codes.index')
            ->with('status', 'کد تخفیف ایجاد شد.')
            ->with('generated_code', $request->boolean('auto_generate_code') ? $discountCode->code : null);
    }

    public function update(
        Request $request,
        DiscountCode $discountCode,
        DiscountCodeManagementService $discountCodes,
    ): RedirectResponse {
        abort_unless($request->user()->can('manage discount codes'), 403);
        $discountCodes->update($request, $discountCode);

        return to_route('admin.discount-codes.index')->with('status', 'کد تخفیف به‌روزرسانی شد.');
    }
}
