<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('requested_amount', 14, 2)->nullable();
            $table->string('currency', 10)->default('USD');
            $table->string('purpose')->nullable();
            $table->string('business_registration_status')->default('unknown');
            $table->string('bank_statement_status')->default('missing');
            $table->string('collateral_status')->default('unknown');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financing_profiles');
    }
};
