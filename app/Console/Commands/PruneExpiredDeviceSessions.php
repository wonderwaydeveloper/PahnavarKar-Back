<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class PruneExpiredDeviceSessions extends Command
{
    protected $signature = 'auth:prune-expired-sessions';

    protected $description = 'Revoke expired device tokens and clean up their device records.';

    public function handle(): int
    {
        $deletedCount = 0;
        $hasFailures = false;

        PersonalAccessToken::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($tokens) use (&$deletedCount, &$hasFailures): void {
                foreach ($tokens as $token) {
                    try {
                        DB::transaction(function () use ($token, &$deletedCount): void {
                            if (
                                $token->tokenable_type === (new User)->getMorphClass()
                                && is_string($token->name)
                                && str_starts_with($token->name, 'device:')
                            ) {
                                $device = UserDevice::query()
                                    ->where('user_id', $token->tokenable_id)
                                    ->where('device_id', substr($token->name, 7))
                                    ->first();

                                if ($device?->is_active) {
                                    $device->pushSubscriptions()->delete();
                                    $device->update(['is_active' => false]);
                                }
                            }

                            $token->delete();
                            $deletedCount++;
                        });
                    } catch (Throwable $exception) {
                        $hasFailures = true;
                        report($exception);
                        $this->error("Could not prune expired token {$token->getKey()}.");
                    }
                }
            });

        $this->info("Pruned {$deletedCount} expired token(s).");

        return $hasFailures ? self::FAILURE : self::SUCCESS;
    }
}
