<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('profile_photo_path')->nullable();
            $table->string('phone', 16)->nullable()->unique();
            $table->timestamp('phone_verified_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->string('account_status_reason', 500)->nullable();
            $table->timestamp('account_status_changed_at')->nullable();
            $table->foreignId('account_status_changed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('last_active_at')->nullable()->index();
            $table->unsignedTinyInteger('inactivity_deletion_period_months')->nullable()->index();
            $table->timestamp('inactivity_deletion_sms_notified_at')->nullable();
            $table->timestamp('inactivity_deletion_scheduled_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_pending_secret')->nullable();
            $table->timestamp('two_factor_pending_expires_at')->nullable();
            $table->timestamp('two_factor_enabled_at')->nullable();
            $table->json('two_factor_recovery_codes')->nullable();
            $table->unsignedBigInteger('two_factor_last_used_step')->nullable();
            $table->unsignedBigInteger('wallet_balance')->default(0);
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('two_factor_login_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->uuid('device_id');
            $table->string('device_name')->nullable();
            $table->string('device_model')->nullable();
            $table->string('platform', 32)->default('unknown');
            $table->text('push_token')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('phone', 16);
            $table->string('code_hash');
            $table->enum('type', ['login', 'register', 'password_reset']);
            $table->timestamp('expires_at');
            $table->boolean('used')->default(false);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
            $table->index(['phone', 'type', 'used']);
        });

        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('device_id');
            $table->string('device_name')->nullable();
            $table->string('device_model')->nullable();
            $table->string('platform', 32)->default('unknown');
            $table->timestamp('last_activity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'device_id']);
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_device_id')->nullable()->constrained('user_devices')->nullOnDelete();
            $table->string('push_token', 512)->unique();
            $table->string('platform', 16);
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('two_factor_login_challenges');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('otps');
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
