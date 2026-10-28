<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\WithdrawalRequestConflictException;
use App\Http\Controllers\Controller;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminWithdrawalPageController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('manage wallet'), 403);

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in([
                'pending', 'approved_pending_payout', 'rejected', 'paid',
            ])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $withdrawals = WithdrawalRequest::query()
            ->with('user:id,name,phone')
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Withdrawals/Index', [
            'admin' => ['name' => $request->user()->name],
            'withdrawals' => [
                'data' => $withdrawals->getCollection()->map(fn (WithdrawalRequest $withdrawal): array => [
                    'id' => $withdrawal->id,
                    'reference' => $withdrawal->request_reference,
                    'user' => $withdrawal->user ? [
                        'name' => $withdrawal->user->name,
                        'phone' => $withdrawal->user->phone,
                    ] : null,
                    'amount' => $withdrawal->amount,
                    'currency' => $withdrawal->currency,
                    'status' => $withdrawal->status,
                    'note' => $withdrawal->review_note,
                    'createdAt' => $withdrawal->created_at?->toIso8601String(),
                    'reviewedAt' => $withdrawal->reviewed_at?->toIso8601String(),
                ]),
                'links' => $withdrawals->linkCollection()->toArray(),
                'currentPage' => $withdrawals->currentPage(),
                'lastPage' => $withdrawals->lastPage(),
                'total' => $withdrawals->total(),
            ],
            'filters' => ['status' => $filters['status'] ?? ''],
        ]);
    }

    public function review(
        Request $request,
        WithdrawalRequest $withdrawal,
        WithdrawalRequestService $withdrawalService,
    ): RedirectResponse {
        abort_unless($request->user()->can('manage wallet'), 403);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $withdrawalService->review(
                $withdrawal->getKey(),
                $request->user(),
                $data['decision'],
                isset($data['note']) ? trim($data['note']) : null,
            );
        } catch (WithdrawalRequestConflictException $exception) {
            throw ValidationException::withMessages(['decision' => $exception->getMessage()]);
        }

        return back()->with('status', $data['decision'] === 'approve'
            ? 'درخواست برداشت تأیید شد؛ پرداخت نهایی باید جداگانه انجام شود.'
            : 'درخواست برداشت رد و مبلغ رزروشده به کیف پول کاربر بازگردانده شد.');
    }
}
