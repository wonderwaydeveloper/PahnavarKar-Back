<?php

namespace App\Console\Commands;

use App\Models\PaymentOrder;
use App\Services\Payments\PaymentService;
use Illuminate\Console\Command;

class ExpireStaleSubscriptionOrders extends Command
{
    protected $signature = 'payments:expire-stale-subscription-orders';

    protected $description = 'Release expired subscription checkouts after all gateway attempts are confirmed failed.';

    public function handle(PaymentService $payments): int
    {
        $expiredCount = 0;

        PaymentOrder::query()
            ->where('kind', 'subscription')
            ->whereIn('status', ['pending', 'processing'])
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(100, function ($orders) use ($payments, &$expiredCount): void {
                foreach ($orders as $order) {
                    if ($payments->expireConfirmedFailedSubscriptionOrder((int) $order->id)) {
                        $expiredCount++;
                    }
                }
            });

        $this->info("Expired {$expiredCount} confirmed failed subscription order(s).");

        return self::SUCCESS;
    }
}
