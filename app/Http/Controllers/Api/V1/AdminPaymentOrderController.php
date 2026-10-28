<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentOrder;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminPaymentOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'user_id' => ['sometimes', 'integer', 'min:1', 'exists:users,id'],
            'status' => ['sometimes', Rule::in(['pending', 'processing', 'succeeded', 'failed'])],
            'kind' => ['sometimes', Rule::in(['subscription'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $orders = PaymentOrder::query()
            ->with([
                'user:id,name,phone',
                'attempts:id,payment_order_id,gateway,status,amount,failure_code,created_at',
                'walletTransaction:id,type,status,amount',
                'refundTransaction:id,type,status,amount',
                'transaction:id,type,status,amount,gateway,reference_id',
                'subscription:id,plan_key,type,status,starts_at,ends_at',
            ])
            ->when(isset($filters['user_id']), fn ($query) => $query->where('user_id', $filters['user_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['kind']), fn ($query) => $query->where('kind', $filters['kind']))
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->through(fn (PaymentOrder $order): array => [
                'id' => $order->getKey(),
                'reference' => $order->order_reference,
                'kind' => $order->kind,
                'plan_key' => $order->plan_key,
                'status' => $order->status,
                'failure_code' => $order->failure_code,
                'gross_amount' => (int) $order->gross_amount,
                'discount_amount' => (int) $order->discount_amount,
                'wallet_amount' => (int) $order->wallet_amount,
                'gateway_amount' => (int) $order->gateway_amount,
                'currency' => $order->currency,
                'created_at' => $order->created_at?->toISOString(),
                'completed_at' => $order->completed_at?->toISOString(),
                'user' => $order->user ? [
                    'id' => $order->user->getKey(),
                    'name' => $order->user->name,
                    'phone' => $order->user->phone,
                ] : null,
                'attempts' => $order->attempts->map(fn ($attempt): array => [
                    'id' => $attempt->getKey(),
                    'gateway' => $attempt->gateway,
                    'status' => $attempt->status,
                    'amount' => (int) $attempt->amount,
                    'failure_code' => $attempt->failure_code,
                    'created_at' => $attempt->created_at?->toISOString(),
                ])->values(),
                'wallet_transaction' => $this->transactionSummary($order->walletTransaction),
                'refund_transaction' => $this->transactionSummary($order->refundTransaction),
                'gateway_transaction' => $this->transactionSummary($order->transaction),
                'subscription' => $order->subscription ? [
                    'id' => $order->subscription->getKey(),
                    'plan_key' => $order->subscription->plan_key,
                    'type' => $order->subscription->type,
                    'status' => $order->subscription->status,
                    'starts_at' => $order->subscription->starts_at?->toISOString(),
                    'ends_at' => $order->subscription->ends_at?->toISOString(),
                ] : null,
            ])
            ->withQueryString();

        return response()->json($orders);
    }

    /**
     * @return array<string, int|string|null>|null
     */
    private function transactionSummary(?Transaction $transaction): ?array
    {
        if (! $transaction) {
            return null;
        }

        return [
            'id' => $transaction->getKey(),
            'type' => $transaction->type,
            'status' => $transaction->status,
            'amount' => (int) $transaction->amount,
            'gateway' => $transaction->gateway,
            'reference_id' => $transaction->reference_id,
        ];
    }
}
