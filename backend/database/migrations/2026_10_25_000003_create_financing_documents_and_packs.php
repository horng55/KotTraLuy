<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financing_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('document_key');
            $table->string('availability')->default('not_available');
            $table->string('verification_status')->default('not_verified');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'document_key']);
        });

        Schema::create('preparation_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('draft');
            $table->string('product_key')->nullable();
            $table->json('snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preparation_packs');
        Schema::dropIfExists('financing_documents');
    }
};
