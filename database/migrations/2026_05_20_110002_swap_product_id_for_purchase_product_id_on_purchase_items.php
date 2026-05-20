<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->foreignId('purchase_product_id')
                ->nullable()
                ->after('purchase_id')
                ->constrained('purchase_products')
                ->nullOnDelete();
            $table->index('purchase_product_id');
        });

        // Quitar el viejo FK a products de venta (en Postgres, drop de la
        // columna arrastra su índice).
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('purchase_id')->constrained('products')->nullOnDelete();
            $table->index('product_id');
        });
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_product_id');
        });
    }
};
