<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('sales', 'payment_method')) {
            Schema::table('sales', fn (Blueprint $table) => $table->string('payment_method', 32)->default('cash')->after('currency'));
        }
        if (!Schema::hasColumn('sales', 'payment_status')) {
            Schema::table('sales', fn (Blueprint $table) => $table->string('payment_status', 20)->default('unpaid')->after('payment_method'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales', 'payment_status')) Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('payment_status'));
        if (Schema::hasColumn('sales', 'payment_method')) Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('payment_method'));
    }
};
