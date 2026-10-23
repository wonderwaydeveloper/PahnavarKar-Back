<?php

namespace App\Http\Requests\Auth;

use App\Services\Auth\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');
        $email = $this->input('email');
        $name = $this->input('name');

        $this->merge([
            'phone' => PhoneNumber::normalize($phone) ?? (is_string($phone) ? trim($phone) : $phone),
            'email' => is_string($email) ? (mb_strtolower(trim($email)) ?: null) : $email,
            'name' => is_string($name) ? trim($name) : $name,
            'type' => is_string($this->input('type')) ? trim($this->input('type')) : $this->input('type'),
        ]);
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^\+989[0-9]{9}$/'],
            'type' => ['required', 'string', 'in:login,register'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            'device_id' => ['required', 'uuid'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_model' => ['nullable', 'string', 'max:255'],
            'push_token' => ['nullable', 'string', 'max:512'],
            'platform' => ['nullable', 'string', 'in:ios,android,web,windows,macos,linux,desktop'],
            'name' => ['required_if:type,register', 'nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
