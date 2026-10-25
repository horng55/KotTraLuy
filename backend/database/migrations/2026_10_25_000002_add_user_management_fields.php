<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
            $table->string('assigned_shift', 1)->nullable()->after('role');
            $table->string('status')->default('active')->after('assigned_shift');
            $table->boolean('must_change_password')->default(false)->after('status');
            $table->foreignId('created_by')->nullable()->after('must_change_password')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('last_login_at')->nullable()->after('created_by');
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'status']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn([
                'username',
                'assigned_shift',
                'status',
                'must_change_password',
                'last_login_at',
            ]);
        });
    }
};
