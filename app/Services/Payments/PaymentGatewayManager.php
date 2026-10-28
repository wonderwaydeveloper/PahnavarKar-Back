<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGatewayInterface;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Models\PaymentGatewaySettings;

class PaymentGatewayManager
{
    public function gateway(?string $name = null): PaymentGatewayInterface
    {
        $name ??= config('payment.default');
        $gateway = config("payment.gateways.{$name}");

        if (! is_array($gateway) || ! ($gateway['enabled'] ?? false) || ! $this->isAdminEnabled($name)) {
            throw new PaymentGatewayUnavailableException("Payment gateway [{$name}] is not enabled.");
        }

        return $this->resolveAdapter($name, $gateway);
    }

    public function gatewayForCallback(string $name): PaymentGatewayInterface
    {
        $gateway = config("payment.gateways.{$name}");
        if (! is_array($gateway) || ! ($gateway['credentials_configured'] ?? $gateway['enabled'] ?? false)) {
            throw new PaymentGatewayUnavailableException("Payment gateway [{$name}] is not configured.");
        }

        return $this->resolveAdapter($name, $gateway);
    }

    /**
     * @param  array<string, mixed>  $gateway
     */
    private function resolveAdapter(string $name, array $gateway): PaymentGatewayInterface
    {
        $class = $gateway['class'] ?? null;
        if (! is_string($class) || ! class_exists($class)) {
            throw new PaymentGatewayUnavailableException("Payment gateway [{$name}] has no installed adapter.");
        }

        $instance = app($class);
        if (! $instance instanceof PaymentGatewayInterface) {
            throw new PaymentGatewayUnavailableException("Payment gateway [{$name}] adapter is invalid.");
        }

        return $instance;
    }

    public function isConfigured(string $name): bool
    {
        return (bool) config("payment.gateways.{$name}.enabled", false);
    }

    /**
     * @return list<string>
     */
    public function configuredGateways(): array
    {
        return array_values(array_filter(
            array_keys(config('payment.gateways', [])),
            function (string $name): bool {
                $class = config("payment.gateways.{$name}.class");

                return $this->isConfigured($name)
                    && is_string($class)
                    && class_exists($class)
                    && is_subclass_of($class, PaymentGatewayInterface::class);
            },
        ));
    }

    /**
     * @return list<string>
     */
    public function purchaseOrder(): array
    {
        $settings = PaymentGatewaySettings::query()->first();

        return array_values(array_unique([
            (string) ($settings?->default_gateway ?? config('payment.default')),
            ...($settings?->failover_order ?? config('payment.failover', [])),
        ]));
    }

    /**
     * @return list<string>
     */
    public function availableGateways(): array
    {
        $available = [];
        foreach ($this->configuredGateways() as $name) {
            try {
                $this->gateway($name);
                $available[] = $name;
            } catch (PaymentGatewayUnavailableException) {
                continue;
            }
        }

        return $available;
    }

    private function isAdminEnabled(string $name): bool
    {
        $settings = PaymentGatewaySettings::query()->first();
        if (! is_array($settings?->enabled_gateways)) {
            return true;
        }

        return in_array($name, $settings->enabled_gateways, true);
    }
}
