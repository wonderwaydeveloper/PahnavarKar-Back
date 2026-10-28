<?php

namespace Tests\Support;

use App\Contracts\PaymentGatewayInterface;
use App\Data\Payments\GatewayPaymentResult;
use App\Data\Payments\PaymentRequest;
use App\Exceptions\PaymentGatewayRejectedException;
use App\Exceptions\PaymentGatewayRetryableException;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Models\PaymentAttempt;
use Illuminate\Http\Request;
use RuntimeException;

class ConcurrentPaymentGateway implements PaymentGatewayInterface
{
    public function initiate(PaymentRequest $request): GatewayPaymentResult
    {
        if (getenv('PAYMENT_CALLBACK_CONCURRENT_INITIATION') !== '1') {
            throw new RuntimeException('The callback concurrency test must not initiate a gateway payment.');
        }

        $directory = (string) getenv('PAYMENT_CALLBACK_CONCURRENCY_DIR');
        if ($directory === '') {
            throw new RuntimeException('The concurrent initiation test requires a barrier directory.');
        }

        touch($directory.DIRECTORY_SEPARATOR.'initiation-entered');

        $deadline = microtime(true) + 25;
        while (! is_file($directory.DIRECTORY_SEPARATOR.'initiation-release')) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting for the initiation concurrency barrier.');
            }

            usleep(10000);
        }

        match (getenv('PAYMENT_CALLBACK_CONCURRENT_INITIATION_FAILURE')) {
            'rejected' => throw new PaymentGatewayRejectedException(
                'Late provider rejection after callback settlement.',
                'late_payment_rejection',
            ),
            'retryable' => throw new PaymentGatewayRetryableException(
                'Late retryable provider error after callback settlement.',
            ),
            'ambiguous' => throw new PaymentGatewayUnavailableException(
                'Late ambiguous provider result after callback settlement.',
            ),
            default => null,
        };

        return new GatewayPaymentResult(
            'pending',
            (string) getenv('PAYMENT_CALLBACK_CONCURRENCY_GATEWAY_REFERENCE'),
            'https://pay.example/concurrent-initiation',
        );
    }

    public function verify(PaymentAttempt $attempt, Request $request): GatewayPaymentResult
    {
        if ($request->input('merchant_reference') !== $attempt->merchant_reference) {
            throw new PaymentGatewayUnavailableException('Callback attempt reference does not match.');
        }

        $directory = (string) getenv('PAYMENT_CALLBACK_CONCURRENCY_DIR');
        $worker = (string) getenv('PAYMENT_CALLBACK_CONCURRENCY_WORKER');
        if ($directory !== '' && $worker !== '') {
            touch($directory.DIRECTORY_SEPARATOR.'verified-'.$worker);
        }

        if (config('payment.gateways.concurrency-test.concurrent_verification', true)) {
            if ($directory === '' || $worker === '') {
                throw new RuntimeException('The concurrent callback test requires worker barrier configuration.');
            }

            $deadline = microtime(true) + 10;
            while (
                ! is_file($directory.DIRECTORY_SEPARATOR.'verified-1')
                || ! is_file($directory.DIRECTORY_SEPARATOR.'verified-2')
            ) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting for both callback verifications.');
                }

                usleep(10000);
            }
        }

        if (getenv('PAYMENT_CALLBACK_CONCURRENCY_ORDERED_VERIFICATION') === '1') {
            $deadline = microtime(true) + 10;
            while (! is_file($directory.DIRECTORY_SEPARATOR.'verify-release-'.$worker)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting for the ordered callback verification barrier.');
                }

                usleep(10000);
            }
        }

        if ($request->input('concurrency_result') === 'failed') {
            return new GatewayPaymentResult('failed', failureCode: 'concurrent_payment_declined');
        }

        return new GatewayPaymentResult(
            'succeeded',
            (string) getenv('PAYMENT_CALLBACK_CONCURRENCY_GATEWAY_REFERENCE'),
        );
    }

    public function validateCallback(Request $request): void
    {
        if ($request->input('callback_proof') !== 'isolated-test-proof') {
            throw new PaymentGatewayUnavailableException('Callback validation failed.');
        }
    }

    public function attemptReference(Request $request): string
    {
        $reference = $request->input('merchant_reference');
        if (! is_string($reference) || $reference === '') {
            throw new PaymentGatewayUnavailableException('Callback reference is missing.');
        }

        return $reference;
    }
}
