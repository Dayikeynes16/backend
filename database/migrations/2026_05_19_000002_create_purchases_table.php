<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // branch_id es OBLIGATORIO — toda compra pertenece a una sucursal.
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('provider_id')->constrained();

            // Folio interno autogenerado (CMP-YYYY-NNNNN); único por tenant.
            $table->string('folio', 20);
            // Número de factura del proveedor (texto libre, opcional).
            $table->string('invoice_number', 60)->nullable();

            // Fecha del comprobante, no de captura.
            $table->timestamp('purchased_at');

            // status: received | cancelled
            $table->string('status', 12)->default('received');

            $table->decimal('subtotal', 12, 2)->default(0);
            // Reserva para impuestos a futuro; F1 mantiene total = subtotal.
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('amount_pending', 12, 2)->default(0);

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'folio']);
            $table->index(['tenant_id', 'purchased_at']);
            $table->index(['branch_id', 'purchased_at']);
            $table->index(['provider_id', 'amount_pending']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
