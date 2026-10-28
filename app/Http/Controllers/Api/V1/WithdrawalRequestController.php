<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\WithdrawalRequestConflictException;
use App\Exceptions\WithdrawalRequestException;
use App\Http\Controllers\Controller;
use App\Http\Resources\WithdrawalRequestResource;
use App\Models\User;
use App\Services\Admin\AdminRuntimeSettings;
use App\Services\WithdrawalRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class WithdrawalRequestController extends Controller
{
    public function __construct(
        private readonly WithdrawalRequestService $withdrawalService,
        private readonly AdminRuntimeSettings $runtimeSettings,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return WithdrawalRequestResource::collection(
            $this->withdrawalService->userRequests($user, $this->perPage($request)),
        );
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'amount' => [
                'required',
                'integer',
                'min:1',
                'max:'.$this->runtimeSettings->walletWithdrawals()['max_per_request'],
            ],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        try {
            $withdrawal = $this->withdrawalService->create(
                $user,
                (int) $data['amount'],
                $data['idempotency_key'],
            );
        } catch (WithdrawalRequestConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'withdrawal_request_conflict',
            ], Response::HTTP_CONFLICT);
        } catch (InsufficientWalletBalanceException|WithdrawalRequestException $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        return response()->json([
            'withdrawal_request' => new WithdrawalRequestResource($withdrawal),
            'wallet_balance' => (int) $user->fresh()->wallet_balance,
        ], $withdrawal->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function queue(Request $request): AnonymousResourceCollection
    {
        return WithdrawalRequestResource::collection(
            $this->withdrawalService->reviewQueue($this->perPage($request)),
        );
    }

    public function approve(Request $request, int $withdrawalRequest): JsonResponse
    {
        return $this->review($request, $withdrawalRequest, 'approve');
    }

    public function reject(Request $request, int $withdrawalRequest): JsonResponse
    {
        return $this->review($request, $withdrawalRequest, 'reject');
    }

    private function review(Request $request, int $withdrawalRequest, string $decision): JsonResponse
    {
        /** @var User $reviewer */
        $reviewer = $request->user();
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $withdrawal = $this->withdrawalService->review(
                $withdrawalRequest,
                $reviewer,
                $decision,
                isset($data['note']) ? trim($data['note']) : null,
            );
        } catch (WithdrawalRequestConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'withdrawal_request_conflict',
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'withdrawal_request' => new WithdrawalRequestResource($withdrawal->load('user')),
        ]);
    }

    private function perPage(Request $request): int
    {
        $perPage = $request->integer('per_page', 20);
        abort_if($perPage < 1 || $perPage > 100, 422, 'تعداد موارد هر صفحه باید بین ۱ تا ۱۰۰ باشد.');

        return $perPage;
    }
}
