<?php

namespace Tests\Unit;

use App\Services\Auth\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_normalizes_local_international_and_farsi_digit_formats(): void
    {
        $expected = '+989121234567';

        $this->assertSame($expected, PhoneNumber::normalize('09121234567'));
        $this->assertSame($expected, PhoneNumber::normalize('+98 912 123 4567'));
        $this->assertSame($expected, PhoneNumber::normalize('۰۰۹۸۹۱۲۱۲۳۴۵۶۷'));
    }

    public function test_rejects_invalid_phone_numbers_and_non_string_input(): void
    {
        $this->assertNull(PhoneNumber::normalize('091234'));
        $this->assertNull(PhoneNumber::normalize('08121234567'));
        $this->assertNull(PhoneNumber::normalize(9121234567));
    }

    public function test_rate_limit_key_is_hashed_from_the_normalized_number(): void
    {
        $phone = '+989121234567';
        $key = PhoneNumber::forRateLimit('09121234567');

        $this->assertSame(hash('sha256', $phone), $key);
        $this->assertNotSame($phone, $key);
    }
}
