<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitácora append-only de cambios a items de una venta desde la Mesa
     * de Trabajo. Conserva snapshots before/after en JSONB para que la
     * historia sobreviva aunque el item se elimine o se renombre el
     * producto en el catálogo.
     */
    public function up(): void
    {
        Schema::create('sale_item_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            // sale_item_id queda nullable para que el cambio sobreviva si el item
            // se purga en algún momento (no debería suceder hoy con soft-delete,
            // pero el constraint es defensivo).
            $table->foreignId('sale_item_id')->nullable()
                ->constrained('sale_items')->nullOnDelete();
            $table->string('event', 20); // added | updated | removed
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->jsonb('diff')->nullable(); // {field: [before, after]} — solo updated
            $table->string('reason', 500)->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['sale_id', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_changes');
    }
};
