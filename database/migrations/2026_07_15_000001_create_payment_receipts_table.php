<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_payment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedInteger('size_bytes');
            $table->timestamps();
            $table->index(['payment_id']);
            $table->index(['customer_payment_id']);
        });

        // Exactamente un padre (pago de venta O cobro global).
        DB::statement('ALTER TABLE payment_receipts ADD CONSTRAINT payment_receipts_one_parent CHECK ((payment_id IS NULL) != (customer_payment_id IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};
