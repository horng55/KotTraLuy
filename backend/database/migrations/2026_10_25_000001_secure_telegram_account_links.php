<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique('telegram_chat_id', 'users_telegram_chat_id_unique');
        });

        Schema::table('telegram_link_codes', function (Blueprint $table) {
            $table->index('expires_at', 'telegram_link_codes_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_link_codes', function (Blueprint $table) {
            $table->dropIndex('telegram_link_codes_expires_at_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_telegram_chat_id_unique');
        });
    }
};

