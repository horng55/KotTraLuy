<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedInteger('expiry_warning_days')->default(7);
            $table->boolean('daily_digest_enabled')->default(false);
            $table->json('notification_preferences')->nullable();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('payment_received', 12, 2)->default(0);
            $table->decimal('outstanding_balance', 12, 2)->default(0);
            $table->string('idempotency_key')->nullable();
            $table->unique(['business_id', 'idempotency_key']);
        });

        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->string('batch_code');
            $table->date('received_date');
            $table->unsignedInteger('quantity_received');
            $table->unsignedInteger('quantity_remaining')->default(0);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->date('expiry_date')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'product_id', 'expiry_date']);
        });

        Schema::create('sale_batch_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 10);
            $table->dateTime('paid_at');
            $table->string('idempotency_key')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'idempotency_key']);
        });

        Schema::create('inventory_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('condition');
            $table->boolean('reviewed')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'condition', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_alerts');
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_batch_allocations');
        Schema::dropIfExists('stock_batches');
        Schema::table('sales', function (Blueprint $table) { $table->dropUnique(['business_id', 'idempotency_key']); });
        Schema::table('sales', function (Blueprint $table) { $table->dropColumn(['discount_amount', 'payment_received', 'outstanding_balance', 'idempotency_key']); });
        Schema::table('businesses', function (Blueprint $table) { $table->dropColumn(['expiry_warning_days', 'daily_digest_enabled', 'notification_preferences']); });
    }
};
