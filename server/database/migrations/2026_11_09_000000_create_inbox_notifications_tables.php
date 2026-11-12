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
        // Not `notifications`: that name belongs to Laravel's own notification table
        Schema::create('inbox_notifications', function (Blueprint $table) {
            $table->id();
            // The event name of the sender, or 'alert' for a message without one
            $table->string('kind', 32);
            $table->string('title', 100);
            $table->string('body', 300);
            // The notify validation sets no length for a path
            $table->text('path');
            // A new message with the same tag replaces the old one (null tags never collide)
            $table->string('tag', 64)->nullable()->unique();
            $table->unsignedInteger('count')->nullable();
            // Listed, but never counted as unread
            $table->boolean('quiet')->default(false);
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('inbox_notification_reads', function (Blueprint $table) {
            $table->id();
            // Firefly's user id, as in push_subscriptions
            $table->string('user_id');
            $table->foreignId('notification_id')->constrained('inbox_notifications')->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->unique(['user_id', 'notification_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbox_notification_reads');
        Schema::dropIfExists('inbox_notifications');
    }
};
