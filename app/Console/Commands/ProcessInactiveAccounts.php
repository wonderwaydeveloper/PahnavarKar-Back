<?php

namespace App\Console\Commands;

use App\Mail\AccountInactivityDeletionWarningMail;
use App\Models\User;
use App\Services\Auth\InactivityWarningDeliveryConfiguration;
use App\Services\Auth\SmsProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessInactiveAccounts extends Command
{
    protected $signature = 'accounts:process-inactivity';

    protected $description = 'Notify users about and process opted-in inactivity-based account deletion.';

    public function handle(
        SmsProvider $smsProvider,
        InactivityWarningDeliveryConfiguration $deliveryConfiguration,
    ): int {
        User::query()
            ->whereNotNull('inactivity_deletion_period_months')
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(100, function ($users) use ($smsProvider, $deliveryConfiguration): void {
                foreach ($users as $user) {
                    $this->processUser((int) $user->id, $smsProvider, $deliveryConfiguration);
                }
            });

        return self::SUCCESS;
    }

    private function processUser(
        int $userId,
        SmsProvider $smsProvider,
        InactivityWarningDeliveryConfiguration $deliveryConfiguration,
    ): void {
        $user = User::query()->find($userId);
        if (! $user) {
            return;
        }
        if (! $this->isEligibleForWarning($user)) {
            if (
                $user->inactivity_deletion_email_notified_at
                || $user->inactivity_deletion_sms_notified_at
                || $user->inactivity_deletion_scheduled_at
            ) {
                $user->forceFill([
                    'inactivity_deletion_email_notified_at' => null,
                    'inactivity_deletion_sms_notified_at' => null,
                    'inactivity_deletion_scheduled_at' => null,
                ])->saveOrFail();
            }

            return;
        }

        if (! $user->email || ! $user->email_verified_at || ! $user->phone || ! $user->phone_verified_at) {
            Log::warning('Inactivity deletion is enabled without verified notification destinations.', [
                'user_id' => $userId,
            ]);

            return;
        }

        if (! $user->inactivity_deletion_email_notified_at && $deliveryConfiguration->emailIsConfigured()) {
            $this->sendEmailWarning($user);
        } elseif (! $user->inactivity_deletion_email_notified_at) {
            Log::warning('Account inactivity email warning was not sent because no real mail transport is configured.', [
                'user_id' => $user->id,
            ]);
        }

        if (! $user->inactivity_deletion_sms_notified_at && $deliveryConfiguration->smsIsConfigured()) {
            $this->sendSmsWarning($user, $smsProvider);
        } elseif (! $user->inactivity_deletion_sms_notified_at) {
            Log::warning('Account inactivity SMS warning was not sent because Melipayamak is not configured.', [
                'user_id' => $user->id,
            ]);
        }

        $user->refresh();
        if (
            $user->inactivity_deletion_email_notified_at
            && $user->inactivity_deletion_sms_notified_at
            && ! $user->inactivity_deletion_scheduled_at
        ) {
            $user->forceFill([
                'inactivity_deletion_scheduled_at' => now()->addDays(30),
            ])->saveOrFail();
        }

        if (
            $user->inactivity_deletion_scheduled_at
            && $user->inactivity_deletion_scheduled_at->isPast()
            && $this->isEligibleForDeletion($user)
        ) {
            $this->deleteAccount($userId);
        }
    }

    private function isEligibleForWarning(User $user): bool
    {
        $lastActivity = $user->last_active_at ?? $user->created_at;

        return $user->inactivity_deletion_period_months !== null
            && $lastActivity->lte(now()->subMonthsNoOverflow($user->inactivity_deletion_period_months));
    }

    private function isEligibleForDeletion(User $user): bool
    {
        return $this->isEligibleForWarning($user)
            && $user->inactivity_deletion_email_notified_at !== null
            && $user->inactivity_deletion_sms_notified_at !== null
            && $user->inactivity_deletion_scheduled_at !== null
            && $user->inactivity_deletion_scheduled_at->lte(now());
    }

    private function sendEmailWarning(User $user): void
    {
        try {
            Mail::to($user->email)->send(new AccountInactivityDeletionWarningMail(
                $user->name,
                $user->inactivity_deletion_period_months,
                30,
            ));

            User::query()
                ->whereKey($user->id)
                ->whereNull('inactivity_deletion_email_notified_at')
                ->update(['inactivity_deletion_email_notified_at' => now()]);
        } catch (Throwable $exception) {
            Log::error('Could not send account inactivity email warning.', [
                'user_id' => $user->id,
                'exception' => $exception,
            ]);
        }
    }

    private function sendSmsWarning(User $user, SmsProvider $smsProvider): void
    {
        try {
            $smsProvider->sendInactivityDeletionWarning(
                $user->phone,
                $user->name,
                30,
            );

            User::query()
                ->whereKey($user->id)
                ->whereNull('inactivity_deletion_sms_notified_at')
                ->update(['inactivity_deletion_sms_notified_at' => now()]);
        } catch (Throwable $exception) {
            Log::error('Could not send account inactivity SMS warning.', [
                'user_id' => $user->id,
                'exception' => $exception,
            ]);
        }
    }

    private function deleteAccount(int $userId): void
    {
        $photoPath = DB::transaction(function () use ($userId): ?string {
            $user = User::query()->lockForUpdate()->find($userId);
            if (! $user || ! $this->isEligibleForDeletion($user)) {
                return null;
            }

            $path = $user->profile_photo_path;
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            if ($user->email) {
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            }
            $user->delete();

            return $path;
        });

        if (is_string($photoPath) && ! Storage::disk('public')->delete($photoPath)) {
            Log::warning('Could not remove profile photo after inactivity-based account deletion.', [
                'user_id' => $userId,
                'path' => $photoPath,
            ]);
        }
    }
}
