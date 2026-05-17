<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('linked_order_id')
                ->nullable()
                ->after('cart_note')
                ->constrained('sales')
                ->nullOnDelete();

            $table->index('linked_order_id', 'sales_linked_order_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_linked_order_id_idx');
            $table->dropConstrainedForeignId('linked_order_id');
        });
    }
};
