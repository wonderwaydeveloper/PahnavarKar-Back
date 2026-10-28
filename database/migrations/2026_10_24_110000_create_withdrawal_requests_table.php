<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hold_transaction_id')->nullable()->unique()->constrained('transactions')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_reference')->unique();
            $table->uuid('idempotency_key');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 8)->default('TOMAN');
            $table->string('status', 24)->default('pending');
            $table->string('review_note', 500)->nullable();
            $table->string('payout_reference', 128)->nullable();
            $table->timestamp('idempotency_expires_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->unique(['user_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
    }
};
