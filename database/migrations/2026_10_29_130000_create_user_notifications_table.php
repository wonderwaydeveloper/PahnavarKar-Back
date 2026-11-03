<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 32);
            $table->string('priority', 16)->default('normal');
            $table->string('title', 160);
            $table->text('body');
            $table->string('event', 100)->nullable();
            $table->json('data')->nullable();
            $table->string('deduplication_key', 191)->nullable()->unique();
            $table->timestamp('read_at')->nullable();
            $table->softDeletes();
            $table->uuid('deletion_batch_uuid')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'read_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
