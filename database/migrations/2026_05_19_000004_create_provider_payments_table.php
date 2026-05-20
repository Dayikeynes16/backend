<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('provider_id')->constrained();
            // Nullable: pago "a cuenta" (sin compra específica). PurchasePaymentService
            // lo distribuye FIFO sobre las compras pendientes del proveedor.
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('paid_at');
            $table->decimal('amount', 12, 2);

            // Reutiliza PaymentMethod enum (cash|card|transfer). Nunca 'credit'.
            $table->string('payment_method', 20);
            // Folio del comprobante bancario / referencia interna.
            $table->string('reference', 60)->nullable();

            $table->string('notes', 500)->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'paid_at']);
            $table->index(['provider_id', 'paid_at']);
            $table->index(['purchase_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_payments');
    }
};
