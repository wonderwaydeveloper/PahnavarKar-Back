<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Admin\UserAccountStatusService;
use Illuminate\Console\Command;

class ReactivateExpiredSuspensions extends Command
{
    protected $signature = 'accounts:reactivate-expired-suspensions';

    protected $description = 'Process expired account suspensions and capability restrictions.';

    public function handle(UserAccountStatusService $statusService): int
    {
        User::query()
            ->where('is_active', false)
            ->whereNotNull('suspended_until')
            ->where('suspended_until', '<=', now())
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(100, function ($users) use ($statusService): void {
                foreach ($users as $user) {
                    $statusService->reactivateExpiredSuspension((int) $user->id);
                }
            });

        User::query()
            ->where('is_active', true)
            ->whereNotNull('restricted_capabilities')
            ->whereNotNull('restricted_until')
            ->where('restricted_until', '<=', now())
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(100, function ($users) use ($statusService): void {
                foreach ($users as $user) {
                    $statusService->clearExpiredRestriction((int) $user->id);
                }
            });

        return self::SUCCESS;
    }
}
