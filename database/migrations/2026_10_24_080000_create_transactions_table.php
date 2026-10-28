<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reverses_transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->string('type', 18);
            $table->string('status', 32)->default('succeeded');
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('balance_after');
            $table->string('currency', 8)->default('TOMAN');
            $table->string('gateway', 64)->nullable();
            $table->string('reference_id', 128)->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->string('description', 255)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['gateway', 'reference_id']);
            $table->unique('reverses_transaction_id');
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
