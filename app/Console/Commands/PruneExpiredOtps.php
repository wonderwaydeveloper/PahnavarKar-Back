<?php

namespace App\Console\Commands;

use App\Models\Otp;
use Illuminate\Console\Command;

class PruneExpiredOtps extends Command
{
    private const RETENTION_DAYS_AFTER_EXPIRY = 1;

    protected $signature = 'auth:prune-expired-otps';

    protected $description = 'Delete OTP records one day after their validity expires.';

    public function handle(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS_AFTER_EXPIRY);
        $deletedCount = 0;

        Otp::query()
            ->where('expires_at', '<=', $cutoff)
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(500, function ($otps) use ($cutoff, &$deletedCount): void {
                $deletedCount += Otp::query()
                    ->whereIn('id', $otps->modelKeys())
                    ->where('expires_at', '<=', $cutoff)
                    ->delete();
            });

        $this->info("Pruned {$deletedCount} expired OTP record(s).");

        return self::SUCCESS;
    }
}
