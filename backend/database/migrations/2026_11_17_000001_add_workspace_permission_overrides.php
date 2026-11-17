<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('business_user', function (Blueprint $table) {
            $table->json('permissions')->nullable();
            $table->string('status')->default('active');
            $table->string('assigned_shift')->nullable();
            $table->boolean('telegram_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('business_user', fn (Blueprint $table) => $table->dropColumn(['permissions', 'status', 'assigned_shift', 'telegram_enabled']));
    }
};
