<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_notification_deliveries', function (Blueprint $table) {
            $table->timestamp('last_attempt_at')->nullable()->after('attempts');
            $table->string('error_category', 64)->nullable()->after('last_error');
            $table->json('telegram_response')->nullable()->after('error_category');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_notification_deliveries', function (Blueprint $table) {
            $table->dropColumn(['last_attempt_at', 'error_category', 'telegram_response']);
        });
    }
};
