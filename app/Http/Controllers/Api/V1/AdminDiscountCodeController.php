<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DiscountCode;
use App\Services\Admin\DiscountCodeManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminDiscountCodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'type' => ['sometimes', Rule::in(['percentage', 'fixed'])],
            'search' => ['sometimes', 'string', 'max:64'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $codes = DiscountCode::query()
            ->with('user:id,name,phone')
            ->withCount(['usages' => fn ($query) => $query->whereIn('status', ['reserved', 'consumed'])])
            ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', $filters['is_active']))
            ->when(isset($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->when(isset($filters['search']), fn ($query) => $query->where('code', 'like', '%'.strtoupper($filters['search']).'%'))
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        return response()->json($codes);
    }

    public function store(Request $request, DiscountCodeManagementService $discountCodes): JsonResponse
    {
        $code = $discountCodes->create($request);

        return response()->json(['discount' => $code->load('user:id,name,phone')], 201);
    }

    public function update(Request $request, DiscountCode $discountCode, DiscountCodeManagementService $discountCodes): JsonResponse
    {
        $discountCode = $discountCodes->update($request, $discountCode);

        return response()->json(['discount' => $discountCode->fresh()->load('user:id,name,phone')]);
    }

    public function usages(Request $request, DiscountCode $discountCode): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        return response()->json(
            $discountCode->usages()
                ->with(['user:id,name,phone', 'transaction:id,type,status,amount', 'paymentAttempt:id,gateway,status,amount'])
                ->latest('id')
                ->paginate((int) ($validated['per_page'] ?? 20))
                ->withQueryString(),
        );
    }

}
