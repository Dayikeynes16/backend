<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('original_unit_price', 10, 2)->nullable()->after('unit_price');
        });

        // Backfill: usar products.price actual como aproximación histórica.
        // Las filas donde product_id ya no existe quedan con unit_price como fallback.
        DB::statement('
            UPDATE sale_items si
            SET original_unit_price = COALESCE(p.price, si.unit_price)
            FROM products p
            WHERE si.product_id = p.id
              AND si.original_unit_price IS NULL
        ');

        DB::statement('
            UPDATE sale_items
            SET original_unit_price = unit_price
            WHERE original_unit_price IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('original_unit_price');
        });
    }
};
