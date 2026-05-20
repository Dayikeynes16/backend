<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            // Liga opcional al catálogo. Null = línea con concepto libre
            // (típico para canales). nullOnDelete preserva la historia si el
            // producto se elimina.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            // Denormalizado: preserva el nombre histórico aunque el product
            // se renombre. Es el campo que se muestra siempre en reportes.
            $table->string('concept', 160);

            $table->decimal('quantity', 12, 3);
            // Unidad libre: kg, pieza, l, caja, etc.
            $table->string('unit', 10);
            $table->decimal('unit_price', 12, 4);
            $table->decimal('subtotal', 12, 2);

            $table->string('notes', 500)->nullable();

            $table->timestamps();

            $table->index('purchase_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};
