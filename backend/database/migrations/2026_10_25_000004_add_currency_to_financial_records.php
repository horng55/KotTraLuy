<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales', 'purchases', 'expenses'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('currency', 10)->default('USD')->after('business_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['sales', 'purchases', 'expenses'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('currency'));
        }
    }
};
