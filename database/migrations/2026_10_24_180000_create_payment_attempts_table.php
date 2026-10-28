<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $environment = config('payment.environment');
        if (! in_array($environment, ['sandbox', 'production'], true)) {
            throw new RuntimeException('PAYMENT_ENVIRONMENT must be sandbox or production before migrating payment attempts.');
        }

        Schema::create('payment_attempts', function (Blueprint $table) use ($environment) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('merchant_reference')->unique();
            $table->string('gateway', 32);
            $table->string('gateway_environment', 16)->default($environment);
            $table->unsignedBigInteger('amount');
            $table->string('currency', 8)->default('TOMAN');
            $table->string('status', 24)->default('pending');
            $table->string('gateway_reference_id', 128)->nullable();
            $table->string('redirect_url', 2048)->nullable();
            $table->string('failure_code', 128)->nullable();
            $table->uuid('idempotency_key');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key', 'gateway']);
            $table->unique(['gateway', 'gateway_reference_id']);
            $table->index(['status', 'expires_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(
                ['gateway', 'gateway_environment', 'status', 'last_reconciled_at'],
                'payment_attempts_reconciliation_index',
            );
        });

        Schema::create('discount_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_code_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('order_amount');
            $table->unsignedBigInteger('discount_amount');
            $table->string('status', 16)->default('consumed');
            $table->timestamps();
            $table->unique('transaction_id');
            $table->unique('payment_attempt_id');
            $table->unique('payment_order_id');
            $table->index(['user_id', 'created_at']);
            $table->index(['discount_code_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_usages');
        Schema::dropIfExists('payment_attempts');
    }
};
