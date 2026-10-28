<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGatewayInterface;
use App\Data\Payments\GatewayPaymentResult;
use App\Data\Payments\PaymentRequest;
use App\Exceptions\PaymentGatewayRejectedException;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Models\PaymentAttempt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ZarinpalGateway implements PaymentGatewayInterface
{
    public function initiate(PaymentRequest $request): GatewayPaymentResult
    {
        $merchantId = $this->merchantId($request->environment);
        $apiUrl = $this->apiUrl($request->environment, 'request.json');
        $startUrl = config("payment.gateways.zarinpal.start_urls.{$request->environment}");
        if (! is_string($startUrl) || $startUrl === '') {
            throw new PaymentGatewayUnavailableException('Zarinpal merchant ID is not configured.');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout((int) config('payment.http.connect_timeout', 5))
                ->timeout((int) config('payment.http.timeout', 15))
                ->post($apiUrl, [
                    'merchant_id' => $merchantId,
                    'amount' => $request->amount,
                    'currency' => 'IRT',
                    'description' => $request->description.' '.$request->merchantReference,
                    'callback_url' => $request->callbackUrl,
                    'metadata' => [
                        'order_id' => $request->merchantReference,
                        'auto_verify' => false,
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new PaymentGatewayUnavailableException(
                'Zarinpal request result is unknown; do not retry automatically.',
                previous: $exception,
            );
        }

        $data = $this->responseData($response);
        $code = $data['code'] ?? null;
        $authority = $data['authority'] ?? null;

        if ($code !== 100) {
            $errors = $response->json('errors');
            $failureCode = is_array($errors) ? (string) ($errors['code'] ?? 'request_rejected') : 'request_rejected';

            throw new PaymentGatewayRejectedException(
                'Zarinpal rejected the payment request ('.$failureCode.').',
                $failureCode,
            );
        }

        if (! is_string($authority) || $authority === '') {
            throw new PaymentGatewayUnavailableException(
                'Zarinpal accepted the payment request but omitted its authority; do not retry automatically.',
            );
        }

        return new GatewayPaymentResult(
            status: 'pending',
            gatewayReference: $authority,
            redirectUrl: rtrim($startUrl, '/').'/'.rawurlencode($authority),
        );
    }

    public function verify(PaymentAttempt $attempt, Request $request): GatewayPaymentResult
    {
        $authority = (string) $this->callbackValue($request, 'Authority');
        if ($authority === '' || $authority !== $attempt->gateway_reference_id) {
            throw new PaymentGatewayUnavailableException('Zarinpal authority does not match the payment attempt.');
        }

        $callbackStatus = strtoupper((string) $this->callbackValue($request, 'Status'));
        if ($callbackStatus === 'NOK') {
            // A browser-supplied failure must not trigger compensation before Zarinpal confirms it.
            $providerStatus = $this->inquiryStatus($attempt);

            if ($providerStatus === 'IN_BANK') {
                return new GatewayPaymentResult(status: 'pending');
            }

            if (in_array($providerStatus, ['FAILED', 'REVERSED'], true)) {
                return new GatewayPaymentResult(
                    status: 'failed',
                    failureCode: 'inquiry_'.strtolower($providerStatus),
                );
            }
        } elseif ($callbackStatus !== 'OK') {
            throw new PaymentGatewayUnavailableException('Zarinpal callback has an invalid payment status.');
        }

        $environment = (string) $attempt->gateway_environment;
        $merchantId = $this->merchantId($environment);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout((int) config('payment.http.connect_timeout', 5))
                ->timeout((int) config('payment.http.timeout', 15))
                ->post($this->apiUrl($environment, 'verify.json'), [
                    'merchant_id' => $merchantId,
                    'amount' => (int) $attempt->amount,
                    'authority' => $authority,
                ]);
        } catch (ConnectionException $exception) {
            throw new PaymentGatewayUnavailableException(
                'Zarinpal verification result is unknown; retry callback processing later.',
                previous: $exception,
            );
        }

        $data = $this->responseData($response);
        $code = $data['code'] ?? null;
        if (! in_array($code, [100, 101], true)) {
            if (! in_array($code, [-51, -54, -55], true)) {
                throw new PaymentGatewayUnavailableException(
                    'Zarinpal verification returned a non-final status; payment remains under reconciliation.',
                );
            }

            return new GatewayPaymentResult(
                status: 'failed',
                failureCode: 'verification_code_'.(is_int($code) || is_string($code) ? $code : 'unknown'),
            );
        }

        $referenceId = $data['ref_id'] ?? null;
        if (! is_int($referenceId) && ! is_string($referenceId)) {
            throw new PaymentGatewayUnavailableException('Zarinpal verification response has no reference ID.');
        }

        return new GatewayPaymentResult(
            status: 'succeeded',
            gatewayReference: (string) $referenceId,
        );
    }

    /**
     * @return list<string>
     */
    public function unverifiedAuthorities(string $environment): array
    {
        $merchantId = $this->merchantId($environment);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout((int) config('payment.http.connect_timeout', 5))
                ->timeout((int) config('payment.http.timeout', 15))
                ->post($this->apiUrl($environment, 'unVerified.json'), [
                    'merchant_id' => $merchantId,
                ]);
        } catch (ConnectionException $exception) {
            throw new PaymentGatewayUnavailableException(
                'Zarinpal unverified-payment inquiry failed; reconciliation must be retried.',
                previous: $exception,
            );
        }

        $data = $this->responseData($response);
        if (($data['code'] ?? null) !== 100) {
            throw new PaymentGatewayUnavailableException(
                'Zarinpal unverified-payment inquiry returned a non-success status.',
            );
        }

        if (! array_key_exists('authorities', $data)) {
            throw new PaymentGatewayUnavailableException(
                'Zarinpal unverified-payment inquiry omitted its authority data.',
            );
        }

        $authorities = $data['authorities'];
        if (is_string($authorities)) {
            try {
                $authorities = json_decode($authorities, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new PaymentGatewayUnavailableException(
                    'Zarinpal unverified-payment inquiry returned invalid authority data.',
                    previous: $exception,
                );
            }
        }

        if (! is_array($authorities)) {
            throw new PaymentGatewayUnavailableException(
                'Zarinpal unverified-payment inquiry returned invalid authority data.',
            );
        }

        $references = [];
        foreach ($authorities as $authority) {
            if (! is_array($authority) || ! is_string($authority['authority'] ?? null) || $authority['authority'] === '') {
                throw new PaymentGatewayUnavailableException(
                    'Zarinpal unverified-payment inquiry returned a malformed authority entry.',
                );
            }

            $references[] = $authority['authority'];
        }

        return array_values(array_unique($references));
    }

    public function inquiryStatus(PaymentAttempt $attempt): string
    {
        $environment = (string) $attempt->gateway_environment;
        $merchantId = $this->merchantId($environment);
        $authority = $attempt->gateway_reference_id;

        if (! is_string($authority) || $authority === '') {
            throw new PaymentGatewayUnavailableException('Zarinpal inquiry requires a stored payment authority.');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout((int) config('payment.http.connect_timeout', 5))
                ->timeout((int) config('payment.http.timeout', 15))
                ->post($this->apiUrl($environment, 'inquiry.json'), [
                    'merchant_id' => $merchantId,
                    'authority' => $authority,
                ]);
        } catch (ConnectionException $exception) {
            throw new PaymentGatewayUnavailableException(
                'Zarinpal payment inquiry failed; reconciliation must be retried.',
                previous: $exception,
            );
        }

        $data = $this->responseData($response);
        $status = $data['status'] ?? null;
        if (
            ($data['code'] ?? null) !== 100
            || ! is_string($status)
            || ! in_array($status, ['VERIFIED', 'PAID', 'IN_BANK', 'FAILED', 'REVERSED'], true)
        ) {
            throw new PaymentGatewayUnavailableException('Zarinpal inquiry returned an invalid or unknown status.');
        }

        return $status;
    }

    public function validateCallback(Request $request): void
    {
        $authority = $this->callbackValue($request, 'Authority');
        $status = strtoupper((string) $this->callbackValue($request, 'Status'));

        if (! is_string($authority) || $authority === '' || ! in_array($status, ['OK', 'NOK'], true)) {
            throw new PaymentGatewayUnavailableException('Zarinpal callback is missing a valid status or authority.');
        }
    }

    public function attemptReference(Request $request): string
    {
        return (string) $this->callbackValue($request, 'Authority');
    }

    private function merchantId(string $environment): string
    {
        if (! in_array($environment, ['sandbox', 'production'], true)) {
            throw new PaymentGatewayUnavailableException('Zarinpal payment environment is invalid.');
        }

        $merchantId = config("payment.gateways.zarinpal.merchant_ids.{$environment}");
        if (! is_string($merchantId) || ! Str::isUuid($merchantId)) {
            throw new PaymentGatewayUnavailableException('Zarinpal merchant ID for this payment environment must be a valid UUID.');
        }

        return $merchantId;
    }

    private function apiUrl(string $environment, string $method): string
    {
        if (! in_array($environment, ['sandbox', 'production'], true)) {
            throw new PaymentGatewayUnavailableException('Zarinpal payment environment is invalid.');
        }

        $baseUrl = config("payment.gateways.zarinpal.api_urls.{$environment}");
        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new PaymentGatewayUnavailableException('Zarinpal API URL for this environment is not configured.');
        }

        return rtrim($baseUrl, '/').'/'.$method;
    }

    private function callbackValue(Request $request, string $key): mixed
    {
        return $request->query($key, $request->input($key));
    }

    /**
     * @return array<string, mixed>
     */
    private function responseData(Response $response): array
    {
        if (! $response->successful()) {
            $errors = $response->json('errors');
            $providerCode = is_array($errors) ? ($errors['code'] ?? null) : null;
            $providerCodeSuffix = is_int($providerCode) || is_string($providerCode)
                ? ' (provider code '.$providerCode.')'
                : '';

            throw new PaymentGatewayUnavailableException(
                'Zarinpal API returned HTTP '.$response->status().$providerCodeSuffix.'.',
            );
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            throw new PaymentGatewayUnavailableException('Zarinpal API returned an invalid response.');
        }

        return $data;
    }
}
