<?php

namespace App\Console\Commands;

use App\Exceptions\PaymentGatewayUnavailableException;
use App\Models\PaymentAttempt;
use App\Services\Payments\PaymentService;
use App\Services\Payments\ZarinpalGateway;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ReconcileZarinpalPayments extends Command
{
    private const MAX_ATTEMPTS_PER_ENVIRONMENT = 100;

    protected $signature = 'payments:reconcile-zarinpal';

    protected $description = 'Verify known Zarinpal payments listed as unverified by the provider.';

    public function handle(ZarinpalGateway $gateway, PaymentService $payments): int
    {
        $unmatchedInitiations = PaymentAttempt::query()
            ->where('gateway', 'zarinpal')
            ->where('status', 'initiation_unknown')
            ->whereNull('gateway_reference_id')
            ->count();

        $failed = $unmatchedInitiations > 0;
        if ($failed) {
            Log::warning('Zarinpal initiation results without a known authority require manual reconciliation.', [
                'count' => $unmatchedInitiations,
            ]);
            $this->warn(
                $unmatchedInitiations.' Zarinpal initiation(s) have no known authority and require manual reconciliation.',
            );
        }

        $environments = PaymentAttempt::query()
            ->where('gateway', 'zarinpal')
            ->whereIn('status', ['pending', 'initiation_unknown'])
            ->whereNotNull('gateway_reference_id')
            ->distinct()
            ->pluck('gateway_environment');

        if ($environments->isEmpty()) {
            $this->info('No Zarinpal attempts with a known authority require reconciliation.');

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $verified = 0;
        $matched = 0;

        foreach ($environments as $environment) {
            if (! in_array($environment, ['sandbox', 'production'], true)) {
                Log::warning('Skipping Zarinpal attempts with an invalid stored environment.', [
                    'environment' => $environment,
                ]);
                $this->error('Skipped attempts with an invalid stored payment environment.');
                $failed = true;

                continue;
            }

            $processed = 0;

            try {
                $authorities = $gateway->unverifiedAuthorities($environment);
            } catch (PaymentGatewayUnavailableException $exception) {
                Log::error('Zarinpal payment reconciliation could not retrieve unverified authorities.', [
                    'environment' => $environment,
                    'exception' => $exception,
                ]);
                $this->error("Could not query Zarinpal {$environment} unverified payments.");
                $failed = true;

                continue;
            }

            foreach ($authorities as $authority) {
                if ($processed >= self::MAX_ATTEMPTS_PER_ENVIRONMENT) {
                    break;
                }

                $attempt = PaymentAttempt::query()
                    ->where('gateway', 'zarinpal')
                    ->where('gateway_environment', $environment)
                    ->where('gateway_reference_id', $authority)
                    ->whereIn('status', ['pending', 'initiation_unknown'])
                    ->where(function ($query): void {
                        $query->whereNull('last_reconciled_at')
                            ->orWhere('last_reconciled_at', '<=', now()->subMinutes(30));
                    })
                    ->first();

                if (! $attempt) {
                    continue;
                }

                $matched++;
                $processed++;

                $this->markReconciliationChecked($attempt);
                try {
                    $request = Request::create('/', 'GET', [
                        'Authority' => $authority,
                        'Status' => 'OK',
                    ]);
                    $result = $payments->processCallback('zarinpal', $request);

                    if ($result->status === 'succeeded') {
                        $verified++;
                    } else {
                        $this->warn("Attempt {$attempt->getKey()} remains {$result->status}.");
                    }
                } catch (PaymentGatewayUnavailableException $exception) {
                    Log::error('Zarinpal payment verification during reconciliation failed.', [
                        'attempt_id' => $attempt->getKey(),
                        'environment' => $environment,
                        'exception' => $exception,
                    ]);
                    $this->error("Could not verify Zarinpal attempt {$attempt->getKey()}.");
                    $failed = true;
                }
            }

            $remaining = self::MAX_ATTEMPTS_PER_ENVIRONMENT - $processed;
            if ($remaining === 0) {
                continue;
            }

            $attempts = PaymentAttempt::query()
                ->where('gateway', 'zarinpal')
                ->where('gateway_environment', $environment)
                ->whereIn('status', ['pending', 'initiation_unknown'])
                ->whereNotNull('gateway_reference_id')
                ->where(function ($query): void {
                    $query->whereNull('last_reconciled_at')
                        ->orWhere('last_reconciled_at', '<=', now()->subMinutes(30));
                })
                ->orderBy('id')
                ->limit($remaining)
                ->get();

            foreach ($attempts as $attempt) {
                $processed++;

                try {
                    $status = $gateway->inquiryStatus($attempt);
                    $this->markReconciliationChecked($attempt);
                } catch (PaymentGatewayUnavailableException $exception) {
                    Log::error('Zarinpal inquiry during payment reconciliation failed.', [
                        'attempt_id' => $attempt->getKey(),
                        'environment' => $environment,
                        'exception' => $exception,
                    ]);
                    $this->error("Could not inquire Zarinpal attempt {$attempt->getKey()}.");
                    $failed = true;

                    continue;
                }

                if (! in_array($status, ['PAID', 'VERIFIED'], true)) {
                    if (in_array($status, ['FAILED', 'REVERSED'], true)) {
                        Log::warning('Zarinpal inquiry returned a non-success terminal status requiring review.', [
                            'attempt_id' => $attempt->getKey(),
                            'status' => $status,
                        ]);
                        $this->warn("Attempt {$attempt->getKey()} requires review ({$status}).");
                        $failed = true;
                    }

                    continue;
                }

                try {
                    $request = Request::create('/', 'GET', [
                        'Authority' => $attempt->gateway_reference_id,
                        'Status' => 'OK',
                    ]);
                    $result = $payments->processCallback('zarinpal', $request);

                    if ($result->status === 'succeeded') {
                        $verified++;
                    } else {
                        $this->warn("Attempt {$attempt->getKey()} remains {$result->status}.");
                    }
                } catch (PaymentGatewayUnavailableException $exception) {
                    Log::error('Zarinpal payment verification after inquiry failed.', [
                        'attempt_id' => $attempt->getKey(),
                        'environment' => $environment,
                        'exception' => $exception,
                    ]);
                    $this->error("Could not verify Zarinpal attempt {$attempt->getKey()}.");
                    $failed = true;
                }
            }
        }

        $this->info("Matched {$matched} local attempt(s); verified {$verified} payment(s).");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function markReconciliationChecked(PaymentAttempt $attempt): void
    {
        PaymentAttempt::query()
            ->whereKey($attempt->getKey())
            ->whereIn('status', ['pending', 'initiation_unknown'])
            ->update(['last_reconciled_at' => now()]);
    }
}
