<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminTransactionPageController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('view transactions'), 403);

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'type' => ['sometimes', 'nullable', Rule::in([
                'deposit', 'debit', 'refund', 'subscription', 'withdrawal_hold', 'withdrawal_release',
            ])],
            'status' => ['sometimes', 'nullable', Rule::in([
                'succeeded', 'refunded', 'released', 'pending', 'processing', 'failed',
            ])],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $transactions = Transaction::query()
            ->with('user:id,name,phone')
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters): void {
                $search = trim($filters['search']);
                $query->where(function ($query) use ($search): void {
                    $query->where('reference_id', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($userQuery) => $userQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%"));
                });
            })
            ->when(filled($filters['type'] ?? null), fn ($query) => $query->where('type', $filters['type']))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['from'] ?? null), fn ($query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($query) => $query->whereDate('created_at', '<=', $filters['to']))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Transactions/Index', [
            'admin' => ['name' => $request->user()->name],
            'transactions' => [
                'data' => $transactions->getCollection()->map(fn (Transaction $transaction): array => [
                    'id' => $transaction->id,
                    'user' => $transaction->user ? [
                        'name' => $transaction->user->name,
                        'phone' => $transaction->user->phone,
                    ] : null,
                    'type' => $transaction->type,
                    'status' => $transaction->status,
                    'amount' => $transaction->amount,
                    'balanceAfter' => $transaction->balance_after,
                    'currency' => $transaction->currency,
                    'gateway' => $transaction->gateway,
                    'reference' => $transaction->reference_id,
                    'description' => $transaction->description,
                    'createdAt' => $transaction->created_at?->toIso8601String(),
                ]),
                'links' => $transactions->linkCollection()->toArray(),
                'currentPage' => $transactions->currentPage(),
                'lastPage' => $transactions->lastPage(),
                'total' => $transactions->total(),
            ],
            'filters' => [
                'search' => $filters['search'] ?? '',
                'type' => $filters['type'] ?? '',
                'status' => $filters['status'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
        ]);
    }
}
