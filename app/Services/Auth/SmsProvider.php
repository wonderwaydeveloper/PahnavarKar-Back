<?php

namespace App\Services\Auth;

interface SmsProvider
{
    public function sendOtp(string $phone, string $code): void;

    public function sendInactivityDeletionWarning(string $phone, string $userName, int $graceDays): void;
}
