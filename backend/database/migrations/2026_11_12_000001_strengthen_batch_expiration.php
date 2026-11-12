<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('businesses', fn (Blueprint $table) => $table->unsignedInteger('expiry_warning_days')->default(30)->change());
        Schema::table('stock_batches', fn (Blueprint $table) => $table->index(['business_id','expiry_date','quantity_remaining'], 'batch_expiration_lookup'));
        Schema::create('batch_audits', function (Blueprint $table) { $table->id(); $table->foreignId('stock_batch_id')->constrained()->restrictOnDelete(); $table->foreignId('user_id')->constrained()->restrictOnDelete(); $table->json('before_data'); $table->json('after_data'); $table->timestamp('created_at'); });
        Schema::table('waste_records', fn (Blueprint $table) => $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches')->restrictOnDelete());
    }
    public function down(): void {
        Schema::dropIfExists('batch_audits');
        Schema::table('waste_records', fn (Blueprint $table) => $table->dropConstrainedForeignId('stock_batch_id'));
        Schema::table('stock_batches', fn (Blueprint $table) => $table->dropIndex('batch_expiration_lookup'));
        Schema::table('businesses', fn (Blueprint $table) => $table->unsignedInteger('expiry_warning_days')->default(7)->change());
    }
};
