<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 80);
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->morphs('notifiable');
            $table->string('event_key', 191);
            $table->boolean('owner_only')->default(false);
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'notifiable_type', 'notifiable_id', 'event_key'], 'notifications_event_recipient_unique');
            $table->index(['business_id', 'notifiable_id', 'read_at', 'created_at'], 'notifications_inbox_index');
            $table->index(['business_id', 'notifiable_id', 'created_at'], 'notifications_history_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
