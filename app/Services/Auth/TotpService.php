<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TotpService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * @return array{secret: string, provisioning_uri: string}
     */
    public function beginEnrollment(User $user): array
    {
        $secret = $this->base32Encode(random_bytes(20));
        $user->forceFill([
            'two_factor_pending_secret' => $secret,
            'two_factor_pending_expires_at' => now()->addMinutes(10),
        ])->saveOrFail();

        $label = rawurlencode('PahnavarKar:'.$user->phone);
        $issuer = rawurlencode('PahnavarKar');

        return [
            'secret' => $secret,
            'provisioning_uri' => "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30",
        ];
    }

    /**
     * @return list<string>
     */
    public function confirmEnrollment(User $user, string $code): array
    {
        return DB::transaction(function () use ($user, $code): array {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $secret = $lockedUser->two_factor_pending_secret;
            $expiresAt = $lockedUser->two_factor_pending_expires_at;

            if (! is_string($secret) || $expiresAt === null || $expiresAt->isPast()) {
                throw ValidationException::withMessages([
                    'code' => 'راه‌اندازی تأیید دومرحله‌ای منقضی شده است؛ دوباره شروع کنید.',
                ]);
            }

            $step = $this->matchingStep($secret, $code);
            if ($step === null) {
                throw ValidationException::withMessages(['code' => 'کد تأیید دومرحله‌ای معتبر نیست.']);
            }

            $recoveryCodes = array_map(
                static fn (): string => strtoupper(bin2hex(random_bytes(5))),
                range(1, 10),
            );
            $lockedUser->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_pending_secret' => null,
                'two_factor_pending_expires_at' => null,
                'two_factor_enabled_at' => now(),
                'two_factor_recovery_codes' => array_map(
                    static fn (string $recoveryCode): string => Hash::make($recoveryCode),
                    $recoveryCodes,
                ),
                'two_factor_last_used_step' => $step,
            ])->saveOrFail();

            return $recoveryCodes;
        });
    }

    public function disable(User $user, string $code): void
    {
        DB::transaction(function () use ($user, $code): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            if (! $this->verifyAndConsume($lockedUser, $code)) {
                throw ValidationException::withMessages([
                    'code' => 'برای غیرفعال‌کردن تأیید دومرحله‌ای، کد معتبر وارد کنید.',
                ]);
            }
            $lockedUser->forceFill([
                'two_factor_secret' => null,
                'two_factor_pending_secret' => null,
                'two_factor_pending_expires_at' => null,
                'two_factor_enabled_at' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_last_used_step' => null,
            ])->saveOrFail();
        });
    }

    public function verifyAndConsume(User $user, string $code): bool
    {
        if ($user->two_factor_enabled_at === null || ! is_string($user->two_factor_secret)) {
            return false;
        }

        $step = $this->matchingStep($user->two_factor_secret, $code);
        if ($step !== null) {
            $lastUsedStep = $user->two_factor_last_used_step;
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                return false;
            }

            $user->forceFill(['two_factor_last_used_step' => $step])->saveOrFail();

            return true;
        }

        $codes = $user->two_factor_recovery_codes ?? [];
        foreach ($codes as $index => $hashedCode) {
            if (is_string($hashedCode) && Hash::check(strtoupper(trim($code)), $hashedCode)) {
                unset($codes[$index]);
                $user->forceFill([
                    'two_factor_recovery_codes' => array_values($codes),
                ])->saveOrFail();

                return true;
            }
        }

        return false;
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->matchingStep($secret, $code) !== null;
    }

    private function matchingStep(string $secret, string $code): ?int
    {
        if (! preg_match('/^[0-9]{6}$/', $code)) {
            return null;
        }

        $key = $this->base32Decode($secret);
        $currentStep = intdiv(time(), 30);
        for ($step = $currentStep - 1; $step <= $currentStep + 1; $step++) {
            $counter = pack('N2', intdiv($step, 0x100000000), $step % 0x100000000);
            $hash = hash_hmac('sha1', $counter, $key, true);
            $offset = ord($hash[19]) & 0x0F;
            $binary = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
            $expected = str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
            if (hash_equals($expected, $code)) {
                return $step;
            }
        }

        return null;
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (unpack('C*', $bytes) as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private function base32Decode(string $encoded): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($encoded, '='))) as $character) {
            $index = strpos(self::BASE32_ALPHABET, $character);
            if ($index === false) {
                throw new \UnexpectedValueException('Stored two-factor secret is malformed.');
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
