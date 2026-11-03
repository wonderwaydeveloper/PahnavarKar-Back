<?php

namespace App\Services\Auth;

interface ConfigurableSmsProvider extends SmsProvider
{
    public function isConfigured(): bool;
}
