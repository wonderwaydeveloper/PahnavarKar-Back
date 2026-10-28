<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminTransactionResource;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminTransactionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'user_id' => ['sometimes', 'integer', 'min:1', 'exists:users,id'],
            'type' => [
                'sometimes',
                'string',
                Rule::in(['deposit', 'debit', 'refund', 'subscription', 'withdrawal_hold', 'withdrawal_release']),
            ],
            'status' => [
                'sometimes',
                'string',
                Rule::in(['succeeded', 'refunded', 'released', 'pending', 'processing', 'failed']),
            ],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        if (
            isset($filters['from'], $filters['to'])
            && Carbon::parse($filters['to'])->lt(Carbon::parse($filters['from']))
        ) {
            throw ValidationException::withMessages([
                'to' => 'تاریخ پایان باید برابر یا بعد از تاریخ شروع باشد.',
            ]);
        }

        $query = Transaction::query()
            ->with('user:id,name,phone')
            ->when(isset($filters['user_id']), fn ($query) => $query->where('user_id', $filters['user_id']))
            ->when(isset($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(
                isset($filters['from']),
                fn ($query) => $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay()),
            )
            ->when(
                isset($filters['to']),
                fn ($query) => $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay()),
            )
            ->latest('id');

        return AdminTransactionResource::collection(
            $query->paginate((int) ($filters['per_page'] ?? 20))->withQueryString(),
        );
    }
}
