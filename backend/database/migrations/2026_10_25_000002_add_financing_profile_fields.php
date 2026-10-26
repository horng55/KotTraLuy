<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('activity')->nullable()->after('business_type');
            $table->string('province')->nullable()->after('activity');
            $table->date('operating_started_on')->nullable()->after('province');
            $table->string('registration_status')->default('unknown')->after('operating_started_on');
            $table->string('profile_source')->default('self_reported')->after('registration_status');
        });

        Schema::table('financing_profiles', function (Blueprint $table) {
            $table->string('requested_timing')->nullable()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('financing_profiles', function (Blueprint $table) {
            $table->dropColumn('requested_timing');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['activity', 'province', 'operating_started_on', 'registration_status', 'profile_source']);
        });
    }
};
