<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->index(['sale_id', 'product_id'], 'sale_items_sale_product_idx');
            $table->index('product_id', 'sale_items_product_idx');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->index(['branch_id', 'status', 'completed_at'], 'sales_branch_status_completed_idx');
            $table->index(['branch_id', 'customer_id', 'completed_at'], 'sales_branch_customer_completed_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['sale_id', 'created_at'], 'payments_sale_created_idx');
        });

        Schema::table('customer_payments', function (Blueprint $table) {
            $table->index(['branch_id', 'created_at'], 'customer_payments_branch_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropIndex('sale_items_sale_product_idx');
            $table->dropIndex('sale_items_product_idx');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_branch_status_completed_idx');
            $table->dropIndex('sales_branch_customer_completed_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_sale_created_idx');
        });

        Schema::table('customer_payments', function (Blueprint $table) {
            $table->dropIndex('customer_payments_branch_created_idx');
        });
    }
};
