<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('official_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->text('body');
            $table->string('priority', 16)->default('normal');
            $table->boolean('send_push')->default(false);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('recipient_max_user_id')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->string('delivery_status', 16)->default('queued');
            $table->timestamp('published_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['published_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_announcements');
    }
};
