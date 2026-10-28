<?php

namespace App\Services\Payments;

use App\Models\PaymentGatewaySettings;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

class PaymentGatewaySettingsService
{
    /**
     * @return array<string, mixed>
     */
    public function current(PaymentGatewayManager $gateways): array
    {
        $settings = PaymentGatewaySettings::query()->first();
        $configured = $gateways->configuredGateways();

        return [
            'environment' => config('payment.environment'),
            'configured_gateways' => $configured,
            'available_gateways' => $gateways->availableGateways(),
            'default_gateway' => $settings?->default_gateway ?? config('payment.default'),
            'failover_order' => $settings?->failover_order ?? config('payment.failover', []),
            'enabled_gateways' => $settings?->enabled_gateways ?? $configured,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function update(Request $request, PaymentGatewayManager $gateways): array
    {
        $configured = $gateways->configuredGateways();
        if ($configured === []) {
            throw ValidationException::withMessages([
                'enabled_gateways' => 'هیچ آداپتور پرداختی به‌صورت محیطی پیکربندی نشده است.',
            ]);
        }

        $validated = $request->validate([
            'default_gateway' => ['required', 'string', Rule::in($configured)],
            'enabled_gateways' => ['required', 'array'],
            'enabled_gateways.*' => ['required', 'string', 'distinct', Rule::in($configured)],
            'failover_order' => ['present', 'array'],
            'failover_order.*' => ['required', 'string', 'distinct', Rule::in($configured)],
        ]);

        if (
            $validated['enabled_gateways'] !== []
            && ! in_array($validated['default_gateway'], $validated['enabled_gateways'], true)
        ) {
            throw ValidationException::withMessages([
                'default_gateway' => 'درگاه پیش‌فرض باید در فهرست درگاه‌های فعال باشد.',
            ]);
        }
        if (array_diff($validated['failover_order'], $validated['enabled_gateways']) !== []) {
            throw ValidationException::withMessages([
                'failover_order' => 'ترتیب جایگزین فقط باید شامل درگاه‌های فعال باشد.',
            ]);
        }
        if (in_array($validated['default_gateway'], $validated['failover_order'], true)) {
            throw ValidationException::withMessages([
                'failover_order' => 'درگاه پیش‌فرض نباید در فهرست جایگزین تکرار شود.',
            ]);
        }

        /** @var User $user */
        $user = $request->user();
        $settings = PaymentGatewaySettings::query()->firstOrNew(['id' => 1]);
        app(CauserResolver::class)->withCauser($user, function () use ($settings, $validated, $user): void {
            DB::transaction(function () use ($settings, $validated, $user): void {
                $settings->forceFill([
                    'default_gateway' => $validated['default_gateway'],
                    'failover_order' => $validated['failover_order'],
                    'enabled_gateways' => $validated['enabled_gateways'],
                    'updated_by' => $user->getKey(),
                ])->saveOrFail();
            });
        });

        return $this->current($gateways);
    }
}
