<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('order_reference')->unique();
            $table->string('kind', 32);
            $table->string('subscription_change_type', 24)->default('purchase');
            $table->foreignId('source_subscription_id')
                ->nullable()
                ->constrained('subscriptions')
                ->nullOnDelete();
            $table->string('plan_key', 64)->nullable();
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->foreignId('discount_code_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('plan_credit_amount')->default(0);
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedBigInteger('wallet_amount')->default(0);
            $table->unsignedBigInteger('gateway_amount')->default(0);
            $table->string('currency', 8)->default('TOMAN');
            $table->string('status', 24)->default('pending');
            $table->string('failure_code', 128)->nullable();
            $table->uuid('idempotency_key');
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('refund_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('effective_starts_at')->nullable();
            $table->timestamp('effective_ends_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'status', 'created_at']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('payment_order_id')->nullable()->unique()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['payment_order_id']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['payment_order_id']);
            $table->dropColumn('payment_order_id');
        });

        Schema::dropIfExists('payment_orders');
    }
};
