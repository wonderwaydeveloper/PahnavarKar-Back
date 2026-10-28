<?php

namespace App\Jobs;

use App\Models\Otp;
use App\Services\Auth\SmsDeliveryException;
use App\Services\Auth\SmsProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendOtpSms implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 20;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30];

    public function __construct(
        public readonly int $otpId,
        public readonly string $phone,
        public readonly string $code,
    ) {}

    public function handle(SmsProvider $smsProvider): void
    {
        $otp = Otp::query()->find($this->otpId);

        if (! $otp || $otp->used || ! Hash::check($this->code, $otp->code_hash)) {
            return;
        }

        if ($otp->isExpired()) {
            Otp::query()->whereKey($otp->id)->where('used', false)->update(['used' => true]);

            return;
        }

        try {
            $smsProvider->sendOtp($this->phone, $this->code);
        } catch (Throwable) {
            throw new SmsDeliveryException('SMS delivery failed.');
        }
    }

    public function failed(Throwable $exception): void
    {
        Otp::query()
            ->whereKey($this->otpId)
            ->where('used', false)
            ->update(['used' => true]);

        Log::error('Queued OTP SMS delivery failed after all retries.', [
            'otp_id' => $this->otpId,
            'exception_class' => $exception::class,
            'delivery_error' => 'SMS delivery failed after all retries.',
        ]);
    }
}
