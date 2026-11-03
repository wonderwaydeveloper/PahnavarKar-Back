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
use App\Support\FinancialHistoryDateRange;
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
        $dateRange = FinancialHistoryDateRange::fromRequest($request);
        $query = $user->withdrawalRequests();
        FinancialHistoryDateRange::apply($query->getQuery(), $dateRange);

        return WithdrawalRequestResource::collection(
            $query->latest('id')->paginate($this->perPage($request)),
        );
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $cardNumber = strtoupper(preg_replace('/[\s-]+/u', '', (string) $request->input('card_number', '')) ?? '');
        $cardNumber = strtr($cardNumber, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $request->merge(['card_number' => $cardNumber]);
        $data = $request->validate([
            'amount' => [
                'required',
                'integer',
                'min:1',
                'max:'.$this->runtimeSettings->walletWithdrawals()['max_per_request'],
            ],
            'idempotency_key' => ['required', 'uuid'],
            'payout_method' => ['sometimes', 'required', 'string', 'in:iban,card'],
            'card_number' => ['required_if:payout_method,card', 'prohibited_unless:payout_method,card', 'string', 'size:16', 'regex:/^[0-9]{16}$/'],
        ]);
        $payoutMethod = $data['payout_method'] ?? 'iban';
        if ($payoutMethod === 'card' && ! $this->hasValidCardChecksum($data['card_number'])) {
            throw ValidationException::withMessages([
                'card_number' => 'شماره کارت واردشده معتبر نیست.',
            ]);
        }

        try {
            $withdrawal = $this->withdrawalService->create(
                $user,
                (int) $data['amount'],
                $data['idempotency_key'],
                $payoutMethod,
                $data['card_number'] ?? null,
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

    private function hasValidCardChecksum(string $cardNumber): bool
    {
        $sum = 0;
        $double = false;
        for ($index = strlen($cardNumber) - 1; $index >= 0; $index--) {
            $digit = (int) $cardNumber[$index];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
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

    public function markPaid(Request $request, int $withdrawalRequest): JsonResponse
    {
        /** @var User $reviewer */
        $reviewer = $request->user();
        $data = $request->validate([
            'payout_reference' => ['required', 'string', 'max:128'],
        ]);

        try {
            $withdrawal = $this->withdrawalService->markPaid(
                $withdrawalRequest,
                $reviewer,
                trim($data['payout_reference']),
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
